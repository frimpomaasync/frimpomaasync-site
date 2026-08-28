<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * The shape of one recovery row, section 11.1 and section 19.
 *
 * Three kinds. A VERIFIED row is money that has actually reached the practice
 * and been checked; it is the only kind that creates a fee. An ADJUSTMENT is
 * part of a verified figure taken back, a REVERSAL is all of it, and both are
 * new rows that name the row they take from. Nothing is ever written over.
 *
 * The verification source is a category, never a document. What was looked
 * at to verify the money is claim-level and lives in the secure route; the
 * row says only what kind of thing it was.
 */
final class RecoveryRecord
{
    public const KIND_VERIFIED   = 'verified';
    public const KIND_ADJUSTMENT = 'adjustment';
    public const KIND_REVERSAL   = 'reversal';

    public const SOURCE_REMITTANCE   = 'remittance';
    public const SOURCE_BANK_DEPOSIT = 'bank_deposit';
    public const SOURCE_PRACTICE     = 'practice_confirmation';
    public const SOURCE_PAYER_PORTAL = 'payer_portal';
    public const SOURCE_OTHER        = 'other';

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::KIND_VERIFIED, self::KIND_ADJUSTMENT, self::KIND_REVERSAL];
    }

    /** @return list<string> */
    public static function sources(): array
    {
        return [
            self::SOURCE_REMITTANCE,
            self::SOURCE_BANK_DEPOSIT,
            self::SOURCE_PRACTICE,
            self::SOURCE_PAYER_PORTAL,
            self::SOURCE_OTHER,
        ];
    }

    public static function isValidKind(string $kind): bool
    {
        return in_array($kind, self::kinds(), true);
    }

    public static function isValidSource(string $source): bool
    {
        return in_array($source, self::sources(), true);
    }

    /** True for the two kinds that take money off a verified row. */
    public static function takesBack(string $kind): bool
    {
        return in_array($kind, [self::KIND_ADJUSTMENT, self::KIND_REVERSAL], true);
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_VERIFIED   => 'Verified reimbursement',
            self::KIND_ADJUSTMENT => 'Adjustment',
            self::KIND_REVERSAL   => 'Reversal',
            default               => $kind,
        };
    }

    /** What the practice reads. */
    public static function kindClientLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_VERIFIED   => 'Reimbursement verified',
            self::KIND_ADJUSTMENT => 'Part of a reimbursement taken back by the payer',
            self::KIND_REVERSAL   => 'A reimbursement reversed by the payer',
            default               => 'Updated',
        };
    }

    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            self::SOURCE_REMITTANCE   => 'Remittance advice',
            self::SOURCE_BANK_DEPOSIT => 'Bank deposit',
            self::SOURCE_PRACTICE     => 'Confirmed by the practice',
            self::SOURCE_PAYER_PORTAL => 'Payer portal',
            self::SOURCE_OTHER        => 'Other',
            default                   => $source,
        };
    }

    /** The timeline line, written for the practice. */
    public static function timelineLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_VERIFIED   => 'A reimbursement was verified as received',
            self::KIND_ADJUSTMENT => 'Part of a verified reimbursement was adjusted',
            self::KIND_REVERSAL   => 'A verified reimbursement was reversed',
            default               => 'A recovery record was updated',
        };
    }
}
