<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * The engagement state machine, from section 7 of the plan.
 *
 * The transition table is the gate. A browser calling a later endpoint directly
 * cannot skip a required step, because every transition is looked up here on
 * the server before it is written. That is what keeps "secure intake ready"
 * unreachable until the BAA is actually executed.
 *
 * Phase 1 defines the machine and proves it. Phases 2 onward drive it.
 */
final class Stage
{
    // Pre-engagement.
    public const INQUIRY_RECEIVED       = 'inquiry_received';
    public const FIT_REVIEW             = 'fit_review';
    public const DECLINED               = 'declined';
    public const TERMS_READY            = 'terms_ready';
    public const TERMS_SENT             = 'terms_sent';
    public const PREFERENCES_CONFIRMED  = 'preferences_confirmed';
    public const BAA_PENDING            = 'baa_pending';
    public const BAA_EXECUTED           = 'baa_executed';
    public const REVIEW_AUTH_PENDING    = 'review_auth_pending';
    public const REVIEW_AUTH_EXECUTED   = 'review_auth_executed';
    public const SECURE_INTAKE_READY    = 'secure_intake_ready';

    // Assessment.
    public const RECEIPT_CONFIRMED      = 'receipt_confirmed';
    public const ASSESSMENT_IN_PROGRESS = 'assessment_in_progress';
    public const ASSESSMENT_QA          = 'assessment_quality_review';
    public const ASSESSMENT_DELIVERED   = 'assessment_delivered';
    public const CLIENT_DECISION_PENDING = 'client_decision_pending';

    // Recovery.
    public const RECOVERY_SCOPE_SELECTED = 'recovery_scope_selected';
    public const RECOVERY_AGREEMENT_PENDING  = 'recovery_agreement_pending';
    public const RECOVERY_AGREEMENT_EXECUTED = 'recovery_agreement_executed';
    public const RECOVERY_ACTIVE         = 'recovery_active';

    // Closeout.
    public const RECONCILIATION         = 'financial_reconciliation';
    public const FINAL_REPORT           = 'final_report';
    public const ACCESS_REVIEW          = 'access_review';
    public const DATA_DISPOSITION       = 'data_disposition_confirmed';
    public const CLOSED                 = 'closed';
    public const CLOSED_NO_RECOVERY     = 'closed_without_recovery';

    /**
     * from => the stages it may move to.
     *
     * Declined and closed are terminal. Nothing transitions out of them, which
     * is why a declined inquiry cannot be quietly revived into an engagement
     * without a new record and a new audit trail.
     *
     * @return array<string,list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::INQUIRY_RECEIVED  => [self::FIT_REVIEW],
            self::FIT_REVIEW        => [self::DECLINED, self::TERMS_READY],
            self::TERMS_READY       => [self::TERMS_SENT, self::DECLINED],

            // Terms can be re-sent. The stage does not move, the token rotates
            // and a second communication row is written, which is how the Desk
            // shows "sent twice" rather than losing the first send.
            self::TERMS_SENT        => [self::TERMS_SENT, self::PREFERENCES_CONFIRMED, self::DECLINED],

            self::PREFERENCES_CONFIRMED => [self::BAA_PENDING],
            self::BAA_PENDING           => [self::BAA_EXECUTED],
            self::BAA_EXECUTED          => [self::REVIEW_AUTH_PENDING],
            self::REVIEW_AUTH_PENDING   => [self::REVIEW_AUTH_EXECUTED],

            // The PHI gate. Nothing before this point may receive claim files.
            self::REVIEW_AUTH_EXECUTED  => [self::SECURE_INTAKE_READY],

            self::SECURE_INTAKE_READY    => [self::RECEIPT_CONFIRMED],
            self::RECEIPT_CONFIRMED      => [self::ASSESSMENT_IN_PROGRESS],
            self::ASSESSMENT_IN_PROGRESS => [self::ASSESSMENT_QA],
            self::ASSESSMENT_QA          => [self::ASSESSMENT_IN_PROGRESS, self::ASSESSMENT_DELIVERED],
            self::ASSESSMENT_DELIVERED   => [self::CLIENT_DECISION_PENDING],

            self::CLIENT_DECISION_PENDING     => [self::RECOVERY_SCOPE_SELECTED, self::CLOSED_NO_RECOVERY],
            self::RECOVERY_SCOPE_SELECTED     => [self::RECOVERY_AGREEMENT_PENDING],
            self::RECOVERY_AGREEMENT_PENDING  => [self::RECOVERY_AGREEMENT_EXECUTED],
            self::RECOVERY_AGREEMENT_EXECUTED => [self::RECOVERY_ACTIVE],
            self::RECOVERY_ACTIVE             => [self::RECONCILIATION, self::CLOSED_NO_RECOVERY],

            self::RECONCILIATION    => [self::FINAL_REPORT],
            self::FINAL_REPORT      => [self::ACCESS_REVIEW],
            self::ACCESS_REVIEW     => [self::DATA_DISPOSITION],
            self::DATA_DISPOSITION  => [self::CLOSED],

            self::CLOSED             => [],
            self::CLOSED_NO_RECOVERY => [],
            self::DECLINED           => [],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::transitions());
    }

    public static function isValid(string $stage): bool
    {
        return array_key_exists($stage, self::transitions());
    }

    public static function isTerminal(string $stage): bool
    {
        return self::isValid($stage) && self::transitions()[$stage] === [];
    }

    public static function canMove(string $from, string $to): bool
    {
        if (!self::isValid($from) || !self::isValid($to)) {
            return false;
        }
        return in_array($to, self::transitions()[$from], true);
    }

    /**
     * True once the engagement is permitted to receive claim-level material
     * through the secure channel. Nothing in this application stores that
     * material; this only says the paperwork gate has been passed.
     */
    public static function phiGatePassed(string $stage): bool
    {
        return in_array($stage, [
            self::SECURE_INTAKE_READY,
            self::RECEIPT_CONFIRMED,
            self::ASSESSMENT_IN_PROGRESS,
            self::ASSESSMENT_QA,
            self::ASSESSMENT_DELIVERED,
            self::CLIENT_DECISION_PENDING,
            self::RECOVERY_SCOPE_SELECTED,
            self::RECOVERY_AGREEMENT_PENDING,
            self::RECOVERY_AGREEMENT_EXECUTED,
            self::RECOVERY_ACTIVE,
            self::RECONCILIATION,
            self::FINAL_REPORT,
            self::ACCESS_REVIEW,
            self::DATA_DISPOSITION,
            self::CLOSED,
        ], true);
    }

