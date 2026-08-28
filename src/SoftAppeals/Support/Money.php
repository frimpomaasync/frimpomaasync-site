<?php
declare(strict_types=1);

namespace SoftAppeals\Support;

/**
 * Money, in integer cents, section 19.
 *
 * Nothing here is ever a float. A dollar figure typed on the Desk is split at
 * the decimal point as text and assembled into cents with integer arithmetic,
 * and a fee is calculated with intdiv and half-up rounding exactly as the
 * plan's worked example does. The one place a float could sneak in is the
 * parse, and that is why the parse refuses anything it cannot read exactly.
 */
final class Money
{
    /** The largest figure the Desk will accept: a hundred million dollars. */
    private const MAX_CENTS = 10_000_000_000;

    /**
     * "12,345.67" to 1234567. Null when the text is not a plain dollar figure.
     *
     * Accepts an optional leading $, thousands commas, and at most two decimal
     * places. Refuses a negative, a third decimal, or anything with letters in
     * it, because a figure that had to be guessed at is not a figure.
     */
    public static function parseCents(string $text): ?int
    {
        $text = trim($text);
        $text = ltrim($text, '$');
        $text = str_replace([',', ' '], '', $text);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $text, $m) !== 1) {
            return null;
        }
        $dollars = (int) $m[1];
        $fraction = $m[2] ?? '';
        $cents = $fraction === '' ? 0 : (int) str_pad($fraction, 2, '0', STR_PAD_RIGHT);
        $total = $dollars * 100 + $cents;
        if ($total > self::MAX_CENTS) {
            return null;
        }
        return $total;
    }

    /** 1234567 to "$12,345.67". */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        $dollars = intdiv($cents, 100);
        $rest = $cents % 100;
        return $sign . '$' . number_format($dollars) . '.' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT);
    }

    /**
     * The fee on a verified reimbursement, at a rate in basis points, rounded
     * half up. The plan's own example: 240000 cents at 2500 bps is 60000.
     */
    public static function feeCents(int $verifiedCents, int $rateBps): int
    {
        if ($verifiedCents < 0 || $rateBps < 0) {
            throw new \RuntimeException('A fee cannot be calculated on a negative figure.');
        }
        return intdiv(($verifiedCents * $rateBps) + 5000, 10000);
    }
}
