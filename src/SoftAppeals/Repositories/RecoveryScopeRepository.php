<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Support\Uuid;

/**
 * The recovery scope, one row per engagement, and the batches it covers.
 *
 * The scope is what the Recovery Services Agreement and the Approved
 * Recovery Scope are generated from. It is written by her on the Desk and
 * read by DocumentService at generate time, and it is the only place the fee
 * rate for recovery work lives once the practice has chosen recovery.
 */
final class RecoveryScopeRepository extends Repository
{
    protected function table(): string
    {
        return 'sa_recovery_scopes';
    }

    /** @return array<string,mixed>|null */
    public function forEngagement(string $engagementId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_recovery_scopes WHERE engagement_id = :e',
            ['e' => $engagementId]
        );
    }

    /**
     * Write the scope, or rewrite it. One row per engagement, so a second
     * save updates rather than inserting.
     *
     * @param array{fee_basis:string,fee_rate_bps:?int,summary:string,approver_contact_id:?string} $fields
     * @return array<string,mixed> the row as stored
     */
    public function save(string $engagementId, string $organizationId, array $fields, ?string $userId = null): array
    {
        $feeBasis = (string) $fields['fee_basis'];
        if (!EngagementTerms::isValidFee($feeBasis) || $feeBasis === EngagementTerms::FEE_NOT_SET) {
            throw new \RuntimeException('A recovery scope needs a fee basis.');
        }

        $now = $this->clock->nowUtc();
        $existing = $this->forEngagement($engagementId);

        if ($existing === null) {
            $id = Uuid::v4();
            $this->db->insert('sa_recovery_scopes', [
                'id'                    => $id,
                'engagement_id'         => $engagementId,
                'organization_id'       => $organizationId,
                'fee_basis'             => $feeBasis,
                'fee_rate_bps'          => $fields['fee_rate_bps'],
                'summary'               => (string) $fields['summary'],
                'approver_contact_id'   => $fields['approver_contact_id'],
                'approver_confirmed_at' => $fields['approver_contact_id'] === null ? null : $now,
                'recorded_by'           => $userId,
                'created_at'            => $now,
                'updated_at'            => $now,
                'row_version'           => 1,
            ]);
        } else {
            $id = (string) $existing['id'];
            $changes = [
                'fee_basis'           => $feeBasis,
                'fee_rate_bps'        => $fields['fee_rate_bps'],
                'summary'             => (string) $fields['summary'],
                'approver_contact_id' => $fields['approver_contact_id'],
                'recorded_by'         => $userId,
                'updated_at'          => $now,
                'row_version'         => (int) $existing['row_version'] + 1,
            ];
            // The confirmation stamp is the FIRST time an approver was named.
            // Renaming the approver re-stamps; clearing the approver clears it.
            if ($fields['approver_contact_id'] === null) {
                $changes['approver_confirmed_at'] = null;
            } elseif ($existing['approver_confirmed_at'] === null
                || (string) $existing['approver_contact_id'] !== (string) $fields['approver_contact_id']
            ) {
                $changes['approver_confirmed_at'] = $now;
            }
            $this->db->update('sa_recovery_scopes', $changes, ['id' => $id]);
        }

        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('The scope was written and could not be read back.');
        }
        return $row;
    }

    /**
     * Replace the set of batches the scope covers.
     *
     * @param list<string> $batchIds
     */
    public function setBatches(string $scopeId, array $batchIds): void
    {
        $this->db->run('DELETE FROM sa_recovery_scope_batches WHERE scope_id = :s', ['s' => $scopeId]);
        $now = $this->clock->nowUtc();
        foreach (array_values(array_unique($batchIds)) as $batchId) {
            $this->db->insert('sa_recovery_scope_batches', [
                'scope_id'      => $scopeId,
                'work_batch_id' => $batchId,
                'created_at'    => $now,
            ]);
        }
    }

    /** @return list<string> the batch ids in scope */
    public function batchIds(string $scopeId): array
    {
        $rows = $this->db->all(
            'SELECT work_batch_id FROM sa_recovery_scope_batches WHERE scope_id = :s ORDER BY created_at ASC',
            ['s' => $scopeId]
        );
        return array_map(static fn (array $row): string => (string) $row['work_batch_id'], $rows);
    }

    public function coversBatch(string $scopeId, string $batchId): bool
    {
        return $this->db->exists(
            'SELECT scope_id FROM sa_recovery_scope_batches WHERE scope_id = :s AND work_batch_id = :b',
            ['s' => $scopeId, 'b' => $batchId]
        );
    }

    /**
     * The batches in scope, joined, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function batches(string $scopeId): array
    {
        return $this->db->all(
            'SELECT b.* FROM sa_work_batches b'
            . ' JOIN sa_recovery_scope_batches sb ON sb.work_batch_id = b.id'
            . ' WHERE sb.scope_id = :s ORDER BY b.created_at ASC, b.public_ref ASC',
            ['s' => $scopeId]
        );
    }
}
