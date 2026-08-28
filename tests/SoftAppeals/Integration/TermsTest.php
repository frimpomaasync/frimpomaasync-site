<?php
declare(strict_types=1);

/**
 * Phase 2 acceptance, the terms half.
 *
 *   sending terms requires a preview and explicit approval
 *   Resend revokes the previous unused invitation
 *   the existing confirmation language remains no-PHI
 *
 * No test here opens a socket. The transport is a closure that records what it
 * was handed, which is also how the body is inspected: the assertions read the
 * message that would actually have been sent, not a second copy built for the
 * test to look at.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\FitDecision;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\CommunicationRepository;
use SoftAppeals\Repositories\InvitationRepository;
use SoftAppeals\Services\TermsService;

$answers = [
    'organization'      => 'Fictional Behavioral Health LLC',
    'name'              => 'A Person',
    'email'             => 'a.person@example.org',
    'organization_type' => 'Behavioral health',
    'state'             => 'Maryland',
    'denial_volume'     => '51 to 100',
];

/**
 * An accepted inquiry with an engagement waiting at terms ready.
 *
 * @return array<string,mixed> the engagement row, joined with its organization
 */
$readyEngagement = static function (Bootstrap $app, array $answers): array {
    $intake = $app->intakeService()->record('soft-appeals-start', $answers, 'raw-body');
    $result = $app->intakeService()->review(
        $intake['id'],
        FitDecision::ACCEPT,
        null,
        null,
        EngagementTerms::FEE_CONTINGENCY_25,
        EngagementTerms::CHANNEL_DECIDE_LATER,
        'within ten business days'
    );
    return $app->engagements()->findWithOrganization((string) $result['engagement_id']);
};

/**
 * A second application, configured to email anybody, so the accepted path can
 * be exercised. The runner's own config carries an allowlist, which is correct
 * for staging and is itself tested below.
 *
 * @return array{0:Bootstrap,1:ArrayObject<int,array{to:string,subject:string,body:string}>}
 */
$unrestricted = static function (Database $db): array {
    $path = sys_get_temp_dir() . '/sa-terms-config-' . bin2hex(random_bytes(4)) . '.php';
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

    // An ArrayObject rather than a plain array, because the caller destructures
    // what comes back and a list assignment copies a value. A copy taken before
    // the send would still be empty when the assertions read it.
    $sent = new ArrayObject();
    $app->mail(static function (string $to, string $subject, string $body) use ($sent): bool {
        $sent->append(['to' => $to, 'subject' => $subject, 'body' => $body]);
        return true;
    });

    return [$app, $sent];
};

