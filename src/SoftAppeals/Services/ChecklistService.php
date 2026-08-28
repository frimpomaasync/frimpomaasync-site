<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Domain\Checklist;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\ChecklistRepository;
use SoftAppeals\Repositories\StatusEventRepository;

/**
 * The checklist, section 15.6, as a projection of the timeline.
 *
 * Nothing ticks an item by hand. Each item names the stage transition that
 * completes it, and sync() reads the engagement's own status events and
 * marks the item done at the moment that event says it happened. That is
 * what makes the list true for an engagement that was already at "BAA
 * executed" before this table existed: the events were always there, and the
 * checklist is only a second way of reading them.
 *
 * The recovery list is seeded the first time an event lands on "recovery
 * scope selected", and never before. Section 15.6: it appears only if the
 * organization continues.
 */
final class ChecklistService
{
    private ChecklistRepository $items;
    private StatusEventRepository $timeline;

    public function __construct(ChecklistRepository $items, StatusEventRepository $timeline)
    {
        $this->items = $items;
        $this->timeline = $timeline;
    }

    /**
     * item key => the stages whose arrival completes it.
     *
     * @return array<string,list<string>>
     */
    public static function completingStages(): array
    {
        return [
            Checklist::PREFERENCES_CONFIRMED => [Stage::PREFERENCES_CONFIRMED],
            Checklist::BAA_EXECUTED          => [Stage::BAA_EXECUTED],
            Checklist::REVIEW_AUTH_EXECUTED  => [Stage::REVIEW_AUTH_EXECUTED],
            Checklist::SECURE_OPENED         => [Stage::SECURE_INTAKE_READY],
            Checklist::INITIAL_SET_RECEIVED  => [Stage::RECEIPT_CONFIRMED],
            Checklist::ASSESSMENT_DELIVERED  => [Stage::ASSESSMENT_DELIVERED],
            Checklist::DECISION_RECORDED     => [Stage::RECOVERY_SCOPE_SELECTED, Stage::CLOSED_NO_RECOVERY],
            Checklist::SCOPE_SELECTED        => [Stage::RECOVERY_SCOPE_SELECTED],
            Checklist::RECOVERY_AGREEMENT    => [Stage::RECOVERY_AGREEMENT_EXECUTED],
            // The last three complete on events, not stages. See
            // completingEvents().
            Checklist::APPROVER_CONFIRMED    => [],
            Checklist::FIRST_APPROVAL        => [],
            Checklist::FIRST_SUBMISSION      => [],
        ];
    }

    /**
     * item key => the event types whose first arrival completes it.
     *
     * Phase 6 writes these. Naming an approver is not a stage, a first
     * approval is not a stage, and a first submission is not a stage; each
     * is one line on the timeline, and the checklist reads that line.
     *
     * @return array<string,list<string>>
     */
    public static function completingEvents(): array
    {
        return [
            Checklist::APPROVER_CONFIRMED => ['recovery.approver_confirmed'],
            Checklist::FIRST_APPROVAL     => ['approval.approved'],
            Checklist::FIRST_SUBMISSION   => ['submission.recorded'],
        ];
    }

    /**
     * Bring the checklist up to date with the timeline and return it.
     *
     * @return list<array<string,mixed>>
     */
    public function sync(string $engagementId): array
    {
        $this->items->seed($engagementId, Checklist::initial(), 1);

        $events = $this->timeline->forEngagement($engagementId);

        // The first event that landed on each stage. First, because a stage
        // that is visited twice (quality review and back) was first reached
        // once, and that is the completion.
        $arrivedAt = [];
        $firstOfType = [];
        foreach ($events as $event) {
            $to = $event['to_stage'] === null ? '' : (string) $event['to_stage'];
            if ($to !== '' && !array_key_exists($to, $arrivedAt)) {
                $arrivedAt[$to] = $event;
            }
            $type = (string) $event['event_type'];
            if (!array_key_exists($type, $firstOfType)) {
                $firstOfType[$type] = $event;
            }
        }

        if (array_key_exists(Stage::RECOVERY_SCOPE_SELECTED, $arrivedAt)) {
            $this->items->seed($engagementId, Checklist::recovery(), count(Checklist::initial()) + 1);
        }

        foreach (self::completingStages() as $key => $stages) {
            if (!$this->items->hasKey($engagementId, $key)) {
                continue;
            }
            foreach ($stages as $stage) {
                if (array_key_exists($stage, $arrivedAt)) {
                    $event = $arrivedAt[$stage];
                    $this->items->complete(
                        $engagementId,
                        $key,
                        (string) $event['created_at'],
                        $event['actor_id'] === null ? null : (string) $event['actor_id'],
                        (string) $event['id']
                    );
                    break;
                }
            }
        }

        foreach (self::completingEvents() as $key => $types) {
            if (!$this->items->hasKey($engagementId, $key)) {
                continue;
            }
            foreach ($types as $type) {
                if (array_key_exists($type, $firstOfType)) {
                    $event = $firstOfType[$type];
                    $this->items->complete(
                        $engagementId,
                        $key,
                        (string) $event['created_at'],
                        $event['actor_id'] === null ? null : (string) $event['actor_id'],
                        (string) $event['id']
                    );
                    break;
                }
            }
        }

        return $this->items->forEngagement($engagementId);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array{done:int,total:int}
     */
    public static function progress(array $items): array
    {
        $done = 0;
        foreach ($items as $item) {
            if ($item['completed_at'] !== null) {
                $done++;
            }
        }
        return ['done' => $done, 'total' => count($items)];
    }
}
