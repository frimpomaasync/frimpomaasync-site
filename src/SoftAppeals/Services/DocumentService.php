<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Config;
use SoftAppeals\Database;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\DocumentTemplates;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\Role;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\ContactRepository;
use SoftAppeals\Repositories\DocumentRepository;
use SoftAppeals\Repositories\EngagementRepository;
use SoftAppeals\Repositories\InvitationRepository;
use SoftAppeals\Repositories\PreferenceRepository;
use SoftAppeals\Repositories\RecoveryScopeRepository;
use SoftAppeals\Repositories\SettingsRepository;
use SoftAppeals\Repositories\SignatureRepository;
use SoftAppeals\Repositories\StatusEventRepository;
use SoftAppeals\Security\Hmac;
use SoftAppeals\Support\Clock;
use SoftAppeals\Support\Money;

/**
 * Her half of section 14: generating a document, sending it for signature,
 * countersigning it, executing it, and correcting one that was wrong.
 *
 * The client's half is SigningService, and the split is on purpose. Everything
 * in this class runs behind a staff permission and a staff session. Everything
 * in that one runs behind a client session that holds exactly one role. Two
 * classes means no method in either can be called by the wrong side by
 * accident, because the wrong side has no route that reaches it.
 *
 * The immutability rule in one place: this class writes a document body exactly
 * once, at generate(), into a file whose hash goes on the row. Nothing here
 * ever opens that file for writing again. A correction is generate() called a
 * second time, which produces a second version, and the first one is marked
 * void with a reason and left otherwise untouched.
 */
final class DocumentService
{
    public const TEMPLATE_KEY_SIGN_REQUEST = 'document_sign_request';
    public const TEMPLATE_KEY_EXECUTED     = 'document_executed';

    /** How long a signing link lives. Section 10.3, the same as the terms link. */
    public const SIGN_TTL_SECONDS = 14 * 24 * 60 * 60;

    private Config $config;
    private Database $db;
    private Clock $clock;
    private DocumentRepository $documents;
    private EngagementRepository $engagements;
    private SignatureRepository $signatures;
    private PreferenceRepository $preferences;
    private ContactRepository $contacts;
    private InvitationRepository $invitations;
    private StatusEventRepository $timeline;
    private EngagementService $engagementService;
    private DocumentVault $vault;
    private MailService $mail;
    private AuditService $audit;
    private Hmac $hmac;
    private SettingsRepository $settings;
    private RecoveryScopeRepository $scopes;

    public function __construct(
        Config $config,
        Database $db,
        Clock $clock,
        DocumentRepository $documents,
        EngagementRepository $engagements,
        SignatureRepository $signatures,
        PreferenceRepository $preferences,
        ContactRepository $contacts,
        InvitationRepository $invitations,
        StatusEventRepository $timeline,
        EngagementService $engagementService,
        DocumentVault $vault,
        MailService $mail,
        AuditService $audit,
        Hmac $hmac,
        SettingsRepository $settings,
        RecoveryScopeRepository $scopes
    ) {
        $this->config = $config;
        $this->settings = $settings;
        $this->scopes = $scopes;
        $this->db = $db;
        $this->clock = $clock;
        $this->documents = $documents;
        $this->engagements = $engagements;
        $this->signatures = $signatures;
        $this->preferences = $preferences;
        $this->contacts = $contacts;
        $this->invitations = $invitations;
        $this->timeline = $timeline;
        $this->engagementService = $engagementService;
        $this->vault = $vault;
        $this->mail = $mail;
        $this->audit = $audit;
        $this->hmac = $hmac;
    }

    // ------------------------------------------------------------------
    // Generating.
    // ------------------------------------------------------------------

