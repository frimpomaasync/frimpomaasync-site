<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * What can happen to a batch once it is with a payer. Section 7.3, the payer
 * half of it, recorded as aggregate events.
 *
 * Every event carries a count, a dollar figure and a date, and none of them
 * carries a claim. The decision events are the payer-response states the
 * plan's Phase 6 asks for, and the rule that goes with them is section 19:
 * none of them creates a fee. A favorable decision moves the batch to
 * "overturned" and opens a follow-up to verify what actually arrives, and
 * the verification is the money phase's job, not this one's.
 */
final class SubmissionEventType
{
    public const SUBMITTED             = 'submitted';
    public const PAYER_ACKNOWLEDGED    = 'payer_acknowledged';
    public const INFORMATION_REQUESTED = 'information_requested';
    public const DECISION_FAVORABLE    = 'decision_favorable';
    public const DECISION_PARTIAL      = 'decision_partial';
    public const DECISION_UNFAVORABLE  = 'decision_unfavorable';
    public const WITHDRAWN             = 'withdrawn';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SUBMITTED,
            self::PAYER_ACKNOWLEDGED,
            self::INFORMATION_REQUESTED,
            self::DECISION_FAVORABLE,
            self::DECISION_PARTIAL,
            self::DECISION_UNFAVORABLE,
            self::WITHDRAWN,
        ];
    }

    /**
     * The responses the Desk offers once a batch has been submitted. The
     * submission itself is recorded by its own form, because it needs the
     * approval behind it and the others do not.
     *
     * @return list<string>
     */
    public static function responses(): array
    {
        return [
            self::PAYER_ACKNOWLEDGED,
            self::INFORMATION_REQUESTED,
            self::DECISION_FAVORABLE,
            self::DECISION_PARTIAL,
            self::DECISION_UNFAVORABLE,
            self::WITHDRAWN,
        ];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    public static function isResponse(string $type): bool
    {
        return in_array($type, self::responses(), true);
    }

    /** True for the three events that are the payer's answer. */
    public static function isDecision(string $type): bool
    {
        return in_array($type, [
            self::DECISION_FAVORABLE,
            self::DECISION_PARTIAL,
            self::DECISION_UNFAVORABLE,
        ], true);
    }

    /** The batch stage this event moves the batch to. */
    public static function batchStageAfter(string $type): string
    {
        return match ($type) {
            self::SUBMITTED             => BatchStage::SUBMITTED,
            self::PAYER_ACKNOWLEDGED,
            self::INFORMATION_REQUESTED => BatchStage::PAYER_REVIEW,
            self::DECISION_FAVORABLE,
            self::DECISION_PARTIAL      => BatchStage::OVERTURNED,
            self::DECISION_UNFAVORABLE  => BatchStage::UPHELD,
            self::WITHDRAWN             => BatchStage::CLOSED,
            default                     => BatchStage::PAYER_REVIEW,
        };
    }

    /** What she reads. */
    public static function staffLabel(string $type): string
    {
        return match ($type) {
            self::SUBMITTED             => 'Submitted to the payer',
            self::PAYER_ACKNOWLEDGED    => 'Payer acknowledged receipt',
            self::INFORMATION_REQUESTED => 'Payer asked for more information',
            self::DECISION_FAVORABLE    => 'Decision: overturned in full',
            self::DECISION_PARTIAL      => 'Decision: overturned in part',
            self::DECISION_UNFAVORABLE  => 'Decision: upheld',
            self::WITHDRAWN             => 'Withdrawn',
            default                     => $type,
        };
    }

    /** What the practice reads. Never the internal token. */
    public static function clientLabel(string $type): string
    {
        return match ($type) {
            self::SUBMITTED             => 'Sent to the payer',
            self::PAYER_ACKNOWLEDGED    => 'The payer has it',
            self::INFORMATION_REQUESTED => 'The payer asked for more',
            self::DECISION_FAVORABLE    => 'Overturned in your favour',
            self::DECISION_PARTIAL      => 'Partly overturned in your favour',
            self::DECISION_UNFAVORABLE  => 'Upheld by the payer',
            self::WITHDRAWN             => 'Withdrawn',
            default                     => 'Updated',
        };
    }

    /** The timeline line, written for the practice. */
    public static function timelineLabel(string $type): string
    {
        return match ($type) {
            self::SUBMITTED             => 'A batch was submitted to the payer',
            self::PAYER_ACKNOWLEDGED    => 'The payer acknowledged a submission',
            self::INFORMATION_REQUESTED => 'The payer asked for more information',
            self::DECISION_FAVORABLE    => 'The payer overturned a denial in your favour',
            self::DECISION_PARTIAL      => 'The payer partly overturned a denial in your favour',
            self::DECISION_UNFAVORABLE  => 'The payer upheld a denial',
            self::WITHDRAWN             => 'A submission was withdrawn',
            default                     => 'A submission was updated',
        };
    }

    /**
     * The safe next action on the batch card after this event, in words the
     * practice reads.
     */
    public static function nextActionAfter(string $type): string
    {
        return match ($type) {
            self::SUBMITTED             => 'Waiting on the payer',
            self::PAYER_ACKNOWLEDGED    => 'Waiting on the payer decision',
            self::INFORMATION_REQUESTED => 'Send what the payer asked for through the secure route',
            self::DECISION_FAVORABLE,
            self::DECISION_PARTIAL      => 'Verify the reimbursement when it arrives. No fee until it is verified',
            self::DECISION_UNFAVORABLE  => 'Nothing further unless a second level is agreed',
            self::WITHDRAWN             => 'Nothing',
            default                     => 'Waiting on the payer',
        };
    }
}
