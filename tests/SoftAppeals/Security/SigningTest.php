<?php
declare(strict_types=1);

/**
 * Phase 4 acceptance, the signing half.
 *
 *   only assigned signers can sign
 *   direct HTTP access to private files fails
 *   a signed document cannot be edited
 *   signature event references the exact document hash
 *
 * Section 14.3 lists eight things the server verifies before a signature is
 * applied. Each of them has a case here, and each case attacks the check rather
 * than demonstrating it: a test that only shows the happy path proves the
 * button works and proves nothing about the lock.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\FitDecision;
use SoftAppeals\Domain\Permission;
use SoftAppeals\Domain\PreferenceForm;
use SoftAppeals\Domain\Role;
use SoftAppeals\Repositories\InvitationRepository;
use SoftAppeals\Repositories\SignatureRepository;
use SoftAppeals\Services\SigningService;

/**
 * An application with a vault of its own, a captured transport, and no mail
 * allowlist, so the accepted path is the one under test.
 *
 * @return array{0:Bootstrap,1:ArrayObject<int,array{to:string,subject:string,body:string}>,2:string}
 */
$boot = static function (Database $db, array $overrides = []): array {
    $vault = sys_get_temp_dir() . '/sa-sign-vault-' . bin2hex(random_bytes(4));
    $path = sys_get_temp_dir() . '/sa-sign-config-' . bin2hex(random_bytes(4)) . '.php';

    file_put_contents($path, '<?php return ' . var_export(array_merge([
        'SA_APP_ENV'              => 'testing',
        'SA_APP_URL'              => 'https://staging.frimpomaasync.com',
        'SA_BUSINESS_TIMEZONE'    => 'America/New_York',
        'SA_SESSION_SECRET'       => str_repeat('test-session-secret-', 3),
        'SA_TOKEN_SECRET'         => str_repeat('test-token-secret-', 3),
        'SA_IP_HMAC_SECRET'       => str_repeat('test-ip-hmac-secret-', 3),
        'SA_DEMO_MODE'            => true,
        'SA_MAIL_ALLOWLIST'       => '',
        'SA_PRIVATE_STORAGE_PATH' => $vault,
        'SA_LEGAL_ENTITY'         => 'A Fictional Legal Entity LLC',
    ], $overrides), true) . ";\n");

    register_shutdown_function(static function () use ($path, $vault): void {
        @unlink($path);
        removeTree($vault);
    });

    $app = Bootstrap::boot($path, false);
    $app->useDatabase($db);

    $sent = new ArrayObject();
    $app->mail(static function (string $to, string $subject, string $body) use ($sent): bool {
        $sent->append(['to' => $to, 'subject' => $subject, 'body' => $body]);
        return true;
    });

    return [$app, $sent, $vault];
};

/**
 * One practice, all the way to a BAA sitting out for signature.
 *
 * @return array{engagement:array<string,mixed>,document:array<string,mixed>,token:string,signer_email:string}
 */
