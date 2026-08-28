<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * The four steps of section 7.4, in order, and the two answers each of the
 * last two can end with.
 *
 *   Resolved batches
 *     -> Financial reconciliation
 *     -> Final report
 *     -> Access review
 *     -> Data disposition confirmed
 *     -> Closed
 *
 * Each step is one stage of Domain\Stage. Confirming a step is what moves the
 * engagement to the next stage, and the step row records who confirmed it
 * and when. Nothing here can be ticked out of order, because the stage the
 * engagement sits at is checked on the server before any step is written.
 */
final class CloseoutStep
{
    public const RECONCILIATION   = 'reconciliation';
    public const FINAL_REPORT     = 'final_report';
    public const ACCESS_REVIEW    = 'access_review';
    public const DATA_DISPOSITION = 'data_disposition';

    // What was done with the practice's data. Section 15.10.
    public const DISPOSITION_RETURNED  = 'returned';
    public const DISPOSITION_DESTROYED = 'destroyed';
    public const DISPOSITION_RETAINED  = 'retained_per_agreement';

    // What was done with each person's access.
    public const ACCESS_REMOVED  = 'removed';
    public const ACCESS_RETAINED = 'retained';

    /** @return list<string> in order */
    public static function all(): array
    {
        return [
            self::RECONCILIATION,
            self::FINAL_REPORT,
            self::ACCESS_REVIEW,
            self::DATA_DISPOSITION,
        ];
    }

    public static function isValid(string $step): bool
    {
        return in_array($step, self::all(), true);
    }

    /** The stage an engagement sits at while this step is the open one. */
    public static function stage(string $step): string
    {
        return match ($step) {
            self::RECONCILIATION   => Stage::RECONCILIATION,
            self::FINAL_REPORT     => Stage::FINAL_REPORT,
            self::ACCESS_REVIEW    => Stage::ACCESS_REVIEW,
            self::DATA_DISPOSITION => Stage::DATA_DISPOSITION,
            default                => throw new \RuntimeException('Unknown closeout step: ' . $step),
        };
    }

    /** The stage confirming this step moves the engagement to. */
    public static function stageAfter(string $step): string
    {
        return match ($step) {
            self::RECONCILIATION   => Stage::FINAL_REPORT,
            self::FINAL_REPORT     => Stage::ACCESS_REVIEW,
            self::ACCESS_REVIEW    => Stage::DATA_DISPOSITION,
            self::DATA_DISPOSITION => Stage::CLOSED,
            default                => throw new \RuntimeException('Unknown closeout step: ' . $step),
        };
    }

    /** The step that is open at a stage, or null outside closeout. */
    public static function forStage(string $stage): ?string
    {
        foreach (self::all() as $step) {
            if (self::stage($step) === $stage) {
                return $step;
            }
        }
        return null;
    }

    public static function label(string $step): string
    {
        return match ($step) {
            self::RECONCILIATION   => 'Financial reconciliation',
            self::FINAL_REPORT     => 'Final report',
            self::ACCESS_REVIEW    => 'Access review',
            self::DATA_DISPOSITION => 'Data disposition',
            default                => $step,
        };
    }

    /** What the practice reads. */
    public static function clientLabel(string $step): string
    {
        return match ($step) {
            self::RECONCILIATION   => 'The money was reconciled',
            self::FINAL_REPORT     => 'Your final report was written',
            self::ACCESS_REVIEW    => 'Access was reviewed',
            self::DATA_DISPOSITION => 'Your data was returned or destroyed',
            default                => $step,
        };
    }

    /** What confirming this step means, on the Desk, in a sentence. */
    public static function instructions(string $step): string
    {
        return match ($step) {
            self::RECONCILIATION   => 'Every overturned batch in scope has a verified figure, even if that figure is zero, every fee is on an invoice, and no invoice is still a draft.',
            self::FINAL_REPORT     => 'Write the final summary the practice reads: what was reviewed, what was submitted, what came back, and what was verified. Aggregate only. No patient, member, claim or date of service.',
            self::ACCESS_REVIEW    => 'Decide, for every person who can sign in at this practice, whether their access is removed or retained. Removing it signs them out and cancels any open link.',
            self::DATA_DISPOSITION => 'Say what happened to the practice\'s material in the secure route: returned, destroyed, or retained under the agreement. Confirming this seals the closeout record and closes the engagement.',
            default                => '',
        };
    }

    /** @return list<string> */
    public static function dispositions(): array
    {
        return [self::DISPOSITION_RETURNED, self::DISPOSITION_DESTROYED, self::DISPOSITION_RETAINED];
    }

    public static function isValidDisposition(string $value): bool
    {
        return in_array($value, self::dispositions(), true);
    }

    public static function dispositionLabel(string $value): string
    {
        return match ($value) {
            self::DISPOSITION_RETURNED  => 'Returned to the practice',
            self::DISPOSITION_DESTROYED => 'Destroyed',
            self::DISPOSITION_RETAINED  => 'Retained under the agreement',
            default                     => $value,
        };
    }

    /** @return list<string> */
    public static function accessDecisions(): array
    {
        return [self::ACCESS_REMOVED, self::ACCESS_RETAINED];
    }

    public static function isValidAccessDecision(string $value): bool
    {
        return in_array($value, self::accessDecisions(), true);
    }

    public static function accessLabel(string $value): string
    {
        return match ($value) {
            self::ACCESS_REMOVED  => 'Removed',
            self::ACCESS_RETAINED => 'Retained',
            default               => $value,
        };
    }
}
