<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Config;
use SoftAppeals\Database;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\DocumentTemplates;
use SoftAppeals\Domain\Permission;
use SoftAppeals\Domain\Role;
use SoftAppeals\Auth\AuthorizationService;
use SoftAppeals\Repositories\ContactRepository;
use SoftAppeals\Repositories\DocumentRepository;
use SoftAppeals\Repositories\SignatureRepository;
use SoftAppeals\Repositories\StatusEventRepository;
use SoftAppeals\Security\Hmac;
use SoftAppeals\Support\Clock;

/**
 * The practice's half of section 14: reading a document and signing it.
 *
 * Section 14.3 lists eight things the server verifies before a signature is
 * applied. They are all here, in one method, in that order, each with the
 * sentence a practice reads if it fails. None of them is a check the page
 * performs: the page hides what a person cannot do, and this class refuses it.
 *
 * The one that is easiest to underrate is the hash. The signing screen posts
 * back the hash of the document it displayed, and this class compares three
 * things: what the screen was showing, what the row says, and what is actually
 * in the vault right now. All three have to agree. That is what makes
 * "signature event references the exact document hash" a fact about the world
 * rather than a column somebody filled in.
 */
final class SigningService
{
    private Config $config;
    private Database $db;
    private Clock $clock;
    private DocumentRepository $documents;
    private SignatureRepository $signatures;
    private ContactRepository $contacts;
    private StatusEventRepository $timeline;
    private DocumentVault $vault;
    private AuthorizationService $authorization;
    private AuditService $audit;
    private Hmac $hmac;
    private DocumentService $documentService;

    public function __construct(
        Config $config,
        Database $db,
        Clock $clock,
        DocumentRepository $documents,
        SignatureRepository $signatures,
        ContactRepository $contacts,
        StatusEventRepository $timeline,
        DocumentVault $vault,
        AuthorizationService $authorization,
        AuditService $audit,
        Hmac $hmac,
        DocumentService $documentService
    ) {
        $this->config = $config;
        $this->documentService = $documentService;
        $this->db = $db;
        $this->clock = $clock;
        $this->documents = $documents;
        $this->signatures = $signatures;
        $this->contacts = $contacts;
        $this->timeline = $timeline;
        $this->vault = $vault;
        $this->authorization = $authorization;
        $this->audit = $audit;
        $this->hmac = $hmac;
    }

    /**
     * The document this signed-in person is being asked to sign, if there is
     * one.
     *
     * Found from the session's organization and the session's contact, never
     * from anything in the request. A document id in a query string would be a
     * way to ask for somebody else's agreement by guessing, and the answer here
     * is that there is no parameter to guess with.
     *
     * @param array{organization_id:string,engagement:?array<string,mixed>,contact_id:?string} $context
     * @return array<string,mixed>|null
     */
    public function pending(array $context): ?array
    {
        $engagement = $context['engagement'];
        if ($engagement === null || $context['contact_id'] === null) {
            return null;
        }

        $candidates = [];
        foreach ($this->documents->forEngagement((string) $engagement['id']) as $document) {
            if ((string) $document['status'] !== DocumentStatus::SENT) {
                continue;
            }
            if ((string) $document['organization_id'] !== $context['organization_id']) {
                continue;
            }
            if ((string) $document['signer_contact_id'] !== (string) $context['contact_id']) {
                continue;
            }
            $candidates[] = $document;
        }

        // The Approved Recovery Scope is the schedule to the agreement, and
        // it is signed after it. While both are waiting, the agreement is the
        // one offered; the scope follows the moment the agreement is signed.
        foreach ($candidates as $document) {
            if ((string) $document['kind'] !== DocumentKind::APPROVED_SCOPE) {
                return $document;
            }
        }
        return $candidates[0] ?? null;
    }

