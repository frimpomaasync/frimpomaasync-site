<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Support\Uuid;

/**
 * Work batches. Section 11.1, the row that replaces claim-level storage.
 *
 * Every value here is one a practice may be shown on a card. That is the
 * test for a new column: if it could not go on the card in section 15.7, it
 * does not belong in this table.
 */
final class WorkBatchRepository extends Repository
{
    protected function table(): string
    {
        return 'sa_work_batches';
    }

    /**
     * @param array<string,mixed> $fields label, payer_label, claim_count,
     *        denied_amount_cents, received_count, stage, earliest_deadline_at,
     *        deadline_confirmed, next_owner, next_action
     * @return array<string,mixed> the row as inserted
     */
    public function create(
        string $engagementId,
        string $organizationId,
        array $fields,
        ?string $userId = null
    ): array {
        $stage = (string) ($fields['stage'] ?? BatchStage::RECEIVED);
        if (!BatchStage::isValid($stage)) {
            throw new \RuntimeException('Unknown batch stage: ' . $stage);
        }
        $owner = (string) ($fields['next_owner'] ?? BatchStage::defaultOwner($stage));
        if (!BatchStage::isValidOwner($owner)) {
            throw new \RuntimeException('Unknown next owner: ' . $owner);
        }

        $id = Uuid::v4();
        $now = $this->clock->nowUtc();
        $received = max(0, (int) ($fields['received_count'] ?? ($fields['claim_count'] ?? 0)));

        $this->db->insert('sa_work_batches', [
            'id'                   => $id,
            'public_ref'           => $this->uniquePublicRef(),
            'engagement_id'        => $engagementId,
            'organization_id'      => $organizationId,
            'label'                => mb_substr(trim((string) ($fields['label'] ?? 'Batch')), 0, 80),
            'payer_label'          => self::nullable($fields['payer_label'] ?? null, 80),
            'payer_label_approved' => !empty($fields['payer_label_approved']) ? 1 : 0,
            'claim_count'          => max(0, (int) ($fields['claim_count'] ?? 0)),
            'denied_amount_cents'  => max(0, (int) ($fields['denied_amount_cents'] ?? 0)),
            'received_count'       => $received,
            'in_review_count'      => max(0, (int) ($fields['in_review_count'] ?? 0)),
            'submitted_count'      => 0,
            'overturned_count'     => 0,
            'upheld_count'         => 0,
            'closed_count'         => 0,
            'stage'                => $stage,
            'earliest_deadline_at' => self::nullable($fields['earliest_deadline_at'] ?? null, 19),
            'deadline_confirmed'   => !empty($fields['deadline_confirmed']) ? 1 : 0,
            'next_owner'           => $owner,
            'next_action'          => self::nullable($fields['next_action'] ?? null, 160),
            'created_by'           => $userId,
            'created_at'           => $now,
            'updated_at'           => $now,
            'row_version'          => 1,
        ]);

        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('The batch was written and could not be read back.');
        }
        return $row;
    }

    /**
     * Change one batch. Guarded by row_version like an engagement, for the
     * same reason: two tabs open on one practice must not both write.
     *
     * @param array<string,mixed> $changes
     * @return bool false when somebody else changed it first
     */
    public function patch(string $batchId, array $changes, ?int $expectedVersion = null): bool
    {
        $row = $this->find($batchId);
        if ($row === null) {
            throw new \RuntimeException('No such batch.');
        }
        if ($expectedVersion !== null && (int) $row['row_version'] !== $expectedVersion) {
            return false;
        }
        if (isset($changes['stage']) && !BatchStage::isValid((string) $changes['stage'])) {
            throw new \RuntimeException('Unknown batch stage: ' . (string) $changes['stage']);
        }
        if (isset($changes['next_owner']) && !BatchStage::isValidOwner((string) $changes['next_owner'])) {
            throw new \RuntimeException('Unknown next owner: ' . (string) $changes['next_owner']);
        }

        $current = (int) $row['row_version'];
        $changes['updated_at'] = $this->clock->nowUtc();
        $changes['row_version'] = $current + 1;

        $sets = [];
        $params = ['id' => $batchId, 'old_version' => $current];
        foreach ($changes as $column => $value) {
            $sets[] = $this->db->quoteIdentifier($column) . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }

        return $this->db->run(
            'UPDATE sa_work_batches SET ' . implode(', ', $sets)
            . ' WHERE id = :id AND row_version = :old_version',
            $params
        )->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> oldest first */
    public function forEngagement(string $engagementId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_work_batches WHERE engagement_id = :e ORDER BY created_at ASC, public_ref ASC',
            ['e' => $engagementId]
        );
    }

    /** @return array<string,mixed>|null found through the engagement, never alone */
    public function findForEngagement(string $batchRef, string $engagementId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_work_batches WHERE public_ref = :r AND engagement_id = :e',
            ['r' => $batchRef, 'e' => $engagementId]
        );
    }

    /**
     * Every batch carrying a deadline on an open engagement, soonest first,
     * with the organization joined on. The Desk's deadline board.
     *
     * @return list<array<string,mixed>>
     */
    public function withDeadlines(): array
    {
        return $this->db->all(
            'SELECT b.*, e.public_ref AS engagement_ref, e.stage AS engagement_stage,'
            . ' o.legal_name, o.display_name'
            . ' FROM sa_work_batches b'
            . ' JOIN sa_engagements e ON e.id = b.engagement_id'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' WHERE b.earliest_deadline_at IS NOT NULL AND e.closed_at IS NULL'
            . ' AND b.stage NOT IN (\'closed\', \'overturned\', \'upheld\')'
            . ' ORDER BY b.earliest_deadline_at ASC'
        );
    }

    /**
     * Aggregates across an engagement, for the overview cards.
     *
     * @return array{batches:int,claims:int,received:int,denied_cents:int,recommended:int}
     */
    public function totals(string $engagementId): array
    {
        $row = $this->db->one(
            'SELECT COUNT(*) AS batches, COALESCE(SUM(claim_count), 0) AS claims,'
            . ' COALESCE(SUM(received_count), 0) AS received,'
            . ' COALESCE(SUM(denied_amount_cents), 0) AS denied_cents'
            . ' FROM sa_work_batches WHERE engagement_id = :e',
            ['e' => $engagementId]
        ) ?? [];
        $recommended = (int) ($this->db->value(
            'SELECT COUNT(*) FROM sa_work_batches WHERE engagement_id = :e AND stage = :s',
            ['e' => $engagementId, 's' => BatchStage::RECOMMENDED]
        ) ?? 0);

        return [
            'batches'      => (int) ($row['batches'] ?? 0),
            'claims'       => (int) ($row['claims'] ?? 0),
            'received'     => (int) ($row['received'] ?? 0),
            'denied_cents' => (int) ($row['denied_cents'] ?? 0),
            'recommended'  => $recommended,
        ];
    }

    private static function nullable(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function uniquePublicRef(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $ref = Uuid::publicRef('BAT');
            if (!$this->db->exists('SELECT id FROM sa_work_batches WHERE public_ref = :r', ['r' => $ref])) {
                return $ref;
            }
        }
        throw new \RuntimeException('Could not mint a unique batch reference.');
    }
}
