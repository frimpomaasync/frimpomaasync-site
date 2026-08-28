<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * The three choices that make up an engagement's terms: how the fee works,
 * which secure route the claim material will eventually take, and how often the
 * client hears from her.
 *
 * Kept together because they are set together, in one drawer, in one decision.
 * Each list is closed and each is checked on the server before it is stored.
 *
 * The fee list is short on purpose. `contingency_25` is her standard commercial
 * arrangement. `fixed` exists because Maryland's dental practice statute
 * requires a fixed fee rather than a percentage, which is a legal constraint and
 * not a pricing preference. `custom` and `scoped` exist so an arrangement that
 * does not fit either can still be recorded honestly instead of being forced
 * into a label that misdescribes it.
 *
 * No rate is stored for anything but `contingency_25`, and even there the rate
 * is basis points on a column of its own. A fee is never rendered from a label.
 */
final class EngagementTerms
{
    // Fee basis.
    public const FEE_NOT_SET        = 'not_set';
    public const FEE_CONTINGENCY_25 = 'contingency_25';
    public const FEE_FIXED          = 'fixed';
    public const FEE_CUSTOM         = 'custom';
    public const FEE_SCOPED         = 'scoped';

    // Secure-channel route, section 12.5. A route, never a credential and never
    // a secret URL. What is stored is which kind of channel was chosen.
    public const CHANNEL_CLIENT_SYSTEM = 'client_system';
    public const CHANNEL_SOFT_APPEALS  = 'soft_appeals_link';
    public const CHANNEL_DECIDE_LATER  = 'decide_later';

    // Communication cadence, section 13.2 question 1.
    public const CADENCE_WEEKLY     = 'weekly';
    public const CADENCE_BIWEEKLY   = 'biweekly';
    public const CADENCE_MONTHLY    = 'monthly';
    public const CADENCE_MILESTONES = 'milestones';

    /** @return array<string,string> */
    public static function feeBases(): array
    {
        return [
            self::FEE_NOT_SET        => 'Not set yet',
            self::FEE_CONTINGENCY_25 => 'Contingency, 25 percent of verified recovery',
            self::FEE_FIXED          => 'Fixed fee',
            self::FEE_CUSTOM         => 'Custom, written into the agreement',
            self::FEE_SCOPED         => 'Scoped project fee',
        ];
    }

    /** The basis points stored alongside the label, where one applies. */
    public static function feeRateBps(string $feeBasis): ?int
    {
        return $feeBasis === self::FEE_CONTINGENCY_25 ? 2500 : null;
    }

    /**
     * The sentence that goes in the terms email for this fee basis. Every one
     * of them says the same true thing in its own way: nothing is owed for the
     * assessment, and a fee only ever follows money that actually arrived.
     */
    public static function feeSentence(string $feeBasis): string
    {
        return match ($feeBasis) {
            self::FEE_CONTINGENCY_25 =>
                'If you go ahead with recovery work after the assessment, the fee is '
                . '25 percent of reimbursement that is actually recovered and verified. '
                . 'A claim that was already paid is not one you pay for.',
            self::FEE_FIXED =>
                'If you go ahead with recovery work after the assessment, the fee is a '
                . 'fixed amount agreed in writing before any work starts, not a share of '
                . 'what comes back. Maryland requires that arrangement for dental practices.',
            self::FEE_CUSTOM =>
                'If you go ahead with recovery work after the assessment, the fee is the '
                . 'one written into your agreement. Nothing is charged before you have '
                . 'read it and signed it.',
            self::FEE_SCOPED =>
                'If you go ahead with recovery work after the assessment, the fee is a '
                . 'project fee for a scope agreed in writing before any work starts.',
            default =>
                'The fee for recovery work is not set yet. It is agreed in writing before '
                . 'any work starts, and the assessment stays free either way.',
        };
    }

    /** @return array<string,string> */
    public static function secureChannels(): array
    {
        return [
            self::CHANNEL_CLIENT_SYSTEM => 'Their own approved environment',
            self::CHANNEL_SOFT_APPEALS  => 'The Soft Appeals approved transfer route',
            self::CHANNEL_DECIDE_LATER  => 'Decide with their compliance or IT',
        ];
    }

    /** @return array<string,string> */
    public static function cadences(): array
    {
        return [
            self::CADENCE_WEEKLY     => 'Weekly',
            self::CADENCE_BIWEEKLY   => 'Every two weeks',
            self::CADENCE_MONTHLY    => 'Monthly',
            self::CADENCE_MILESTONES => 'At major milestones only',
        ];
    }

    public static function isValidFee(string $value): bool
    {
        return array_key_exists($value, self::feeBases());
    }

    public static function isValidChannel(string $value): bool
    {
        return array_key_exists($value, self::secureChannels());
    }

    public static function isValidCadence(string $value): bool
    {
        return array_key_exists($value, self::cadences());
    }

    public static function feeLabel(string $value): string
    {
        return self::feeBases()[$value] ?? $value;
    }

    public static function channelLabel(?string $value): string
    {
        return $value === null ? 'Not chosen yet' : (self::secureChannels()[$value] ?? $value);
    }

    public static function cadenceLabel(?string $value): string
    {
        return $value === null ? 'Not chosen yet' : (self::cadences()[$value] ?? $value);
    }
}