    /**
     * Everything the signing screen shows, section 14.3 in order.
     *
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    public function screen(array $document): array
    {
        $body = $this->vault->read((string) $document['private_path']);
        $stored = (string) $document['content_sha256'];
        $actual = $body === null ? null : hash('sha256', $body);

        $signer = $document['signer_contact_id'] === null
            ? null
            : $this->contacts->find((string) $document['signer_contact_id']);

        return [
            'document'      => $document,
            'kind_label'    => DocumentKind::label((string) $document['kind']),
            'body'          => $body,
            'body_intact'   => $actual !== null && hash_equals($stored, $actual),
            'content_sha'   => $stored,
            'signer'        => $signer,
            'consent_text'  => DocumentTemplates::consentText(),
            'consent_version' => DocumentTemplates::CONSENT_VERSION,
            'utc_now'       => $this->clock->nowUtc(),
            'local_now'     => $this->clock->displayDateTime($this->clock->nowUtc()),
        ];
    }

    /**
     * Apply the practice's signature.
     *
     * @param array<string,mixed> $document
     * @param array{organization_id:string,engagement:?array<string,mixed>,contact_id:?string,user_id:string} $context
     * @param array{typed_name:string,typed_title:?string,typed_organization:?string,consent:bool,document_sha256:string} $input
     * @return array{signed:bool,already:bool,signature_id:?string}
     */
    public function sign(array $document, array $context, array $input): array
    {
        $documentId = (string) $document['id'];
        $organizationId = (string) $document['organization_id'];

        $refuse = function (string $reason) use ($documentId, $organizationId): void {
            $this->audit->record('document.sign', 'denied', 'document', $documentId, [
                'reason' => mb_substr($reason, 0, 200),
            ], $organizationId);
            throw new \RuntimeException($reason);
        };

        // 1. Signing is switched on at all.
        if (!$this->config->eSignEnabled()) {
            $refuse('Signing is not switched on here yet. Nothing was signed.');
        }

        // 2. The session belongs to this organization, and 3. it holds the role
        // that may sign. Both are checked against the session, and the second
        // one is checked against the organization the DOCUMENT belongs to
        // rather than the one the session claims, so the two have to agree.
        if ($organizationId !== $context['organization_id']) {
            $refuse('That document belongs to a different practice.');
        }
        if (!$this->authorization->can(Permission::DOCUMENT_SIGN, $organizationId)) {
            $refuse('Only the person named as the authorized signer can sign this.');
        }

        // 4. This person is the assigned signer, not merely somebody holding
        // the role. A practice with two authorized signers has one person named
        // on each document, and the other one is not that person.
        if ($context['contact_id'] === null
            || (string) $document['signer_contact_id'] !== (string) $context['contact_id']
        ) {
            $refuse('This document names somebody else as its signer.');
        }

        // 5. It is the current version and it is out for signature.
        if ((string) $document['status'] !== DocumentStatus::SENT) {
            $refuse('This document is not waiting for a signature. It is: '
                . DocumentStatus::clientLabel((string) $document['status']) . '.');
        }
        $current = $this->documents->current(
            (string) $document['engagement_id'],
            (string) $document['kind']
        );
        if ($current === null || (string) $current['id'] !== $documentId) {
            $refuse('A newer version of this document has replaced the one you were reading. '
                . 'Reload and read the new one.');
        }

        // 6. The document has not changed since it was generated, and the
        // screen was showing the same one.
        $body = $this->vault->read((string) $document['private_path']);
        if ($body === null) {
            $refuse('This document could not be opened, so nothing was signed.');
        }
        $stored = (string) $document['content_sha256'];
        $actual = hash('sha256', (string) $body);
        if (!hash_equals($stored, $actual)) {
            $refuse('This document does not match the record of it, so nothing was signed. '
                . 'Write to softappeals@frimpomaasync.com.');
        }
        if (!hash_equals($stored, trim($input['document_sha256']))) {
            $refuse('The page you signed from was showing an older version. '
                . 'Reload and read it again.');
        }

        // 7. The consent, and the typed name.
        if ($input['consent'] !== true) {
            $refuse('Tick the box to agree to sign electronically.');
        }

        $signer = $document['signer_contact_id'] === null
            ? null
            : $this->contacts->find((string) $document['signer_contact_id']);
        if ($signer === null) {
            $refuse('The signer on this document is no longer on the record.');
        }

        $typedName = trim($input['typed_name']);
        if (!self::namesMatch($typedName, (string) $signer['name'])) {
            $refuse('Type the name this document was issued to: ' . (string) $signer['name'] . '.');
        }

        // 8. Idempotency. A double click, a reload of the POST, a retried
        // request on a flaky connection: all three arrive as a second attempt
        // with the same key, and all three get the first signature back rather
        // than making a second one.
        $idempotencyKey = hash('sha256', 'sign:' . $documentId . ':' . $context['user_id']);
        $existing = $this->signatures->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return [
                'signed'       => true,
                'already'      => true,
                'signature_id' => (string) $existing['id'],
            ];
        }

