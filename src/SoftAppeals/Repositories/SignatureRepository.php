<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Support\Uuid;

/**
 * Signature records. Section 11.1 and section 14.4.
 *
 * Append-only. There is no update method on this class and there is not meant
 * to be one: a signature is an event that happened, and an event that can be
 * edited afterwards is not evidence of anything.
 *
 * document_sha256 is copied onto the row at the moment of signing rather than
 * read back through the document later. That is the acceptance criterion
 * "signature event references the exact document hash", and copying is what
 * makes it hold: if the document row were somehow rewritten, this row would
 * disagree with it, loudly, instead of quietly agreeing with whatever it now
 * says.
 *
 * Nothing here identifies a device. The IP and the user agent arrive already
 * HMAC'd with the application secret, which is enough to say two signatures
 * came from the same place and not enough to say where that place was.
 */
final class SignatureRepository extends Repository
{
    public const PARTY_CLIENT       = 'client';
    public const PARTY_SOFT_APPEALS = 'soft_appeals';

    protected function table(): string
    {
        return 'sa_signatures';
    }

    /** @return list<string> */
    public static function parties(): array
    {
        return [self::PARTY_CLIENT, self::PARTY_SOFT_APPEALS];
    }

    public static function partyLabel(string $party): string
    {
        return match ($party) {
            self::PARTY_CLIENT       => 'The practice',
            self::PARTY_SOFT_APPEALS => 'Soft Appeals',
            default                  => $party,
        };
    }

    /**
     * Write one signature.
     *
     * The unique constraint on (document_id, party) does the work that matters:
     * a second client signature on the same document is refused by the
     * database, so a replayed POST cannot produce two signatures even if every
     * check above this line were somehow passed twice.
     *
     * @param array<string,mixed> $fields
     */
    public function record(string $documentId, string $party, array $fields): string
    {
        if (!in_array($party, self::parties(), true)) {
            throw new \RuntimeException('Unknown signing party: ' . $party);
        }

        $id = Uuid::v4();
        $now = $this->clock->nowUtc();

        $this->db->insert('sa_signatures', [
            'id'                  => $id,
            'document_id'         => $documentId,
            'organization_id'     => $fields['organization_id'] ?? null,
            'party'               => $party,
            'signer_user_id'      => $fields['signer_user_id'] ?? null,
            'signer_contact_id'   => $fields['signer_contact_id'] ?? null,
            'signer_role'         => (string) $fields['signer_role'],
            'typed_name'          => (string) $fields['typed_name'],
            'typed_title'         => $fields['typed_title'] ?? null,
            'typed_organization'  => $fields['typed_organization'] ?? null,
            'consent_version'     => (string) $fields['consent_version'],
            'consent_text_sha256' => (string) $fields['consent_text_sha256'],
            'consent_accepted_at' => (string) ($fields['consent_accepted_at'] ?? $now),
            'document_sha256'     => (string) $fields['document_sha256'],
            'payload_path'        => (string) $fields['payload_path'],
            'payload_sha256'      => (string) $fields['payload_sha256'],
            'ip_digest'           => $fields['ip_digest'] ?? null,
            'user_agent_digest'   => $fields['user_agent_digest'] ?? null,
            'auth_event_id'       => $fields['auth_event_id'] ?? null,
            'idempotency_key'     => $fields['idempotency_key'] ?? null,
            'signed_at'           => (string) ($fields['signed_at'] ?? $now),
            'created_at'          => $now,
        ]);

        return $id;
    }

    /**
     * Every signature on one document, in the order they were made.
     *
     * @return list<array<string,mixed>>
     */
    public function forDocument(string $documentId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_signatures WHERE document_id = :d ORDER BY signed_at ASC, created_at ASC',
            ['d' => $documentId]
        );
    }

    /** @return array<string,mixed>|null */
    public function forDocumentAndParty(string $documentId, string $party): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_signatures WHERE document_id = :d AND party = :p',
            ['d' => $documentId, 'p' => $party]
        );
    }

    public function hasSigned(string $documentId, string $party): bool
    {
        return $this->db->exists(
            'SELECT id FROM sa_signatures WHERE document_id = :d AND party = :p',
            ['d' => $documentId, 'p' => $party]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_signatures WHERE idempotency_key = :k',
            ['k' => $key]
        );
    }
}