    /** What the client sees. Never the internal token. */
    public static function clientLabel(string $stage): string
    {
        return match ($stage) {
            self::INQUIRY_RECEIVED, self::FIT_REVIEW      => 'Under review',
            self::DECLINED                                => 'Closed',
            self::TERMS_READY, self::TERMS_SENT           => 'Terms with you',
            self::PREFERENCES_CONFIRMED                   => 'Preferences confirmed',
            self::BAA_PENDING                             => 'Agreement with you to sign',
            self::BAA_EXECUTED, self::REVIEW_AUTH_PENDING => 'Second agreement with you to sign',
            self::REVIEW_AUTH_EXECUTED                    => 'Paperwork complete',
            self::SECURE_INTAKE_READY                     => 'Ready for your denials',
            self::RECEIPT_CONFIRMED                       => 'Denials received',
            self::ASSESSMENT_IN_PROGRESS, self::ASSESSMENT_QA => 'Review in progress',
            self::ASSESSMENT_DELIVERED                    => 'Review delivered',
            self::CLIENT_DECISION_PENDING                 => 'Your decision',
            self::RECOVERY_SCOPE_SELECTED,
            self::RECOVERY_AGREEMENT_PENDING              => 'Recovery agreement with you',
            self::RECOVERY_AGREEMENT_EXECUTED             => 'Recovery starting',
            self::RECOVERY_ACTIVE                         => 'Recovery active',
            self::RECONCILIATION                          => 'Reconciling',
            self::FINAL_REPORT                            => 'Final report',
            self::ACCESS_REVIEW, self::DATA_DISPOSITION   => 'Closing out',
            self::CLOSED                                  => 'Closed',
            self::CLOSED_NO_RECOVERY                      => 'Closed, no recovery',
            default                                        => 'In progress',
        };
    }

    /** The four buckets the Desk pipeline counts. */
    public static function pipelineBucket(string $stage): string
    {
        if (in_array($stage, [self::INQUIRY_RECEIVED, self::FIT_REVIEW, self::TERMS_READY], true)) {
            return 'inquiry';
        }
        if (in_array($stage, [self::TERMS_SENT, self::PREFERENCES_CONFIRMED], true)) {
            return 'terms_sent';
        }
        if (in_array($stage, [self::DECLINED, self::CLOSED, self::CLOSED_NO_RECOVERY], true)) {
            return 'closed';
        }
        if (in_array($stage, [
            self::BAA_PENDING,
            self::BAA_EXECUTED,
            self::REVIEW_AUTH_PENDING,
            self::REVIEW_AUTH_EXECUTED,
        ], true)) {
            return 'signing';
        }
        return 'active';
    }
}
