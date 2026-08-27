<?php
declare(strict_types=1);

/**
 * The pieces the rest of the system is built on: identifiers, time, digests,
 * the state machine, and the no-secret-in-a-page rule.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\Permission;
use SoftAppeals\Domain\Role;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Security\Hmac;
use SoftAppeals\Support\Clock;
use SoftAppeals\Support\Uuid;

return [

    'uuids are valid version 4 and never repeat' =>
        static function (Bootstrap $app): void {
            $seen = [];
            for ($i = 0; $i < 500; $i++) {
                $id = Uuid::v4();
                Expect::true(Uuid::isValid($id), 'every id should be a valid uuid v4');
                Expect::false(isset($seen[$id]), 'ids must not repeat');
                $seen[$id] = true;
            }
        },

    'public references avoid the letters that get misread' =>
        static function (Bootstrap $app): void {
            for ($i = 0; $i < 200; $i++) {
                $ref = Uuid::publicRef('ORG');
                Expect::true(str_starts_with($ref, 'SA-ORG-'), 'the prefix should be there');
                $tail = substr($ref, 7);
                Expect::same(6, strlen($tail), 'six characters after the prefix');
                foreach (['I', 'L', 'O', 'U', '0', '1'] as $ambiguous) {
                    Expect::false(
                        str_contains($tail, $ambiguous),
                        'a reference read down a phone must not contain ' . $ambiguous
                    );
                }
            }
        },

    'money never becomes a float' =>
        static function (Bootstrap $app): void {
            // Section 19's worked example, and the boundary either side of it.
            $fee = static fn (int $cents, int $bps): int => intdiv(($cents * $bps) + 5000, 10000);

            Expect::same(60000, $fee(240000, 2500), 'the plan example: 2400.00 at 25 percent is 600.00');
            Expect::same(0, $fee(0, 2500), 'nothing recovered is no fee');
            Expect::same(1, $fee(2, 2500), 'half a cent rounds up, not down');
            Expect::same(25, $fee(100, 2500), 'one dollar at 25 percent is 25 cents');
            Expect::same(2500, $fee(10000, 2500), 'a hundred dollars is twenty five');
        },

    'timestamps store as utc and display in her timezone' =>
        static function (Bootstrap $app): void {
            $frozen = new DateTimeImmutable('2026-08-05 20:27:00', new DateTimeZone('UTC'));
            $clock = new Clock('America/New_York', $frozen);

            Expect::same('2026-08-05 20:27:00', $clock->nowUtc(), 'storage is UTC');
            // 20:27 UTC in August is 16:27 Eastern.
            Expect::same(
                'Wednesday, August 5, 2026 at 4:27 PM',
                $clock->displaySigningStamp('2026-08-05 20:27:00'),
                'the signing stamp reads in her timezone'
            );
            Expect::same(
                '4:27pm on 5 August 2026',
                $clock->displayDateTime('2026-08-05 20:27:00'),
                'the friendly form reads in her timezone'
            );
        },

    'the deadline countdown is right at the boundaries' =>
        static function (Bootstrap $app): void {
            $frozen = new DateTimeImmutable('2026-08-05 12:00:00', new DateTimeZone('UTC'));
            $clock = new Clock('America/New_York', $frozen);

            Expect::same(0, $clock->daysUntil('2026-08-05 23:00:00'), 'later today is zero days');
            Expect::same(1, $clock->daysUntil('2026-08-06 01:00:00'), 'tomorrow is one day');
            Expect::same(61, $clock->daysUntil('2026-10-05 12:00:00'), 'two months out');
            Expect::same(-1, $clock->daysUntil('2026-08-04 12:00:00'), 'a passed deadline goes negative');
            Expect::true($clock->hasPassed('2026-08-04 12:00:00'), 'yesterday has passed');
            Expect::false($clock->hasPassed('2026-08-06 12:00:00'), 'tomorrow has not');
        },

    'digests are purpose separated' =>
        static function (Bootstrap $app): void {
            $hmac = new Hmac(str_repeat('secret-material-', 4));
            $one = $hmac->digest('ratelimit:login', 'a@example.org');
            $two = $hmac->digest('audit', 'a@example.org');
            Expect::true($one !== $two, 'the same value under two purposes must differ');
            Expect::same(64, strlen($one), 'a sha256 hex digest is 64 characters');
            Expect::true(
                Hmac::matches($one, $hmac->digest('ratelimit:login', 'a@example.org')),
                'the same input under the same purpose must match'
            );
        },

    'a short hmac secret is refused' =>
        static function (Bootstrap $app): void {
            Expect::throws(
                RuntimeException::class,
                static fn () => new Hmac('too-short'),
                'a weak secret must not be accepted'
            );
        },

    'tokens are long and url safe' =>
        static function (Bootstrap $app): void {
            $token = Hmac::newToken(32);
            Expect::true(strlen($token) >= 40, 'a 32 byte token is at least 40 base64 characters');
            Expect::same(
                1,
                preg_match('/^[A-Za-z0-9_-]+$/', $token),
                'a token must survive a URL without escaping'
            );
        },

    'numeric codes keep a leading zero' =>
        static function (Bootstrap $app): void {
            $sawLeadingZero = false;
            for ($i = 0; $i < 400; $i++) {
                $code = Hmac::newNumericCode(6);
                Expect::same(6, strlen($code), 'always six digits');
                if ($code[0] === '0') {
                    $sawLeadingZero = true;
                }
            }
            Expect::true($sawLeadingZero, 'a leading zero must survive across 400 codes');
        },

    'the state machine refuses a skipped gate' =>
        static function (Bootstrap $app): void {
            // The one that matters: nothing reaches secure intake without the
            // review authorization actually being executed.
            Expect::false(
                Stage::canMove(Stage::TERMS_SENT, Stage::SECURE_INTAKE_READY),
                'terms sent must not jump straight to secure intake'
            );
            Expect::false(
                Stage::canMove(Stage::PREFERENCES_CONFIRMED, Stage::RECOVERY_ACTIVE),
                'preferences must not jump to recovery'
            );
            Expect::true(
                Stage::canMove(Stage::REVIEW_AUTH_EXECUTED, Stage::SECURE_INTAKE_READY),
                'the proper route must work'
            );
        },

    'the phi gate opens only after both documents' =>
        static function (Bootstrap $app): void {
            foreach ([
                Stage::INQUIRY_RECEIVED,
                Stage::TERMS_SENT,
                Stage::PREFERENCES_CONFIRMED,
                Stage::BAA_PENDING,
                Stage::BAA_EXECUTED,
                Stage::REVIEW_AUTH_PENDING,
                Stage::REVIEW_AUTH_EXECUTED,
            ] as $stage) {
                Expect::false(
                    Stage::phiGatePassed($stage),
                    $stage . ' must not be allowed to receive claim files'
                );
            }
            Expect::true(
                Stage::phiGatePassed(Stage::SECURE_INTAKE_READY),
                'secure intake ready is the first stage that may'
            );
        },

    'terminal stages go nowhere' =>
        static function (Bootstrap $app): void {
            foreach ([Stage::DECLINED, Stage::CLOSED, Stage::CLOSED_NO_RECOVERY] as $stage) {
                Expect::true(Stage::isTerminal($stage), $stage . ' should be terminal');
                foreach (Stage::all() as $target) {
                    Expect::false(
                        Stage::canMove($stage, $target),
                        'a declined or closed engagement must not be revived silently'
                    );
                }
            }
        },

    'every stage transition names a stage that exists' =>
        static function (Bootstrap $app): void {
            foreach (Stage::transitions() as $from => $targets) {
                Expect::true(Stage::isValid($from), $from . ' should be a real stage');
                foreach ($targets as $to) {
                    Expect::true(Stage::isValid($to), $from . ' points at unknown stage ' . $to);
                }
            }
        },

    'every permission is held by at least one role' =>
        static function (Bootstrap $app): void {
            foreach (Permission::map() as $permission => $roles) {
                Expect::true($roles !== [], $permission . ' is held by nobody');
                foreach ($roles as $role) {
                    Expect::true(Role::isValid($role), $permission . ' names unknown role ' . $role);
                }
            }
        },

    'no client role can reach a staff permission' =>
        static function (Bootstrap $app): void {
            $staffOnly = [
                Permission::DESK_VIEW,
                Permission::TERMS_SEND,
                Permission::DOCUMENT_COUNTERSIGN,
                Permission::RECOVERY_VERIFY,
                Permission::CONFIG_MANAGE,
                Permission::USER_MANAGE,
                Permission::AUDIT_VIEW,
            ];
            foreach ($staffOnly as $permission) {
                foreach (Permission::map()[$permission] as $role) {
                    Expect::true(
                        Role::isStaff($role),
                        $permission . ' is reachable by the client role ' . $role
                    );
                }
            }
        },

    'the redacted config prints no secret' =>
        static function (Bootstrap $app): void {
            $redacted = $app->config()->redacted();
            $printed = json_encode($redacted) ?: '';

            foreach (['SA_SESSION_SECRET', 'SA_TOKEN_SECRET', 'SA_IP_HMAC_SECRET'] as $key) {
                Expect::same('set', $redacted[$key] ?? null, $key . ' should show as set, never as a value');
            }
            Expect::false(
                str_contains($printed, 'test-session-secret-'),
                'a secret value must never appear in the redacted view'
            );
        },

    'a staff role cannot be granted against an organization' =>
        static function (Bootstrap $app): void {
            $organizationId = $app->organizations()->create('Somewhere LLC');
            $userId = $app->users()->create('x@example.org');
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->memberships()->grant($userId, Role::OWNER_ADMIN, $organizationId),
                'a staff role is global and must not be scoped'
            );
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->memberships()->grant($userId, Role::VIEWER, null),
                'a client role must name an organization'
            );
        },

    'an organization reference is unique' =>
        static function (Bootstrap $app, Database $db): void {
            $refs = [];
            for ($i = 0; $i < 50; $i++) {
                $id = $app->organizations()->create('Practice ' . $i . ' LLC');
                $ref = (string) $db->value('SELECT public_ref FROM sa_organizations WHERE id = :i', ['i' => $id]);
                Expect::false(isset($refs[$ref]), 'references must not collide');
                $refs[$ref] = true;
            }
        },

    'a database identifier that is not a plain name is refused' =>
        static function (Bootstrap $app, Database $db): void {
            Expect::throws(
                RuntimeException::class,
                static fn () => $db->quoteIdentifier('users; DROP TABLE sa_users'),
                'an injected identifier must be refused, not escaped'
            );
            Expect::throws(
                RuntimeException::class,
                static fn () => $db->quoteIdentifier('sa_users`'),
                'a backtick must be refused'
            );
        },
];
