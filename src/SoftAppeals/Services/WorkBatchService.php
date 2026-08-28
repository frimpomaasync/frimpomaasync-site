<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Domain\SafeText;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\EngagementRepository;
use SoftAppeals\Repositories\WorkBatchRepository;
use SoftAppeals\Support\Clock;
use SoftAppeals\Support\Money;

/**
 * Work batches, sections 11.1 and 15.7. Her side.
 *
 * A batch is opened and changed from the Desk and read in the Recovery Room.
 * Two rules live here. A batch cannot exist before the PHI gate is passed,
 * because before that point there is nothing to count. And a deadline is
 * either a date she has confirmed or it is labelled unconfirmed, on the card
 * and on the Desk alike, section 12.4.
 */
final class WorkBatchService
{
    private Clock $clock;
    private WorkBatchRepository $batches;
    private EngagementRepository $engagements;
    private AuditService $audit;

    public function __construct(
        Clock $clock,
        WorkBatchRepository $batches,
        EngagementRepository $engagements,
        AuditService $audit
    ) {
        $this->clock = $clock;
        $this->batches = $batches;
        $this->engagements = $engagements;
        $this->audit = $audit;
    }

    /**
     * Read the form the Desk posts into the fields the repository takes.
     * Every refusal names the field.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function fieldsFromInput(array $input): array
    {
        $fields = [];

        $fields['label'] = SafeText::requireLine((string) ($input['label'] ?? ''), 80, 'the batch name');
        if ($fields['label'] === '') {
            $fields['label'] = 'Batch';
        }

        $payer = SafeText::requireLine((string) ($input['payer_label'] ?? ''), 80, 'the payer label');
        $fields['payer_label'] = $payer === '' ? null : $payer;
        $fields['payer_label_approved'] = (string) ($input['payer_label_approved'] ?? '') === 'yes';

        foreach (['claim_count', 'received_count', 'in_review_count'] as $count) {
            $raw = trim((string) ($input[$count] ?? ''));
            if ($raw === '') {
                continue;
            }
            if (preg_match('/^\d{1,6}$/', $raw) !== 1) {
                throw new \RuntimeException('Not saved: ' . str_replace('_', ' ', $count) . ' has to be a whole number.');
            }
            $fields[$count] = (int) $raw;
        }

        $amount = trim((string) ($input['denied_amount'] ?? ''));
        if ($amount !== '') {
            $cents = Money::parseCents($amount);
            if ($cents === null) {
                throw new \RuntimeException('Not saved: the denied amount has to be a plain dollar figure, like 12,345.67.');
            }
            $fields['denied_amount_cents'] = $cents;
        }

        $stage = trim((string) ($input['stage'] ?? ''));
        if ($stage !== '') {
            if (!BatchStage::isValid($stage) || !in_array($stage, BatchStage::phaseFive(), true)) {
                throw new \RuntimeException('Not saved: that batch stage is not one the Desk offers yet.');
            }
            $fields['stage'] = $stage;
        }

        $owner = trim((string) ($input['next_owner'] ?? ''));
        if ($owner !== '') {
            if (!BatchStage::isValidOwner($owner)) {
                throw new \RuntimeException('Not saved: that is not one of the five next owners.');
            }
            $fields['next_owner'] = $owner;
        }

        $action = SafeText::requireLine((string) ($input['next_action'] ?? ''), 160, 'the next action');
        $fields['next_action'] = $action === '' ? null : $action;

        $deadline = trim((string) ($input['earliest_deadline'] ?? ''));
        $confirmed = (string) ($input['deadline_confirmed'] ?? '') === 'yes';
        if ($deadline === '') {
            if ($confirmed) {
                throw new \RuntimeException('Not saved: a deadline cannot be confirmed without a date.');
            }
            $fields['earliest_deadline_at'] = null;
            $fields['deadline_confirmed'] = false;
        } else {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $deadline, $m) !== 1
                || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ) {
                throw new \RuntimeException('Not saved: the deadline has to be a date, like 2026-09-30.');
            }
            // Noon UTC, so the same calendar day is shown in her timezone and
            // the day count is not off by one on either side of midnight.
            $fields['earliest_deadline_at'] = $deadline . ' 12:00:00';
            $fields['deadline_confirmed'] = $confirmed;
        }

        return $fields;
    }

    /**
     * Open a batch on an engagement past the PHI gate.
     *
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $fields already read by fieldsFromInput
     * @return array<string,mixed>
     */
    public function open(array $engagement, array $fields, ?string $userId = null): array
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];

        $stage = $this->currentStage($engagementId);
        if (!Stage::phiGatePassed($stage)) {
            $this->audit->record('work_batch.create', 'denied', 'engagement', $engagementId, [
                'reason' => 'the secure route is not open',
            ], $organizationId);
            throw new \RuntimeException(
                'No batch can exist before the secure route is open. This engagement is at "'
                . Stage::staffLabel($stage) . '".'
            );
        }

        $row = $this->batches->create($engagementId, $organizationId, $fields, $userId);

        $this->audit->record('work_batch.create', 'success', 'work_batch', (string) $row['id'], [
            'count'  => (string) $row['claim_count'],
            'amount_cents' => (string) $row['denied_amount_cents'],
        ], $organizationId);

        return $row;
    }

    /**
     * Change a batch. The batch is found through the engagement by the
     * caller; here the two are checked against each other once more.
     *
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $batch
     * @param array<string,mixed> $fields
     */
    public function update(array $engagement, array $batch, array $fields, ?string $userId = null): void
    {
        if ((string) $batch['engagement_id'] !== (string) $engagement['id']) {
            throw new \RuntimeException('That batch is not on this engagement.');
        }

        $changes = [];
        foreach ([
            'label', 'payer_label', 'claim_count', 'denied_amount_cents', 'received_count',
            'in_review_count', 'stage', 'next_owner', 'next_action', 'earliest_deadline_at',
        ] as $column) {
            if (array_key_exists($column, $fields)) {
                $changes[$column] = $fields[$column];
            }
        }
        if (array_key_exists('payer_label_approved', $fields)) {
            $changes['payer_label_approved'] = $fields['payer_label_approved'] ? 1 : 0;
        }
        if (array_key_exists('deadline_confirmed', $fields)) {
            $changes['deadline_confirmed'] = $fields['deadline_confirmed'] ? 1 : 0;
        }
        if (isset($changes['stage']) && !isset($changes['next_owner'])) {
            $changes['next_owner'] = BatchStage::defaultOwner((string) $changes['stage']);
        }
        if ($changes === []) {
            return;
        }

        if (!$this->batches->patch((string) $batch['id'], $changes, (int) $batch['row_version'])) {
            throw new \RuntimeException('This batch changed while you were looking at it. Reload and try again.');
        }

        $this->audit->record('work_batch.update', 'success', 'work_batch', (string) $batch['id'], [
            'from_stage' => (string) $batch['stage'],
            'to_stage'   => (string) ($changes['stage'] ?? $batch['stage']),
        ], (string) $engagement['organization_id']);
    }

    /**
     * Move every batch sitting at one stage to another. The assessment
     * starting moves "received" to "in review" in one go.
     */
    public function moveAll(array $engagement, string $from, string $to, ?string $userId = null): int
    {
        $moved = 0;
        foreach ($this->batches->forEngagement((string) $engagement['id']) as $batch) {
            if ((string) $batch['stage'] !== $from) {
                continue;
            }
            $this->update($engagement, $batch, ['stage' => $to], $userId);
            $moved++;
        }
        return $moved;
    }

    /**
     * The card, section 15.7, as the practice reads it. Only the permitted
     * fields, with the payer label held back unless approved.
     *
     * @param array<string,mixed> $batch
     * @return array<string,mixed>
     */
    public function card(array $batch): array
    {
        $deadline = $batch['earliest_deadline_at'] === null ? null : (string) $batch['earliest_deadline_at'];
        $confirmed = (int) $batch['deadline_confirmed'] === 1;
        $stage = (string) $batch['stage'];

        return [
            'ref'            => (string) $batch['public_ref'],
            'label'          => (string) $batch['label'],
            'payer'          => (int) $batch['payer_label_approved'] === 1 && $batch['payer_label'] !== null
                ? (string) $batch['payer_label']
                : null,
            'count'          => (int) $batch['claim_count'],
            'denied'         => Money::format((int) $batch['denied_amount_cents']),
            'stage'          => BatchStage::clientLabel($stage),
            'owner'          => BatchStage::ownerLabel((string) $batch['next_owner'], true),
            'action'         => $batch['next_action'] === null
                ? BatchStage::defaultNextAction($stage)
                : (string) $batch['next_action'],
            'deadline'       => $deadline === null ? null : $this->clock->displayDate($deadline),
            'deadline_days'  => $deadline === null ? null : $this->clock->daysUntil($deadline),
            'confirmed'      => $confirmed,
            'deadline_words' => $deadline === null
                ? 'No deadline recorded'
                : ($confirmed ? 'Confirmed' : 'Unconfirmed'),
        ];
    }

    private function currentStage(string $engagementId): string
    {
        $row = $this->engagements->find($engagementId);
        if ($row === null) {
            throw new \RuntimeException('No such engagement.');
        }
        return (string) $row['stage'];
    }
}
