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
     * The kinds that have a workflow behind them.
     *
     * Phase 4 drove the BAA and the review authorization. Phase 6 adds the
     * Recovery Services Agreement and the Approved Recovery Scope, which are
     * generated together and signed one after the other. The last two are
     * defined and refused until the phase that drives them: a Desk button
     * that generated a closeout record nobody can execute would leave an
     * engagement stuck at a stage with no way out of it.
     *
     * @return list<string>
     */
    public static function live(): array
    {
        return [
            self::BAA,
            self::REVIEW_AUTHORIZATION,
            self::RECOVERY_AGREEMENT,
            self::APPROVED_SCOPE,
            self::CLOSEOUT,
        ];
    }

    /**
     * The kinds nobody signs. Phase 7 drives the first: the Closeout and
     * Data-Disposition Record is Soft Appeals' own statement of how the
     * engagement ended, sealed into the vault by DocumentService::seal() and
     * hashed like an agreement, and it carries no signature because there
     * is nothing in it for a practice to agree to. It is a record, and the
     * room says so.
     *
     * @return list<string>
     */
    public static function records(): array
    {
        return [self::CLOSEOUT];
    }

    public static function isRecord(string $kind): bool
    {
        return in_array($kind, self::records(), true);
    }

    /** True for every kind a practice signs. */
    public static function requiresSignature(string $kind): bool
    {
        return !self::isRecord($kind);
    }

    /**
     * The two documents Gate B is made of, section 6. Generated as a pair
     * from the recorded scope, sent together, signed one after the other.
     *
     * @return list<string>
     */
    public static function recoveryPair(): array
    {
        return [self::RECOVERY_AGREEMENT, self::APPROVED_SCOPE];
    }

    public static function isRecoveryPair(string $kind): bool
    {
        return in_array($kind, self::recoveryPair(), true);
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
            self::RECOVERY_AGREEMENT,
            self::APPROVED_SCOPE       => Stage::RECOVERY_SCOPE_SELECTED,
            self::CLOSEOUT             => Stage::DATA_DISPOSITION,
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
     * The three agreements are two-party, so all three are countersigned. The
     * Approved Recovery Scope is the practice's own statement of what it is
     * authorizing, so it is one-party: it is executed the moment the practice
     * signs it, and SigningService does that on the same request.
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
            Stage::PREFERENCES_CONFIRMED    => self::BAA,
            Stage::BAA_EXECUTED             => self::REVIEW_AUTHORIZATION,
            Stage::RECOVERY_SCOPE_SELECTED  => self::RECOVERY_AGREEMENT,
            default                         => null,
        };
    }
}
