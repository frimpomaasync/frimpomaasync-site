<?php
declare(strict_types=1);

namespace SoftAppeals\Security;

/**
 * Keyed digests, in one place.
 *
 * The rule this class exists to enforce: a raw IP address is never stored, and
 * neither is a raw one-time token. `sa-lead.php` has done the IP part correctly
 * since it was written, and this generalises that behaviour rather than
 * inventing a second approach.
 *
 * Every digest is purpose-separated. A digest of the same IP for the login
 * throttle and for the audit trail produce different values, so a match in one
 * table cannot be used to look up rows in another.
 */
final class Hmac
{
    private string $secret;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \RuntimeException('The HMAC secret is too short.');
        }
        $this->secret = $secret;
    }

    /** A digest bound to a purpose. 64 lowercase hex characters. */
    public function digest(string $purpose, string $value): string
    {
        return hash_hmac('sha256', $purpose . "\0" . $value, $this->secret);
    }

    /**
     * The caller's IP, digested. Never the address itself.
     *
     * REMOTE_ADDR is used and no forwarded header is trusted: on this host the
     * application sits behind the web server directly, and honouring
     * X-Forwarded-For would let a caller choose their own rate-limit bucket.
     */
    public function ipDigest(string $purpose = 'audit'): string
    {
        return $this->digest('ip:' . $purpose, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    /**
     * The caller's user agent, digested and truncated first.
     *
     * A user agent is not identifying on its own but it is fingerprintable in
     * combination, so the stored form is a digest and the input is capped so a
     * caller cannot send a megabyte of header to slow the hash down.
     */
    public function userAgentDigest(string $purpose = 'audit'): string
    {
        $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
        return $this->digest('ua:' . $purpose, $agent);
    }

    /**
     * The stored form of a one-time token. The token itself is in the email and
     * nowhere else, so a database copy cannot be replayed against a client.
     */
    public function tokenDigest(string $purpose, string $token): string
    {
        return $this->digest('token:' . $purpose, $token);
    }

    /** Constant-time comparison, so a digest check cannot be timed. */
    public static function matches(string $expected, string $given): bool
    {
        return hash_equals($expected, $given);
    }

    /**
     * A fresh token. 32 random bytes, URL-safe, no padding.
     * Section 10.3: 32 bytes minimum for an invitation token.
     */
    public static function newToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /**
     * A six-digit verification code, uniformly distributed.
     *
     * random_int is used rather than a modulo of random_bytes so that every
     * code from 000000 to 999999 is equally likely. Returned as a string so a
     * leading zero survives.
     */
    public static function newNumericCode(int $digits = 6): string
    {
        $max = (10 ** $digits) - 1;
        return str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);
    }
}