    /**
     * Whether this kind can be generated for this engagement right now, and if
     * not, the sentence that says why.
     *
     * The Desk asks this before it offers a button, and generate() asks it
     * again before it does anything, because a hidden button is presentation
     * and a checked precondition is protection.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @return array{ok:bool,reason:?string}
     */
    public function canGenerate(array $engagement, string $kind): array
    {
        if (!DocumentKind::isLive($kind)) {
            return ['ok' => false, 'reason' => 'There is no workflow for that document type yet.'];
        }

        $existing = $this->documents->current((string) $engagement['id'], $kind);
        $everIssued = $this->documents->nextVersion((string) $engagement['id'], $kind) > 1;

        // The stage gate applies to the FIRST version only.
        //
        // A correction is generated after the original has already moved the
        // engagement along, so checking the opening stage again would refuse
        // every replacement a document ever needs. What matters for a
        // replacement is that this kind of document belongs to this engagement
        // at all, and a version already existing is the proof of that.
        $required = DocumentKind::requiredStage($kind);
        $stage = $this->currentStage((string) $engagement['id']);
        if (!$everIssued && $required !== null && $stage !== $required) {
            return [
                'ok'     => false,
                'reason' => DocumentKind::label($kind) . ' is generated at "'
                    . Stage::staffLabel($required) . '". This engagement is at "'
                    . Stage::staffLabel($stage) . '".',
            ];
        }

        // Any version that is not void blocks a bare generate, INCLUDING an
        // executed one.
        //
        // An executed agreement is closed, but closed is not the same as gone.
        // Generating over it would leave version 1 executed and un-voided while
        // version 2 quietly became the current one, so the agreement both
        // parties actually signed would stop being the agreement the portal
        // shows, with no reason recorded anywhere. Section 14.2 says a
        // correction voids the previous version with an audit reason, and
        // correct() is the only door that does both.
        if ($existing !== null) {
            $status = (string) $existing['status'];
            return [
                'ok'     => false,
                'reason' => $status === DocumentStatus::EXECUTED
                    ? 'Version ' . (int) $existing['version'] . ' is executed. Replacing an '
                        . 'executed agreement voids it with a reason, so use "Void this and '
                        . 'generate a replacement".'
                    : 'Version ' . (int) $existing['version'] . ' is already open ('
                        . DocumentStatus::staffLabel($status)
                        . '). Void it before generating another.',
            ];
        }

        if ($this->signerContact($engagement) === null) {
            return [
                'ok'     => false,
                'reason' => 'Nobody is named as the authorized signer. The practice names '
                    . 'one on the preferences page.',
            ];
        }

        if ($this->settings->legalEntity($this->config) === '') {
            return [
                'ok'     => false,
                'reason' => 'No legal entity name is set, so there is no legal party name to '
                    . 'put on the document. Set it under Settings on the Desk, or as '
                    . 'SA_LEGAL_ENTITY in the server config.',
            ];
        }

        // Gate B, section 6. The two recovery documents are generated FROM
        // the recorded scope. No scope, no fee basis, no batches, no approver
        // means no document, and the reason names which.
        if (DocumentKind::isRecoveryPair($kind)) {
            $scope = $this->scopes->forEngagement((string) $engagement['id']);
            if ($scope === null) {
                return [
                    'ok'     => false,
                    'reason' => 'The recovery scope has not been recorded. Record it on the Recovery '
                        . 'screen first: the fee basis, the batches in scope and the approver '
                        . 'are what these two documents are written from.',
                ];
            }
            if ($scope['approver_contact_id'] === null) {
                return [
                    'ok'     => false,
                    'reason' => 'No submission approver is named on the scope. Both recovery '
                        . 'documents name that person, so name one first.',
                ];
            }
            if ($this->scopes->batchIds((string) $scope['id']) === []) {
                return [
                    'ok'     => false,
                    'reason' => 'The scope covers no batches. Choose at least one recommended batch.',
                ];
            }
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * Generate the Recovery Services Agreement and the Approved Recovery
     * Scope together, from the recorded scope, in one transaction.
     *
     * Together because they are one gate. An agreement with no scope behind
     * it names nothing, and a scope with no agreement authorizes work under
     * no terms. Both are drafts until sent, and sending the agreement sends
     * the scope alongside it.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @return array{agreement:array<string,mixed>,scope:array<string,mixed>}
     */
    public function generateRecoveryPair(array $engagement, ?string $userId = null): array
    {
        return $this->db->transaction(function () use ($engagement, $userId): array {
            $agreement = $this->generate($engagement, DocumentKind::RECOVERY_AGREEMENT, $userId);
            $scope = $this->generate($engagement, DocumentKind::APPROVED_SCOPE, $userId);
            return ['agreement' => $agreement, 'scope' => $scope];
        });
    }

    /**
     * Generate a record kind and execute it in the same transaction. Phase 7.
     *
     * A record is Soft Appeals' own statement, not an agreement: nobody
     * signs it, so there is no sent, no client-signed and no countersigned.
     * It goes from draft to executed in one move, is rendered into the vault
     * with its hash on the row like every agreement, and reopens and
     * re-hashes on every read the same way. Refused for any kind a practice
     * signs, so this can never be a way past a signature.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @param array<string,string> $context the figures the record prints
     * @return array<string,mixed> the executed document row
     */
    public function seal(array $engagement, string $kind, array $context, ?string $userId = null): array
    {
        if (!DocumentKind::isRecord($kind)) {
            throw new \RuntimeException(DocumentKind::label($kind) . ' is signed, not sealed.');
        }
        return $this->db->transaction(function () use ($engagement, $kind, $context, $userId): array {
            $document = $this->generate($engagement, $kind, $userId, $context);
            return $this->execute((string) $document['id'], $engagement, $userId);
        });
    }

    /**
     * Generate one version of one document.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @param array<string,string> $extraContext record figures, see seal()
     * @return array<string,mixed> the document row as inserted
     */
    public function generate(array $engagement, string $kind, ?string $userId = null, array $extraContext = []): array
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];

        $allowed = $this->canGenerate($engagement, $kind);
        if (!$allowed['ok']) {
            $this->audit->record('document.generate', 'failure', 'engagement', $engagementId, [
                'document_kind' => $kind,
                'reason'        => (string) $allowed['reason'],
            ], $organizationId);
            throw new \RuntimeException((string) $allowed['reason']);
        }

        $signer = $this->signerContact($engagement);
        if ($signer === null) {
            throw new \RuntimeException('Nobody is named as the authorized signer.');
        }

        $reserved = $this->documents->reserve($engagementId, $kind);
        $effectiveDate = $this->clock->displayDate($this->clock->nowUtc());

        // The caller's keys sit on the left so a record kind can hand in the
        // figures it prints. No agreement kind passes any, and the identity
        // keys are set here, so nothing a caller passes can rename a party.
        $body = DocumentTemplates::body($kind, $extraContext + $this->templateContext(
            $engagement,
            $signer,
            $reserved['public_ref'],
            $reserved['version'],
            $effectiveDate
        ));

        $path = DocumentVault::documentPath(
            (string) $engagement['public_ref'],
            $reserved['public_ref'],
            $reserved['version']
        );
        $sha = $this->vault->write($path, $body);

        $this->documents->insertReserved($reserved, $engagementId, $organizationId, $kind, [
            'title'             => DocumentTemplates::title($kind),
            'template_version'  => DocumentTemplates::TEMPLATE_VERSION,
            'consent_version'   => DocumentTemplates::CONSENT_VERSION,
            'content_sha256'    => $sha,
            'private_path'      => $path,
            'signer_contact_id' => (string) $signer['id'],
            'fee_basis'         => $this->feeBasisFor($kind, $engagement),
            'created_by'        => $userId,
        ]);

        $this->audit->record('document.generate', 'success', 'document', $reserved['id'], [
            'document_kind'    => $kind,
            'document_version' => (string) $reserved['version'],
            'template_version' => DocumentTemplates::TEMPLATE_VERSION,
        ], $organizationId);

        $document = $this->documents->find($reserved['id']);
        if ($document === null) {
            throw new \RuntimeException('The document was written and then could not be read back.');
        }
        return $document;
    }

