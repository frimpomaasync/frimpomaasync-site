<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Domain\SubmissionEventType;
use SoftAppeals\Support\Uuid;

/**
 * Submission events: what went to a payer and what came back, in aggregate.
 *
 * Append-only by convention. A row is never edited; a correction is a new
 * row. There is no fee column here, and this class has no method that would
 * calculate one. Section 19.
 */
final class SubmissionEventRepository extends Repository
{
    protected function table(): string
    {
        return 'sa_submission_events';
    }

    /**
     * @param array{event_type:string,claim_count:int,amount_cents:int,occurred_at:string,note:?string,follow_up_due_at:?string,approval_request_id:?string} $fields
     * @return array<string,mixed> the row as inserted
     */
    public function record(
        string $engagementId,
        string $organizationId,
        string $batchId,
        array $fields,
        ?string $userId = null
    ): array {
        $type = (string) $fields['event_type'];
        if (!SubmissionEventType::isValid($type)) {
            throw new \RuntimeException('Unknown submission event: ' . $type);
        }
        $id = Uuid::v4();
        $this->db->insert('sa_submission_events', [
            'id'                  => $id,
            'public_ref'          => $this->uniquePublicRef(),
            'engagement_id'       => $engagementId,
            'organization_id'     => $organizationId,
            'work_batch_id'       => $batchId,
            'approval_request_id' => $fields['approval_request_id'],
            'event_type'          => $type,
            'claim_count'         => max(0, (int) $fields['claim_count']),
            'amount_cents'        => max(0, (int) $fields['amount_cents']),
            'occurred_at'         => (string) $fields['occurred_at'],
            'note'                => $fields['note'] === null || trim((string) $fields['note']) === ''
                ? null
                : mb_substr(trim((string) $fields['note']), 0, 500),
            'follow_up_due_at'    => $fields['follow_up_due_at'],
            'follow_up_done_at'   => null,
            'recorded_by'         => $userId,
            'created_at'          => $this->clock->nowUtc(),
        ]);
        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('The submission event was written and could not be read back.');
        }
        return $row;
    }

    /** Close a follow-up. Idempotent. */
    public function completeFollowUp(string $eventId, ?string $userId = null): bool
    {
        return $this->db->run(
            'UPDATE sa_submission_events SET follow_up_done_at = :now'
            . ' WHERE id = :id AND follow_up_due_at IS NOT NULL AND follow_up_done_at IS NULL',
            ['now' => $this->clock->nowUtc(), 'id' => $eventId]
        )->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> newest first, batch joined */
    public function forEngagement(string $engagementId): array
    {
        return $this->db->all(
            'SELECT se.*, b.public_ref AS batch_ref, b.label AS batch_label'
            . ' FROM sa_submission_events se'
            . ' JOIN sa_work_batches b ON b.id = se.work_batch_id'
            . ' WHERE se.engagement_id = :e'
            . ' ORDER BY se.occurred_at DESC, se.created_at DESC',
            ['e' => $engagementId]
        );
    }

    /** @return list<array<string,mixed>> newest first */
    public function forBatch(string $batchId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_submission_events WHERE work_batch_id = :b'
            . ' ORDER BY occurred_at DESC, created_at DESC',
            ['b' => $batchId]
        );
    }

    /** @return array<string,mixed>|null the newest event on a batch */
    public function latestForBatch(string $batchId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_submission_events WHERE work_batch_id = :b'
            . ' ORDER BY occurred_at DESC, created_at DESC',
            ['b' => $batchId]
        );
    }

    /** @return array<string,mixed>|null found through the engagement, never alone */
    public function findForEngagement(string $ref, string $engagementId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_submission_events WHERE public_ref = :r AND engagement_id = :e',
            ['r' => $ref, 'e' => $engagementId]
        );
    }

    /**
     * Every open follow-up across every practice, soonest first. The Desk's
     * follow-up board and its "needs you" cards.
     *
     * @return list<array<string,mixed>>
     */
    public function openFollowUps(): array
    {
        return $this->db->all(
            'SELECT se.*, e.public_ref AS engagement_ref, o.legal_name, o.display_name,'
            . ' b.public_ref AS batch_ref, b.label AS batch_label'
            . ' FROM sa_submission_events se'
            . ' JOIN sa_engagements e ON e.id = se.engagement_id'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' JOIN sa_work_batches b ON b.id = se.work_batch_id'
            . ' WHERE se.follow_up_due_at IS NOT NULL AND se.follow_up_done_at IS NULL'
            . ' AND e.closed_at IS NULL'
            . ' ORDER BY se.follow_up_due_at ASC'
        );
    }

    /**
     * Aggregates for the recovery block, section 15.9, and the Desk. Counts
     * and cents. Nothing here is a fee.
     *
     * @return array{submitted_count:int,submitted_cents:int,overturned_count:int,overturned_cents:int,upheld_count:int,upheld_cents:int}
     */
    public function totals(string $engagementId): array
    {
        $sum = function (array $types) use ($engagementId): array {
            $marks = [];
            $params = ['e' => $engagementId];
            foreach (array_values($types) as $i => $type) {
                $marks[] = ':t' . $i;
                $params['t' . $i] = $type;
            }
            $row = $this->db->one(
                'SELECT COALESCE(SUM(claim_count), 0) AS n, COALESCE(SUM(amount_cents), 0) AS c'
                . ' FROM sa_submission_events WHERE engagement_id = :e'
                . ' AND event_type IN (' . implode(', ', $marks) . ')',
                $params
            ) ?? [];
            return [(int) ($row['n'] ?? 0), (int) ($row['c'] ?? 0)];
        };

        [$submittedN, $submittedC] = $sum([SubmissionEventType::SUBMITTED]);
        [$overturnedN, $overturnedC] = $sum([SubmissionEventType::DECISION_FAVORABLE, SubmissionEventType::DECISION_PARTIAL]);
        [$upheldN, $upheldC] = $sum([SubmissionEventType::DECISION_UNFAVORABLE]);

        return [
            'submitted_count'  => $submittedN,
            'submitted_cents'  => $submittedC,
            'overturned_count' => $overturnedN,
            'overturned_cents' => $overturnedC,
            'upheld_count'     => $upheldN,
            'upheld_cents'     => $upheldC,
        ];
    }

    private function uniquePublicRef(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $ref = Uuid::publicRef('SUB');
            if (!$this->db->exists('SELECT id FROM sa_submission_events WHERE public_ref = :r', ['r' => $ref])) {
                return $ref;
            }
        }
        throw new \RuntimeException('Could not mint a unique submission reference.');
    }
}
