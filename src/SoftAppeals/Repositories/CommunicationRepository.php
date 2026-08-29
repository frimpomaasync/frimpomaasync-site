<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Support\Uuid;

/**
 * One row per message the system sent, or tried to.
 *
 * The state vocabulary is deliberately short and one word is deliberately
 * absent. There is no `delivered`. Section 16.1 says do not label an email
 * delivered unless the mail system provides and verifies delivery events, and
 * hers does not: it is an SMTP connection to the host's mail server, which can
 * tell us the server accepted the message and nothing whatsoever about whether
 * a human ever saw it. `accepted` is the honest ceiling and it is what the Desk
 * prints.
 *
 * `refused` is its own state, separate from `failed`. A staging environment is
 * configured to refuse any recipient outside the allowlist, and that is a
 * correct outcome rather than an error, so it is recorded as what it is.
 */
final class CommunicationRepository extends Repository
{
    public const QUEUED    = 'queued';
    public const ACCEPTED  = 'accepted';
    public const FAILED    = 'failed';
    public const CONFIRMED = 'manually_confirmed';
    public const REFUSED   = 'refused';

    protected function table(): string
    {
        return 'sa_communications';
    }

    /** @return array<string,string> */
    public static function stateLabels(): array
    {
        return [
            self::QUEUED    => 'Queued',
            self::ACCEPTED  => 'Accepted by the mail server',
            self::FAILED    => 'The mail server refused it',
            self::CONFIRMED => 'Confirmed by hand',
            self::REFUSED   => 'Not sent: outside this environment\'s allowlist',
        ];
    }

    public static function stateLabel(string $state): string
    {
        return self::stateLabels()[$state] ?? $state;
    }

    /**
     * Record a send.
     *
     * $idempotencyKey carries a unique constraint. A double click that
     * regenerates the same key finds the row already there and gets it back
     * rather than sending a second email to a practice.
     *
     * @return array{id:string,created:bool}
     */
    public function record(
        ?string $engagementId,
        ?string $organizationId,
        string $recipientEmail,
        string $templateKey,
        string $templateVersion,
        string $subject,
        string $state,
        ?string $idempotencyKey = null,
        ?string $errorCategory = null,
        string $channel = 'email'
    ): array {
        if (!array_key_exists($state, self::stateLabels())) {
            throw new \RuntimeException('Unknown communication state: ' . $state);
        }

        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return ['id' => (string) $existing['id'], 'created' => false];
            }
        }

        $id = Uuid::v4();
        $now = $this->clock->nowUtc();
        $this->db->insert('sa_communications', [
            'id'               => $id,
            'engagement_id'    => $engagementId,
            'organization_id'  => $organizationId,
            'recipient_email'  => strtolower(trim($recipientEmail)),
            'template_key'     => $templateKey,
            'template_version' => $templateVersion,
            'subject'          => mb_substr($subject, 0, 200),
            'channel'          => $channel,
            'state'            => $state,
            'error_category'   => $errorCategory,
            'idempotency_key'  => $idempotencyKey,
            'sent_at'          => $state === self::ACCEPTED ? $now : null,
            'created_at'       => $now,
        ]);

        return ['id' => $id, 'created' => true];
    }

    /** @return array<string,mixed>|null */
    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_communications WHERE idempotency_key = :k',
            ['k' => $key]
        );
    }

    /** @return list<array<string,mixed>> */
    public function forEngagement(string $engagementId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_communications WHERE engagement_id = :e'
            . ' ORDER BY created_at DESC, id DESC',
            ['e' => $engagementId]
        );
    }

    /** The newest message per engagement. The Last communication column. */
    public function latestForEngagement(string $engagementId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_communications WHERE engagement_id = :e'
            . ' ORDER BY created_at DESC, id DESC LIMIT 1',
            ['e' => $engagementId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db->all(
            'SELECT c.*, o.legal_name FROM sa_communications c'
            . ' LEFT JOIN sa_organizations o ON o.id = c.organization_id'
            . ' ORDER BY c.created_at DESC, c.id DESC LIMIT ' . $limit
        );
    }

    /** How many times this template has gone to this engagement. Resend count. */
    public function countFor(string $engagementId, string $templateKey): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM sa_communications'
            . ' WHERE engagement_id = :e AND template_key = :t',
            ['e' => $engagementId, 't' => $templateKey]
        );
    }
}