    // ------------------------------------------------------------------
    // Sending for signature.
    // ------------------------------------------------------------------

    /**
     * Send the signing link to the named signer.
     *
     * Section 14.4: the email carries a notice and a link, never the document.
     * An agreement in an inbox is an agreement in every mail server it passed
     * through, and the whole point of the portal is that the document stays
     * behind a session.
     *
     * @param array<string,mixed> $document
     * @param array<string,mixed> $engagement joined with its organization
     * @return array{sent:bool,state:string,reason:string,link:?string,expires_at:string}
     */
    public function send(array $document, array $engagement, ?string $userId = null): array
    {
        $documentId = (string) $document['id'];
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];
        $status = (string) $document['status'];

        if ($status !== DocumentStatus::DRAFT) {
            throw new \RuntimeException(
                'Only a draft goes out for signature. This one is: '
                . DocumentStatus::staffLabel($status) . '.'
            );
        }

        if (!$this->config->eSignEnabled()) {
            $this->audit->record('document.send', 'denied', 'document', $documentId, [
                'reason' => 'signing is switched off in this environment',
            ], $organizationId);
            throw new \RuntimeException(
                'Signing is switched off here, so a signing link would go nowhere. '
                . 'Nothing was sent.'
            );
        }

        $signer = $document['signer_contact_id'] === null
            ? null
            : $this->contacts->find((string) $document['signer_contact_id']);
        if ($signer === null) {
            throw new \RuntimeException('The signer named on this document is no longer there.');
        }

        $invitation = $this->invitations->mint(
            $organizationId,
            $engagementId,
            (string) $signer['work_email'],
            InvitationRepository::PURPOSE_SIGN,
            self::SIGN_TTL_SECONDS,
            $userId,
            (string) $signer['id']
        );

        $link = rtrim($this->config->string('SA_APP_URL'), '/')
            . '/soft-appeals-sign?t=' . $invitation['token'];

        // The status moves BEFORE the message goes out, and it moves whether or
        // not the mail server later takes it. Two reasons, in that order.
        //
        // If the move fails, somebody else acted on this document first and no
        // email should go at all, so the move is the thing that has to happen
        // first for the refusal to mean anything.
        //
        // And once it has moved, the document is issued. That is her act, and
        // it is true even if the mail server refuses the message a moment
        // later. Whether the message arrived is the communication row's
        // business, and the Desk shows both facts separately.
        $moved = $this->documents->moveStatus(
            $documentId,
            DocumentStatus::DRAFT,
            DocumentStatus::SENT,
            ['sent_at' => $this->clock->nowUtc()]
        );
        if (!$moved) {
            throw new \RuntimeException(
                'This document moved while you were looking at it. Reload and try again.'
            );
        }

        $result = $this->mail->send(
            (string) $signer['work_email'],
            DocumentKind::label((string) $document['kind']) . ' to sign',
            $this->signRequestBody($document, $engagement, $signer, $link, (string) $invitation['expires_at']),
            self::TEMPLATE_KEY_SIGN_REQUEST,
            $engagementId,
            $organizationId,
            hash('sha256', 'document-send:' . $documentId)
        );

        // The Approved Recovery Scope goes out with the agreement, on the
        // same invitation. It is one gate and one email; the room offers the
        // scope for signature the moment the agreement is signed.
        if ((string) $document['kind'] === DocumentKind::RECOVERY_AGREEMENT) {
            $scope = $this->documents->current($engagementId, DocumentKind::APPROVED_SCOPE);
            if ($scope !== null && (string) $scope['status'] === DocumentStatus::DRAFT) {
                $this->sendAlongside($scope, $engagement, $userId);
            }
        }

        $pending = DocumentKind::pendingStage((string) $document['kind']);
        if ($pending !== null && Stage::canMove($this->currentStage($engagementId), $pending)) {
            $this->engagementService->move(
                $engagementId,
                $pending,
                DocumentKind::label((string) $document['kind']) . ' sent for signature',
                'document.sent',
                $userId,
                ['template_key' => self::TEMPLATE_KEY_SIGN_REQUEST]
            );
        }

        $this->audit->record('document.send', $result['sent'] ? 'success' : 'failure', 'document', $documentId, [
            'document_kind'    => (string) $document['kind'],
            'document_version' => (string) $document['version'],
            'reason'           => $result['sent'] ? 'sent' : (string) $result['reason'],
        ], $organizationId);

