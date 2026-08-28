<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * What the fit review concluded. Section 12.5.
 *
 * Four answers, and one of them is "not yet". The Desk offers exactly these and
 * the server accepts exactly these, so a hand-built POST cannot invent a fifth
 * outcome and land an inquiry in a state nothing else understands.
 *
 * Each decision moves the intake to one status and no other. That mapping lives
 * here rather than in the page, because it is the rule and a page is a view.
 */
final class FitDecision
{
    public const ACCEPT  = 'accept';
    public const CLARIFY = 'clarify';
    public const DECLINE = 'decline';
    public const HOLD    = 'hold';

    /** @return array<string,string> decision => what she sees on the button */
    public static function labels(): array
    {
        return [
            self::ACCEPT  => 'Accept and prepare terms',
            self::CLARIFY => 'Ask one business-level question',
            self::DECLINE => 'Decline',
            self::HOLD    => 'Capacity hold',
        ];
    }

    /** @return array<string,string> decision => the one line explaining it */
    public static function notes(): array
    {
        return [
            self::ACCEPT  => 'Creates the engagement and opens the terms preview. Nothing is emailed yet.',
            self::CLARIFY => 'Keeps the inquiry open. She sends the question herself.',
            self::DECLINE => 'Closes the inquiry. The reason stays attached to it.',
            self::HOLD    => 'A fit, but not this month. It stays on the board.',
        ];
    }

    public static function isValid(string $decision): bool
    {
        return array_key_exists($decision, self::labels());
    }

    public static function label(string $decision): string
    {
        return self::labels()[$decision] ?? $decision;
    }

    /** The one status this decision puts the intake into. */
    public static function resultingStatus(string $decision): string
    {
        return match ($decision) {
            self::ACCEPT  => IntakeStatus::ACCEPTED,
            self::CLARIFY => IntakeStatus::CLARIFICATION,
            self::DECLINE => IntakeStatus::DECLINED,
            self::HOLD    => IntakeStatus::HOLD,
            default       => throw new \RuntimeException('Unknown fit decision: ' . $decision),
        };
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::labels());
    }
}
