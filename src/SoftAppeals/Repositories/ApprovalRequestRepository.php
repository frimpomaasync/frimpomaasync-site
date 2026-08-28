<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Domain\ApprovalState;
use SoftAppeals\Support\Uuid;

/**
 * Approval requests, section 11.1 and section 6 Gate C.
 *
 * The one write that matters is decide(): an UPDATE guarded on state =
 * pending, so two decisions racing for the same request leave exactly one
 * winner and the other gets false back. That, and the UNIQUE idempotency
 * key, are what "double submission does not create duplicate approval
 * events" rests on.
 */
final class ApprovalRequestRepository extends Repository
{
    protected function table(): string
    {
        return 'sa_approval_requests';
    }

    /**
     * @param array{safe_summary:string,claim_count:int,amount_cents:int,requested_from:?string,due_at:?string} $fields
     * @return array<string,mixed> the row as inserted
     */
    public function open(
        string $engagementId,
        string $organizationId,
        string $batchId,
        array $fields,
        ?string $userId = null
    ): array {
        $id = Uuid::v4();
        $now = $this->clock->nowUtc();
        $this->db->insert('sa_approval_requests', [
            'id'                  => $id,
            'public_ref'          => $this->uniquePublicRef(),
            'engagement_id'       => $engagementId,
            'organization_id'     => $organizationId,
            'work_batch_id'       => $batchId,
            'kind'                => ApprovalState::KIND_SUBMISSION,
            'safe_summary'        => (string) $fields['safe_summary'],
            'claim_count'         => max(0, (int) $fields['claim_count']),
            'amount_cents'        => max(0, (int) $fields['amount_cents']),
            'requested_from'      => $fields['requested_from'],
            'due_at'              => $fields['due_at'],
            'state'               => ApprovalState::PENDING,
            'decision_at'         => null,
            'decision_by'         => null,
            'decision_contact_id' => null,
            'decision_note'       => null,
            'idempotency_key'     => null,
            'requested_by'        => $userId,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('The approval request was written and could not be read back.');
        }
        return $row;
    }

    /**
     * Decide a pending request. Exactly once.
     *
     * @return bool false when it was not pending any more
     */
    public function decide(
        string $requestId,
        string $state,
        string $idempotencyKey,
        ?string $userId,
        ?string $contactId,
        ?string $note
    ): bool {
        if (!ApprovalState::isValid($state) || $state === ApprovalState::PENDING) {
            throw new \RuntimeException('A request is decided as approved, returned, expired or cancelled.');
        }
        $now = $this->clock->nowUtc();
        return $this->db->run(
            'UPDATE sa_approval_requests SET state = :state, decision_at = :at, decision_by = :by,'
            . ' decision_contact_id = :contact, decision_note = :note, idempotency_key = :key,'
            . ' updated_at = :updated'
            . ' WHERE id = :id AND state = :pending',
            [
                'state'   => $state,
                'at'      => $now,
                'by'      => $userId,
                'contact' => $contactId,
                'note'    => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 500),
                'key'     => $idempotencyKey,
                'updated' => $now,
                'id'      => $requestId,
                'pending' => ApprovalState::PENDING,
            ]
        )->rowCount() === 1;
    }

    /** @return array<string,mixed>|null */
    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_approval_requests WHERE idempotency_key = :k',
            ['k' => $key]
        );
    }

    /** @return array<string,mixed>|null the pending request on a batch, if any */
    public function pendingForBatch(string $batchId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_approval_requests WHERE work_batch_id = :b AND state = :s ORDER BY created_at DESC',
            ['b' => $batchId, 's' => ApprovalState::PENDING]
        );
    }

    /** @return array<string,mixed>|null the newest request on a batch, whatever its state */
    public function latestForBatch(string $batchId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_approval_requests WHERE work_batch_id = :b ORDER BY created_at DESC',
            ['b' => $batchId]
        );
    }

    /**
     * The approved request on a batch that no submission has used yet.
     *
     * @return array<string,mixed>|null
     */
    public function approvedUnusedForBatch(string $batchId): ?array
    {
        return $this->db->one(
            'SELECT a.* FROM sa_approval_requests a'
            . ' WHERE a.work_batch_id = :b AND a.state = :s'
            . ' AND NOT EXISTS ('
            . '   SELECT 1 FROM sa_submission_events se'
            . '   WHERE se.approval_request_id = a.id AND se.event_type = :submitted'
            . ' )'
            . ' ORDER BY a.decision_at DESC',
            ['b' => $batchId, 's' => ApprovalState::APPROVED, 'submitted' => 'submitted']
        );
    }

    /** @return list<array<string,mixed>> pending first, then newest */
    public function forEngagement(string $engagementId): array
    {
        return $this->db->all(
            'SELECT a.*, b.public_ref AS batch_ref, b.label AS batch_label'
            . ' FROM sa_approval_requests a'
            . ' JOIN sa_work_batches b ON b.id = a.work_batch_id'
            . ' WHERE a.engagement_id = :e'
            . ' ORDER BY CASE WHEN a.state = \'pending\' THEN 0 ELSE 1 END ASC, a.created_at DESC',
            ['e' => $engagementId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function pendingForEngagement(string $engagementId): array
    {
        return array_values(array_filter(
            $this->forEngagement($engagementId),
            static fn (array $row): bool => (string) $row['state'] === ApprovalState::PENDING
        ));
    }

    /** @return array<string,mixed>|null found through the engagement, never alone */
    public function findForEngagement(string $ref, string $engagementId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_approval_requests WHERE public_ref = :r AND engagement_id = :e',
            ['r' => $ref, 'e' => $engagementId]
        );
    }

    /**
     * Everything pending across every practice, with the organization joined
     * on. The Desk's "with the practice" board.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingEverywhere(): array
    {
        return $this->db->all(
            'SELECT a.*, e.public_ref AS engagement_ref, o.legal_name, o.display_name,'
            . ' b.public_ref AS batch_ref, b.label AS batch_label'
            . ' FROM sa_approval_requests a'
            . ' JOIN sa_engagements e ON e.id = a.engagement_id'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' JOIN sa_work_batches b ON b.id = a.work_batch_id'
            . ' WHERE a.state = :s ORDER BY a.created_at ASC',
            ['s' => ApprovalState::PENDING]
        );
    }

    /**
     * Approved and not yet submitted, across every practice. Her queue.
     *
     * @return list<array<string,mixed>>
     */
    public function approvedAwaitingSubmission(): array
    {
        return $this->db->all(
            'SELECT a.*, e.public_ref AS engagement_ref, o.legal_name, o.display_name,'
            . ' b.public_ref AS batch_ref, b.label AS batch_label'
            . ' FROM sa_approval_requests a'
            . ' JOIN sa_engagements e ON e.id = a.engagement_id'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' JOIN sa_work_batches b ON b.id = a.work_batch_id'
            . ' WHERE a.state = :s AND e.closed_at IS NULL'
            . ' AND NOT EXISTS ('
            . '   SELECT 1 FROM sa_submission_events se'
            . '   WHERE se.approval_request_id = a.id AND se.event_type = :submitted'
            . ' )'
            . ' ORDER BY a.decision_at ASC',
            ['s' => ApprovalState::APPROVED, 'submitted' => 'submitted']
        );
    }

    private function uniquePublicRef(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $ref = Uuid::publicRef('APR');
            if (!$this->db->exists('SELECT id FROM sa_approval_requests WHERE public_ref = :r', ['r' => $ref])) {
                return $ref;
            }
        }
        throw new \RuntimeException('Could not mint a unique approval reference.');
    }
}
