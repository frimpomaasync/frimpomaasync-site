<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Support\Uuid;

/**
 * Attention items: what the scheduled jobs surface for her. Section 17.2.
 *
 * The shape is the whole design. A job does not "create a task"; it says
 * "this condition holds right now" under a key it can compute again
 * tomorrow. The first time, a row is made. Every later time, the row is
 * touched. When the condition stops holding, the job says so and the row is
 * resolved. That is what makes every job safe to rerun, which is the first
 * Phase 8 acceptance line, and it is enforced by the UNIQUE on item_key
 * rather than by any job remembering to check.
 *
 * Dismissed is separate from resolved. Resolved means the job saw the
 * condition end. Dismissed means she has seen it and does not want the card;
 * the row stays, the job keeps touching it, and it comes back only if it is
 * resolved and then arises again under a fresh key.
 *
 * Nothing here holds a person. A label is a practice name, a batch label and
 * a count, the same words the Desk already shows.
 */
final class AttentionRepository extends Repository
{
    public const KIND_DEADLINE        = 'deadline';
    public const KIND_PAYMENT_PENDING = 'payment_pending';
    public const KIND_CLOSEOUT_ACCESS = 'closeout_access';
    public const KIND_INTERNAL_TASK   = 'internal_task';
    public const KIND_COUNTERSIGN     = 'countersign';
    public const KIND_SUBMISSION      = 'submission';
    public const KIND_FOLLOW_UP       = 'follow_up';
    public const KIND_INVOICE_OVERDUE = 'invoice_overdue';
    public const KIND_BACKUP          = 'backup';
    public const KIND_JOB_FAILED      = 'job_failed';

    public const SEVERITY_URGENT = 'urgent';
    public const SEVERITY_ACTION = 'action';
    public const SEVERITY_NOTE   = 'note';

    protected function table(): string
    {
        return 'sa_attention_items';
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return [
            self::KIND_DEADLINE, self::KIND_PAYMENT_PENDING, self::KIND_CLOSEOUT_ACCESS,
            self::KIND_INTERNAL_TASK, self::KIND_COUNTERSIGN, self::KIND_SUBMISSION,
            self::KIND_FOLLOW_UP, self::KIND_INVOICE_OVERDUE, self::KIND_BACKUP, self::KIND_JOB_FAILED,
        ];
    }