        $signatureId = $this->db->transaction(function () use (
            $document,
            $documentId,
            $organizationId,
            $context,
            $input,
            $signer,
            $typedName,
            $stored,
            $idempotencyKey
        ): string {
            $now = $this->clock->nowUtc();

            $payloadPath = DocumentVault::signaturePath(
                (string) $document['public_ref'],
                SignatureRepository::PARTY_CLIENT
            );
            $payloadSha = $this->vault->write($payloadPath, $this->payload([
                'document_ref'    => (string) $document['public_ref'],
                'document_sha256' => $stored,
                'party'           => SignatureRepository::PARTY_CLIENT,
                'typed_name'      => $typedName,
                'typed_title'     => (string) ($input['typed_title'] ?? ''),
                'consent_version' => DocumentTemplates::CONSENT_VERSION,
                'signed_at'       => $now,
            ]));

            $id = $this->signatures->record($documentId, SignatureRepository::PARTY_CLIENT, [
                'organization_id'     => $organizationId,
                'signer_user_id'      => $context['user_id'],
                'signer_contact_id'   => (string) $signer['id'],
                'signer_role'         => Role::AUTHORIZED_SIGNER,
                'typed_name'          => $typedName,
                'typed_title'         => $input['typed_title'] ?? null,
                'typed_organization'  => $input['typed_organization'] ?? null,
                'consent_version'     => DocumentTemplates::CONSENT_VERSION,
                'consent_text_sha256' => DocumentTemplates::consentSha256(),
                'consent_accepted_at' => $now,
                'document_sha256'     => $stored,
                'payload_path'        => $payloadPath,
                'payload_sha256'      => $payloadSha,
                'ip_digest'           => $this->hmac->ipDigest('signature'),
                'user_agent_digest'   => $this->hmac->userAgentDigest('signature'),
                'idempotency_key'     => $idempotencyKey,
                'signed_at'           => $now,
            ]);

            if (!$this->documents->moveStatus(
                $documentId,
                DocumentStatus::SENT,
                DocumentStatus::CLIENT_SIGNED,
                ['client_signed_at' => $now]
            )) {
                throw new \RuntimeException(
                    'This document moved while you were signing it. Nothing was signed.'
                );
            }

            // The client's own history. Her countersignature writes its own
            // line later; this one is the practice's act, credited to the
            // practice, which is the whole reason actor_type exists.
            $this->timeline->record(
                (string) $document['engagement_id'],
                'document.client_signed',
                DocumentKind::shortLabel((string) $document['kind']) . ' signed',
                null,
                null,
                StatusEventRepository::ACTOR_CLIENT,
                $context['user_id'],
                [
                    'document_kind'    => (string) $document['kind'],
                    'document_version' => (string) $document['version'],
                ]
            );

            return $id;
        });

        $this->audit->record('document.sign', 'success', 'document', $documentId, [
            'document_kind'    => (string) $document['kind'],
            'document_version' => (string) $document['version'],
            'idempotency_key'  => $idempotencyKey,
        ], $organizationId);

        // A one-party document is executed by the one signature it takes.
        // The Approved Recovery Scope is the practice's own statement of what
        // it authorizes, so there is nobody to countersign it, and waiting
        // for a signature that is never coming would leave it stuck at
        // "signed by you, with us to finish" for ever.
        if (!DocumentKind::requiresCountersignature((string) $document['kind']) && $context['engagement'] !== null) {
            $this->documentService->execute($documentId, $context['engagement'], $context['user_id']);
        }

        return ['signed' => true, 'already' => false, 'signature_id' => $signatureId];
    }

    /**
     * Whether a typed name is the name this document was issued to.
     *
     * Case and spacing are forgiven, and so is punctuation: somebody typing
     * "dr. a. person" for "Dr A Person" has typed their own name and refusing
     * it teaches them nothing. What is not forgiven is a different name, which
     * is the thing the check is for.
     */
    public static function namesMatch(string $typed, string $expected): bool
    {
        return self::normalizeName($typed) === self::normalizeName($expected)
            && self::normalizeName($typed) !== '';
    }

    private static function normalizeName(string $value): string
    {
        $lower = mb_strtolower(trim($value));
        $stripped = preg_replace('/[^\p{L}\p{N}\s]/u', '', $lower) ?? '';
        $collapsed = preg_replace('/\s+/u', ' ', $stripped) ?? '';
        return trim($collapsed);
    }

    /** @param array<string,string> $fields */
    private function payload(array $fields): string
    {
        ksort($fields);
        $json = json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('The signature record could not be written.');
        }
        return $json . "\n";
    }
}
