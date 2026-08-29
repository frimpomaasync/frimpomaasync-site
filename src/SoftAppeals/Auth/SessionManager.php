<?php
declare(strict_types=1);

namespace SoftAppeals\Auth;

use SoftAppeals\Config;

/**
 * Server-side sessions, with the timeouts from section 18.3.
 *
 * Three session kinds, three sets of timeouts:
 *
 *   admin    30 minutes idle, 12 hours absolute
 *   client   20 minutes idle,  8 hours absolute
 *   signing  15 minutes idle, 30 minutes absolute
 *
 * The signing session is short on purpose. It exists for the length of one
 * signature, not for a working day, so a signing page left open on a shared
 * clinic machine stops being usable within half an hour.
 *
 * The session ID rotates after login and on any privilege change. Rotating
 * invalidates a session ID an attacker may have planted before the person
 * logged in, which is the whole of session fixation.
 */
final class SessionManager
{
    public const KIND_ADMIN   = 'admin';
    public const KIND_CLIENT  = 'client';
    public const KIND_SIGNING = 'signing';

    /** kind => [idle seconds, absolute seconds] */
    private const TIMEOUTS = [
        self::KIND_ADMIN   => [1800, 43200],
        self::KIND_CLIENT  => [1200, 28800],
        self::KIND_SIGNING => [900, 1800],
    ];

    private const COOKIE = 'sa_session';

    private Config $config;
    private bool $started = false;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        // Secure is unconditional. The host forces HTTPS and .htaccess sends
        // HSTS, so there is no plain-HTTP case to accommodate.
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_name(self::COOKIE);

        // The ID must come from the cookie, never from a query string.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '5');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', '1');

        session_start();
        $this->started = true;

        $this->enforceTimeouts();
    }

    /**
     * Rotate the session ID, keeping the data.
     *
     * Called after login and after any change of privilege. The CSRF seed is
     * rotated with it by the caller, so a token minted before the rotation
     * stops working at the same moment the ID does.
     */
    public function rotate(): void
    {
        $this->start();
        session_regenerate_id(true);
        $this->set('sa_started_at', time());
        $this->touch();
    }

    /** Establish an authenticated session of one kind. */
    public function establish(string $kind, string $userId, ?string $organizationId = null): void
    {
        if (!isset(self::TIMEOUTS[$kind])) {
            throw new \RuntimeException('Unknown session kind: ' . $kind);
        }
        $this->start();
        $this->rotate();
        $this->set('sa_kind', $kind);
        $this->set('sa_user_id', $userId);
        $this->set('sa_organization_id', $organizationId);
    }

    public function kind(): ?string
    {
        $kind = $this->get('sa_kind');
        return is_string($kind) ? $kind : null;
    }

    public function userId(): ?string
    {
        $id = $this->get('sa_user_id');
        return is_string($id) && $id !== '' ? $id : null;
    }

    public function organizationId(): ?string
    {
        $id = $this->get('sa_organization_id');
        return is_string($id) && $id !== '' ? $id : null;
    }

    public function isAuthenticated(): bool
    {
        return $this->userId() !== null && $this->kind() !== null;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        $this->start();
        return $_SESSION[$key] ?? $fallback;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    /**
     * A message that survives exactly one redirect. Used for "check your
     * email" and for a login failure, so neither ends up in a query string.
     */
    public function flash(string $key, ?string $value = null): ?string
    {
        $this->start();
        if ($value !== null) {
            $_SESSION['sa_flash'][$key] = $value;
            return null;
        }
        $out = $_SESSION['sa_flash'][$key] ?? null;
        unset($_SESSION['sa_flash'][$key]);
        return is_string($out) ? $out : null;
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->start();
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        session_destroy();
        $this->started = false;
    }

    public function touch(): void
    {
        $this->set('sa_last_seen', time());
    }

    /**
     * Kill the session once either clock runs out.
     *
     * Absolute first: a session that has been alive for twelve hours ends even
     * if somebody kept it warm, which is what an idle timeout alone cannot do.
     */
    private function enforceTimeouts(): void
    {
        $kind = $this->kind();
        if ($kind === null || !isset(self::TIMEOUTS[$kind])) {
            return;
        }
        [$idle, $absolute] = self::TIMEOUTS[$kind];

        $startedAt = (int) $this->get('sa_started_at', 0);
        $lastSeen  = (int) $this->get('sa_last_seen', 0);
        $now = time();

        if ($startedAt > 0 && ($now - $startedAt) > $absolute) {
            $this->destroy();
            $this->start();
            return;
        }
        if ($lastSeen > 0 && ($now - $lastSeen) > $idle) {
            $this->destroy();
            $this->start();
            return;
        }
        $this->touch();
    }

    /** @return array{idle:int,absolute:int} */
    public static function timeoutsFor(string $kind): array
    {
        $t = self::TIMEOUTS[$kind] ?? self::TIMEOUTS[self::KIND_CLIENT];
        return ['idle' => $t[0], 'absolute' => $t[1]];
    }
}
