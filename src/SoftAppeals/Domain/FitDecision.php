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
    public const ACCEPT   = 'accept';
    public const CLARIFY  = 'clarify';
    public const DECLINE  = 'decline';
    public const HOLD     = 'hold';

    /**
     * Not a practice at all: her own test submission, or the same enquiry
     * arriving twice. Separate from `decline`, which is a real practice she
     * said no to and whose reason is worth keeping. This one is noise, and
     * mixing the two would make the declined list useless.
     */
    public const NOT_REAL = 'not_real';

    /** @return array<string,string> decision => what she sees on the button */
    public static function labels(): array
    {
        return [
            self::ACCEPT  => 'Accept and prepare terms',
            self::CLARIFY => 'Ask one business-level question',
            self::DECLINE => 'Decline',
            self::HOLD    => 'Capacity hold',
            self::NOT_REAL => 'Not a real enquiry',
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
            self::NOT_REAL => 'A test of your own, or the same enquiry twice. Off the board, still on the record.',
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
            self::HOLD     => IntakeStatus::HOLD,
            self::NOT_REAL => IntakeStatus::DUPLICATE,
            default       => throw new \RuntimeException('Unknown fit decision: ' . $decision),
        };
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::labels());
    }
}
