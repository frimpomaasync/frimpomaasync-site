<?php
declare(strict_types=1);

/**
 * Phase 3 acceptance, the access half.
 *
 *   used or expired invitations cannot be replayed
 *   a client cannot access another organization's session or data
 *
 * Plus the rules section 10.2 states about the passwordless door, because a
 * six-digit code is only safe if all three hold at once: ten minutes, one use,
 * and a limited number of guesses.
 *
 * The tenancy case is the one that matters most on this project. Her practices
 * compete with each other in the same state, so a client session that could
 * read another organization's engagement is not a bug, it is the end of the
 * business.
 */

use SoftAppeals\Auth\AuthService;
use SoftAppeals\Auth\SessionManager;
use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\Permission;
use SoftAppeals\Domain\Role;
use SoftAppeals\Repositories\LoginCodeRepository;

/**
 * An application that may email anybody, with the transport captured, so the
 * six digits can be read out of the message that would have been sent.
 *
 * @return array{0:Bootstrap,1:ArrayObject<int,array{to:string,subject:string,body:string}>}
 */
$unrestricted = static function (Database $db): array {
    $path = sys_get_temp_dir() . '/sa-access-config-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($path, '<?php return ' . var_export([
        'SA_APP_ENV'           => 'testing',
        'SA_APP_URL'           => 'https://staging.frimpomaasync.com',
        'SA_BUSINESS_TIMEZONE' => 'America/New_York',
        'SA_SESSION_SECRET'    => str_repeat('test-session-secret-', 3),
        'SA_TOKEN_SECRET'      => str_repeat('test-token-secret-', 3),
        'SA_IP_HMAC_SECRET'    => str_repeat('test-ip-hmac-secret-', 3),
        'SA_DEMO_MODE'         => true,
        'SA_MAIL_ALLOWLIST'    => '',
    ], true) . ";\n");
    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });

    $app = Bootstrap::boot($path, false);
    $app->useDatabase($db);

    $sent = new ArrayObject();
    $app->mail(static function (string $to, string $subject, string $body) use ($sent): bool {
        $sent->append(['to' => $to, 'subject' => $subject, 'body' => $body]);
        return true;
    });

    return [$app, $sent];
};

/**
 * A practice with one client contact who holds one role.
 *
 * @return array{organization_id:string,user_id:string,email:string}
 */
$practice = static function (Bootstrap $app, string $name, string $email, string $role = Role::ORG_ADMIN): array {
    $organizationId = $app->organizations()->create($name, $name, 'Primary care', 'MD');
    $contact = $app->contacts()->upsert($organizationId, 'A Person', $email, 'Practice owner');
    $userId = $app->users()->create($email, null, $contact['id']);
    $app->memberships()->grant($userId, $role, $organizationId);
    return ['organization_id' => $organizationId, 'user_id' => $userId, 'email' => $email];
};

/** Pull the six digits out of the message that was sent. */
$codeFrom = static function (ArrayObject $sent, string $to): string {
    $body = '';
    foreach ($sent as $message) {
        if ($message['to'] === $to && str_contains($message['subject'], 'sign-in code')) {
            $body = $message['body'];
        }
    }
    Expect::same(1, preg_match('/\b(\d{6})\b/', $body, $matches), 'the email should carry six digits');
    return $matches[1];
};