$outForSignature = static function (
    Bootstrap $app,
    ArrayObject $sent,
    string $practice,
    string $signerName,
    string $signerEmail
): array {
    $intake = $app->intakeService()->record('soft-appeals-start', [
        'organization'      => $practice,
        'name'              => $signerName,
        'email'             => $signerEmail,
        'organization_type' => 'Behavioral health',
        'state'             => 'Maryland',
        'denial_volume'     => '51 to 100',
    ], 'raw-' . bin2hex(random_bytes(6)));

    $review = $app->intakeService()->review(
        $intake['id'],
        FitDecision::ACCEPT,
        null,
        null,
        EngagementTerms::FEE_CONTINGENCY_25,
        EngagementTerms::CHANNEL_CLIENT_SYSTEM,
        'within ten business days'
    );

    $engagementId = (string) $review['engagement_id'];
    $app->termsService()->send($app->engagements()->findWithOrganization($engagementId), 0, null);

    $token = '';
    foreach ($sent as $message) {
        if ($message['to'] === strtolower($signerEmail)
            && preg_match('~soft-appeals-preferences\.php\?t=([0-9a-f]+)~', $message['body'], $m) === 1
        ) {
            $token = $m[1];
        }
    }
    Expect::true($token !== '', 'the terms email should carry a preferences token');

    $app->clientAccess()->redeemInvitation($token, InvitationRepository::PURPOSE_PREFERENCES);
    $context = $app->clientAccess()->context();
    Expect::notNull($context, 'redeeming should open a client session');

    $result = $app->preferencesService()->confirm(
        $app->engagements()->findWithOrganization($engagementId),
        [
            'communication_cadence' => EngagementTerms::CADENCE_BIWEEKLY,
            'secure_channel'        => EngagementTerms::CHANNEL_CLIENT_SYSTEM,
            'billing_partner'       => PreferenceForm::PARTNER_NO,
            'signer_name'           => $signerName,
            'signer_role'           => 'Practice owner',
            'signer_email'          => $signerEmail,
            'approver_name'         => '',
            'approver_role'         => '',
            'approver_email'        => '',
            'billing_name'          => '',
            'billing_role'          => '',
            'billing_email'         => '',
            'initial_payer_group'   => '',
            'procurement_notes'     => '',
        ],
        (string) $context['user']['id'],
        $context['contact_id']
    );
    Expect::true($result['saved'], 'the preferences should save');
    $app->clientAccess()->signOut();

    $engagement = $app->engagements()->findWithOrganization($engagementId);
    $document = $app->documentService()->generate($engagement, DocumentKind::BAA, null);
    $app->documentService()->send($document, $engagement, null);

    $signToken = '';
    foreach ($sent as $message) {
        if ($message['to'] === strtolower($signerEmail)
            && preg_match('~soft-appeals-sign\?t=([0-9a-f]+)~', $message['body'], $m) === 1
        ) {
            $signToken = $m[1];
        }
    }
    Expect::true($signToken !== '', 'the signing email should carry a token');

    return [
        'engagement'   => $app->engagements()->findWithOrganization($engagementId),
        'document'     => $app->documents()->find((string) $document['id']),
        'token'        => $signToken,
        'signer_email' => strtolower($signerEmail),
    ];
};

/** Sign in as the practice and hand back the context SigningService takes. */
$sessionFor = static function (Bootstrap $app, string $token, array $engagement): array {
    $redeemed = $app->clientAccess()->redeemInvitation($token, InvitationRepository::PURPOSE_SIGN);
    Expect::notNull($redeemed, 'the signing invitation should redeem');
    $context = $app->clientAccess()->context();
    Expect::notNull($context, 'redeeming should open a client session');

    return [
        'organization_id' => (string) $engagement['organization_id'],
        'engagement'      => $engagement,
        'contact_id'      => $context['contact_id'],
        'user_id'         => (string) $context['user']['id'],
    ];
};

/** A complete, valid set of signing input. */
$goodInput = static function (array $document, string $typedName = 'Dana Owusu'): array {
    return [
        'typed_name'         => $typedName,
        'typed_title'        => 'Practice owner',
        'typed_organization' => 'Fictional Behavioral Health LLC',
        'consent'            => true,
        'document_sha256'    => (string) $document['content_sha256'],
    ];
};

