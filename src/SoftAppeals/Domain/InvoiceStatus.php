<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * The life of one invoice.
 *
 * Draft is invoice-ready made concrete: the rows are gathered and summed and
 * nothing has gone to the practice. Issued is the moment the practice is
 * told. Paid is her word that it was paid. Void takes the rows back off it so
 * they can be invoiced again, and keeps the row so the number is never reused.
 *
 * Nothing here moves money. Section 19.9: payer reimbursement goes to the
 * practice, and the practice pays the fee by whatever means it pays anything.
 * This application records that it happened and never handles a cent.
 */
final class InvoiceStatus
{
    public const DRAFT  = 'draft';
    public const ISSUED = 'issued';
    public const PAID   = 'paid';
    public const VOID   = 'void';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DRAFT, self::ISSUED, self::PAID, self::VOID];
    }

    /** @return array<string,list<string>> */
    public static function transitions(): array
    {
        return [
            self::DRAFT  => [self::ISSUED, self::VOID],
            self::ISSUED => [self::PAID, self::VOID],
            self::PAID   => [],
            self::VOID   => [],
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::transitions()[$from] ?? [], true);
    }

    /** True while the invoice counts towards what is owed. */
    public static function counts(string $status): bool
    {
        return in_array($status, [self::ISSUED, self::PAID], true);
    }

    public static function staffLabel(string $status): string
    {
        return match ($status) {
            self::DRAFT  => 'Draft, not issued',
            self::ISSUED => 'Issued, unpaid',
            self::PAID   => 'Paid',
            self::VOID   => 'Void',
            default      => $status,
        };
    }

    public static function clientLabel(string $status): string
    {
        return match ($status) {
            self::DRAFT  => 'Being prepared',
            self::ISSUED => 'Issued',
            self::PAID   => 'Paid, thank you',
            self::VOID   => 'Withdrawn',
            default      => 'In progress',
        };
    }
}
