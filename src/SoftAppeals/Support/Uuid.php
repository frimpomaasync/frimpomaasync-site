<?php
declare(strict_types=1);

namespace SoftAppeals\Support;

/**
 * UUIDv4 and the human-readable public references.
 *
 * Primary keys are CHAR(36) UUIDv4 rather than BINARY(16). The plan allows
 * either. Text keys stay readable in an audit export, survive a SQLite and a
 * MySQL migration unchanged, and at a volume of a handful of practices the
 * storage difference is not measurable.
 *
 * Public references are what a person says out loud on a call. They are
 * separate from the primary key on purpose: SA-ORG-8F3K2Q can be read down a
 * phone line, and it reveals nothing about how many organizations exist.
 */
final class Uuid
{
    /**
     * Crockford-style alphabet for public references. I, L, O, U and the digits
     * 0 and 1 are absent, so a reference cannot be misread or mistyped between
     * the ambiguous pairs.
     */
    private const REF_ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }

    /**
     * A public reference such as SA-ORG-8F3K2Q.
     *
     * $kind is a short uppercase noun: ORG, ENG, INT, DOC, BATCH.
     */
    public static function publicRef(string $kind, int $length = 6): string
    {
        $kind = strtoupper(preg_replace('/[^A-Za-z]/', '', $kind) ?? '');
        if ($kind === '') {
            $kind = 'REF';
        }
        $max = strlen(self::REF_ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::REF_ALPHABET[random_int(0, $max)];
        }
        return 'SA-' . $kind . '-' . $out;
    }
}
