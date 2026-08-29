<?php
declare(strict_types=1);

/**
 * Phase 3 acceptance, the preferences half.
 *
 *   used or expired invitations cannot be replayed
 *   free-text fields show and enforce safe length and no-PHI warnings
 *   preferences update the engagement state once
 *   successful confirmation creates timeline and audit events
 *
 * Every case walks the real path: an inquiry arrives, she accepts it, the terms
 * go out, and the token is read back out of the message that was actually sent
 * rather than minted separately for the test. A test that mints its own token
 * proves the repository works and proves nothing about the page.
 *
 * No test here opens a socket. The transport is a closure that records what it
 * was handed.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\FitDecision;
use SoftAppeals\Domain\PreferenceForm;
use SoftAppeals\Domain\Role;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\InvitationRepository;
use SoftAppeals\Services\PreferencesService;

$answers = [
    'organization'      => 'Fictional Family Practice LLC',
    'name'              => 'A Person',
    'email'             => 'a.person@example.org',
    'organization_type' => 'Primary care',
    'state'             => 'Maryland',
    'denial_volume'     => '51 to 100',
];

/**
 * An application that may email anybody, with the transport captured.
 *
 * The runner's own config carries an allowlist, which is correct for staging
 * and is tested elsewhere. These cases need the accepted path.
 *
 * @return array{0:Bootstrap,1:ArrayObject<int,array{to:string,subject:string,body:string}>}
 */
