<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * The life of one work batch, section 11.1 and section 15.7.
 *
 * A batch is the unit of work a practice is shown. It carries counts and
 * dollars in aggregate and nothing else: no claim, no member, no date of
 * service. The stage is what the card says, and the next owner is derived from
 * it unless she overrides it on the Desk.
 *
 * The stages are coarse on purpose. Phase 5 opens batches and moves them
 * through the assessment; submission, payer follow-up and decisions are Phase 6
 * and 7 work, but the names exist now so those phases add rows rather than
 * states.
 */
final class BatchStage
{
    public const RECEIVED         = 'received';
    public const IN_REVIEW        = 'in_review';
    public const RECOMMENDED      = 'recommended';
    public const NOT_RECOMMENDED  = 'not_recommended';
    public const APPROVAL_PENDING = 'approval_pending';
    public const SUBMITTED        = 'submitted';
    public const PAYER_REVIEW     = 'payer_review';
    public const OVERTURNED       = 'overturned';
    public const UPHELD           = 'upheld';
    public const CLOSED           = 'closed';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::RECEIVED,
            self::IN_REVIEW,
            self::RECOMMENDED,
            self::NOT_RECOMMENDED,
            self::APPROVAL_PENDING,
            self::SUBMITTED,
            self::PAYER_REVIEW,
            self::OVERTURNED,
            self::UPHELD,
            self::CLOSED,
        ];
    }

    /**
     * The ones the Desk's batch form offers directly.
     *
     * The recovery stages are not on this list on purpose. A batch reaches
     * "awaiting approval" by an approval request being raised, "submitted" by
     * a submission being recorded against an approval, and the payer stages by
     * a payer response being recorded. Offering them in a dropdown would let a
     * batch say "submitted" with no approval and no submission behind it.
     *
     * @return list<string>
     */
    public static function phaseFive(): array
    {
        return [
            self::RECEIVED,
            self::IN_REVIEW,
            self::RECOMMENDED,
            self::NOT_RECOMMENDED,
            self::CLOSED,
        ];
    }

    /**
     * from => the stages a recovery event may move a batch to. Section 7.3.
     *
     * The form's own moves (received, in review, recommended, not
     * recommended, closed) are not here; this table is for the moves that
     * only an approval, a submission or a payer response may make.
     *
     * @return array<string,list<string>>
     */
    public static function recoveryTransitions(): array
    {
        return [
            self::RECOMMENDED      => [self::APPROVAL_PENDING],
            self::APPROVAL_PENDING => [self::RECOMMENDED, self::SUBMITTED],
            self::SUBMITTED        => [self::PAYER_REVIEW, self::OVERTURNED, self::UPHELD, self::CLOSED],
            self::PAYER_REVIEW     => [self::PAYER_REVIEW, self::OVERTURNED, self::UPHELD, self::CLOSED],
        ];
    }

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::recoveryTransitions()[$from] ?? [], true);
    }

    /** True while the batch is with a payer or waiting on an approval. */
    public static function isInRecovery(string $stage): bool
    {
        return in_array($stage, [
            self::APPROVAL_PENDING,
            self::SUBMITTED,
            self::PAYER_REVIEW,
            self::OVERTURNED,
            self::UPHELD,
        ], true);
    }

    public static function isValid(string $stage): bool
    {
        return in_array($stage, self::all(), true);
    }

    public static function isTerminal(string $stage): bool
    {
        return in_array($stage, [self::OVERTURNED, self::UPHELD, self::CLOSED], true);
    }

    /** What she reads. */
    public static function staffLabel(string $stage): string
    {
        return match ($stage) {
            self::RECEIVED         => 'Received',
            self::IN_REVIEW        => 'In review',
            self::RECOMMENDED      => 'Recommended for action',
            self::NOT_RECOMMENDED  => 'Not recommended',
            self::APPROVAL_PENDING => 'Awaiting client approval',
            self::SUBMITTED        => 'Submitted to the payer',
            self::PAYER_REVIEW     => 'With the payer',
            self::OVERTURNED       => 'Overturned',
            self::UPHELD           => 'Upheld',
            self::CLOSED           => 'Closed',
            default                => $stage,
        };
    }

    /** What the practice reads. Never the internal token. */
    public static function clientLabel(string $stage): string
    {
        return match ($stage) {
            self::RECEIVED         => 'Received',
            self::IN_REVIEW        => 'Being reviewed',
            self::RECOMMENDED      => 'Recommended for action',
            self::NOT_RECOMMENDED  => 'Not recommended for action',
            self::APPROVAL_PENDING => 'Waiting for your approval',
            self::SUBMITTED        => 'Submitted to the payer',
            self::PAYER_REVIEW     => 'With the payer',
            self::OVERTURNED       => 'Overturned in your favour',
            self::UPHELD           => 'Upheld by the payer',
            self::CLOSED           => 'Closed',
            default                => 'In progress',
        };
    }

    /**
     * Who the next move belongs to, from the stage. Section 11.1 lists five
     * owners; the Desk may override this per batch, and this is the default.
     */
    public static function defaultOwner(string $stage): string
    {
        return match ($stage) {
            self::RECEIVED, self::IN_REVIEW,
            self::RECOMMENDED, self::NOT_RECOMMENDED => self::OWNER_SOFT_APPEALS,
            self::APPROVAL_PENDING                   => self::OWNER_CLIENT,
            self::SUBMITTED, self::PAYER_REVIEW      => self::OWNER_PAYER,
            default                                  => self::OWNER_OTHER,
        };
    }

    /** The safe next action, in words, when she has not written one. */
    public static function defaultNextAction(string $stage): string
    {
        return match ($stage) {
            self::RECEIVED         => 'Review begins with the assessment',
            self::IN_REVIEW        => 'Under review',
            self::RECOMMENDED      => 'Covered in the assessment; decide after reading it',
            self::NOT_RECOMMENDED  => 'Nothing further on this batch',
            self::APPROVAL_PENDING => 'Approve in the secure workflow',
            self::SUBMITTED        => 'Waiting on the payer',
            self::PAYER_REVIEW     => 'Waiting on the payer',
            self::OVERTURNED       => 'Verify the reimbursement when it arrives',
            self::UPHELD           => 'Nothing further unless a second level is agreed',
            self::CLOSED           => 'Nothing',
            default                => 'Nothing',
        };
    }

    // Next owners. Section 11.1.
    public const OWNER_SOFT_APPEALS    = 'soft_appeals';
    public const OWNER_CLIENT          = 'client';
    public const OWNER_BILLING_PARTNER = 'billing_partner';
    public const OWNER_PAYER           = 'payer';
    public const OWNER_OTHER           = 'other';

    /** @return list<string> */
    public static function owners(): array
    {
        return [
            self::OWNER_SOFT_APPEALS,
            self::OWNER_CLIENT,
            self::OWNER_BILLING_PARTNER,
            self::OWNER_PAYER,
            self::OWNER_OTHER,
        ];
    }

    public static function isValidOwner(string $owner): bool
    {
        return in_array($owner, self::owners(), true);
    }

    public static function ownerLabel(string $owner, bool $forClient = false): string
    {
        return match ($owner) {
            self::OWNER_SOFT_APPEALS    => $forClient ? 'Us' : 'Soft Appeals',
            self::OWNER_CLIENT          => $forClient ? 'You' : 'Client',
            self::OWNER_BILLING_PARTNER => 'Billing partner',
            self::OWNER_PAYER           => 'The payer',
            self::OWNER_OTHER           => 'Other',
            default                     => $owner,
        };
    }
}