return [

    'the assigned signer signs, and the signature carries the exact hash' =>
        static function (Bootstrap $app, Database $db) use ($boot, $outForSignature, $sessionFor, $goodInput): void {
            [$app, $sent] = $boot($db);
            $set = $outForSignature($app, $sent, 'Fictional Behavioral Health LLC', 'Dana Owusu', 'dana@example.org');

            $context = $sessionFor($app, $set['token'], $set['engagement']);
            $result = $app->signingService()->sign($set['document'], $context, $goodInput($set['document']));

            Expect::true($result['signed'], 'the assigned signer should be able to sign');
            Expect::false($result['already'], 'this is the first signature');

            $signature = $app->signatures()->forDocumentAndParty(
                (string) $set['document']['id'],
                SignatureRepository::PARTY_CLIENT
            );
            Expect::notNull($signature, 'a signature row should exist');
            Expect::same(
                (string) $set['document']['content_sha256'],
                (string) $signature['document_sha256'],
                'the signature must reference the exact document hash'
            );
            Expect::same(64, strlen((string) $signature['payload_sha256']), 'the payload should be hashed');
            Expect::same(
                \SoftAppeals\Domain\DocumentTemplates::consentSha256(),
                (string) $signature['consent_text_sha256'],
                'the exact consent wording should be provable later'
            );
        },

    'a second attempt returns the first signature rather than making a second' =>
        static function (Bootstrap $app, Database $db) use ($boot, $outForSignature, $sessionFor, $goodInput): void {
            [$app, $sent] = $boot($db);
            $set = $outForSignature($app, $sent, 'Fictional Behavioral Health LLC', 'Dana Owusu', 'dana@example.org');

            $context = $sessionFor($app, $set['token'], $set['engagement']);
            $first = $app->signingService()->sign($set['document'], $context, $goodInput($set['document']));
            $second = $app->signingService()->sign($set['document'], $context, $goodInput($set['document']));

            Expect::true($second['already'], 'the second attempt should be recognised as a replay');
            Expect::same($first['signature_id'], $second['signature_id'], 'and it should be the same signature');
            Expect::same(
                1,
                count($app->signatures()->forDocument((string) $set['document']['id'])),
                'there should still be exactly one signature'
            );
        },

    'somebody at another practice cannot sign this document' =>
        static function (Bootstrap $app, Database $db) use ($boot, $outForSignature, $sessionFor, $goodInput): void {
            [$app, $sent] = $boot($db);

            $ours = $outForSignature($app, $sent, 'Fictional Behavioral Health LLC', 'Dana Owusu', 'dana@example.org');
            $theirs = $outForSignature($app, $sent, 'Fictional Family Practice LLC', 'Kwame Boateng', 'kwame@example.org');

            // Signed in as the other practice, pointed at our document.
            $context = $sessionFor($app, $theirs['token'], $theirs['engagement']);

            Expect::throws(
                RuntimeException::class,
                static fn () => $app->signingService()->sign($ours['document'], $context, $goodInput($ours['document'])),
                'a signer at one practice must not reach another practice document'
            );

            Expect::same(
                0,
                count($app->signatures()->forDocument((string) $ours['document']['id'])),
                'and nothing should have been written'
            );
        },

    'holding the role is not the same as being the named signer' =>
        static function (Bootstrap $app, Database $db) use ($boot, $outForSignature, $sessionFor, $goodInput): void {
            [$app, $sent] = $boot($db);
            $set = $outForSignature($app, $sent, 'Fictional Behavioral Health LLC', 'Dana Owusu', 'dana@example.org');

            // A second authorized signer at the same practice. Same role, same
            // organization, and not the person this document names.
            $organizationId = (string) $set['engagement']['organization_id'];
            $other = $app->contacts()->upsert($organizationId, 'Second Signer', 'second@example.org', 'Partner');
            $otherUser = $app->users()->create('second@example.org', null, (string) $other['id']);
            $app->memberships()->grant($otherUser, Role::AUTHORIZED_SIGNER, $organizationId);

            $context = $sessionFor($app, $set['token'], $set['engagement']);
            $context['contact_id'] = (string) $other['id'];
            $context['user_id'] = $otherUser;

            Expect::throws(
                RuntimeException::class,
                static fn () => $app->signingService()->sign($set['document'], $context, $goodInput($set['document'])),
                'a document names one signer and the other one is not it'
            );
        },

    'a name that is not the signer name is refused' =>
        static function (Bootstrap $app, Database $db) use ($boot, $outForSignature, $sessionFor, $goodInput): void {
            [$app, $sent] = $boot($db);
            $set = $outForSignature($app, $sent, 'Fictional Behavioral Health LLC', 'Dana Owusu', 'dana@example.org');
            $context = $sessionFor($app, $set['token'], $set['engagement']);

            Expect::throws(
                RuntimeException::class,
                static fn () => $app->signingService()->sign(
                    $set['document'],
                    $context,
                    $goodInput($set['document'], 'Somebody Else')
                ),
                'a different name is not a signature'
            );

            // Spacing, case and punctuation are forgiven. A person typing their
            // own name a little differently has still typed their own name.
            Expect::true(SigningService::namesMatch('  dana   owusu ', 'Dana Owusu'), 'spacing and case forgiven');
            Expect::true(SigningService::namesMatch('Dr. Dana Owusu', 'Dr Dana Owusu'), 'punctuation forgiven');
            Expect::false(SigningService::namesMatch('Dana', 'Dana Owusu'), 'half a name is not the name');
            Expect::false(SigningService::namesMatch('', 'Dana Owusu'), 'nothing is not a signature');
        },

    'signing without the consent is refused' =>
        static function (Bootstrap $app, Database $db) use ($boot, $outForSignature, $sessionFor, $goodInput): void {
            [$app, $sent] = $boot($db);
            $set = $outForSignature($app, $sent, 'Fictional Behavioral Health LLC', 'Dana Owusu', 'dana@example.org');
            $context = $sessionFor($app, $set['token'], $set['engagement']);

            $input = $goodInput($set['document']);
            $input['consent'] = false;

            Expect::throws(
                RuntimeException::class,
                static fn () => $app->signingService()->sign($set['document'], $context, $input),
                'the electronic-record consent is not optional'
            );
        },

    'a page showing an older version is refused' =>
        static function (Bootstrap $app, Database $db) use ($boot, $outForSignature, $sessionFor, $goodInput): void {
            [$app, $sent] = $boot($db);
            $set = $outForSignature($app, $sent, 'Fictional Behavioral Health LLC', 'Dana Owusu', 'dana@example.org');
            $context = $sessionFor($app, $set['token'], $set['engagement']);

            $input = $goodInput($set['document']);
            $input['document_sha256'] = str_repeat('f', 64);

            Expect::throws(
                RuntimeException::class,
                static fn () => $app->signingService()->sign($set['document'], $context, $input),
                'a stale page must not be able to sign'
            );
        },

    'a document whose stored body has changed cannot be signed' =>
        static function (Bootstrap $app, Database $db) use ($boot, $outForSignature, $sessionFor, $goodInput): void {
            [$app, $sent, $vault] = $boot($db);
            $set = $outForSignature($app, $sent, 'Fictional Behavioral Health LLC', 'Dana Owusu', 'dana@example.org');
            $context = $sessionFor($app, $set['token'], $set['engagement']);

            // Somebody edits the stored document. Nothing in the application
            // does this; the point is that if anything ever did, the next
            // signature would refuse rather than sign the altered text.
            $path = $vault . '/' . (string) $set['document']['private_path'];
            file_put_contents($path, (string) file_get_contents($path) . "\nAn added clause.\n");

            Expect::throws(
                RuntimeException::class,
                static fn () => $app->signingService()->sign($set['document'], $context, $goodInput($set['document'])),
                'a document that no longer matches its hash must not be signed'
            );

            $check = $app->documentService()->verify($app->documents()->find((string) $set['document']['id']));
            Expect::false($check['body']['matches'], 'and the verification should say so out loud');
        },

    'a version that has been voided cannot be signed' =>
        static function (Bootstrap $app, Database $db) use ($boot, $outForSignature, $sessionFor, $goodInput): void {
            [$app, $sent] = $boot($db);
            $set = $outForSignature($app, $sent, 'Fictional Behavioral Health LLC', 'Dana Owusu', 'dana@example.org');
            $context = $sessionFor($app, $set['token'], $set['engagement']);

            $app->documentService()->void(
                $set['document'],
                $set['engagement'],
                'Replaced before it was signed',
                null
            );
            $voided = $app->documents()->find((string) $set['document']['id']);
            Expect::same(DocumentStatus::VOID, (string) $voided['status'], 'it should be void');

            Expect::throws(
                RuntimeException::class,
                static fn () => $app->signingService()->sign($voided, $context, $goodInput($voided)),
                'a voided version must not be signable'
            );
        },

    'signing is refused outright while it is switched off' =>
        static function (Bootstrap $app, Database $db) use ($boot, $outForSignature, $sessionFor, $goodInput): void {
            [$app, $sent] = $boot($db);
            $set = $outForSignature($app, $sent, 'Fictional Behavioral Health LLC', 'Dana Owusu', 'dana@example.org');
            $context = $sessionFor($app, $set['token'], $set['engagement']);

            // The same engagement, seen by an application with signing off.
            Bootstrap::resetInstance();
            [$off] = $boot($db, ['SA_E_SIGN_ENABLED' => false]);
            Expect::false($off->config()->eSignEnabled(), 'the flag should be honoured off production too');

            Expect::throws(
                RuntimeException::class,
                static fn () => $off->signingService()->sign($set['document'], $context, $goodInput($set['document'])),
                'nothing signs while signing is off'
            );
            Expect::same(
                0,
                count($off->signatures()->forDocument((string) $set['document']['id'])),
                'and nothing was written'
            );
        },

    'the countersignature belongs to the owner alone' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db);

            $holders = Permission::map()[Permission::DOCUMENT_COUNTERSIGN];
            Expect::same([Role::OWNER_ADMIN], $holders, 'only the owner countersigns');

            $signers = Permission::map()[Permission::DOCUMENT_SIGN];
            Expect::same([Role::AUTHORIZED_SIGNER], $signers, 'only the authorized signer signs');

            foreach (Role::clientRoles() as $role) {
                Expect::false(
                    in_array($role, Permission::map()[Permission::DOCUMENT_GENERATE], true),
                    'no client role may generate a document: ' . $role
                );
            }
        },

    'a signing link grants nothing it was not already owed' =>
        static function (Bootstrap $app, Database $db) use ($boot, $outForSignature): void {
            [$app, $sent] = $boot($db);
            $set = $outForSignature($app, $sent, 'Fictional Behavioral Health LLC', 'Dana Owusu', 'dana@example.org');

            $organizationId = (string) $set['engagement']['organization_id'];
            $user = $app->users()->findByEmail($set['signer_email']);
            Expect::notNull($user, 'the signer should have an account by now');

            $before = $app->memberships()->rolesFor((string) $user['id'], $organizationId);
            $app->clientAccess()->redeemInvitation($set['token'], InvitationRepository::PURPOSE_SIGN);
            $after = $app->memberships()->rolesFor((string) $user['id'], $organizationId);

            sort($before);
            sort($after);
            Expect::same($before, $after, 'opening a signing link must not widen what somebody may do');
        },

    'a signing link for somebody who holds no role here is spent and refused' =>
        static function (Bootstrap $app, Database $db) use ($boot, $outForSignature): void {
            [$app, $sent] = $boot($db);
            $set = $outForSignature($app, $sent, 'Fictional Behavioral Health LLC', 'Dana Owusu', 'dana@example.org');

            $organizationId = (string) $set['engagement']['organization_id'];
            $user = $app->users()->findByEmail($set['signer_email']);
            $app->memberships()->revokeAllForUser((string) $user['id']);

            Expect::null(
                $app->clientAccess()->redeemInvitation($set['token'], InvitationRepository::PURPOSE_SIGN),
                'a link for somebody with no role here should refuse'
            );
            Expect::null(
                $app->clientAccess()->context(),
                'and it should not have opened a session'
            );
        },

    'the vault directories are closed to the web' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db);

            // The deny-all files are what stop a document being fetched over
            // HTTP. They live in the repository, they are .htaccess rather than
            // .gitkeep because the deploy excludes the dot-git glob, and a
            // missing one means an executed agreement is a URL away.
            $root = dirname(__DIR__, 3);
            foreach (['agreements', 'signatures', 'config', 'audit-exports', 'backups'] as $folder) {
                $htaccess = $root . '/storage-private/soft-appeals/' . $folder . '/.htaccess';
                Expect::true(is_file($htaccess), $folder . ' should carry a deny-all .htaccess');
                Expect::true(
                    str_contains((string) file_get_contents($htaccess), 'Require all denied'),
                    $folder . ' should actually deny'
                );
            }

            Expect::true(
                is_file($root . '/storage-private/.htaccess'),
                'the whole private tree should be denied as well'
            );
        },
];
