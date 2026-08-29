<?php
declare(strict_types=1);

/**
 * The legal entity name, set from the Desk.
 *
 * SA_LEGAL_ENTITY sat unset in the server config for a day while every
 * document on staging named "Legal entity name not confirmed yet". The fix is
 * a setting the owner writes from a screen. These cases prove the order of
 * precedence: the Desk value wins, a blank Desk value falls back to the file,
 * and a blank in both is still Config's own answer.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\FitDecision;
use SoftAppeals\Domain\PreferenceForm;
use SoftAppeals\Repositories\InvitationRepository;
use SoftAppeals\Repositories\SettingsRepository;

$boot = static function (Database $db, array $overrides = []): array {
    $vault = sys_get_temp_dir() . '/sa-set-vault-' . bin2hex(random_bytes(4));
    $path = sys_get_temp_dir() . '/sa-set-config-' . bin2hex(random_bytes(4)) . '.php';
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
    return [$app, $sent];
};

/** An engagement ready for a BAA, the shortest walk that reaches generate(). */
$ready = static function (Bootstrap $app, ArrayObject $sent): array {
    $intake = $app->intakeService()->record('soft-appeals-start', [
        'organization'      => 'Fictional Behavioral Health LLC',
        'name'              => 'Dana Owusu',
        'email'             => 'dana@example.org',
        'organization_type' => 'Behavioral health',
        'state'             => 'Maryland',
        'denial_volume'     => '51 to 100',
    ], 'raw-' . bin2hex(random_bytes(4)));
    $review = $app->intakeService()->review($intake['id'], FitDecision::ACCEPT, null, null, EngagementTerms::FEE_CONTINGENCY_25, EngagementTerms::CHANNEL_CLIENT_SYSTEM, null);
    $engagementId = (string) $review['engagement_id'];
    $app->termsService()->send($app->engagements()->findWithOrganization($engagementId), 0, null);
    $token = '';
    foreach ($sent as $message) {
        if (preg_match('~soft-appeals-preferences\.php\?t=([0-9a-f]+)~', $message['body'], $m) === 1) {
            $token = $m[1];
        }
    }
    $app->clientAccess()->redeemInvitation($token, InvitationRepository::PURPOSE_PREFERENCES);
    $context = $app->clientAccess()->context();
    $app->preferencesService()->confirm($app->engagements()->findWithOrganization($engagementId), [
        'communication_cadence' => EngagementTerms::CADENCE_BIWEEKLY,
        'secure_channel'        => EngagementTerms::CHANNEL_CLIENT_SYSTEM,
        'billing_partner'       => PreferenceForm::PARTNER_YES,
        'signer_name'           => 'Dana Owusu',
        'signer_role'           => 'Practice owner',
        'signer_email'          => 'dana@example.org',
        'approver_name'         => '', 'approver_role' => '', 'approver_email' => '',
        'billing_name'          => '', 'billing_role' => '', 'billing_email' => '',
        'initial_payer_group'   => 'Commercial',
        'procurement_notes'     => '',
    ], (string) $context['user']['id'], $context['contact_id']);
    $app->clientAccess()->signOut();
    return $app->engagements()->findWithOrganization($engagementId);
};

return [

    'with nothing set anywhere, the document names the placeholder off production' =>
        static function (Bootstrap $app, Database $db) use ($boot, $ready): void {
            [$app, $sent] = $boot($db);
            Expect::same('none', $app->settings()->legalEntitySource($app->config()), 'no source');
            $engagement = $ready($app, $sent);
            $document = $app->documentService()->generate($engagement, DocumentKind::BAA, null);
            $body = (string) $app->documentService()->body($document);
            Expect::true(str_contains($body, 'Legal entity name not confirmed yet'), 'the placeholder should say what it is');
        },

    'a name set on the Desk goes on the next document and wins over the config file' =>
        static function (Bootstrap $app, Database $db) use ($boot, $ready): void {
            [$app, $sent] = $boot($db, ['SA_LEGAL_ENTITY' => 'The Config File Entity LLC']);
            Expect::same('config', $app->settings()->legalEntitySource($app->config()), 'the file is the source');

            $app->settings()->set(SettingsRepository::LEGAL_ENTITY, '  Frimpomaa Sync Inc.  ', null);
            Expect::same('Frimpomaa Sync Inc.', $app->settings()->legalEntity($app->config()), 'trimmed and stored');
            Expect::same('desk', $app->settings()->legalEntitySource($app->config()), 'the Desk is the source now');

            $engagement = $ready($app, $sent);
            $document = $app->documentService()->generate($engagement, DocumentKind::BAA, null);
            $body = (string) $app->documentService()->body($document);
            Expect::true(str_contains($body, 'Frimpomaa Sync Inc.'), 'the Desk name should be on the face of the document');
            Expect::false(str_contains($body, 'The Config File Entity LLC'), 'and the file name should not');
            Expect::false(str_contains($body, 'not confirmed yet'), 'and no placeholder');
        },

    'clearing the Desk value falls back to the file' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db, ['SA_LEGAL_ENTITY' => 'The Config File Entity LLC']);
            $app->settings()->set(SettingsRepository::LEGAL_ENTITY, 'Temporary Name LLC', null);
            $app->settings()->set(SettingsRepository::LEGAL_ENTITY, '', null);
            Expect::same('The Config File Entity LLC', $app->settings()->legalEntity($app->config()), 'blank means the file');
            Expect::same('config', $app->settings()->legalEntitySource($app->config()), 'and says so');
        },

    'on production a blank name still refuses to generate, Desk or no Desk' =>
        static function (Bootstrap $app, Database $db) use ($boot, $ready): void {
            [$app, $sent] = $boot($db, ['SA_APP_ENV' => 'production', 'SA_E_SIGN_ENABLED' => true]);
            Expect::same('', $app->settings()->legalEntity($app->config()), 'production blank is empty, not a placeholder');
            $engagement = $ready($app, $sent);
            $check = $app->documentService()->canGenerate($engagement, DocumentKind::BAA);
            Expect::false($check['ok'], 'generating should be refused');
            Expect::true(str_contains((string) $check['reason'], 'legal entity'), 'and the reason should name the field');

            $app->settings()->set(SettingsRepository::LEGAL_ENTITY, 'A Real Entity LLC', null);
            $check = $app->documentService()->canGenerate($engagement, DocumentKind::BAA);
            Expect::true($check['ok'], 'with a name set, generating is allowed again');
            Expect::false($app->config()->eSignEnabled(), 'but production signing stays clamped shut');
        },

    'an unknown setting key is refused, and secrets have no door here' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db);
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->settings()->set('SA_DB_PASSWORD', 'nope', null),
                'a secret key should be refused'
            );
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->settings()->get('anything_else'),
                'an unlisted key should be refused'
            );
            Expect::same([SettingsRepository::LEGAL_ENTITY, SettingsRepository::TRADE_NAME], SettingsRepository::keys(), 'two keys, no more');
        },
];