$unrestricted = static function (Database $db): array {
    $path = sys_get_temp_dir() . '/sa-prefs-config-' . bin2hex(random_bytes(4)) . '.php';
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
 * An accepted inquiry whose terms have been sent, and the token out of the
 * email that carried them.
 *
 * @return array{engagement:array<string,mixed>,token:string}
 */
$sentTerms = static function (Bootstrap $app, ArrayObject $sent, array $answers): array {
    $intake = $app->intakeService()->record('soft-appeals-start', $answers, 'raw-body-' . bin2hex(random_bytes(4)));
    $review = $app->intakeService()->review(
        $intake['id'],
        FitDecision::ACCEPT,
        null,
        null,
        EngagementTerms::FEE_CONTINGENCY_25,
        EngagementTerms::CHANNEL_DECIDE_LATER,
        'within ten business days'
    );

    $engagement = $app->engagements()->findWithOrganization((string) $review['engagement_id']);
    $app->termsService()->send($engagement, 0, null);

    $body = '';
    foreach ($sent as $message) {
        if (str_contains($message['body'], 'soft-appeals-preferences.php?t=')) {
            $body = $message['body'];
        }
    }

    $found = preg_match('~soft-appeals-preferences\.php\?t=([0-9a-f]+)~', $body, $matches);
    Expect::same(1, $found, 'the terms email should carry a preferences link with a token');

    return [
        'engagement' => $app->engagements()->findWithOrganization((string) $review['engagement_id']),
        'token'      => $matches[1],
    ];
};

/** A complete, valid set of answers. */
$goodAnswers = [
    'communication_cadence' => EngagementTerms::CADENCE_BIWEEKLY,
    'secure_channel'        => EngagementTerms::CHANNEL_CLIENT_SYSTEM,
    'billing_partner'       => PreferenceForm::PARTNER_YES,
    'signer_name'           => 'Dana Owusu',
    'signer_role'           => 'Practice owner',
    'signer_email'          => 'dana@example.org',
    'approver_name'         => '',
    'approver_role'         => '',
    'approver_email'        => '',
    'billing_name'          => 'Sam Reyes',
    'billing_role'          => 'Billing manager',
    'billing_email'         => 'sam@example.org',
    'initial_payer_group'   => 'Commercial behavioral health, mostly prior authorization denials',
    'procurement_notes'     => 'We need a certificate of insurance before onboarding.',
];

return [

    'a fresh invitation opens a client session and burns the link' =>
        static function (Bootstrap $app, Database $db) use ($answers, $unrestricted, $sentTerms): void {
            [$app, $sent] = $unrestricted($db);
            $scenario = $sentTerms($app, $sent, $answers);

            $redeemed = $app->clientAccess()->redeemInvitation(
                $scenario['token'],
                InvitationRepository::PURPOSE_PREFERENCES
            );

            Expect::notNull($redeemed, 'a live invitation should redeem');
            Expect::same('client', $app->session()->kind(), 'the session should be a client session');
            Expect::same(
                (string) $scenario['engagement']['organization_id'],
                $app->session()->organizationId(),
                'the session should be pinned to the practice the invitation named'
            );

            $again = $app->clientAccess()->redeemInvitation(
                $scenario['token'],
                InvitationRepository::PURPOSE_PREFERENCES
            );
            Expect::null($again, 'a used invitation must not be redeemable a second time');
        },

    'an expired invitation is refused' =>
        static function (Bootstrap $app, Database $db) use ($answers, $unrestricted, $sentTerms): void {
            [$app, $sent] = $unrestricted($db);
            $scenario = $sentTerms($app, $sent, $answers);

            // Age it. The expiry is checked against the clock, so moving the
            // stored date backwards is the same thing as waiting.
            $db->run(
                'UPDATE sa_invitations SET expires_at = :e WHERE purpose = :p',
                ['e' => '2020-01-01 00:00:00', 'p' => InvitationRepository::PURPOSE_PREFERENCES]
            );

            Expect::null(
                $app->clientAccess()->redeemInvitation(
                    $scenario['token'],
                    InvitationRepository::PURPOSE_PREFERENCES
                ),
                'an expired invitation must be refused'
            );
        },

    'a token for one purpose does not open another' =>
        static function (Bootstrap $app, Database $db) use ($answers, $unrestricted, $sentTerms): void {
            [$app, $sent] = $unrestricted($db);
            $scenario = $sentTerms($app, $sent, $answers);

            Expect::null(
                $app->clientAccess()->redeemInvitation(
                    $scenario['token'],
                    InvitationRepository::PURPOSE_SIGN
                ),
                'a preferences token must not redeem as a signing token'
            );
        },

    'confirming moves the engagement to preferences confirmed' =>
        static function (Bootstrap $app, Database $db) use ($answers, $goodAnswers, $unrestricted, $sentTerms): void {
            [$app, $sent] = $unrestricted($db);
            $scenario = $sentTerms($app, $sent, $answers);
            $engagement = $scenario['engagement'];

            Expect::same(Stage::TERMS_SENT, (string) $engagement['stage'], 'the terms should have gone out');

            $result = $app->preferencesService()->confirm($engagement, $goodAnswers, null, null);

            Expect::true($result['saved'], 'a complete set of answers should save');
            Expect::true($result['first_confirmation'], 'this should be the first confirmation');

            $after = $app->engagements()->find((string) $engagement['id']);
            Expect::same(
                Stage::PREFERENCES_CONFIRMED,
                (string) $after['stage'],
                'the engagement should have moved to preferences confirmed'
            );
        },

    'confirming twice moves the stage once and emails once' =>
        static function (Bootstrap $app, Database $db) use ($answers, $goodAnswers, $unrestricted, $sentTerms): void {
            [$app, $sent] = $unrestricted($db);
            $scenario = $sentTerms($app, $sent, $answers);
            $engagement = $scenario['engagement'];

            $app->preferencesService()->confirm($engagement, $goodAnswers, null, null);

            // Second time round, read the row back the way a page would.
            $reloaded = $app->engagements()->findWithOrganization((string) $engagement['id']);
            $changed = $goodAnswers;
            $changed['communication_cadence'] = EngagementTerms::CADENCE_MONTHLY;
            $second = $app->preferencesService()->confirm($reloaded, $changed, null, null);

            Expect::true($second['saved'], 'a change should still save');
            Expect::false($second['first_confirmation'], 'the second one must not count as a confirmation');

            $after = $app->engagements()->find((string) $engagement['id']);
            Expect::same(
                Stage::PREFERENCES_CONFIRMED,
                (string) $after['stage'],
                'the stage must not move a second time'
            );

            $confirmations = 0;
            foreach ($sent as $message) {
                if ($message['subject'] === PreferencesService::SUBJECT) {
                    $confirmations++;
                }
            }
            Expect::same(1, $confirmations, 'only one confirmation email should ever go out');

            $events = 0;
            foreach ($app->timeline()->forEngagement((string) $engagement['id']) as $event) {
                if ((string) $event['event_type'] === 'preferences.confirmed') {
                    $events++;
                }
            }
            Expect::same(1, $events, 'the timeline should carry one confirmation, not two');

            $stored = $app->preferences()->forEngagement((string) $engagement['id']);
            Expect::same(
                EngagementTerms::CADENCE_MONTHLY,
                (string) $stored['communication_cadence'],
                'the changed answer should still be stored'
            );
        },

    'confirming creates a timeline event a client can read, and an audit event' =>
        static function (Bootstrap $app, Database $db) use ($answers, $goodAnswers, $unrestricted, $sentTerms): void {
            [$app, $sent] = $unrestricted($db);
            $scenario = $sentTerms($app, $sent, $answers);
            $engagement = $scenario['engagement'];

            $app->preferencesService()->confirm($engagement, $goodAnswers, null, null);

            $found = null;
            foreach ($app->timeline()->forEngagement((string) $engagement['id']) as $event) {
                if ((string) $event['event_type'] === 'preferences.confirmed') {
                    $found = $event;
                }
            }
            Expect::notNull($found, 'the confirmation should appear on the client timeline');
            Expect::same('client', (string) $found['actor_type'], 'the practice did it, not her');
            Expect::true(
                str_contains((string) $found['public_label'], 'preferences'),
                'the timeline line should be readable by the practice'
            );

            $audited = false;
            foreach ($app->audit()->recent(50) as $row) {
                if ((string) $row['action'] === 'preferences.confirm'
                    && (string) $row['outcome'] === 'success'
                ) {
                    $audited = true;
                }
            }
            Expect::true($audited, 'a successful confirmation should be audited');
        },

    'the three named people become contacts with one role each' =>
        static function (Bootstrap $app, Database $db) use ($answers, $goodAnswers, $unrestricted, $sentTerms): void {
            [$app, $sent] = $unrestricted($db);
            $scenario = $sentTerms($app, $sent, $answers);
            $engagement = $scenario['engagement'];
            $organizationId = (string) $engagement['organization_id'];

            $app->preferencesService()->confirm($engagement, $goodAnswers, null, null);

            $signer = $app->contacts()->findByEmail($organizationId, 'dana@example.org');
            Expect::notNull($signer, 'the signer should exist as a contact');
            Expect::same('Dana Owusu', (string) $signer['name'], 'the signer should carry the name given');

            $signerUser = $app->users()->findByEmail('dana@example.org');
            Expect::notNull($signerUser, 'the signer should have a user row for signing later');
            Expect::null($signerUser['password_hash'], 'a client never has a password');
            Expect::true(
                $app->memberships()->has((string) $signerUser['id'], Role::AUTHORIZED_SIGNER, $organizationId),
                'the signer should hold the signing role'
            );
            Expect::false(
                $app->memberships()->has((string) $signerUser['id'], Role::BILLING, $organizationId),
                'the signer should not pick up a role nobody gave them'
            );

            $billingUser = $app->users()->findByEmail('sam@example.org');
            Expect::notNull($billingUser, 'the billing contact should have a user row');
            Expect::true(
                $app->memberships()->has((string) $billingUser['id'], Role::BILLING, $organizationId),
                'the billing contact should hold the billing role'
            );

            // Question 3 was left blank, so nobody was invented for it.
            $stored = $app->preferences()->forEngagement((string) $engagement['id']);
            Expect::null($stored['approver_contact_id'], 'an unanswered question should name nobody');
        },

    'a free-text answer that carries a member number is refused' =>
        static function (Bootstrap $app, Database $db) use ($answers, $goodAnswers, $unrestricted, $sentTerms): void {
            [$app, $sent] = $unrestricted($db);
            $scenario = $sentTerms($app, $sent, $answers);

            $bad = $goodAnswers;
            $bad['initial_payer_group'] = 'Start with member 483920175, the one that keeps bouncing';

            $result = $app->preferencesService()->confirm($scenario['engagement'], $bad, null, null);

            Expect::false($result['saved'], 'an answer carrying an identifier must not save');
            Expect::true(
                isset($result['errors']['initial_payer_group']),
                'the objection should sit on the field it objects to'
            );
            Expect::same(
                Stage::TERMS_SENT,
                (string) $app->engagements()->find((string) $scenario['engagement']['id'])['stage'],
                'a refused submission must not move the engagement'
            );
        },

    'a free-text answer over the cap is refused' =>
        static function (Bootstrap $app, Database $db) use ($answers, $goodAnswers, $unrestricted, $sentTerms): void {
            [$app, $sent] = $unrestricted($db);
            $scenario = $sentTerms($app, $sent, $answers);

            $bad = $goodAnswers;
            $bad['procurement_notes'] = str_repeat('a', PreferenceForm::FREE_TEXT_MAX + 1);

            $result = $app->preferencesService()->confirm($scenario['engagement'], $bad, null, null);

            Expect::false($result['saved'], 'an answer over the cap must not save');
            Expect::true(
                isset($result['errors']['procurement_notes']),
                'the message should name the field that is too long'
            );
        },

    'a submission with no signer is refused' =>
        static function (Bootstrap $app, Database $db) use ($answers, $goodAnswers, $unrestricted, $sentTerms): void {
            [$app, $sent] = $unrestricted($db);
            $scenario = $sentTerms($app, $sent, $answers);

            $bad = $goodAnswers;
            $bad['signer_name'] = '';
            $bad['signer_role'] = '';
            $bad['signer_email'] = '';

            $result = $app->preferencesService()->confirm($scenario['engagement'], $bad, null, null);

            Expect::false($result['saved'], 'there is no next step without somebody to sign');
            Expect::true(isset($result['errors']['signer']), 'the message should be on the signer question');
        },

    'a choice nobody offers is refused' =>
        static function (Bootstrap $app, Database $db) use ($answers, $goodAnswers, $unrestricted, $sentTerms): void {
            [$app, $sent] = $unrestricted($db);
            $scenario = $sentTerms($app, $sent, $answers);

            $bad = $goodAnswers;
            $bad['communication_cadence'] = 'hourly';

            $result = $app->preferencesService()->confirm($scenario['engagement'], $bad, null, null);

            Expect::false($result['saved'], 'a posted cadence nobody offers must be refused');
            Expect::true(
                isset($result['errors']['communication_cadence']),
                'the message should name the cadence question'
            );
        },

    'the confirmation email says what was chosen and carries no link' =>
        static function (Bootstrap $app, Database $db) use ($answers, $goodAnswers, $unrestricted, $sentTerms): void {
            [$app, $sent] = $unrestricted($db);
            $scenario = $sentTerms($app, $sent, $answers);

            $app->preferencesService()->confirm($scenario['engagement'], $goodAnswers, null, null);

            $confirmation = null;
            foreach ($sent as $message) {
                if ($message['subject'] === PreferencesService::SUBJECT) {
                    $confirmation = $message;
                }
            }
            Expect::notNull($confirmation, 'a confirmation email should have gone out');
            Expect::same(
                'a.person@example.org',
                $confirmation['to'],
                'it should go to the address the terms went to'
            );
            Expect::true(
                str_contains($confirmation['body'], 'Every two weeks'),
                'it should say what they actually chose'
            );
            Expect::true(
                str_contains($confirmation['body'], 'Do not send claim information yet'),
                'it should carry the boundary the plan requires'
            );
            Expect::false(
                str_contains($confirmation['body'], 'http'),
                'a confirmation carries no link, so a forwarded copy opens nothing'
            );
        },

    'the summary reads back only what was answered' =>
        static function (Bootstrap $app, Database $db) use ($answers, $goodAnswers, $unrestricted, $sentTerms): void {
            [$app, $sent] = $unrestricted($db);
            $scenario = $sentTerms($app, $sent, $answers);
            $engagementId = (string) $scenario['engagement']['id'];

            Expect::same([], $app->preferencesService()->summary($engagementId), 'nothing chosen, nothing shown');

            $app->preferencesService()->confirm($scenario['engagement'], $goodAnswers, null, null);

            $labels = array_map(
                static fn (array $row): string => $row['label'],
                $app->preferencesService()->summary($engagementId)
            );

            Expect::true(in_array('Signing the agreements', $labels, true), 'the signer should be listed');
            Expect::true(in_array('Recovery and invoices', $labels, true), 'the billing contact should be listed');
            Expect::false(
                in_array('Approving submissions', $labels, true),
                'a question nobody answered should not appear as an empty row'
            );
        },

];
