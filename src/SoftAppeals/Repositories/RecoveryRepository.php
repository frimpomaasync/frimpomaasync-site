<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Domain\RecoveryRecord;
use SoftAppeals\Support\Uuid;

/**
 * Recovery rows: verified reimbursement, adjustments, reversals. Section 19.
 *
 * Append-only. There is no update method on the money columns and there
 * never will be; the one column written after insert is invoice_id, which
 * says which invoice carried the row and is cleared when that invoice is
 * voided. Every sum this class returns is integer cents.
 */
final class RecoveryRepository extends Repository
{
    protected function table(): string
    {
        return 'sa_recoveries';
    }

    /**
     * @param array{kind:string,adjusts_recovery_id:?string,agreement_document_id:?string,original_denied_cents:int,amount_cents:int,qualifies:bool,fee_basis:string,fee_rate_bps:?int,fee_cents:int,verification_source:string,verified_at:string,note:?string} $fields
     * @return array<string,mixed> the row as inserted
     */
    public function record(
        string $engagementId,
        string $organizationId,
        string $batchId,
        array $fields,
        ?string $userId = null
    ): array {
        $kind = (string) $fields['kind'];
        if (!RecoveryRecord::isValidKind($kind)) {
            throw new \RuntimeException('Unknown recovery kind: ' . $kind);
        }
        if (!RecoveryRecord::isValidSource((string) $fields['verification_source'])) {
            throw new \RuntimeException('Unknown verification source: ' . (string) $fields['verification_source']);
        }
        $id = Uuid::v4();
        $this->db->insert('sa_recoveries', [
            'id'                    => $id,
            'public_ref'            => $this->uniquePublicRef(),
            'engagement_id'         => $engagementId,
            'organization_id'       => $organizationId,
            'work_batch_id'         => $batchId,
            'kind'                  => $kind,
            'adjusts_recovery_id'   => $fields['adjusts_recovery_id'],
            'agreement_document_id' => $fields['agreement_document_id'],
            'original_denied_cents' => max(0, (int) $fields['original_denied_cents']),
            'amount_cents'          => max(0, (int) $fields['amount_cents']),
            'qualifies'             => $fields['qualifies'] ? 1 : 0,
            'fee_basis'             => (string) $fields['fee_basis'],
            'fee_rate_bps'          => $fields['fee_rate_bps'],
            'fee_cents'             => max(0, (int) $fields['fee_cents']),
            'verification_source'   => (string) $fields['verification_source'],
            'verified_at'           => (string) $fields['verified_at'],
            'verified_by'           => $userId,
            'note'                  => $fields['note'] === null || trim((string) $fields['note']) === ''
                ? null
                : mb_substr(trim((string) $fields['note']), 0, 500),
            'invoice_id'            => null,
            'created_at'            => $this->clock->nowUtc(),
        ]);
        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('The recovery row was written and could not be read back.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> oldest first, batch and invoice joined */
    public function forEngagement(string $engagementId): array
    {
        return $this->db->all(
            'SELECT r.*, b.public_ref AS batch_ref, b.label AS batch_label,'
            . ' i.public_ref AS invoice_ref, i.status AS invoice_status'
            . ' FROM sa_recoveries r'
            . ' JOIN sa_work_batches b ON b.id = r.work_batch_id'
            . ' LEFT JOIN sa_invoices i ON i.id = r.invoice_id'
            . ' WHERE r.engagement_id = :e'
            . ' ORDER BY r.verified_at ASC, r.created_at ASC, r.public_ref ASC',
            ['e' => $engagementId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function forBatch(string $batchId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_recoveries WHERE work_batch_id = :b'
            . ' ORDER BY verified_at ASC, created_at ASC',
            ['b' => $batchId]
        );
    }

    /** @return list<array<string,mixed>> the adjustments and reversals on one verified row */
    public function takenFrom(string $recoveryId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_recoveries WHERE adjusts_recovery_id = :r ORDER BY created_at ASC',
            ['r' => $recoveryId]
        );
    }

    /** @return array<string,mixed>|null found through the engagement, never alone */
    public function findForEngagement(string $ref, string $engagementId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_recoveries WHERE public_ref = :r AND engagement_id = :e',
            ['r' => $ref, 'e' => $engagementId]
        );
    }

    /**
     * Every row not yet on an invoice. Invoice-ready, section 19.6.
     *
     * @return list<array<string,mixed>>
     */
    public function uninvoiced(string $engagementId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_recoveries WHERE engagement_id = :e AND invoice_id IS NULL'
            . ' ORDER BY verified_at ASC, created_at ASC',
            ['e' => $engagementId]
        );
    }

    /** Put a set of rows on an invoice. Only rows not already on one. */
    public function attachToInvoice(string $invoiceId, array $recoveryIds): int
    {
        $n = 0;
        foreach ($recoveryIds as $recoveryId) {
            $n += $this->db->run(
                'UPDATE sa_recoveries SET invoice_id = :i WHERE id = :r AND invoice_id IS NULL',
                ['i' => $invoiceId, 'r' => (string) $recoveryId]
            )->rowCount();
        }
        return $n;
    }

    /** Take every row off a voided invoice, so it can be invoiced again. */
    public function detachFromInvoice(string $invoiceId): int
    {
        return $this->db->run(
            'UPDATE sa_recoveries SET invoice_id = NULL WHERE invoice_id = :i',
            ['i' => $invoiceId]
        )->rowCount();
    }

    /** @return list<array<string,mixed>> the rows on one invoice, oldest first */
    public function onInvoice(string $invoiceId): array
    {
        return $this->db->all(
            'SELECT r.*, b.public_ref AS batch_ref, b.label AS batch_label'
            . ' FROM sa_recoveries r'
            . ' JOIN sa_work_batches b ON b.id = r.work_batch_id'
            . ' WHERE r.invoice_id = :i'
            . ' ORDER BY r.verified_at ASC, r.created_at ASC',
            ['i' => $invoiceId]
        );
    }

    /**
     * The money on one engagement, in integer cents.
     *
     * @return array{verified_cents:int,verified_count:int,taken_back_cents:int,net_cents:int,fee_cents:int,fee_credit_cents:int,fee_net_cents:int,uninvoiced_fee_cents:int,uninvoiced_count:int}
     */
    public function totals(string $engagementId): array
    {
        return $this->sum('WHERE engagement_id = :e', ['e' => $engagementId]);
    }

    /**
     * The same figures across every open engagement. The Desk home summary.
     *
     * @return array{verified_cents:int,verified_count:int,taken_back_cents:int,net_cents:int,fee_cents:int,fee_credit_cents:int,fee_net_cents:int,uninvoiced_fee_cents:int,uninvoiced_count:int}
     */
    public function totalsEverywhere(): array
    {
        return $this->sum('', []);
    }

    /**
     * Overturned batches with no verified row on them yet. Section 17.2:
     * "favorable decisions still awaiting payment verification".
     *
     * @return list<array<string,mixed>>
     */
    public function awaitingVerification(): array
    {
        return $this->db->all(
            'SELECT b.*, e.public_ref AS engagement_ref, e.stage AS engagement_stage,'
            . ' o.legal_name, o.display_name'
            . ' FROM sa_work_batches b'
            . ' JOIN sa_engagements e ON e.id = b.engagement_id'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' WHERE b.stage = :s AND e.closed_at IS NULL'
            . ' AND NOT EXISTS (SELECT 1 FROM sa_recoveries r WHERE r.work_batch_id = b.id AND r.kind = :k)'
            . ' ORDER BY b.updated_at ASC',
            ['s' => BatchStage::OVERTURNED, 'k' => RecoveryRecord::KIND_VERIFIED]
        );
    }

    /**
     * Engagements carrying rows not yet on an invoice, with the fee that is
     * waiting. Section 17.1: "calculate fee and create invoice-ready task".
     *
     * @return list<array<string,mixed>>
     */
    public function invoiceReadyEverywhere(): array
    {
        return $this->db->all(
            'SELECT e.public_ref AS engagement_ref, e.stage, o.legal_name, o.display_name,'
            . ' COUNT(r.id) AS n,'
            . ' COALESCE(SUM(CASE WHEN r.kind = :v THEN r.fee_cents ELSE 0 END), 0)'
            . ' - COALESCE(SUM(CASE WHEN r.kind <> :v2 THEN r.fee_cents ELSE 0 END), 0) AS fee_net_cents'
            . ' FROM sa_recoveries r'
            . ' JOIN sa_engagements e ON e.id = r.engagement_id'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' WHERE r.invoice_id IS NULL AND e.closed_at IS NULL'
            . ' GROUP BY e.id, e.public_ref, e.stage, o.legal_name, o.display_name'
            . ' ORDER BY MIN(r.created_at) ASC',
            ['v' => RecoveryRecord::KIND_VERIFIED, 'v2' => RecoveryRecord::KIND_VERIFIED]
        );
    }

    /**
     * @param array<string,string> $params
     * @return array{verified_cents:int,verified_count:int,taken_back_cents:int,net_cents:int,fee_cents:int,fee_credit_cents:int,fee_net_cents:int,uninvoiced_fee_cents:int,uninvoiced_count:int}
     */
    private function sum(string $where, array $params): array
    {
        $row = $this->db->one(
            'SELECT'
            . ' COALESCE(SUM(CASE WHEN kind = :v1 THEN amount_cents ELSE 0 END), 0) AS verified_cents,'
            . ' COALESCE(SUM(CASE WHEN kind = :v2 THEN 1 ELSE 0 END), 0) AS verified_count,'
            . ' COALESCE(SUM(CASE WHEN kind <> :v3 THEN amount_cents ELSE 0 END), 0) AS taken_back_cents,'
            . ' COALESCE(SUM(CASE WHEN kind = :v4 THEN fee_cents ELSE 0 END), 0) AS fee_cents,'
            . ' COALESCE(SUM(CASE WHEN kind <> :v5 THEN fee_cents ELSE 0 END), 0) AS fee_credit_cents,'
            . ' COALESCE(SUM(CASE WHEN invoice_id IS NULL AND kind = :v6 THEN fee_cents ELSE 0 END), 0)'
            . ' - COALESCE(SUM(CASE WHEN invoice_id IS NULL AND kind <> :v7 THEN fee_cents ELSE 0 END), 0) AS uninvoiced_fee_cents,'
            . ' COALESCE(SUM(CASE WHEN invoice_id IS NULL THEN 1 ELSE 0 END), 0) AS uninvoiced_count'
            . ' FROM sa_recoveries ' . $where,
            $params + [
                'v1' => RecoveryRecord::KIND_VERIFIED,
                'v2' => RecoveryRecord::KIND_VERIFIED,
                'v3' => RecoveryRecord::KIND_VERIFIED,
                'v4' => RecoveryRecord::KIND_VERIFIED,
                'v5' => RecoveryRecord::KIND_VERIFIED,
                'v6' => RecoveryRecord::KIND_VERIFIED,
                'v7' => RecoveryRecord::KIND_VERIFIED,
            ]
        ) ?? [];

        $verified = (int) ($row['verified_cents'] ?? 0);
        $takenBack = (int) ($row['taken_back_cents'] ?? 0);
        $fee = (int) ($row['fee_cents'] ?? 0);
        $credit = (int) ($row['fee_credit_cents'] ?? 0);

        return [
            'verified_cents'       => $verified,
            'verified_count'       => (int) ($row['verified_count'] ?? 0),
            'taken_back_cents'     => $takenBack,
            'net_cents'            => $verified - $takenBack,
            'fee_cents'            => $fee,
            'fee_credit_cents'     => $credit,
            'fee_net_cents'        => $fee - $credit,
            'uninvoiced_fee_cents' => (int) ($row['uninvoiced_fee_cents'] ?? 0),
            'uninvoiced_count'     => (int) ($row['uninvoiced_count'] ?? 0),
        ];
    }

    private function uniquePublicRef(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $ref = Uuid::publicRef('REC');
            if (!$this->db->exists('SELECT id FROM sa_recoveries WHERE public_ref = :r', ['r' => $ref])) {
                return $ref;
            }
        }
        throw new \RuntimeException('Could not mint a unique recovery reference.');
    }
}