return [

    'a code signs a client in, once' =>
        static function (Bootstrap $app, Database $db) use ($unrestricted, $practice, $codeFrom): void {
            [$app, $sent] = $unrestricted($db);
            $who = $practice($app, 'Fictional Family Practice', 'owner@example.org');

            $requested = $app->clientAccess()->requestLoginCode($who['email']);
            Expect::true($requested['sent'], 'a known client should be sent a code');

            $code = $codeFrom($sent, $who['email']);

            $first = $app->clientAccess()->verifyLoginCode($who['email'], $code);
            Expect::notNull($first, 'the right code should sign them in');
            Expect::same('client', $app->session()->kind(), 'the session should be a client session');
            Expect::same(
                $who['organization_id'],
                $app->session()->organizationId(),
                'the session should be pinned to their own practice'
            );

            $second = $app->clientAccess()->verifyLoginCode($who['email'], $code);
            Expect::null($second, 'the same code must not work twice');
        },

    'an expired code is refused' =>
        static function (Bootstrap $app, Database $db) use ($unrestricted, $practice, $codeFrom): void {
            [$app, $sent] = $unrestricted($db);
            $who = $practice($app, 'Fictional Family Practice', 'owner@example.org');

            $app->clientAccess()->requestLoginCode($who['email']);
            $code = $codeFrom($sent, $who['email']);

            $db->run('UPDATE sa_login_codes SET expires_at = :e', ['e' => '2020-01-01 00:00:00']);

            Expect::null(
                $app->clientAccess()->verifyLoginCode($who['email'], $code),
                'a code past its ten minutes must be refused'
            );
        },

    'wrong guesses burn the code' =>
        static function (Bootstrap $app, Database $db) use ($unrestricted, $practice, $codeFrom): void {
            [$app, $sent] = $unrestricted($db);
            $who = $practice($app, 'Fictional Family Practice', 'owner@example.org');

            $app->clientAccess()->requestLoginCode($who['email']);
            $code = $codeFrom($sent, $who['email']);

            for ($attempt = 0; $attempt < LoginCodeRepository::MAX_ATTEMPTS; $attempt++) {
                // A wrong guess that is not the real code, whatever the real
                // code happens to be.
                $wrong = str_pad((string) (((int) $code + $attempt + 1) % 1000000), 6, '0', STR_PAD_LEFT);
                Expect::null(
                    $app->clientAccess()->verifyLoginCode($who['email'], $wrong),
                    'a wrong code must never sign anyone in'
                );
            }

            Expect::null(
                $app->clientAccess()->verifyLoginCode($who['email'], $code),
                'the right code must be dead once it has been guessed at five times'
            );
        },

    'asking again kills the previous code' =>
        static function (Bootstrap $app, Database $db) use ($unrestricted, $practice, $codeFrom): void {
            [$app, $sent] = $unrestricted($db);
            $who = $practice($app, 'Fictional Family Practice', 'owner@example.org');

            $app->clientAccess()->requestLoginCode($who['email']);
            $first = $codeFrom($sent, $who['email']);

            $app->clientAccess()->requestLoginCode($who['email']);
            $second = $codeFrom($sent, $who['email']);

            if ($first === $second) {
                // One in a million, and it would make the assertion below
                // meaningless rather than wrong. Skip rather than fail.
                return;
            }

            Expect::null(
                $app->clientAccess()->verifyLoginCode($who['email'], $first),
                'asking for a new code must kill the old one'
            );
            Expect::notNull(
                $app->clientAccess()->verifyLoginCode($who['email'], $second),
                'the newest code should still work'
            );
        },

    'an address nobody invited is sent nothing, and told nothing' =>
        static function (Bootstrap $app, Database $db) use ($unrestricted, $practice): void {
            [$app, $sent] = $unrestricted($db);
            $practice($app, 'Fictional Family Practice', 'owner@example.org');

            $result = $app->clientAccess()->requestLoginCode('stranger@example.org');

            Expect::false($result['sent'], 'no code should be minted for an address nobody knows');
            Expect::same(0, count($sent), 'and nothing at all should be emailed');
        },

    'a staff account cannot get in through the client door' =>
        static function (Bootstrap $app, Database $db) use ($unrestricted): void {
            [$app, $sent] = $unrestricted($db);

            $email = 'owner@frimpomaasync.test';
            $userId = $app->users()->create($email, AuthService::hashPassword('a-long-enough-test-password-9134'));
            $app->memberships()->grant($userId, Role::OWNER_ADMIN, null);

            $result = $app->clientAccess()->requestLoginCode($email);

            Expect::false($result['sent'], 'her own account has a password and a different door');
            Expect::same(0, count($sent), 'and no code should be emailed to it');
        },

    'a client session reaches its own practice and no other' =>
        static function (Bootstrap $app, Database $db) use ($unrestricted, $practice, $codeFrom): void {
            [$app, $sent] = $unrestricted($db);
            $mine = $practice($app, 'Fictional Family Practice', 'owner@example.org');
            $theirs = $practice($app, 'Fictional Behavioral Health', 'other@example.org');

            $app->clientAccess()->requestLoginCode($mine['email']);
            $app->clientAccess()->verifyLoginCode($mine['email'], $codeFrom($sent, $mine['email']));

            $authorization = $app->authorization();

            Expect::true(
                $authorization->can(Permission::ROOM_VIEW, $mine['organization_id']),
                'a client should reach their own room'
            );
            Expect::false(
                $authorization->can(Permission::ROOM_VIEW, $theirs['organization_id']),
                'a client must never reach another practice'
            );
            Expect::false(
                $authorization->can(Permission::ROOM_VIEW),
                'a client role is scoped, so it grants nothing with no organization named'
            );
        },

    'a client cannot reach the Desk' =>
        static function (Bootstrap $app, Database $db) use ($unrestricted, $practice, $codeFrom): void {
            [$app, $sent] = $unrestricted($db);
            $mine = $practice($app, 'Fictional Family Practice', 'owner@example.org');

            $app->clientAccess()->requestLoginCode($mine['email']);
            $app->clientAccess()->verifyLoginCode($mine['email'], $codeFrom($sent, $mine['email']));

            Expect::false(
                $app->authorization()->can(Permission::DESK_VIEW),
                'a client session must not reach the Desk'
            );
            Expect::false(
                $app->authorization()->can(Permission::DESK_VIEW, $mine['organization_id']),
                'naming their own organization must not help either'
            );
            Expect::false($app->authorization()->isStaff(), 'a client is not staff');
        },

    'a role removed between the code and the click takes effect at once' =>
        static function (Bootstrap $app, Database $db) use ($unrestricted, $practice, $codeFrom): void {
            [$app, $sent] = $unrestricted($db);
            $mine = $practice($app, 'Fictional Family Practice', 'owner@example.org');

            $app->clientAccess()->requestLoginCode($mine['email']);
            $code = $codeFrom($sent, $mine['email']);

            $app->memberships()->revokeAllForUser($mine['user_id']);

            Expect::null(
                $app->clientAccess()->verifyLoginCode($mine['email'], $code),
                'a code must not sign in somebody who no longer holds a role'
            );
        },

    'a deactivated client is signed out on their next request' =>
        static function (Bootstrap $app, Database $db) use ($unrestricted, $practice, $codeFrom): void {
            [$app, $sent] = $unrestricted($db);
            $mine = $practice($app, 'Fictional Family Practice', 'owner@example.org');

            $app->clientAccess()->requestLoginCode($mine['email']);
            $app->clientAccess()->verifyLoginCode($mine['email'], $codeFrom($sent, $mine['email']));
            Expect::notNull($app->clientAccess()->context(), 'the session should be live to begin with');

            $app->users()->deactivate($mine['user_id']);

            Expect::null(
                $app->clientAccess()->context(),
                'a deactivated account must lose its session on the next read'
            );
        },

    'a malformed token is refused without a lookup' =>
        static function (Bootstrap $app, Database $db) use ($unrestricted): void {
            [$app] = $unrestricted($db);

            foreach (['', 'not-a-token', '../../etc/passwd', str_repeat('z', 64)] as $rubbish) {
                Expect::null(
                    $app->clientAccess()->redeemInvitation($rubbish, 'preferences'),
                    'a token that is not a token must be refused'
                );
            }
            Expect::false(
                $app->session()->isAuthenticated(),
                'nothing about a bad token should establish a session'
            );
        },

    'signing out ends the session' =>
        static function (Bootstrap $app, Database $db) use ($unrestricted, $practice, $codeFrom): void {
            [$app, $sent] = $unrestricted($db);
            $mine = $practice($app, 'Fictional Family Practice', 'owner@example.org');

            $app->clientAccess()->requestLoginCode($mine['email']);
            $app->clientAccess()->verifyLoginCode($mine['email'], $codeFrom($sent, $mine['email']));
            Expect::same(SessionManager::KIND_CLIENT, $app->session()->kind(), 'signed in to begin with');

            $app->clientAccess()->signOut();

            Expect::null($app->clientAccess()->context(), 'signing out should leave nothing behind');
            Expect::false($app->session()->isAuthenticated(), 'and no authenticated session');
        },

];
