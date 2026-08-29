<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * The four answers a practice may give once the assessment is delivered.
 * Section 22, Phase 5 acceptance: "the client can choose internal use, more
 * information, recovery scope, or no further action".
 *
 * Only one of the four opens recovery, and it opens it as far as "scope
 * selected" and no further. The recovery agreement is the next gate, and
 * Domain\Stage will not let an engagement past it without an executed one.
 * That is the acceptance line "a client cannot activate recovery without the
 * next agreement gate", enforced by the state machine rather than by this
 * class.
 */
final class ClientDecision
{
    public const INTERNAL_USE      = 'internal_use';
    public const MORE_INFORMATION  = 'more_information';
    public const RECOVERY_SCOPE    = 'recovery_scope';
    public const NO_FURTHER_ACTION = 'no_further_action';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::RECOVERY_SCOPE,
            self::MORE_INFORMATION,
            self::INTERNAL_USE,
            self::NO_FURTHER_ACTION,
        ];
    }

    public static function isValid(string $decision): bool
    {
        return in_array($decision, self::all(), true);
    }

    /** The choice as the practice reads it on the decision page. */
    public static function label(string $decision): string
    {
        return match ($decision) {
            self::RECOVERY_SCOPE    => 'Go ahead with recovery work',
            self::MORE_INFORMATION  => 'I need more information first',
            self::INTERNAL_USE      => 'We will use the assessment ourselves',
            self::NO_FURTHER_ACTION => 'No further action',
            default                 => $decision,
        };
    }

    /** The line under each choice. What happens if they pick it. */
    public static function explanation(string $decision): string
    {
        return match ($decision) {
            self::RECOVERY_SCOPE    => 'We prepare a recovery agreement naming the scope. Nothing is submitted to any payer until you have signed it and approved each submission.',
            self::MORE_INFORMATION  => 'Ask your question below. We answer it in this room and you decide afterwards.',
            self::INTERNAL_USE      => 'You keep the assessment and act on it with your own team. This engagement closes with nothing owed.',
            self::NO_FURTHER_ACTION => 'This engagement closes with nothing owed. The assessment stays in this room.',
            default                 => '',
        };
    }

    /** What she reads on the Desk. */
    public static function staffLabel(string $decision): string
    {
        return match ($decision) {
            self::RECOVERY_SCOPE    => 'Recovery scope',
            self::MORE_INFORMATION  => 'Asked for more information',
            self::INTERNAL_USE      => 'Internal use',
            self::NO_FURTHER_ACTION => 'No further action',
            default                 => $decision,
        };
    }

    /** The timeline line, written for the practice, section 15.5. */
    public static function timelineLabel(string $decision): string
    {
        return match ($decision) {
            self::RECOVERY_SCOPE    => 'Recovery scope selected',
            self::MORE_INFORMATION  => 'You asked for more information before deciding',
            self::INTERNAL_USE      => 'You chose to use the assessment internally',
            self::NO_FURTHER_ACTION => 'You chose no further action',
            default                 => 'Decision recorded',
        };
    }

    /** True for the two answers that close the engagement. */
    public static function closes(string $decision): bool
    {
        return in_array($decision, [self::INTERNAL_USE, self::NO_FURTHER_ACTION], true);
    }

    /** The engagement stage this decision moves to, or null to stay put. */
    public static function stageAfter(string $decision): ?string
    {
        return match ($decision) {
            self::RECOVERY_SCOPE    => Stage::RECOVERY_SCOPE_SELECTED,
            self::INTERNAL_USE,
            self::NO_FURTHER_ACTION => Stage::CLOSED_NO_RECOVERY,
            default                 => null,
        };
    }
}
