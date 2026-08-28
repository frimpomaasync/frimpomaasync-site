<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * The six document types of section 14.1, and what each one does to the
 * engagement it belongs to.
 *
 * All six are named here from the start even though Phase 4 only drives two of
 * them, for the same reason every role was named in Phase 1: adding the third
 * later is a row in a table and a template, not a schema change and not a new
 * state machine.
 *
 * The two Phase 4 drives are the BAA and the Complimentary Review
 * Authorization, and they are the two that matter most, because together they
 * are the PHI gate. Section 6 Gate A says no claim-level material moves before
 * both are executed, and Stage::phiGatePassed() will not return true until the
 * engagement has walked through both of them.
 */
final class DocumentKind
{
    public const BAA                  = 'baa';
    public const REVIEW_AUTHORIZATION = 'review_authorization';
    public const RECOVERY_AGREEMENT   = 'recovery_agreement';
    public const APPROVED_SCOPE       = 'approved_scope';
    public const SUBMISSION_APPROVAL  = 'submission_approval';
    public const CLOSEOUT             = 'closeout';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::BAA,
            self::REVIEW_AUTHORIZATION,
            self::RECOVERY_AGREEMENT,
            self::APPROVED_SCOPE,
            self::SUBMISSION_APPROVAL,
            self::CLOSEOUT,
        ];
    }

    /**
     * The two Phase 4 actually generates, sends and executes.
     *
     * The other four are defined and refused. A Desk button that generated a
     * recovery agreement Phase 4 has no workflow for would produce a document
     * nobody could countersign and an engagement stuck at a stage with no way
     * out of it.
     *
     * @return list<string>
     */
    public static function live(): array
    {
        return [self::BAA, self::REVIEW_AUTHORIZATION];
    }

    public static function isValid(string $kind): bool
    {
        return in_array($kind, self::all(), true);
    }

    public static function isLive(string $kind): bool
    {
        return in_array($kind, self::live(), true);
    }

    public static function label(string $kind): string
    {
        return match ($kind) {
            self::BAA                  => 'Business Associate Agreement',
            self::REVIEW_AUTHORIZATION => 'Complimentary Review Authorization',
            self::RECOVERY_AGREEMENT   => 'Recovery Services Agreement',
            self::APPROVED_SCOPE       => 'Approved Recovery Scope',
            self::SUBMISSION_APPROVAL  => 'Submission Approval Record',
            self::CLOSEOUT             => 'Closeout and Data-Disposition Record',
            default                    => $kind,
        };
    }

    /** The short form, for a column heading or a reference line. */
    public static function shortLabel(string $kind): string
    {
        return match ($kind) {
            self::BAA                  => 'BAA',
            self::REVIEW_AUTHORIZATION => 'Review authorization',
            self::RECOVERY_AGREEMENT   => 'Recovery agreement',
            self::APPROVED_SCOPE       => 'Approved scope',
            self::SUBMISSION_APPROVAL  => 'Submission approval',
            self::CLOSEOUT             => 'Closeout record',
            default                    => $kind,
        };
    }

    /**
     * The stage an engagement must be sitting at before this document can be
     * generated at all.
     *
     * This is the gate that makes the order real. A BAA cannot be generated
     * before the practice has confirmed its preferences, because until then
     * nobody has named an authorized signer, and a signing link sent to nobody
     * is a link sent to whoever finds it.
     */
    public static function requiredStage(string $kind): ?string
    {
        return match ($kind) {
            self::BAA                  => Stage::PREFERENCES_CONFIRMED,
            self::REVIEW_AUTHORIZATION => Stage::BAA_EXECUTED,
            self::RECOVERY_AGREEMENT   => Stage::RECOVERY_SCOPE_SELECTED,
            default                    => null,
        };
    }

    /** The stage the engagement moves to when this document goes out. */
    public static function pendingStage(string $kind): ?string
    {
        return match ($kind) {
            self::BAA                  => Stage::BAA_PENDING,
            self::REVIEW_AUTHORIZATION => Stage::REVIEW_AUTH_PENDING,
            self::RECOVERY_AGREEMENT   => Stage::RECOVERY_AGREEMENT_PENDING,
            default                    => null,
        };
    }

    /** The stage the engagement moves to when this document is executed. */
    public static function executedStage(string $kind): ?string
    {
        return match ($kind) {
            self::BAA                  => Stage::BAA_EXECUTED,
            self::REVIEW_AUTHORIZATION => Stage::REVIEW_AUTH_EXECUTED,
            self::RECOVERY_AGREEMENT   => Stage::RECOVERY_AGREEMENT_EXECUTED,
            default                    => null,
        };
    }

    /**
     * Which client role is allowed to sign this.
     *
     * Every document Phase 4 handles is signed by the authorized signer and by
     * nobody else. Section 8.2 gives that role to one named person, and
     * "only assigned signers can sign" is checked against this and against the
     * contact the document was generated for, which are two separate things: a
     * second authorized signer at the same practice holds the role but is not
     * the person this document names.
     */
    public static function signerRole(string $kind): string
    {
        return Role::AUTHORIZED_SIGNER;
    }

    /**
     * Whether Soft Appeals has to countersign before the document is executed.
     *
     * Both agreements are two-party, so both are countersigned. A one-party
     * record, like a submission approval the practice gives on its own, is
     * executed the moment the client signs it. Phase 4 does not generate one of
     * those, and the flag is here so that the day it does, the executed path
     * does not need a second shape.
     */
    public static function requiresCountersignature(string $kind): bool
    {
        return match ($kind) {
            self::BAA, self::REVIEW_AUTHORIZATION, self::RECOVERY_AGREEMENT => true,
            default => false,
        };
    }

    /**
     * The document kind an engagement at this stage is waiting on, if any.
     *
     * The Desk reads this to decide which button to offer, so the offer follows
     * the state machine rather than a person remembering the order.
     */
    public static function nextForStage(string $stage): ?string
    {
        return match ($stage) {
            Stage::PREFERENCES_CONFIRMED => self::BAA,
            Stage::BAA_EXECUTED          => self::REVIEW_AUTHORIZATION,
            default                      => null,
        };
    }
}