        return [
            'sent'       => (bool) $result['sent'],
            'state'      => (string) $result['state'],
            'reason'     => (string) $result['reason'],
            // Staging cannot email a real practice, so the only way to walk the
            // signing screen is to be handed the link here. Null on production,
            // gated on the environment rather than on a flag, exactly as the
            // terms link is.
            'link'       => $this->config->isProduction() ? null : $link,
            'expires_at' => (string) $invitation['expires_at'],
        ];
    }

    /**
     * Mark a draft as sent without its own email or its own link. Used for
     * the Approved Recovery Scope, which travels with the agreement.
     *
     * @param array<string,mixed> $document
     * @param array<string,mixed> $engagement joined with its organization
     */
    public function sendAlongside(array $document, array $engagement, ?string $userId = null): void
    {
        $documentId = (string) $document['id'];
        if ((string) $document['status'] !== DocumentStatus::DRAFT) {
            throw new \RuntimeException(
                'Only a draft goes out for signature. This one is: '
                . DocumentStatus::staffLabel((string) $document['status']) . '.'
            );
        }
        if (!$this->config->eSignEnabled()) {
            throw new \RuntimeException('Signing is switched off here. Nothing was sent.');
        }
        if (!$this->documents->moveStatus($documentId, DocumentStatus::DRAFT, DocumentStatus::SENT, [
            'sent_at' => $this->clock->nowUtc(),
        ])) {
            throw new \RuntimeException('This document moved while you were looking at it. Reload and try again.');
        }
        $this->audit->record('document.send', 'success', 'document', $documentId, [
            'document_kind'    => (string) $document['kind'],
            'document_version' => (string) $document['version'],
            'reason'           => 'sent alongside the agreement',
        ], (string) $engagement['organization_id']);
    }

    // ------------------------------------------------------------------
    // Countersigning, and executing.
    // ------------------------------------------------------------------

    /**
     * Her countersignature, and the execution that follows it.
     *
     * One transaction. A countersignature that landed without the document
     * being executed would leave an agreement both parties had signed sitting
     * at a status that says it is not finished, and the practice would be shown
     * a half-truth in its own portal.
     *
     * @param array<string,mixed> $document
     * @param array<string,mixed> $engagement joined with its organization
     * @param array{typed_name:string,typed_title:?string,consent:bool} $input
     * @return array<string,mixed> the executed document row
     */
    public function countersign(array $document, array $engagement, array $input, string $userId): array
    {
        $documentId = (string) $document['id'];
        $organizationId = (string) $engagement['organization_id'];

        if ((string) $document['status'] !== DocumentStatus::CLIENT_SIGNED) {
            throw new \RuntimeException(
                'A countersignature goes on a document the practice has already signed. '
                . 'This one is: ' . DocumentStatus::staffLabel((string) $document['status']) . '.'
            );
        }

        if (!$this->config->eSignEnabled()) {
            throw new \RuntimeException('Signing is switched off here. Nothing was signed.');
        }

        if ($input['consent'] !== true) {
            throw new \RuntimeException('The electronic-record consent has to be accepted.');
        }

        $typedName = trim($input['typed_name']);
        if (mb_strlen($typedName) < 2) {
            throw new \RuntimeException('Type the full legal name that is signing.');
        }

        $this->db->transaction(function () use (
            $document,
            $documentId,
            $organizationId,
            $typedName,
            $input,
            $userId
        ): void {
            $now = $this->clock->nowUtc();
            $payloadPath = DocumentVault::signaturePath(
                (string) $document['public_ref'],
                SignatureRepository::PARTY_SOFT_APPEALS
            );
            $payloadSha = $this->vault->write($payloadPath, $this->signaturePayload([
                'document_ref'    => (string) $document['public_ref'],
                'document_sha256' => (string) $document['content_sha256'],
                'party'           => SignatureRepository::PARTY_SOFT_APPEALS,
                'typed_name'      => $typedName,
                'typed_title'     => (string) ($input['typed_title'] ?? ''),
                'consent_version' => DocumentTemplates::CONSENT_VERSION,
                'signed_at'       => $now,
            ]));

            $this->signatures->record($documentId, SignatureRepository::PARTY_SOFT_APPEALS, [
                'organization_id'     => $organizationId,
                'signer_user_id'      => $userId,
                'signer_role'         => Role::OWNER_ADMIN,
                'typed_name'          => $typedName,
                'typed_title'         => $input['typed_title'] ?? null,
                'typed_organization'  => $this->settings->legalEntity($this->config),
                'consent_version'     => DocumentTemplates::CONSENT_VERSION,
                'consent_text_sha256' => DocumentTemplates::consentSha256(),
                'consent_accepted_at' => $now,
                'document_sha256'     => (string) $document['content_sha256'],
                'payload_path'        => $payloadPath,
                'payload_sha256'      => $payloadSha,
                'ip_digest'           => $this->hmac->ipDigest('signature'),
                'user_agent_digest'   => $this->hmac->userAgentDigest('signature'),
                'idempotency_key'     => hash('sha256', 'countersign:' . $documentId),
                'signed_at'           => $now,
            ]);

            if (!$this->documents->moveStatus(
                $documentId,
                DocumentStatus::CLIENT_SIGNED,
                DocumentStatus::COUNTERSIGNED,
                ['countersigned_at' => $now]
            )) {
                throw new \RuntimeException(
                    'This document moved while you were looking at it. Nothing was signed.'
                );
            }
        });

        $this->audit->record('document.countersign', 'success', 'document', $documentId, [
            'document_kind'    => (string) $document['kind'],
            'document_version' => (string) $document['version'],
        ], $organizationId);

        return $this->execute($documentId, $engagement, $userId);
    }

    /**
     * Render the executed record, hash it, and close the document.
     *
     * This is the point at which the agreement becomes a thing rather than a
     * process. The rendered record holds the body exactly as generated, every
     * signature with its evidence, and the audit certificate section 14.4 asks
     * for. Its hash goes on the row, and verify() below reopens it and checks.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @return array<string,mixed> the executed document row
     */
    public function execute(string $documentId, array $engagement, ?string $userId = null): array
    {
        $document = $this->documents->find($documentId);
        if ($document === null) {
            throw new \RuntimeException('No such document.');
        }

        $status = (string) $document['status'];
        if (!DocumentStatus::canMove($status, DocumentStatus::EXECUTED)) {
            throw new \RuntimeException(
                'This document is not ready to execute. It is: '
                . DocumentStatus::staffLabel($status) . '.'
            );
        }

        $kind = (string) $document['kind'];
        if (DocumentKind::requiresCountersignature($kind) && $status !== DocumentStatus::COUNTERSIGNED) {
            throw new \RuntimeException(
                DocumentKind::label($kind) . ' needs a countersignature before it is executed.'
            );
        }

        // The draft-to-executed edge exists for record kinds and for nothing
        // else. An agreement the practice has not signed does not execute,
        // whatever the status table allows, and this is the line that says so.
        if (DocumentKind::requiresSignature($kind)
            && !in_array($status, [DocumentStatus::CLIENT_SIGNED, DocumentStatus::COUNTERSIGNED], true)
        ) {
            throw new \RuntimeException(
                DocumentKind::label($kind) . ' needs the practice\'s signature before it is executed.'
            );
        }

        $organizationId = (string) $engagement['organization_id'];
        $now = $this->clock->nowUtc();

        $rendered = $this->renderExecuted($document, $engagement, $now);
        $executedPath = DocumentVault::executedPath(
            (string) $engagement['public_ref'],
            (string) $document['public_ref'],
            (int) $document['version']
        );
        $executedSha = $this->vault->write($executedPath, $rendered);

        if (!$this->documents->moveStatus($documentId, $status, DocumentStatus::EXECUTED, [
            'executed_at'     => $now,
            'executed_path'   => $executedPath,
            'executed_sha256' => $executedSha,
        ])) {
            throw new \RuntimeException(
                'This document moved while it was being executed. Nothing was written to it.'
            );
        }

        $executedStage = DocumentKind::executedStage($kind);
        if ($executedStage !== null
            && Stage::canMove($this->currentStage((string) $engagement['id']), $executedStage)
        ) {
            $this->engagementService->move(
                (string) $engagement['id'],
                $executedStage,
                DocumentKind::label($kind) . ' executed',
                'document.executed',
                $userId,
                ['document_kind' => $kind, 'document_version' => (string) $document['version']]
            );
        }

        // A record's own service tells the practice what the record is; the
        // "signed by both of us" notice would be untrue for it.
        if (DocumentKind::requiresSignature($kind)) {
            $this->notifyExecuted($document, $engagement);
        }

        $this->audit->record('document.execute', 'success', 'document', $documentId, [
            'document_kind'    => $kind,
            'document_version' => (string) $document['version'],
        ], $organizationId);

        $executed = $this->documents->find($documentId);
        if ($executed === null) {
            throw new \RuntimeException('The document vanished as it was executed.');
        }
        return $executed;
    }

    // ------------------------------------------------------------------
    // Correcting.
    // ------------------------------------------------------------------

    /**
     * Void this version and generate the one that replaces it.
     *
     * Section 14.2 in one method. The old row keeps its body, its hash, its
     * signatures and its executed record; what it gains is a void stamp, the
     * reason, and a pointer to what replaced it.
     *
     * @param array<string,mixed> $document
     * @param array<string,mixed> $engagement joined with its organization
     * @return array<string,mixed> the new version
     */
    public function correct(array $document, array $engagement, string $reason, ?string $userId = null): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 4) {
            throw new \RuntimeException('Say why it is being replaced. It goes on the record.');
        }

        $replacement = $this->db->transaction(function () use ($document, $engagement, $reason, $userId): array {
            $this->void($document, $engagement, $reason, $userId);
            return $this->generate($engagement, (string) $document['kind'], $userId);
        });

        $this->documents->markSuperseded((string) $document['id'], (string) $replacement['id']);

        $this->audit->record('document.correct', 'success', 'document', (string) $document['id'], [
            'document_kind'    => (string) $document['kind'],
            'document_version' => (string) $document['version'],
            'reason'           => mb_substr($reason, 0, 200),
        ], (string) $engagement['organization_id']);

        return $replacement;
    }

    /**
     * Void a version without replacing it.
     *
     * @param array<string,mixed> $document
     * @param array<string,mixed> $engagement joined with its organization
     */
    public function void(array $document, array $engagement, string $reason, ?string $userId = null): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 4) {
            throw new \RuntimeException('Say why it is being voided. It goes on the record.');
        }

        $documentId = (string) $document['id'];
        $status = (string) $document['status'];

        if ($status === DocumentStatus::VOID) {
            throw new \RuntimeException('That version is already void.');
        }

        if (!$this->documents->moveStatus($documentId, $status, DocumentStatus::VOID, [
            'void_reason' => mb_substr($reason, 0, 200),
            'voided_at'   => $this->clock->nowUtc(),
        ])) {
            throw new \RuntimeException(
                'This document moved while you were looking at it. Nothing was voided.'
            );
        }

        $this->timeline->record(
            (string) $engagement['id'],
            'document.void',
            DocumentKind::shortLabel((string) $document['kind']) . ' version '
                . (int) $document['version'] . ' was replaced',
            null,
            null,
            StatusEventRepository::ACTOR_STAFF,
            $userId,
            ['reason' => mb_substr($reason, 0, 200)]
        );

        $this->audit->record('document.void', 'success', 'document', $documentId, [
            'document_kind'    => (string) $document['kind'],
            'document_version' => (string) $document['version'],
            'reason'           => mb_substr($reason, 0, 200),
        ], (string) $engagement['organization_id']);
    }

    // ------------------------------------------------------------------
    // Reading back.
    // ------------------------------------------------------------------

    /**
     * Reopen a document and check both stored hashes.
     *
     * @param array<string,mixed> $document
     * @return array{body:array{found:bool,matches:bool,sha256:?string},
     *               executed:array{found:bool,matches:bool,sha256:?string}|null}
     */
    public function verify(array $document): array
    {
        $body = $this->vault->verify(
            (string) $document['private_path'],
            (string) $document['content_sha256']
        );

        $executed = null;
        if ($document['executed_path'] !== null) {
            $executed = $this->vault->verify(
                (string) $document['executed_path'],
                $document['executed_sha256'] === null ? null : (string) $document['executed_sha256']
            );
        }

        return ['body' => $body, 'executed' => $executed];
    }

    /** The document body as generated, or null if the vault no longer holds it. */
    public function body(array $document): ?string
    {
        return $this->vault->read((string) $document['private_path']);
    }

    /** The executed record, or null while there is not one yet. */
    public function executedRecord(array $document): ?string
    {
        if ($document['executed_path'] === null) {
            return null;
        }
        return $this->vault->read((string) $document['executed_path']);
    }

    // ------------------------------------------------------------------
    // The pieces.
    // ------------------------------------------------------------------

    /**
     * The stage this engagement is at right now, read from the database.
     *
     * Never the stage on the array the caller is holding. Sending a document
     * moves the engagement, and the caller is still holding the row as it was
     * before that happened, so a later step checking the snapshot would be
     * asking whether a move was legal from a stage the engagement left minutes
     * ago. That is exactly how the BAA executed itself and left the engagement
     * sitting at "out for signature" on the first CI run of this phase.
     */
    private function currentStage(string $engagementId): string
    {
        $row = $this->engagements->find($engagementId);
        if ($row === null) {
            throw new \RuntimeException('No such engagement.');
        }
        return (string) $row['stage'];
    }

    /**
     * The contact the practice named as its authorized signer.
     *
     * Read from the preferences row rather than from a role lookup, because
     * "who signs" is an answer the practice gave on its own form and a role is
     * a permission somebody may hold for other reasons.
     *
     * @param array<string,mixed> $engagement
     * @return array<string,mixed>|null
     */
    public function signerContact(array $engagement): ?array
    {
        $preferences = $this->preferences->forEngagement((string) $engagement['id']);
        if ($preferences === null || $preferences['signer_contact_id'] === null) {
            return null;
        }
        return $this->contacts->find((string) $preferences['signer_contact_id']);
    }

    /**
     * The fee basis stamped on the row. The review authorization carries
     * none, the recovery pair carry the scope's, the BAA carries the
     * engagement's.
     *
     * @param array<string,mixed> $engagement
     */
    private function feeBasisFor(string $kind, array $engagement): ?string
    {
        if ($kind === DocumentKind::REVIEW_AUTHORIZATION) {
            return null;
        }
        if (DocumentKind::isRecoveryPair($kind)) {
            $scope = $this->scopes->forEngagement((string) $engagement['id']);
            return $scope === null ? (string) $engagement['fee_basis'] : (string) $scope['fee_basis'];
        }
        return (string) $engagement['fee_basis'];
    }

    /**
     * Everything DocumentTemplates needs, resolved.
     *
     * The scope keys are present only once a scope is recorded. The BAA and
     * the review authorization never read them; the recovery pair refuse to
     * generate without them, and DocumentTemplates::value() names the one
     * that is missing.
     *
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $signer
     * @return array<string,string>
     */
    private function templateContext(
        array $engagement,
        array $signer,
        string $documentRef,
        int $version,
        string $effectiveDate
    ): array {
        $preferences = $this->preferences->forEngagement((string) $engagement['id']);
        $channel = $preferences === null
            ? (string) ($engagement['secure_channel_type'] ?? '')
            : (string) $preferences['secure_channel'];

        // The scope's keys sit on the left, so its fee basis wins on the
        // recovery pair. The BAA and the review authorization never print a
        // fee basis, so nothing else changes.
        return $this->scopeContext($engagement) + [
            'document_ref'        => $documentRef,
            'version'             => (string) $version,
            'effective_date'      => $effectiveDate,
            'provider_legal_name' => $this->settings->legalEntity($this->config),
            'provider_trade_name' => $this->settings->tradeName($this->config),
            'client_legal_name'   => (string) $engagement['legal_name'],
            'signer_name'         => (string) $signer['name'],
            'signer_title'        => trim((string) ($signer['role_title'] ?? '')) === ''
                ? 'Authorized signer'
                : (string) $signer['role_title'],
            'signer_email'        => (string) $signer['work_email'],
            'secure_channel'      => EngagementTerms::channelLabel($channel === '' ? null : $channel),
            'assessment_window'   => trim((string) ($engagement['assessment_window'] ?? '')) === ''
                ? 'agreed with you before the review starts'
                : (string) $engagement['assessment_window'],
            'fee_basis'           => EngagementTerms::feeLabel((string) $engagement['fee_basis']),
        ];
    }

    /**
     * The scope, as strings, for the recovery pair.
     *
     * @param array<string,mixed> $engagement
     * @return array<string,string>
     */
    private function scopeContext(array $engagement): array
    {
        $scope = $this->scopes->forEngagement((string) $engagement['id']);
        if ($scope === null) {
            return [];
        }
        $batches = $this->scopes->batches((string) $scope['id']);
        $lines = [];
        $count = 0;
        $cents = 0;
        foreach ($batches as $batch) {
            $count += (int) $batch['claim_count'];
            $cents += (int) $batch['denied_amount_cents'];
            $lines[] = '  ' . (string) $batch['public_ref'] . '  ' . (string) $batch['label']
                . ((int) $batch['payer_label_approved'] === 1 && $batch['payer_label'] !== null
                    ? ' (' . (string) $batch['payer_label'] . ')'
                    : '')
                . ': ' . (int) $batch['claim_count'] . ' denied claims, '
                . Money::format((int) $batch['denied_amount_cents']);
        }
        $approver = $scope['approver_contact_id'] === null
            ? null
            : $this->contacts->find((string) $scope['approver_contact_id']);
        $rateBps = $scope['fee_rate_bps'] === null ? null : (int) $scope['fee_rate_bps'];

        return [
            'fee_basis'           => EngagementTerms::feeLabel((string) $scope['fee_basis']),
            'fee_rate'            => RecoveryService::feeRateLabel((string) $scope['fee_basis'], $rateBps),
            'scope_summary'       => (string) $scope['summary'],
            'scope_batches'       => implode("\n", $lines),
            'scope_batches_count' => (string) count($batches),
            'scope_count'         => (string) $count,
            'scope_amount'        => Money::format($cents),
            'approver_name'       => $approver === null ? '' : (string) $approver['name'],
            'approver_email'      => $approver === null ? '' : (string) $approver['work_email'],
        ];
    }

    /**
     * The signature payload written into the vault.
     *
     * JSON with the keys sorted, so the same signature serialises to the same
     * bytes and its hash means something. Nothing in it identifies a device.
     *
     * @param array<string,string> $fields
     */
    private function signaturePayload(array $fields): string
    {
        ksort($fields);
        $json = json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('The signature record could not be written.');
        }
        return $json . "\n";
    }

    /**
     * The executed record: the document, the signatures, and the certificate.
     *
     * Self-contained HTML with its styles inline. It has to open in fifteen
     * years, on a machine that has never heard of this site, with no stylesheet
     * to fetch and no font to download, and still be readable and still hash to
     * the value on the row.
     *
     * @param array<string,mixed> $document
     * @param array<string,mixed> $engagement
     */
    private function renderExecuted(array $document, array $engagement, string $executedAt): string
    {
        $body = $this->body($document);
        if ($body === null) {
            throw new \RuntimeException(
                'The document body is not in the vault, so an executed record cannot be built.'
            );
        }

        $stored = (string) $document['content_sha256'];
        $actual = hash('sha256', $body);
        if (!hash_equals($stored, $actual)) {
            throw new \RuntimeException(
                'The stored document does not match the hash it was recorded with. '
                . 'Nothing was executed.'
            );
        }

        $signatures = $this->signatures->forDocument((string) $document['id']);

        $escape = static fn (string $value): string
            => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        $out = [];
        $out[] = '<!doctype html>';
        $out[] = '<html lang="en"><head><meta charset="utf-8">';
        $out[] = '<title>' . $escape((string) $document['title']) . ' · '
            . $escape((string) $document['public_ref']) . '</title>';
        $out[] = '<style>'
            . 'body{font:15px/1.6 Georgia,serif;color:#101426;background:#fff;margin:0;padding:40px}'
            . 'main{max-width:44rem;margin:0 auto}'
            . 'pre{font:13px/1.55 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;'
            . 'word-wrap:break-word;background:#f8f8f9;padding:20px;border:1px solid #e2e2e6}'
            . 'h1{font-size:20px} h2{font-size:15px;text-transform:uppercase;letter-spacing:.08em;'
            . 'border-top:1px solid #101426;padding-top:12px;margin-top:36px}'
            . 'table{border-collapse:collapse;width:100%;font-size:13px}'
            . 'td,th{border-bottom:1px solid #e2e2e6;padding:7px 8px;text-align:left;vertical-align:top}'
            . 'code{font:12px ui-monospace,Menlo,Consolas,monospace;word-break:break-all}'
            . '</style></head><body><main>';

        $out[] = '<h1>' . $escape((string) $document['title']) . '</h1>';
        $out[] = '<p>' . $escape((string) $engagement['legal_name']) . ' and '
            . $escape($this->settings->legalEntity($this->config)) . ', operating as '
            . $escape($this->settings->tradeName($this->config)) . '.</p>';

        $isRecord = DocumentKind::isRecord((string) $document['kind']);

        $out[] = '<h2>' . ($isRecord ? 'The record as sealed' : 'The document as signed') . '</h2>';
        $out[] = '<pre>' . $escape($body) . '</pre>';

        if ($isRecord) {
            $out[] = '<h2>Sealed by</h2>';
            $out[] = '<p>' . $escape($this->settings->tradeName($this->config))
                . ', on ' . $escape($this->clock->displaySigningStamp($executedAt))
                . '. This is a record of how the engagement ended, prepared by Soft Appeals. '
                . 'It carries no signature because there is nothing in it for the Practice to '
                . 'agree to; the agreements it refers to are each sealed under their own '
                . 'signatures.</p>';
        }

        $out[] = '<h2>Signatures</h2>';
        $out[] = '<table><tr><th>Party</th><th>Signed by</th><th>When</th>'
            . '<th>Document hash signed</th></tr>';
        if ($signatures === []) {
            $out[] = '<tr><td colspan="4">None. ' . ($isRecord
                ? 'A sealed record is not signed.'
                : 'No signature is recorded on this version.') . '</td></tr>';
        }
        foreach ($signatures as $signature) {
            $out[] = '<tr><td>' . $escape(SignatureRepository::partyLabel((string) $signature['party']))
                . '</td><td>' . $escape((string) $signature['typed_name'])
                . ($signature['typed_title'] === null
                    ? ''
                    : '<br>' . $escape((string) $signature['typed_title']))
                . '</td><td>' . $escape($this->clock->displaySigningStamp((string) $signature['signed_at']))
                . '</td><td><code>' . $escape((string) $signature['document_sha256'])
                . '</code></td></tr>';
        }
        $out[] = '</table>';

        // ---------------------------------------------------------------
        // The audit certificate. Section 14.4.
        //
        // Everything a person needs to check this record against the database
        // years later, and nothing that would identify a device or a person
        // beyond the names that signed. The IP and user-agent digests are shown
        // as digests, which is what they are.
        // ---------------------------------------------------------------
        $out[] = '<h2>Audit certificate</h2>';
        $out[] = '<table>';
        $rows = [
            'Document reference' => (string) $document['public_ref'],
            'Engagement'         => (string) $engagement['public_ref'],
            'Document type'      => DocumentKind::label((string) $document['kind']),
            'Version'            => (string) $document['version'],
            'Template version'   => (string) $document['template_version'],
            'Consent version'    => (string) $document['consent_version'],
            'Generated'          => $this->clock->displaySigningStamp((string) $document['created_at']),
            'Sent for signature' => $document['sent_at'] === null
                ? 'Not sent'
                : $this->clock->displaySigningStamp((string) $document['sent_at']),
            'Executed'           => $this->clock->displaySigningStamp($executedAt),
            'Document hash'      => $stored,
        ];
        foreach ($rows as $label => $value) {
            $out[] = '<tr><th>' . $escape((string) $label) . '</th><td><code>'
                . $escape((string) $value) . '</code></td></tr>';
        }
        $out[] = '</table>';

        $out[] = '<h2>Signature evidence</h2>';
        $out[] = '<table><tr><th>Party</th><th>Consent</th><th>Accepted</th>'
            . '<th>Network and device digests</th></tr>';
        if ($signatures === []) {
            $out[] = '<tr><td colspan="4">None.</td></tr>';
        }
        foreach ($signatures as $signature) {
            $out[] = '<tr><td>' . $escape(SignatureRepository::partyLabel((string) $signature['party']))
                . '</td><td>version ' . $escape((string) $signature['consent_version'])
                . '<br><code>' . $escape((string) $signature['consent_text_sha256']) . '</code>'
                . '</td><td>' . $escape($this->clock->displaySigningStamp((string) $signature['consent_accepted_at']))
                . '</td><td><code>' . $escape((string) ($signature['ip_digest'] ?? 'none'))
                . '</code><br><code>' . $escape((string) ($signature['user_agent_digest'] ?? 'none'))
                . '</code></td></tr>';
        }
        $out[] = '</table>';

        if (!$isRecord) {
            $out[] = '<h2>The consent that was accepted</h2>';
            $out[] = '<p>' . $escape(DocumentTemplates::consentText()) . '</p>';
        }

        $out[] = '<p>This record was produced by the Soft Appeals command centre at the '
            . 'moment of execution. Its own hash is stored on the document row, so any '
            . 'change to this file is detectable.</p>';

        $out[] = '</main></body></html>';

        return implode("\n", $out) . "\n";
    }

    /**
     * The email that goes out when a document is executed.
     *
     * A notice and a link. Never the document, and never an attachment, which
     * is section 14.4 stated the other way round.
     *
     * @param array<string,mixed> $document
     * @param array<string,mixed> $engagement
     */
    private function notifyExecuted(array $document, array $engagement): void
    {
        $signer = $document['signer_contact_id'] === null
            ? null
            : $this->contacts->find((string) $document['signer_contact_id']);
        if ($signer === null) {
            return;
        }

        $room = rtrim($this->config->string('SA_APP_URL'), '/') . '/soft-appeals-room';
        $lines = [];
        $lines[] = 'Hello ' . $this->firstName((string) $signer['name']) . ',';
        $lines[] = '';
        $lines[] = wordwrap(
            DocumentKind::label((string) $document['kind'])
            . ' is now signed by both of us. Reference '
            . (string) $document['public_ref'] . ', version ' . (int) $document['version'] . '.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = wordwrap(
            'Your copy is in your Recovery Room, with the signature record attached to it:',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = '  ' . $room;
        $lines[] = '';
        $lines[] = wordwrap(
            'Nothing is attached to this email on purpose. Agreements stay behind '
            . 'your sign-in rather than sitting in an inbox.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = 'Do not send patient, member, claim or clinical information by email.';
        $lines[] = '';
        $lines[] = 'Soft Appeals';

        $this->mail->send(
            (string) $signer['work_email'],
            DocumentKind::label((string) $document['kind']) . ' is signed',
            implode("\n", $lines) . "\n",
            self::TEMPLATE_KEY_EXECUTED,
            (string) $engagement['id'],
            (string) $engagement['organization_id'],
            hash('sha256', 'document-executed:' . (string) $document['id'])
        );
    }

    /**
     * The body of the signing request.
     *
     * @param array<string,mixed> $document
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $signer
     */
    private function signRequestBody(
        array $document,
        array $engagement,
        array $signer,
        string $link,
        string $expiresAt
    ): string {
        $lines = [];
        $lines[] = 'Hello ' . $this->firstName((string) $signer['name']) . ',';
        $lines[] = '';
        $lines[] = wordwrap(
            DocumentKind::label((string) $document['kind']) . ' for '
            . (string) $engagement['legal_name'] . ' is ready for your signature.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = wordwrap(
            'You can read the whole document before you sign anything. Nothing is '
            . 'signed until you type your name and press the button.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = '  ' . $link;
        $lines[] = '';
        if ((string) $document['kind'] === DocumentKind::RECOVERY_AGREEMENT) {
            $lines[] = wordwrap(
                'The Approved Recovery Scope, the schedule that names the batches, '
                . 'follows it in your Recovery Room and is signed the same way. Recovery '
                . 'work starts once both are signed.',
                72,
                "\n",
                false
            );
            $lines[] = '';
        }
        $lines[] = wordwrap(
            'The link works once and stops working on '
            . $this->clock->displayDateTime($expiresAt) . '. If it has expired, write '
            . 'to softappeals@frimpomaasync.com and a new one goes out the same day.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = 'Do not send patient, member, claim or clinical information by email.';
        $lines[] = '';
        $lines[] = 'Soft Appeals';

        return implode("\n", $lines) . "\n";
    }

    /**
     * The first name, because section 13.1 greets people by it and a document
     * email that suddenly used the whole name would read as a different sender.
     */
    private function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        return $parts === [] ? $name : (string) $parts[0];
    }
}
