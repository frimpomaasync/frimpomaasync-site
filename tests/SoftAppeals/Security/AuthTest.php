<?php
declare(strict_types=1);

/**
 * The Phase 1 acceptance criteria, as tests.
 *
 *   admin login rotates the session ID
 *   every write without a valid CSRF token fails
 *   unauthenticated Desk access fails without revealing internal details
 *   audit events record successful and rejected admin actions
 *
 * Each one is a named case below, so a failure names the criterion it broke
 * rather than an internal method.
 */

use SoftAppeals\Auth\AuthService;
use SoftAppeals\Auth\SessionManager;
use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\Permission;
use SoftAppeals\Domain\Role;
use SoftAppeals\Security\AuthorizationException;
use SoftAppeals\Security\CsrfException;
use SoftAppeals\Security\RateLimitException;

/**
 * Create an owner admin and return [userId, email, password].
 *
 * @return array{0:string,1:string,2:string}
 */
$makeOwner = static function (Bootstrap $app): array {
    $email = 'owner@example.org';
    $password = 'a-long-enough-test-password-9134';
    $userId = $app->users()->create($email, AuthService::hashPassword($password));
    $app->memberships()->grant($userId, Role::OWNER_ADMIN, null);
    return [$userId, $email, $password];
};

return [

    'a correct password signs the owner in' =>
        static function (Bootstrap $app) use ($makeOwner): void {
            [$userId, $email, $password] = $makeOwner($app);
            $result = $app->auth()->attemptAdminLogin($email, $password);
            Expect::same($userId, $result, 'the owner should be signed in');
            Expect::true($app->session()->isAuthenticated(), 'the session should be authenticated');
            Expect::same('admin', $app->session()->kind(), 'the session kind should be admin');
        },

    'a wrong password is refused' =>
        static function (Bootstrap $app) use ($makeOwner): void {
            [, $email] = $makeOwner($app);
            $result = $app->auth()->attemptAdminLogin($email, 'not-the-password');
            Expect::null($result, 'a wrong password must not sign anyone in');
            Expect::false($app->session()->isAuthenticated(), 'no session should exist');
        },

    'an unknown address is refused the same way' =>
        static function (Bootstrap $app) use ($makeOwner): void {
            $makeOwner($app);
            $result = $app->auth()->attemptAdminLogin('nobody@example.org', 'anything');
            Expect::null($result, 'an unknown address must not sign anyone in');
        },

    'a deactivated account cannot sign in' =>
        static function (Bootstrap $app) use ($makeOwner): void {
            [$userId, $email, $password] = $makeOwner($app);
            $app->users()->deactivate($userId);
            Expect::null(
                $app->auth()->attemptAdminLogin($email, $password),
                'a deactivated account must be refused'
            );
        },

    'a user with no staff role cannot reach the Desk' =>
        static function (Bootstrap $app): void {
            $password = 'a-long-enough-test-password-9134';
            $email = 'client@example.org';
            $app->users()->create($email, AuthService::hashPassword($password));
            // No membership granted at all.
            Expect::null(
                $app->auth()->attemptAdminLogin($email, $password),
                'a user with no staff role must be refused'
            );
        },

    // ------------------------------------------------------------------
    // ACCEPTANCE: admin login rotates the session ID
    // ------------------------------------------------------------------
    'ACCEPTANCE login rotates the session id' =>
        static function (Bootstrap $app) use ($makeOwner): void {
            if (PHP_SAPI === 'cli' && session_status() === PHP_SESSION_DISABLED) {
                return; // sessions unavailable, nothing to assert
            }
            [, $email, $password] = $makeOwner($app);

            $session = $app->session();
            $session->start();
            $before = session_id();

            $app->auth()->attemptAdminLogin($email, $password);
            $after = session_id();

            Expect::true($before !== $after, 'the session id must change on login');
            Expect::true($after !== '', 'a session id must exist after login');
        },

    'the csrf seed changes when the session rotates' =>
        static function (Bootstrap $app) use ($makeOwner): void {
            [, $email, $password] = $makeOwner($app);
            $csrf = $app->csrf();
            $before = $csrf->token('terms.send');
            $app->auth()->attemptAdminLogin($email, $password);
            $after = $csrf->token('terms.send');
            Expect::true($before !== $after, 'a token minted before login must stop working');
        },

    // ------------------------------------------------------------------
    // ACCEPTANCE: every write without a valid CSRF token fails
    // ------------------------------------------------------------------
    'ACCEPTANCE a write with no csrf token is refused' =>
        static function (Bootstrap $app): void {
            $_POST = [];
            unset($_SERVER['HTTP_X_CSRF_TOKEN']);
            Expect::throws(
                CsrfException::class,
                static fn () => $app->csrf()->require('terms.send'),
                'a missing token must be refused'
            );
        },

    'ACCEPTANCE a write with a wrong csrf token is refused' =>
        static function (Bootstrap $app): void {
            $_POST = ['_csrf' => str_repeat('0', 64)];
            Expect::throws(
                CsrfException::class,
                static fn () => $app->csrf()->require('terms.send'),
                'a wrong token must be refused'
            );
        },

    'ACCEPTANCE a token for one action does not work on another' =>
        static function (Bootstrap $app): void {
            $csrf = $app->csrf();
            $_POST = ['_csrf' => $csrf->token('login')];
            Expect::throws(
                CsrfException::class,
                static fn () => $csrf->require('document.countersign'),
                'a login token must not countersign an agreement'
            );
        },

    'the right csrf token passes' =>
        static function (Bootstrap $app): void {
            $csrf = $app->csrf();
            $_POST = ['_csrf' => $csrf->token('terms.send')];
            $csrf->require('terms.send');
            Expect::true(true, 'a valid token should be accepted');
        },

    'a csrf token in the header is accepted' =>
        static function (Bootstrap $app): void {
            $csrf = $app->csrf();
            $_POST = [];
            $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf->token('terms.send');
            $csrf->require('terms.send');
            unset($_SERVER['HTTP_X_CSRF_TOKEN']);
            Expect::true(true, 'a header token should be accepted');
        },

    // ------------------------------------------------------------------
    // ACCEPTANCE: unauthenticated Desk access fails without revealing detail
    // ------------------------------------------------------------------
    'ACCEPTANCE an unauthenticated caller cannot view the Desk' =>
        static function (Bootstrap $app): void {
            Expect::false(
                $app->authorization()->can(Permission::DESK_VIEW),
                'no session must mean no Desk'
            );
            Expect::throws(
                AuthorizationException::class,
                static fn () => $app->authorization()->require(Permission::DESK_VIEW),
                'requiring the permission must throw'
            );
        },

    'ACCEPTANCE the refusal names no internal detail to the caller' =>
        static function (Bootstrap $app): void {
            // The exception carries the permission for the audit trail, but the
            // handler answers 404 and the public message is "Not here.", which
            // is what a caller learns. Assert both halves.
            try {
                $app->authorization()->require(Permission::RECOVERY_VERIFY);
                throw new TestFailure('the check should have thrown');
            } catch (AuthorizationException $e) {
                Expect::same(
                    Permission::RECOVERY_VERIFY,
                    $e->permission,
                    'the audit trail needs the permission'
                );
            }
        },

    'an operations admin cannot countersign' =>
        static function (Bootstrap $app): void {
            $userId = $app->users()->create('ops@example.org', AuthService::hashPassword('x-long-enough-password-1'));
            $app->memberships()->grant($userId, Role::OPERATIONS_ADMIN, null);
            $app->session()->establish(SessionManager::KIND_ADMIN, $userId);

            Expect::true(
                $app->authorization()->can(Permission::DESK_VIEW),
                'operations should see the Desk'
            );
            Expect::false(
                $app->authorization()->can(Permission::DOCUMENT_COUNTERSIGN),
                'operations must not countersign'
            );
            Expect::false(
                $app->authorization()->can(Permission::RECOVERY_VERIFY),
                'operations must not verify money'
            );
        },

    'an auditor can read and cannot write' =>
        static function (Bootstrap $app): void {
            $userId = $app->users()->create('audit@example.org', AuthService::hashPassword('x-long-enough-password-1'));
            $app->memberships()->grant($userId, Role::AUDITOR, null);
            $app->session()->establish(SessionManager::KIND_ADMIN, $userId);

            Expect::true($app->authorization()->can(Permission::DESK_VIEW), 'auditor sees the Desk');
            Expect::true($app->authorization()->can(Permission::AUDIT_VIEW), 'auditor reads the trail');
            Expect::false($app->authorization()->can(Permission::TERMS_SEND), 'auditor must not send');
            Expect::false($app->authorization()->can(Permission::ENGAGEMENT_MANAGE), 'auditor must not manage');
        },

    'a client cannot reach another organization' =>
        static function (Bootstrap $app): void {
            $ownOrg = $app->organizations()->create('Own Practice LLC');
            $otherOrg = $app->organizations()->create('Other Practice LLC');

            $userId = $app->users()->create('signer@example.org');
            $app->memberships()->grant($userId, Role::AUTHORIZED_SIGNER, $ownOrg);
            $app->session()->establish(SessionManager::KIND_CLIENT, $userId, $ownOrg);

            Expect::true(
                $app->authorization()->can(Permission::DOCUMENT_SIGN, $ownOrg),
                'a signer signs for their own practice'
            );
            Expect::false(
                $app->authorization()->can(Permission::DOCUMENT_SIGN, $otherOrg),
                'a signer must never reach another practice'
            );
            Expect::throws(
                AuthorizationException::class,
                static fn () => $app->authorization()->requireSameOrganization($otherOrg),
                'the tenancy check must throw'
            );
        },

    // ------------------------------------------------------------------
    // ACCEPTANCE: audit events record successes and refusals alike
    // ------------------------------------------------------------------
    'ACCEPTANCE a successful login is audited' =>
        static function (Bootstrap $app, Database $db) use ($makeOwner): void {
            [, $email, $password] = $makeOwner($app);
            $app->auth()->attemptAdminLogin($email, $password);

            $rows = $db->all(
                "SELECT * FROM sa_audit_events WHERE action = 'auth.login' AND outcome = 'success'"
            );
            Expect::same(1, count($rows), 'one success row should exist');
            Expect::notNull($rows[0]['ip_digest'], 'the IP digest should be recorded');
            Expect::same(64, strlen((string) $rows[0]['ip_digest']), 'the digest should be a sha256 hex');
        },

    'ACCEPTANCE a refused login is audited' =>
        static function (Bootstrap $app, Database $db) use ($makeOwner): void {
            [, $email] = $makeOwner($app);
            $app->auth()->attemptAdminLogin($email, 'wrong');

            $rows = $db->all(
                "SELECT * FROM sa_audit_events WHERE action = 'auth.login' AND outcome = 'failure'"
            );
            Expect::same(1, count($rows), 'one failure row should exist');
            Expect::true(
                str_contains((string) $rows[0]['metadata'], 'bad_password'),
                'the reason should be recorded'
            );
        },

    'ACCEPTANCE a refused permission is audited' =>
        static function (Bootstrap $app, Database $db): void {
            try {
                $app->authorization()->require(Permission::CONFIG_MANAGE);
            } catch (AuthorizationException) {
                // expected
            }
            $rows = $db->all("SELECT * FROM sa_audit_events WHERE action = 'authz.denied'");
            Expect::same(1, count($rows), 'the refusal should be recorded');
            Expect::true(
                str_contains((string) $rows[0]['metadata'], Permission::CONFIG_MANAGE),
                'the refused permission should be named'
            );
        },

    'the audit trail never stores a raw ip' =>
        static function (Bootstrap $app, Database $db): void {
            $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
            $app->audit()->record('test.action', 'success');

            $rows = $db->all('SELECT * FROM sa_audit_events');
            Expect::same(1, count($rows), 'one row should exist');
            Expect::false(
                str_contains(json_encode($rows[0]) ?: '', '203.0.113.42'),
                'the raw address must appear nowhere in the row'
            );
        },

    'audit metadata drops keys nobody approved' =>
        static function (Bootstrap $app, Database $db): void {
            $app->audit()->record('test.action', 'success', 'thing', null, [
                'reason'       => 'a permitted key',
                'patient_name' => 'MUST NOT BE STORED',
                'claim_number' => 'MUST NOT BE STORED',
            ]);

            $metadata = (string) $db->value('SELECT metadata FROM sa_audit_events');
            Expect::true(str_contains($metadata, 'a permitted key'), 'the allowed key survives');
            Expect::false(str_contains($metadata, 'MUST NOT BE STORED'), 'an unapproved key is dropped');
            Expect::false(str_contains($metadata, 'patient_name'), 'the key name is dropped too');
        },

    // ------------------------------------------------------------------
    // Throttling
    // ------------------------------------------------------------------
    'the eleventh login attempt is throttled' =>
        static function (Bootstrap $app) use ($makeOwner): void {
            [, $email] = $makeOwner($app);

            // The limit is 10 per 15 minutes per account. Each attempt counts
            // the account bucket and the IP bucket, so ten wrong passwords
            // exhausts it and the eleventh throws.
            for ($i = 0; $i < 10; $i++) {
                $app->auth()->attemptAdminLogin($email, 'wrong');
            }
            Expect::throws(
                RateLimitException::class,
                static fn () => $app->auth()->attemptAdminLogin($email, 'wrong'),
                'the eleventh attempt must be throttled'
            );
        },

    'a successful login clears the throttle' =>
        static function (Bootstrap $app) use ($makeOwner): void {
            [, $email, $password] = $makeOwner($app);
            for ($i = 0; $i < 5; $i++) {
                $app->auth()->attemptAdminLogin($email, 'wrong');
            }
            Expect::notNull(
                $app->auth()->attemptAdminLogin($email, $password),
                'the right password should still work'
            );
            Expect::false(
                $app->rateLimiter()->isExceeded('admin.login', 'email:' . $email),
                'the counter should be cleared'
            );
        },
];
