<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * The life of one approval request, section 11.1 and section 6 Gate C.
 *
 * Five states and one rule: pending is the only state a decision can be made
 * from, and a decision is made once. Approved and returned are the two
 * answers an authorized approver can give. Expired and cancelled are the two
 * ways a request ends without an answer, and neither of them is the client's
 * act.
 *
 * Returned is not a refusal for ever. It hands the batch back to Soft Appeals
 * with a note, the batch goes back to recommended, and a fresh request can be
 * raised once the note is dealt with. That is why "returned" and "cancelled"
 * are different words.
 */
final class ApprovalState
{
    public const PENDING   = 'pending';
    public const APPROVED  = 'approved';
    public const RETURNED  = 'returned';
    public const EXPIRED   = 'expired';
    public const CANCELLED = 'cancelled';

    public const KIND_SUBMISSION = 'submission';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PENDING, self::APPROVED, self::RETURNED, self::EXPIRED, self::CANCELLED];
    }

    public static function isValid(string $state): bool
    {
        return in_array($state, self::all(), true);
    }

    /** The two answers the practice can give. */
    public static function isDecision(string $state): bool
    {
        return in_array($state, [self::APPROVED, self::RETURNED], true);
    }

    public static function isOpen(string $state): bool
    {
        return $state === self::PENDING;
    }

    /** What she reads. */
    public static function staffLabel(string $state): string
    {
        return match ($state) {
            self::PENDING   => 'Waiting on the approver',
            self::APPROVED  => 'Approved',
            self::RETURNED  => 'Returned with a note',
            self::EXPIRED   => 'Expired unanswered',
            self::CANCELLED => 'Withdrawn',
            default         => $state,
        };
    }

    /** What the practice reads. */
    public static function clientLabel(string $state): string
    {
        return match ($state) {
            self::PENDING   => 'Waiting on you',
            self::APPROVED  => 'Approved by you',
            self::RETURNED  => 'Returned by you',
            self::EXPIRED   => 'Expired',
            self::CANCELLED => 'Withdrawn by us',
            default         => $state,
        };
    }

    /** The timeline line, written for the practice. */
    public static function timelineLabel(string $state): string
    {
        return match ($state) {
            self::APPROVED  => 'You approved a submission',
            self::RETURNED  => 'You returned a submission with a note',
            self::EXPIRED   => 'An approval request expired unanswered',
            self::CANCELLED => 'We withdrew an approval request',
            default         => 'Approval request updated',
        };
    }
}
