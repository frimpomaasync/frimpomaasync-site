<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Domain\InvoiceStatus;
use SoftAppeals\Support\Uuid;

/**
 * Invoices. A sum of recovery rows with a status and a number.
 *
 * The figures on an invoice are written once, from the rows it gathers, and
 * are never edited. A wrong invoice is voided, which hands its rows back,
 * and a new one is created. The number is never reused, because the voided
 * row stays.
 */
final class InvoiceRepository extends Repository
{
    protected function table(): string
    {
        return 'sa_invoices';
    }

    /**
     * @return array<string,mixed> the row as inserted, a draft
     */
    public function createDraft(
        string $engagementId,
        string $organizationId,
        int $feeCents,
        int $creditCents,
        ?string $agreementDocumentId,
        ?string $userId = null
    ): array {
        $id = Uuid::v4();
        $now = $this->clock->nowUtc();
        $this->db->insert('sa_invoices', [
            'id'                    => $id,
            'public_ref'            => $this->uniquePublicRef(),
            'engagement_id'         => $engagementId,
            'organization_id'       => $organizationId,
            'status'                => InvoiceStatus::DRAFT,
            'fee_cents'             => max(0, $feeCents),
            'credit_cents'          => max(0, $creditCents),
            'total_cents'           => max(0, $feeCents) - max(0, $creditCents),
            'agreement_document_id' => $agreementDocumentId,
            'private_path'          => null,
            'content_sha256'        => null,
            'issued_at'             => null,
            'due_at'                => null,
            'paid_at'               => null,
            'paid_note'             => null,
            'voided_at'             => null,
            'void_reason'           => null,
            'created_by'            => $userId,
            'created_at'            => $now,
            'updated_at'            => $now,
            'row_version'           => 1,
        ]);
        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('The invoice was written and could not be read back.');
        }
        return $row;
    }

    /**
     * Move a status along an allowed edge, guarded on the status it expects
     * to find and on the row version.
     *
     * @param array<string,mixed> $extra columns written alongside
     */
    public function moveStatus(string $invoiceId, string $from, string $to, int $expectedVersion, array $extra = []): bool
    {
        if (!InvoiceStatus::canMove($from, $to)) {
            throw new \RuntimeException('An invoice cannot go from ' . $from . ' to ' . $to . '.');
        }
        $changes = $extra + [
            'status'      => $to,
            'updated_at'  => $this->clock->nowUtc(),
            'row_version' => $expectedVersion + 1,
        ];
        return $this->db->update(
            'sa_invoices',
            $changes,
            ['id' => $invoiceId, 'status' => $from, 'row_version' => $expectedVersion]
        ) === 1;
    }

    /** @return list<array<string,mixed>> newest first */
    public function forEngagement(string $engagementId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_invoices WHERE engagement_id = :e ORDER BY created_at DESC',
            ['e' => $engagementId]
        );
    }

    /** @return list<array<string,mixed>> what the practice is shown: everything but drafts */
    public function forClient(string $engagementId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_invoices WHERE engagement_id = :e AND status <> :d ORDER BY created_at DESC',
            ['e' => $engagementId, 'd' => InvoiceStatus::DRAFT]
        );
    }

    /** @return array<string,mixed>|null found through the engagement, never alone */
    public function findForEngagement(string $ref, string $engagementId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_invoices WHERE public_ref = :r AND engagement_id = :e',
            ['r' => $ref, 'e' => $engagementId]
        );
    }

    /** @return array<string,mixed>|null the newest draft on an engagement */
    public function draftFor(string $engagementId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_invoices WHERE engagement_id = :e AND status = :s ORDER BY created_at DESC',
            ['e' => $engagementId, 's' => InvoiceStatus::DRAFT]
        );
    }

    /** True while any invoice on the engagement is issued and unpaid. */
    public function hasOutstanding(string $engagementId): bool
    {
        return $this->db->exists(
            'SELECT id FROM sa_invoices WHERE engagement_id = :e AND status = :s',
            ['e' => $engagementId, 's' => InvoiceStatus::ISSUED]
        );
    }

    /**
     * @return array{invoiced_cents:int,paid_cents:int,outstanding_cents:int,draft_count:int,issued_count:int}
     */
    public function totals(?string $engagementId = null): array
    {
        $where = $engagementId === null ? '' : ' WHERE engagement_id = :e';
        $params = $engagementId === null ? [] : ['e' => $engagementId];
        $row = $this->db->one(
            'SELECT'
            . ' COALESCE(SUM(CASE WHEN status IN (:i1, :p1) THEN total_cents ELSE 0 END), 0) AS invoiced_cents,'
            . ' COALESCE(SUM(CASE WHEN status = :p2 THEN total_cents ELSE 0 END), 0) AS paid_cents,'
            . ' COALESCE(SUM(CASE WHEN status = :i2 THEN total_cents ELSE 0 END), 0) AS outstanding_cents,'
            . ' COALESCE(SUM(CASE WHEN status = :d THEN 1 ELSE 0 END), 0) AS draft_count,'
            . ' COALESCE(SUM(CASE WHEN status = :i3 THEN 1 ELSE 0 END), 0) AS issued_count'
            . ' FROM sa_invoices' . $where,
            $params + [
                'i1' => InvoiceStatus::ISSUED,
                'p1' => InvoiceStatus::PAID,
                'p2' => InvoiceStatus::PAID,
                'i2' => InvoiceStatus::ISSUED,
                'd'  => InvoiceStatus::DRAFT,
                'i3' => InvoiceStatus::ISSUED,
            ]
        ) ?? [];
        return [
            'invoiced_cents'    => (int) ($row['invoiced_cents'] ?? 0),
            'paid_cents'        => (int) ($row['paid_cents'] ?? 0),
            'outstanding_cents' => (int) ($row['outstanding_cents'] ?? 0),
            'draft_count'       => (int) ($row['draft_count'] ?? 0),
            'issued_count'      => (int) ($row['issued_count'] ?? 0),
        ];
    }

    /**
     * Every issued, unpaid invoice across every practice. The Desk board.
     *
     * @return list<array<string,mixed>>
     */
    public function outstandingEverywhere(): array
    {
        return $this->db->all(
            'SELECT i.*, e.public_ref AS engagement_ref, o.legal_name, o.display_name'
            . ' FROM sa_invoices i'
            . ' JOIN sa_engagements e ON e.id = i.engagement_id'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' WHERE i.status = :s ORDER BY i.due_at ASC, i.issued_at ASC',
            ['s' => InvoiceStatus::ISSUED]
        );
    }

    private function uniquePublicRef(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $ref = Uuid::publicRef('INV');
            if (!$this->db->exists('SELECT id FROM sa_invoices WHERE public_ref = :r', ['r' => $ref])) {
                return $ref;
            }
        }
        throw new \RuntimeException('Could not mint a unique invoice reference.');
    }
}