    /** What a person reads for each kind. */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_DEADLINE        => 'Deadline group',
            self::KIND_PAYMENT_PENDING => 'Favorable, awaiting payment verification',
            self::KIND_CLOSEOUT_ACCESS => 'Open access at closeout',
            self::KIND_INTERNAL_TASK   => 'Internal task overdue',
            self::KIND_COUNTERSIGN     => 'Waiting on your countersignature',
            self::KIND_SUBMISSION      => 'Approved, waiting on your submission',
            self::KIND_FOLLOW_UP       => 'Payer follow-up due',
            self::KIND_INVOICE_OVERDUE => 'Invoice past due',
            self::KIND_BACKUP          => 'Backup',
            self::KIND_JOB_FAILED      => 'A job failed',
            default                    => $kind,
        };
    }

    /**
     * Say that a condition holds. Makes the row on first sight, touches it
     * after that, and un-resolves a row whose condition has come back.
     *
     * @param array{kind:string,severity:string,label:string,detail?:?string,link?:?string,engagement_id?:?string,organization_id?:?string} $fields
     * @return array{id:string,created:bool}
     */
    public function see(string $itemKey, array $fields): array
    {
        if (!in_array($fields['kind'], self::kinds(), true)) {
            throw new \RuntimeException('Unknown attention kind: ' . (string) $fields['kind']);
        }
        if (!in_array($fields['severity'], [self::SEVERITY_URGENT, self::SEVERITY_ACTION, self::SEVERITY_NOTE], true)) {
            throw new \RuntimeException('Unknown attention severity: ' . (string) $fields['severity']);
        }

        $itemKey = mb_substr($itemKey, 0, 120);
        $now = $this->clock->nowUtc();
        $label = mb_substr(trim((string) $fields['label']), 0, 200);
        $detail = self::nullable($fields['detail'] ?? null, 300);
        $link = self::nullable($fields['link'] ?? null, 200);

        $existing = $this->db->one('SELECT * FROM sa_attention_items WHERE item_key = :k', ['k' => $itemKey]);
        if ($existing !== null) {
            $this->db->update('sa_attention_items', [
                'severity'     => $fields['severity'],
                'label'        => $label,
                'detail'       => $detail,
                'link'         => $link,
                'last_seen_at' => $now,
                'resolved_at'  => null,
            ], ['id' => (string) $existing['id']]);
            return ['id' => (string) $existing['id'], 'created' => false];
        }

        $id = Uuid::v4();
        $this->db->insert('sa_attention_items', [
            'id'              => $id,
            'item_key'        => $itemKey,
            'kind'            => $fields['kind'],
            'severity'        => $fields['severity'],
            'engagement_id'   => $fields['engagement_id'] ?? null,
            'organization_id' => $fields['organization_id'] ?? null,
            'label'           => $label,
            'detail'          => $detail,
            'link'            => $link,
            'first_seen_at'   => $now,
            'last_seen_at'    => $now,
            'resolved_at'     => null,
            'dismissed_at'    => null,
            'dismissed_by'    => null,
            'created_at'      => $now,
        ]);
        return ['id' => $id, 'created' => true];
    }

    /**
     * Resolve every open item of one kind the job did NOT see this run.
     *
     * The job hands over the keys it saw; anything else of that kind that is
     * still open has stopped holding, and is closed with the time.
     *
     * @param list<string> $seenKeys
     * @return int how many were resolved
     */
    public function resolveUnseen(string $kind, array $seenKeys): int
    {
        $open = $this->db->all(
            'SELECT id, item_key FROM sa_attention_items WHERE kind = :k AND resolved_at IS NULL',
            ['k' => $kind]
        );
        $seen = array_flip($seenKeys);
        $n = 0;
        foreach ($open as $row) {
            if (isset($seen[(string) $row['item_key']])) {
                continue;
            }
            $n += $this->db->update(
                'sa_attention_items',
                ['resolved_at' => $this->clock->nowUtc()],
                ['id' => (string) $row['id']]
            );
        }
        return $n;
    }

    /** Resolve one key outright. */
    public function resolve(string $itemKey): bool
    {
        return $this->db->run(
            'UPDATE sa_attention_items SET resolved_at = :n WHERE item_key = :k AND resolved_at IS NULL',
            ['n' => $this->clock->nowUtc(), 'k' => $itemKey]
        )->rowCount() === 1;
    }

    /** She has seen it. The row stays; the card goes. */
    public function dismiss(string $id, ?string $userId): bool
    {
        return $this->db->run(
            'UPDATE sa_attention_items SET dismissed_at = :n, dismissed_by = :u'
            . ' WHERE id = :id AND dismissed_at IS NULL',
            ['n' => $this->clock->nowUtc(), 'u' => $userId, 'id' => $id]
        )->rowCount() === 1;
    }

    /**
     * Everything still holding and not dismissed, urgent first, oldest
     * first inside each severity. The Desk's source.
     *
     * @return list<array<string,mixed>>
     */
    public function open(): array
    {
        return $this->db->all(
            'SELECT a.*, e.public_ref AS engagement_ref, o.legal_name, o.display_name'
            . ' FROM sa_attention_items a'
            . ' LEFT JOIN sa_engagements e ON e.id = a.engagement_id'
            . ' LEFT JOIN sa_organizations o ON o.id = a.organization_id'
            . ' WHERE a.resolved_at IS NULL AND a.dismissed_at IS NULL'
            . ' ORDER BY CASE a.severity WHEN \'urgent\' THEN 0 WHEN \'action\' THEN 1 ELSE 2 END ASC,'
            . ' a.first_seen_at ASC, a.item_key ASC'
        );
    }

    /** @return list<array<string,mixed>> open items of one kind */
    public function openOfKind(string $kind): array
    {
        return array_values(array_filter(
            $this->open(),
            static fn (array $row): bool => (string) $row['kind'] === $kind
        ));
    }

    public function openCount(): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM sa_attention_items WHERE resolved_at IS NULL AND dismissed_at IS NULL'
        );
    }

    /** @return array<string,int> kind => open count */
    public function openCountsByKind(): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT kind, COUNT(*) AS n FROM sa_attention_items'
            . ' WHERE resolved_at IS NULL AND dismissed_at IS NULL GROUP BY kind'
        ) as $row) {
            $out[(string) $row['kind']] = (int) $row['n'];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function findByKey(string $itemKey): ?array
    {
        return $this->db->one('SELECT * FROM sa_attention_items WHERE item_key = :k', ['k' => $itemKey]);
    }

    /** @return list<array<string,mixed>> resolved in the last $days days, newest first */
    public function recentlyResolved(int $days = 7, int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db->all(
            'SELECT a.*, e.public_ref AS engagement_ref, o.legal_name, o.display_name'
            . ' FROM sa_attention_items a'
            . ' LEFT JOIN sa_engagements e ON e.id = a.engagement_id'
            . ' LEFT JOIN sa_organizations o ON o.id = a.organization_id'
            . ' WHERE a.resolved_at IS NOT NULL AND a.resolved_at >= :since'
            . ' ORDER BY a.resolved_at DESC LIMIT ' . $limit,
            ['since' => $this->clock->utcPlusSeconds(-86400 * max(1, $days))]
        );
    }

    /** Drop items resolved more than $days days ago. */
    public function pruneResolved(int $days = 90): int
    {
        return $this->db->run(
            'DELETE FROM sa_attention_items WHERE resolved_at IS NOT NULL AND resolved_at < :c',
            ['c' => $this->clock->utcPlusSeconds(-86400 * max(1, $days))]
        )->rowCount();
    }

    private static function nullable(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