return [

    'the preview builds the whole email and sends nothing' =>
        static function (Bootstrap $app, Database $db) use ($answers, $readyEngagement): void {
            $engagement = $readyEngagement($app, $answers);
            $preview = $app->termsService()->preview($engagement);

            Expect::same(
                'Your Soft Appeals assessment onboarding terms',
                (string) $preview['subject'],
                'the subject from section 13.1'
            );
            Expect::same('a.person@example.org', (string) $preview['recipient_email'], 'to the person who asked');
            Expect::false($preview['is_resend'], 'nothing has gone yet');

            $body = (string) $preview['body'];
            Expect::true(str_contains($body, 'Hello A Person,'), 'it greets them by their first name');
            Expect::true(
                str_contains($body, "Fictional Behavioral Health LLC's denial situation"),
                'and names their practice'
            );
            Expect::true(
                str_contains($body, 'It does not produce 20 finished appeals.'),
                'it says outright that this is an assessment and not the appeals themselves'
            );
            Expect::true(
                str_contains($body, '25 percent of reimbursement that is actually recovered'),
                'the fee sentence matches the fee basis she chose'
            );
            Expect::true(
                str_contains($body, 'Do not reply with claim or patient information'),
                'the no-PHI line is in the message itself, not only on the page'
            );
            Expect::true(
                str_contains($body, 'Nana Frimpongmaa'),
                'it is signed with her name in full'
            );
            Expect::true(
                str_contains($body, '[the one-time link, created when you send this]'),
                'the preview shows a placeholder, because the link does not exist yet'
            );

            Expect::same(
                0,
                (int) $db->value('SELECT COUNT(*) FROM sa_invitations'),
                'a preview mints no link'
            );
            Expect::same(
                0,
                (int) $db->value('SELECT COUNT(*) FROM sa_communications'),
                'and records no send'
            );

            $still = $app->engagements()->find((string) $engagement['id']);
            Expect::same(Stage::TERMS_READY, (string) $still['stage'], 'and moves nothing');
        },

    'the email carries no answer the practice typed' =>
        static function (Bootstrap $app, Database $db) use ($readyEngagement): void {
            // A submission with something distinctive in a free-text field. It
            // must not come back out in a message that leaves the building.
            $engagement = $readyEngagement($app, [
                'organization'   => 'Fictional Behavioral Health LLC',
                'name'           => 'A Person',
                'email'          => 'a.person@example.org',
                'context'        => 'ZZQX-CANARY-STRING',
                'denial_volume'  => '51 to 100',
                'denied_value'   => '$25,001 to $50,000',
                'current_handling' => 'ZZQX-SECOND-CANARY',
            ]);
            $body = (string) $app->termsService()->preview($engagement)['body'];

            Expect::false(str_contains($body, 'ZZQX-CANARY-STRING'), 'a free-text answer is never echoed back');
            Expect::false(str_contains($body, 'ZZQX-SECOND-CANARY'), 'nor a second one');
            Expect::false(str_contains($body, '51 to 100'), 'nor a band');
            Expect::false(str_contains($body, '$25,001'), 'nor a figure');
        },

    'sending mints one link, records one message, and moves the stage' =>
        static function (Bootstrap $app, Database $db) use ($answers, $readyEngagement, $unrestricted): void {
            $engagement = $readyEngagement($app, $answers);
            [$sender, $sent] = $unrestricted($db);

            $result = $sender->termsService()->send($engagement, 0, null);

            Expect::true($result['sent'], 'the transport took it');
            Expect::same(CommunicationRepository::ACCEPTED, $result['state'], 'accepted, never delivered');
            Expect::same(1, count($sent), 'exactly one message');

            Expect::same(
                1,
                (int) $db->value('SELECT COUNT(*) FROM sa_invitations'),
                'one invitation'
            );
            $invitation = $db->one('SELECT * FROM sa_invitations');
            Expect::same(
                InvitationRepository::PURPOSE_PREFERENCES,
                (string) $invitation['purpose'],
                'purpose-bound'
            );
            Expect::same(64, strlen((string) $invitation['token_digest']), 'stored as a sha256 digest');
            Expect::null($invitation['used_at'], 'not used yet');
            Expect::null($invitation['revoked_at'], 'and not revoked');

            // The token itself must exist in the email and nowhere else.
            $body = $sent[0]['body'];
            Expect::true(
                str_contains($body, 'https://staging.frimpomaasync.com/soft-appeals-preferences.php?t='),
                'the link is in the message'
            );
            Expect::false(
                str_contains($body, (string) $invitation['token_digest']),
                'the digest is not the token, and must never appear in the message'
            );
            Expect::same(
                0,
                (int) $db->value(
                    'SELECT COUNT(*) FROM sa_audit_events WHERE metadata IS NOT NULL'
                        . " AND metadata LIKE '%soft-appeals-preferences.php%'"
                ),
                'no audit row carries the link'
            );

            $moved = $sender->engagements()->find((string) $engagement['id']);
            Expect::same(Stage::TERMS_SENT, (string) $moved['stage'], 'the stage moved once');

            $timeline = $sender->timeline()->forEngagement((string) $engagement['id']);
            $last = end($timeline);
            Expect::same('terms.sent', (string) $last['event_type'], 'and the client-visible timeline says so');
        },

    'submitting the same preview twice sends once' =>
        static function (Bootstrap $app, Database $db) use ($answers, $readyEngagement, $unrestricted): void {
            $engagement = $readyEngagement($app, $answers);
            [$sender, $sent] = $unrestricted($db);

            $sender->termsService()->send($engagement, 0, null);
            $again = $sender->termsService()->send($engagement, 0, null);

            Expect::same(1, count($sent), 'the second submit reaches no transport');
            Expect::same('this preview had already been sent', $again['reason'], 'and says why');
            Expect::same(
                1,
                (int) $db->value('SELECT COUNT(*) FROM sa_invitations'),
                'and mints no second link, so the first one is still the live one'
            );
        },

    'a resend rotates the link and kills the old one' =>
        static function (Bootstrap $app, Database $db) use ($answers, $readyEngagement, $unrestricted): void {
            $engagement = $readyEngagement($app, $answers);
            [$sender, $sent] = $unrestricted($db);

            $sender->termsService()->send($engagement, 0, null);
            $firstDigest = (string) $db->value('SELECT token_digest FROM sa_invitations');

            // A deliberate resend, from a freshly drawn page: the sequence has
            // moved on, so this is a new send and not a double submit.
            $reloaded = $sender->engagements()->findWithOrganization((string) $engagement['id']);
            $resent = $sender->termsService()->send($reloaded, 1, null);

            Expect::true($resent['resent'], 'it knows it is a resend');
            Expect::same(2, count($sent), 'two messages went out');
            Expect::same(
                2,
                (int) $db->value('SELECT COUNT(*) FROM sa_invitations'),
                'two invitations exist'
            );

            $old = $db->one('SELECT * FROM sa_invitations WHERE token_digest = :d', ['d' => $firstDigest]);
            Expect::notNull($old['revoked_at'], 'the first link is revoked');
            Expect::same(
                1,
                (int) $db->value('SELECT COUNT(*) FROM sa_invitations WHERE revoked_at IS NULL'),
                'exactly one live link at a time'
            );

            Expect::same(
                2,
                (int) $db->value('SELECT COUNT(*) FROM sa_communications'),
                'both sends are on the record, and the first is not lost'
            );
        },

    'a revoked or expired link cannot be redeemed' =>
        static function (Bootstrap $app, Database $db): void {
            $organizationId = $app->organizations()->create('Fictional Practice');
            $invitations = $app->invitations();

            $live = $invitations->mint(
                $organizationId,
                null,
                'a.person@example.org',
                InvitationRepository::PURPOSE_PREFERENCES,
                3600
            );
            Expect::notNull(
                $invitations->redeemable($live['token'], InvitationRepository::PURPOSE_PREFERENCES),
                'a live link works'
            );
            Expect::null(
                $invitations->redeemable($live['token'], InvitationRepository::PURPOSE_SIGN),
                'but only for the purpose it was minted for'
            );

            Expect::true($invitations->markUsed($live['id']), 'it can be used once');
            Expect::null(
                $invitations->redeemable($live['token'], InvitationRepository::PURPOSE_PREFERENCES),
                'and not twice'
            );

            $expired = $invitations->mint(
                $organizationId,
                null,
                'a.person@example.org',
                InvitationRepository::PURPOSE_PREFERENCES,
                -60
            );
            Expect::null(
                $invitations->redeemable($expired['token'], InvitationRepository::PURPOSE_PREFERENCES),
                'an expired link is refused'
            );
        },

    'the one-time link comes back off production, and never on it' =>
        static function (Bootstrap $app, Database $db) use ($answers, $readyEngagement): void {
            // Off production. The Desk shows the link because staging refuses
            // to email a real practice, and without it the client side cannot
            // be walked at all.
            $engagement = $readyEngagement($app, $answers);
            $app->mail(static fn (): bool => true);

            $result = $app->termsService()->send($engagement, 0, null);

            Expect::notNull($result['link'], 'staging should hand the link back');
            Expect::true(
                str_contains((string) $result['link'], 'soft-appeals-preferences.php?t='),
                'and it should be the real preferences link'
            );

            // On production the same call returns null, and the check is
            // against the environment itself rather than a feature flag, so
            // there is no setting anybody can switch that would start printing
            // live tokens on the live site.
            $path = sys_get_temp_dir() . '/sa-prod-config-' . bin2hex(random_bytes(4)) . '.php';
            file_put_contents($path, '<?php return ' . var_export([
                'SA_APP_ENV'           => 'production',
                'SA_APP_URL'           => 'https://frimpomaasync.com',
                'SA_BUSINESS_TIMEZONE' => 'America/New_York',
                'SA_SESSION_SECRET'    => str_repeat('test-session-secret-', 3),
                'SA_TOKEN_SECRET'      => str_repeat('test-token-secret-', 3),
                'SA_IP_HMAC_SECRET'    => str_repeat('test-ip-hmac-secret-', 3),
                'SA_DEMO_MODE'         => false,
                'SA_MAIL_ALLOWLIST'    => '',
            ], true) . ";\n");
            register_shutdown_function(static function () use ($path): void {
                @unlink($path);
            });

            $live = Bootstrap::boot($path, false);
            $live->useDatabase($db);
            $live->mail(static fn (): bool => true);

            $reloaded = $live->engagements()->findWithOrganization((string) $engagement['id']);

            // Sequence 1, because sequence 0 already went above and the
            // idempotency guard would return the earlier row instead of minting.
            $onLive = $live->termsService()->send($reloaded, 1, null);

            Expect::true($onLive['sent'], 'the send itself still works on production');
            Expect::null($onLive['link'], 'but production never hands a live token back to a page');
        },

    'this environment cannot email a practice that is not on its allowlist' =>
        static function (Bootstrap $app, Database $db) use ($answers, $readyEngagement): void {
            $engagement = $readyEngagement($app, $answers);

            $reached = 0;
            $app->mail(static function () use (&$reached): bool {
                $reached++;
                return true;
            });

            $result = $app->termsService()->send($engagement, 0, null);

            Expect::same(0, $reached, 'the transport was never opened');
            Expect::false($result['sent'], 'and nothing was sent');
            Expect::same(
                CommunicationRepository::REFUSED,
                $result['state'],
                'refused is its own state, not a failure, because the guard did its job'
            );
            Expect::same(
                1,
                (int) $db->value("SELECT COUNT(*) FROM sa_communications WHERE state = 'refused'"),
                'and the attempt is on the record'
            );

            // The decision she made still stands. A mail guard must not quietly
            // unwind the fact that she approved and issued the terms.
            $moved = $app->engagements()->find((string) $engagement['id']);
            Expect::same(Stage::TERMS_SENT, (string) $moved['stage'], 'the terms are still issued');
        },

    'the template version is recorded on every message' =>
        static function (Bootstrap $app, Database $db) use ($answers, $readyEngagement, $unrestricted): void {
            $engagement = $readyEngagement($app, $answers);
            [$sender] = $unrestricted($db);
            $sender->termsService()->send($engagement, 0, null);

            $row = $db->one('SELECT * FROM sa_communications');
            Expect::same(TermsService::TEMPLATE_KEY, (string) $row['template_key'], 'which template');
            Expect::false(trim((string) $row['template_version']) === '', 'and which version of it');
            Expect::false(
                (string) $row['state'] === 'delivered',
                'no message is ever marked delivered, because nothing here can know that'
            );
        },
];
