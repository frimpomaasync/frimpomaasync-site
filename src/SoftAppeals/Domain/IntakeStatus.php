<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * Where an inquiry stands, and what the fit review decided.
 *
 * Two lists rather than one, because they answer different questions. The
 * status is where the record is; the fit decision is what she concluded. An
 * inquiry can be `accepted` and still carry a fit note explaining what she is
 * watching, and a `declined` one keeps the reason attached to it forever, which
 * is the point of writing it down at all.
 *
 * Both lists are closed. The migration carries the same status values as a
 * CHECK constraint, so a value that is not here cannot reach the table even if
 * something in PHP tried.
 */
final class IntakeStatus
{
    public const RECEIVED      = 'received';
    public const IN_REVIEW     = 'in_review';
    public const ACCEPTED      = 'accepted';
    public const DECLINED      = 'declined';
    public const CLARIFICATION = 'clarification';
    public const HOLD          = 'hold';
    public const DUPLICATE     = 'duplicate';

    /** @return array<string,string> */
    public static function labels(): array
    {
        return [
            self::RECEIVED      => 'Received',
            self::IN_REVIEW     => 'In review',
            self::ACCEPTED      => 'Accepted',
            self::DECLINED      => 'Declined',
            self::CLARIFICATION => 'Clarification asked',
            self::HOLD          => 'Capacity hold',
            self::DUPLICATE     => 'Duplicate',
        ];
    }

    public static function isValid(string $status): bool
    {
        return array_key_exists($status, self::labels());
    }

    public static function label(string $status): string
    {
        return self::labels()[$status] ?? $status;
    }

    /** The ones still waiting on her. What the Needs you card counts. */
    public static function isOpen(string $status): bool
    {
        return in_array($status, [self::RECEIVED, self::IN_REVIEW], true);
    }

    /** Open in the wider sense: not yet accepted, declined or dismissed. */
    public static function isUnresolved(string $status): bool
    {
        return in_array(
            $status,
            [self::RECEIVED, self::IN_REVIEW, self::CLARIFICATION, self::HOLD],
            true
        );
    }

    /** The pill colour class in assets/soft-appeals.css. */
    public static function pill(string $status): string
    {
        return match ($status) {
            self::RECEIVED, self::IN_REVIEW => 'is-action',
            self::ACCEPTED                  => 'is-ok',
            self::CLARIFICATION, self::HOLD => 'is-wait',
            self::DECLINED, self::DUPLICATE => '',
            default                         => '',
        };
    }

    /**
     * @return list<string> the whole set, for the migration's CHECK list and
     *         for the test that proves the two agree
     */
    public static function all(): array
    {
        return array_keys(self::labels());
    }
}
