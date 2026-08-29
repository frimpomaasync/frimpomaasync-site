<?php
declare(strict_types=1);

namespace SoftAppeals\Auth;

use SoftAppeals\Repositories\MembershipRepository;
use SoftAppeals\Repositories\UserRepository;
use SoftAppeals\Security\Csrf;
use SoftAppeals\Security\Hmac;
use SoftAppeals\Security\RateLimiter;
use SoftAppeals\Services\AuditService;
use SoftAppeals\Support\Clock;

/**
 * Admin authentication.
 *
 * Password hashing through the current PHP APIs, session rotation on success,
 * throttling on both the account and the caller, and an audit row for every
 * outcome including the refusals.
 *
 * Client authentication is passwordless and arrives in Phase 3. This class
 * carries the admin half only, which is what the Desk needs.
 */
final class AuthService
{
    /**
     * Argon2id where it is compiled in, bcrypt otherwise. Hostinger's PHP 8.3
     * almost certainly has Argon2id, but a hosting change must not lock her out
     * of her own Desk, so the choice is made at runtime and password_verify
     * reads whichever algorithm produced the stored hash.
     */
    private const ARGON_OPTIONS = [
        'memory_cost' => 65536,  // 64 MB
        'time_cost'   => 4,
        'threads'     => 2,
    ];

    private UserRepository $users;
    private MembershipRepository $memberships;
    private SessionManager $session;
    private Csrf $csrf;
    private RateLimiter $limiter;
    private AuditService $audit;
    private Clock $clock;
    private Hmac $hmac;

    public function __construct(
        UserRepository $users,
        MembershipRepository $memberships,
        SessionManager $session,
        Csrf $csrf,
        RateLimiter $limiter,
        AuditService $audit,
        Clock $clock,
        Hmac $hmac
    ) {
        $this->users = $users;
        $this->memberships = $memberships;
        $this->session = $session;
        $this->csrf = $csrf;
        $this->limiter = $limiter;
        $this->audit = $audit;
        $this->clock = $clock;
        $this->hmac = $hmac;
    }

    public static function hashPassword(string $plain): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $hash = password_hash($plain, PASSWORD_ARGON2ID, self::ARGON_OPTIONS);
        } else {
            $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
        }
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('The password could not be hashed.');
        }
        return $hash;
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Attempt an admin login.
     *
     * Returns the user id on success and null on failure. The caller shows one
     * message for every failure: an unknown account and a wrong password are
     * indistinguishable from outside, so the form cannot be used to find out
     * which addresses exist.
     *
     * Throws RateLimitException when either the account or the caller has had
     * too many attempts.
     */
    public function attemptAdminLogin(string $email, string $password): ?string
    {
        $email = self::normalizeEmail($email);
        $ipDigest = $this->hmac->ipDigest('login');

        // Both buckets are counted before anything else. The account bucket
        // stops one address being ground down; the IP bucket stops one caller
        // working through a list of addresses.
        $this->limiter->hit('admin.login', 'email:' . $email);
        $this->limiter->hit('admin.login', 'ip:' . $ipDigest);

        $user = $this->users->findByEmail($email);

        // A dummy verify against a real hash when the account is unknown, so
        // that a missing account and a wrong password take the same time.
        if ($user === null || ($user['password_hash'] ?? '') === '') {
            password_verify($password, '$2y$12$usesomesillystringfooooooooooooooooooooooooooooooooooooo');
            $this->audit->record('auth.login', 'failure', 'user', null, ['reason' => 'unknown_account']);
            return null;
        }

        if ((int) $user['active'] !== 1) {
            $this->audit->record('auth.login', 'failure', 'user', (string) $user['id'], ['reason' => 'inactive']);
            return null;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->audit->record('auth.login', 'failure', 'user', (string) $user['id'], ['reason' => 'bad_password']);
            return null;
        }

        $userId = (string) $user['id'];

        // Staff only. A client contact must not be able to reach the Desk even
        // if a password is ever set on their row.
        if (!$this->memberships->hasAnyStaffRole($userId)) {
            $this->audit->record('auth.login', 'failure', 'user', $userId, ['reason' => 'not_staff']);
            return null;
        }

        // Re-hash when the cost or the algorithm has moved on. Free upgrade on
        // the one occasion the plaintext is legitimately in hand.
        if (password_needs_rehash(
            (string) $user['password_hash'],
            defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT,
            defined('PASSWORD_ARGON2ID') ? self::ARGON_OPTIONS : ['cost' => 12]
        )) {
            $this->users->updatePasswordHash($userId, self::hashPassword($password));
        }

        $this->session->establish(SessionManager::KIND_ADMIN, $userId, null);
        $this->csrf->rotate();

        $this->users->markLoggedIn($userId, $this->clock->nowUtc());
        $this->limiter->clear('admin.login', 'email:' . $email);
        $this->limiter->clear('admin.login', 'ip:' . $ipDigest);

        $this->audit->record('auth.login', 'success', 'user', $userId);

        return $userId;
    }

    public function logout(): void
    {
        $userId = $this->session->userId();
        if ($userId !== null) {
            $this->audit->record('auth.logout', 'success', 'user', $userId);
        }
        $this->session->destroy();
    }

    /**
     * The signed-in user's row, or null.
     *
     * The row is read from the database on every request rather than trusted
     * from the session, so deactivating an account takes effect on their next
     * click instead of whenever their session happens to expire.
     *
     * @return array<string,mixed>|null
     */
    public function currentUser(): ?array
    {
        $userId = $this->session->userId();
        if ($userId === null) {
            return null;
        }
        $user = $this->users->find($userId);
        if ($user === null || (int) $user['active'] !== 1) {
            $this->session->destroy();
            return null;
        }
        return $user;
    }
}
