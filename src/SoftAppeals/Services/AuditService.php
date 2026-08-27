<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Auth\SessionManager;
use SoftAppeals\Database;
use SoftAppeals\Security\Hmac;
use SoftAppeals\Support\Clock;
use SoftAppeals\Support\Uuid;

/**
 * The append-only internal security history.
 *
 * Every meaningful action is recorded, and so is every refusal. A trail that
 * only holds successes cannot answer the question anyone actually asks after an
 * incident, which is what somebody tried and was stopped from doing.
 *
 * Three rules this class enforces rather than documents:
 *
 *   1. Append only. There is no update and no delete method, and the migration
 *      grants none through the application.
 *   2. No raw IP, ever. The address is digested, the way sa-lead.php has always
 *      done it.
 *   3. Metadata is an allowlist. A key nobody approved is dropped rather than
 *      stored, so a caller cannot smuggle a patient name into the audit trail
 *      by naming a field something plausible.
 */
final class AuditService
{
    /**
     * The only metadata keys that may be written. Every one is business-level.
     * Adding a key here is a deliberate act, reviewed alongside the reason.
     */
    private const ALLOWED_METADATA_KEYS = [
        'reason',
        'permission',
        'from_stage',
        'to_stage',
        'document_kind',
        'document_version',
        'template_version',
        'communication_template',
        'fee_rate_bps',
        'amount_cents',
        'count',
        'source',
        'field',
        'idempotency_key',
        'migration',
        'environment',
    ];

    /** Outcomes. Anything else is refused. */
    private const OUTCOMES = ['success', 'failure', 'denied', 'error'];

    private Database $db;
    private Clock $clock;
    private Hmac $hmac;
    private ?SessionManager $session;
    private string $correlationId;

    public function __construct(
        Database $db,
        Clock $clock,
        Hmac $hmac,
        ?SessionManager $session = null,
        ?string $correlationId = null
    ) {
        $this->db = $db;
        $this->clock = $clock;
        $this->hmac = $hmac;
        $this->session = $session;
        $this->correlationId = $correlationId ?? strtoupper(bin2hex(random_bytes(4)));
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    /**
     * Write one audit row.
     *
     * @param string $action     dotted, e.g. auth.login, terms.send, document.countersign
     * @param string $outcome    success, failure, denied, error
     * @param string|null $objectType  the kind of thing acted on
     * @param string|null $objectId    its id
     * @param array<string,scalar|null> $metadata  filtered against the allowlist
     */
    public function record(
        string $action,
        string $outcome = 'success',
        ?string $objectType = null,
        ?string $objectId = null,
        array $metadata = [],
        ?string $organizationId = null
    ): void {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \RuntimeException('Unknown audit outcome: ' . $outcome);
        }

        // The audit trail must never be the reason a request fails. If the row
        // cannot be written the action still completes, and the error handler
        // records that separately. Losing an audit row is bad; losing an
        // executed agreement because the audit table was locked is worse.
        try {
            $this->db->insert('sa_audit_events', [
                'id'              => Uuid::v4(),
                'actor_user_id'   => $this->session?->userId(),
                'organization_id' => $organizationId ?? $this->session?->organizationId(),
                'action'          => substr($action, 0, 80),
                'object_type'     => $objectType === null ? null : substr($objectType, 0, 40),
                'object_id'       => $objectId,
                'outcome'         => $outcome,
                'correlation_id'  => $this->correlationId,
                'ip_digest'       => $this->hmac->ipDigest('audit'),
                'user_agent_digest' => $this->hmac->userAgentDigest('audit'),
                'metadata'        => $this->encodeMetadata($metadata),
                'created_at'      => $this->clock->nowUtc(),
            ]);
        } catch (\Throwable) {
            // Deliberately swallowed. See the comment above.
        }
    }

    /**
     * The most recent rows, newest first. Read-only, for the auditor view.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 100, ?string $organizationId = null): array
    {
        $limit = max(1, min(500, $limit));
        if ($organizationId === null) {
            return $this->db->all(
                'SELECT * FROM sa_audit_events ORDER BY created_at DESC, id DESC LIMIT ' . $limit
            );
        }
        return $this->db->all(
            'SELECT * FROM sa_audit_events WHERE organization_id = :o'
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit,
            ['o' => $organizationId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function forObject(string $objectType, string $objectId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_audit_events WHERE object_type = :t AND object_id = :i'
            . ' ORDER BY created_at ASC, id ASC',
            ['t' => $objectType, 'i' => $objectId]
        );
    }

    public function countByOutcome(string $outcome): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM sa_audit_events WHERE outcome = :o',
            ['o' => $outcome]
        );
    }

    /**
     * Filter to the allowlist, drop anything else, and cap what survives.
     *
     * Values are cast to strings and truncated. An integer count stays legible
     * as "12"; a paragraph somebody pasted becomes 200 characters and stops.
     */
    private function encodeMetadata(array $metadata): ?string
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (!in_array($key, self::ALLOWED_METADATA_KEYS, true)) {
                continue;
            }
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $clean[$key] = $value ? 'true' : 'false';
                continue;
            }
            $string = preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $value) ?? '';
            $clean[$key] = mb_substr(trim($string), 0, 200);
        }
        if ($clean === []) {
            return null;
        }
        $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : mb_substr($json, 0, 2000);
    }

    /** @return list<string> */
    public static function allowedMetadataKeys(): array
    {
        return self::ALLOWED_METADATA_KEYS;
    }
}
