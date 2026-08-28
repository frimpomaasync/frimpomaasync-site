<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * Action requests, section 15.8.
 *
 * A request is one thing somebody is asked to do, with an owner, a due date,
 * safe instructions, and either a button in the portal or a pointer to the
 * approved secure channel. The list of kinds is the plan's own list. A kind
 * that is not here cannot be opened, which is what keeps the portal from
 * growing a free-text "please upload" request that would breach section 5.
 *
 * Three fields decide how a request renders:
 *
 *   owner          who it is waiting on: the client or Soft Appeals
 *   portal_action  which button the Recovery Room offers, if any
 *   secure         whether it directs the person to the secure channel
 */
final class ActionRequestKind
{
    public const CONFIRM_SIGNER        = 'confirm_signer';
    public const COMPLETE_BAA          = 'complete_baa';
    public const OPEN_SECURE_CHANNEL   = 'open_secure_channel';
    public const CONFIRM_RECEIPT_COUNT = 'confirm_receipt_count';
    public const REVIEW_ASSESSMENT     = 'review_assessment';
    public const CHOOSE_SCOPE          = 'choose_scope';
    public const APPROVE_SUBMISSION    = 'approve_submission';
    public const PROVIDE_INFORMATION   = 'provide_information';
    public const VERIFY_REIMBURSEMENT  = 'verify_reimbursement';
    public const REVIEW_CLOSEOUT       = 'review_closeout';

    // Hers. Opened when a practice asks a question through the decision page.
    public const ANSWER_QUESTION       = 'answer_question';

    public const OWNER_CLIENT       = 'client';
    public const OWNER_SOFT_APPEALS = 'soft_appeals';

    public const STATUS_OPEN      = 'open';
    public const STATUS_DONE      = 'done';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED   = 'expired';

    // The buttons the Recovery Room knows how to draw.
    public const ACTION_CONFIRM_RECEIPT = 'confirm_receipt';
    public const ACTION_READ_ASSESSMENT = 'read_assessment';
    public const ACTION_DECIDE          = 'decide';
    public const ACTION_APPROVE         = 'approve';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::CONFIRM_SIGNER,
            self::COMPLETE_BAA,
            self::OPEN_SECURE_CHANNEL,
            self::CONFIRM_RECEIPT_COUNT,
            self::REVIEW_ASSESSMENT,
            self::CHOOSE_SCOPE,
            self::APPROVE_SUBMISSION,
            self::PROVIDE_INFORMATION,
            self::VERIFY_REIMBURSEMENT,
            self::REVIEW_CLOSEOUT,
            self::ANSWER_QUESTION,
        ];
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::STATUS_OPEN, self::STATUS_DONE, self::STATUS_CANCELLED, self::STATUS_EXPIRED];
    }

    public static function isValid(string $kind): bool
    {
        return in_array($kind, self::all(), true);
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::statuses(), true);
    }

    /** The title on the card. The plan's wording, verbatim. */
    public static function title(string $kind): string
    {
        return match ($kind) {
            self::CONFIRM_SIGNER        => 'Confirm authorized signer',
            self::COMPLETE_BAA          => 'Complete BAA',
            self::OPEN_SECURE_CHANNEL   => 'Open approved secure channel',
            self::CONFIRM_RECEIPT_COUNT => 'Confirm aggregate receipt count',
            self::REVIEW_ASSESSMENT     => 'Review the assessment',
            self::CHOOSE_SCOPE          => 'Choose recovery scope',
            self::APPROVE_SUBMISSION    => 'Approve submission in the secure workflow',
            self::PROVIDE_INFORMATION   => 'Provide requested information through the secure workflow',
            self::VERIFY_REIMBURSEMENT  => 'Verify payer reimbursement',
            self::REVIEW_CLOSEOUT       => 'Review closeout record',
            self::ANSWER_QUESTION       => 'Answer the practice\'s question',
            default                     => $kind,
        };
    }

    /** Who it waits on, by kind. */
    public static function owner(string $kind): string
    {
        return $kind === self::ANSWER_QUESTION ? self::OWNER_SOFT_APPEALS : self::OWNER_CLIENT;
    }

    /** The portal button, or null when the request has none. */
    public static function portalAction(string $kind): ?string
    {
        return match ($kind) {
            self::CONFIRM_RECEIPT_COUNT => self::ACTION_CONFIRM_RECEIPT,
            self::REVIEW_ASSESSMENT     => self::ACTION_READ_ASSESSMENT,
            self::CHOOSE_SCOPE          => self::ACTION_DECIDE,
            self::APPROVE_SUBMISSION    => self::ACTION_APPROVE,
            default                     => null,
        };
    }

    /**
     * Whether the request points the person at the approved secure channel.
     * Anything involving patient-level material does. Nothing in the portal
     * ever carries it, so these requests say where to go instead.
     */
    public static function directsToSecureChannel(string $kind): bool
    {
        return in_array($kind, [
            self::OPEN_SECURE_CHANNEL,
            self::APPROVE_SUBMISSION,
            self::PROVIDE_INFORMATION,
        ], true);
    }

    /**
     * The standing instructions for each kind. Safe by construction: none of
     * these sentences can carry a patient, and the per-request note she may
     * add is screened separately.
     */
    public static function instructions(string $kind): string
    {
        return match ($kind) {
            self::CONFIRM_SIGNER        => 'Name the person who signs agreements for your organization on the preferences page.',
            self::COMPLETE_BAA          => 'Read and sign the Business Associate Agreement in your Recovery Room.',
            self::OPEN_SECURE_CHANNEL   => 'Send the initial denial set through the approved secure route. Nothing at patient level goes through this portal or by email.',
            self::CONFIRM_RECEIPT_COUNT => 'We have recorded how many denials arrived. Confirm the count matches what you sent. Counts only, no claim detail.',
            self::REVIEW_ASSESSMENT     => 'Your assessment is ready in the Assessment section. It is written at aggregate level; the claim-level detail stays in the secure route.',
            self::CHOOSE_SCOPE          => 'Tell us what you want to do next: keep the assessment for internal use, ask for more information, choose a recovery scope, or take no further action.',
            self::APPROVE_SUBMISSION    => 'Review the appeal materials in the approved secure workflow, then record your approval or return it with a note under Approvals in this room. Nothing is sent to a payer without that approval.',
            self::PROVIDE_INFORMATION   => 'The information we need is claim-level, so please send it through the approved secure route rather than through this portal.',
            self::VERIFY_REIMBURSEMENT  => 'Confirm the payer reimbursement that actually arrived, so the recovery can be verified.',
            self::REVIEW_CLOSEOUT       => 'Read the closeout record and confirm the access and data-disposition steps.',
            self::ANSWER_QUESTION       => 'The practice asked for more information before deciding. Answer it here; the answer appears in their Recovery Room.',
            default                     => '',
        };
    }

    public static function statusLabel(string $status, bool $forClient = false): string
    {
        return match ($status) {
            self::STATUS_OPEN      => $forClient ? 'Waiting on you' : 'Open',
            self::STATUS_DONE      => 'Done',
            self::STATUS_CANCELLED => 'Withdrawn',
            self::STATUS_EXPIRED   => 'Expired',
            default                => $status,
        };
    }
}
