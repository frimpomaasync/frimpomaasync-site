<?php
declare(strict_types=1);

/**
 * Phase 5 acceptance, section 22.
 *
 *   the portal contains no patient-level fields or upload element
 *     (Unit/PortalBoundaryTest, which reads the templates)
 *   every aggregate deadline is marked confirmed or unconfirmed
 *   the client can choose internal use, more information, recovery scope, or
 *     no further action
 *   milestone changes create status and audit events
 *   a client cannot activate recovery without the next agreement gate
 *
 * Every case walks the real path from an inquiry arriving, through both
 * agreements, to the secure route opening, exactly as DocumentsTest does, and
 * only then starts the assessment. The walk is long and it is the point: an
 * assessment test that planted an engagement at "secure intake ready" by
 * writing the column would prove nothing about the gate.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\ActionRequestKind;
use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Domain\Checklist;
use SoftAppeals\Domain\ClientDecision;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\FitDecision;
use SoftAppeals\Domain\PreferenceForm;
use SoftAppeals\Domain\Role;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\InvitationRepository;

$answers = [
    'organization'      => 'Fictional Behavioral Health LLC',
    'name'              => 'Dana Owusu',
    'email'             => 'dana@example.org',
    'organization_type' => 'Behavioral health',
    'state'             => 'Maryland',
    'denial_volume'     => '51 to 100',
];

$preferences = [
    'communication_cadence' => EngagementTerms::CADENCE_BIWEEKLY,
    'secure_channel'        => EngagementTerms::CHANNEL_CLIENT_SYSTEM,
    'billing_partner'       => PreferenceForm::PARTNER_YES,
    'signer_name'           => 'Dana Owusu',
    'signer_role'           => 'Practice owner',
    'signer_email'          => 'dana@example.org',
    'approver_name'         => '',
    'approver_role'         => '',
    'approver_email'        => '',
    'billing_name'          => '',
    'billing_role'          => '',
    'billing_email'         => '',
    'initial_payer_group'   => 'Commercial behavioral health',
    'procurement_notes'     => '',
];

/** @return array{0:Bootstrap,1:ArrayObject<int,array{to:string,subject:string,body:string}>} */
$boot = static function (Database $db, array $overrides = []): array {
    $vault = sys_get_temp_dir() . '/sa-as-vault-' . bin2hex(random_bytes(4));
    $path = sys_get_temp_dir() . '/sa-as-config-' . bin2hex(random_bytes(4)) . '.php';

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
        'SA_OWNER_EMAIL'          => 'owner@example.org',
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

$owner = static function (Bootstrap $app): string {
    $existing = $app->users()->findByEmail('owner@example.org');
    if ($existing !== null) {
        return (string) $existing['id'];
    }
    $id = $app->users()->create('owner@example.org');
    $app->memberships()->grant($id, Role::OWNER_ADMIN);
    return $id;
};

/**
 * From nothing to "secure intake ready", the only honest starting point.
 *
 * @return array<string,mixed> the engagement joined with its organization
 */
$atSecureRoute = static function (Bootstrap $app, ArrayObject $sent) use ($answers, $preferences, $owner): array {
    $intake = $app->intakeService()->record('soft-appeals-start', $answers, 'raw-' . bin2hex(random_bytes(4)));
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
    $engagement = $app->engagements()->findWithOrganization($engagementId);
    $app->termsService()->send($engagement, 0, null);

    $token = '';
    foreach ($sent as $message) {
        if (preg_match('~soft-appeals-preferences\.php\?t=([0-9a-f]+)~', $message['body'], $m) === 1) {
            $token = $m[1];
        }
    }
    Expect::true($token !== '', 'the terms email should carry a preferences token');
    Expect::notNull(
        $app->clientAccess()->redeemInvitation($token, InvitationRepository::PURPOSE_PREFERENCES),
        'the preferences invitation should redeem'
    );
    $context = $app->clientAccess()->context();
    $result = $app->preferencesService()->confirm(
        $app->engagements()->findWithOrganization($engagementId),
        $preferences,
        (string) $context['user']['id'],
        $context['contact_id']
    );
    Expect::true($result['saved'], 'the preferences should save');
    $app->clientAccess()->signOut();

    foreach ([DocumentKind::BAA, DocumentKind::REVIEW_AUTHORIZATION] as $kind) {
        $engagement = $app->engagements()->findWithOrganization($engagementId);
        $document = $app->documentService()->generate($engagement, $kind, null);
        $app->documentService()->send($document, $engagement, null);
        $document = $app->documents()->find((string) $document['id']);

        $signToken = '';
        foreach ($sent as $message) {
            if (preg_match('~soft-appeals-sign\?t=([0-9a-f]+)~', $message['body'], $m) === 1) {
                $signToken = $m[1];
            }
        }
        Expect::notNull(
            $app->clientAccess()->redeemInvitation($signToken, InvitationRepository::PURPOSE_SIGN),
            'the signing invitation should redeem'
        );
        $context = $app->clientAccess()->context();
        $signed = $app->signingService()->sign($document, [
            'organization_id' => (string) $engagement['organization_id'],
            'engagement'      => $engagement,
            'contact_id'      => $context['contact_id'],
            'user_id'         => (string) $context['user']['id'],
        ], [
            'typed_name'         => 'Dana Owusu',
            'typed_title'        => 'Practice owner',
            'typed_organization' => (string) $engagement['legal_name'],
            'consent'            => true,
            'document_sha256'    => (string) $document['content_sha256'],
        ]);
        Expect::true($signed['signed'], 'the practice should be able to sign');
        $app->clientAccess()->signOut();

        $app->documentService()->countersign($app->documents()->find((string) $document['id']), $engagement, [
            'typed_name'  => 'Nana Frimpongmaa',
            'typed_title' => 'Owner',
            'consent'     => true,
        ], $owner($app));
    }

    $app->engagementService()->move(
        $engagementId,
        Stage::SECURE_INTAKE_READY,
        'The secure route is open',
        'engagement.secure_route_open',
        $owner($app)
    );

    return $app->engagements()->findWithOrganization($engagementId);
};

/** The practice's session, for the client-side calls. */
$asClient = static function (Bootstrap $app, array $engagement): array {
    $contact = $app->contacts()->findByEmail((string) $engagement['organization_id'], 'dana@example.org');
    Expect::notNull($contact, 'the signer contact should exist');
    $user = $app->users()->findByEmail('dana@example.org');
    Expect::notNull($user, 'the signer user should exist');
    $app->session()->start();
    $app->session()->establish(\SoftAppeals\Auth\SessionManager::KIND_CLIENT, (string) $user['id'], (string) $engagement['organization_id']);
    return ['user_id' => (string) $user['id'], 'contact_id' => (string) $contact['id']];
};

/** Walk to "delivered", so the decision cases start where they need to. */
$delivered = static function (Bootstrap $app, ArrayObject $sent, array $engagement, string $ownerId): array {
    $service = $app->assessmentService();
    $service->confirmReceipt($engagement, 20, 20, $app->workBatchService()->fieldsFromInput([
        'label' => 'Initial set', 'payer_label' => 'Commercial', 'denied_amount' => '18,400.00',
        'earliest_deadline' => '2026-12-01', 'deadline_confirmed' => '',
    ]), $ownerId);
    $service->start($engagement, $ownerId);
    $service->sendToQualityReview($engagement, $ownerId);
    $service->deliver($engagement, [
        'summary'                  => 'Twenty denials reviewed. Twelve are timely-filing and coding denials with a clear path; eight are not worth pursuing.',
        'recommended_count'        => 12,
        'recommended_amount_cents' => 1120000,
        'decision_due'             => '2026-10-15',
    ], $ownerId);
    return $app->engagements()->findWithOrganization((string) $engagement['id']);
};

return [

    'nothing on the assessment can happen before the secure route is open' =>
        static function (Bootstrap $app, Database $db) use ($boot, $answers, $preferences, $owner): void {
            [$app] = $boot($db);
            $intake = $app->intakeService()->record('soft-appeals-start', $answers, 'raw-' . bin2hex(random_bytes(4)));
            $review = $app->intakeService()->review($intake['id'], FitDecision::ACCEPT, null, null, EngagementTerms::FEE_CONTINGENCY_25, EngagementTerms::CHANNEL_CLIENT_SYSTEM, null);
            $engagement = $app->engagements()->findWithOrganization((string) $review['engagement_id']);

            Expect::null($app->assessmentService()->forEngagement($engagement), 'no assessment row before the gate');
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->assessmentService()->confirmReceipt($engagement, 20, 20, [], $owner($app)),
                'confirming receipt at terms_ready should be refused'
            );
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->workBatchService()->open($engagement, ['label' => 'Early'], $owner($app)),
                'a batch before the gate should be refused'
            );
            Expect::same(0, count($app->workBatches()->forEngagement((string) $engagement['id'])), 'no batch should exist');
        },

    'confirming receipt moves the stage, opens the first batch, asks the practice, and writes both trails' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner): void {
            [$app, $sent] = $boot($db);
            $engagement = $atSecureRoute($app, $sent);
            $ownerId = $owner($app);
            $before = count($sent);

            $assessment = $app->assessmentService()->confirmReceipt($engagement, 20, 20, $app->workBatchService()->fieldsFromInput([
                'label' => 'Initial set', 'denied_amount' => '18,400.00', 'earliest_deadline' => '2026-12-01',
            ]), $ownerId);

            Expect::same(20, (int) $assessment['received_count'], 'the count should be stored');
            Expect::notNull($assessment['receipt_confirmed_at'], 'the receipt should be stamped');

            $after = $app->engagements()->find((string) $engagement['id']);
            Expect::same(Stage::RECEIPT_CONFIRMED, (string) $after['stage'], 'the stage should move to receipt confirmed');

            $batches = $app->workBatches()->forEngagement((string) $engagement['id']);
            Expect::same(1, count($batches), 'one batch should be open');
            Expect::same(20, (int) $batches[0]['claim_count'], 'the batch should carry the count');
            Expect::same(1840000, (int) $batches[0]['denied_amount_cents'], 'the amount should be integer cents');
            Expect::same(0, (int) $batches[0]['deadline_confirmed'], 'an unticked deadline is unconfirmed');
            Expect::same(BatchStage::RECEIVED, (string) $batches[0]['stage'], 'the batch should be received');

            $open = $app->actionRequests()->openOfKind((string) $engagement['id'], ActionRequestKind::CONFIRM_RECEIPT_COUNT);
            Expect::notNull($open, 'the practice should be asked to confirm the count');
            Expect::same(ActionRequestKind::OWNER_CLIENT, (string) $open['owner'], 'it waits on the client');
            Expect::true(count($sent) > $before, 'the practice should have been emailed that something is waiting');
            $last = $sent[count($sent) - 1];
            Expect::false(str_contains($last['body'], 'Initial set'), 'the email must not carry the request itself');

            // Milestone changes create status and audit events.
            $labels = array_map(static fn (array $e): string => (string) $e['public_label'], $app->timeline()->forEngagement((string) $engagement['id']));
            Expect::true(in_array('Initial denial set received', $labels, true), 'the timeline should say the set arrived');
            $audit = $app->audit()->forObject('engagement', (string) $engagement['id']);
            $moved = array_filter($audit, static fn (array $row): bool => (string) $row['action'] === 'engagement.transition'
                && str_contains((string) ($row['metadata'] ?? ''), Stage::RECEIPT_CONFIRMED));
            Expect::true($moved !== [], 'the audit trail should hold the transition');
        },

    'a deadline cannot be confirmed without a date, and a dated one is labelled either way' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db);
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->workBatchService()->fieldsFromInput(['deadline_confirmed' => 'yes']),
                'a confirmed deadline with no date should be refused'
            );
            $unconfirmed = $app->workBatchService()->fieldsFromInput(['earliest_deadline' => '2026-11-30']);
            Expect::same('2026-11-30 12:00:00', $unconfirmed['earliest_deadline_at'], 'the date should be stored at noon UTC');
            Expect::false($unconfirmed['deadline_confirmed'], 'unticked means unconfirmed');
            $confirmed = $app->workBatchService()->fieldsFromInput(['earliest_deadline' => '2026-11-30', 'deadline_confirmed' => 'yes']);
            Expect::true($confirmed['deadline_confirmed'], 'ticked means confirmed');
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->workBatchService()->fieldsFromInput(['earliest_deadline' => '2026-02-30']),
                'an impossible date should be refused'
            );
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->workBatchService()->fieldsFromInput(['denied_amount' => '12.345']),
                'a third decimal should be refused rather than rounded'
            );
        },

    'the walk to delivered writes a line for every milestone and emails the practice once' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner, $delivered): void {
            [$app, $sent] = $boot($db);
            $engagement = $atSecureRoute($app, $sent);
            $ownerId = $owner($app);

            $service = $app->assessmentService();
            $service->confirmReceipt($engagement, 20, 20, ['label' => 'Initial set'], $ownerId);
            $service->start($engagement, $ownerId);
            Expect::same(Stage::ASSESSMENT_IN_PROGRESS, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'started');
            Expect::same(BatchStage::IN_REVIEW, (string) $app->workBatches()->forEngagement((string) $engagement['id'])[0]['stage'], 'the batch should be in review');

            $service->sendToQualityReview($engagement, $ownerId);
            Expect::same(Stage::ASSESSMENT_QA, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'in quality review');
            $service->returnToWork($engagement, $ownerId);
            Expect::same(Stage::ASSESSMENT_IN_PROGRESS, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'returned');
            Expect::throws(
                RuntimeException::class,
                static fn () => $service->deliver($engagement, ['summary' => 'Not from in progress, this needs the quality check first.'], $ownerId),
                'delivering from in progress should be refused'
            );
            $service->sendToQualityReview($engagement, $ownerId);

            $before = count($sent);
            $row = $service->deliver($engagement, [
                'summary'                  => 'Twenty denials reviewed. Twelve have a clear path and eight do not.',
                'recommended_count'        => 12,
                'recommended_amount_cents' => 1120000,
                'decision_due'             => '2026-10-15',
            ], $ownerId);
            Expect::same(Stage::ASSESSMENT_DELIVERED, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'delivered');
            Expect::same(12, (int) $row['recommended_count'], 'the count should be stored');
            Expect::same('2026-10-15 12:00:00', (string) $app->engagements()->find((string) $engagement['id'])['client_decision_due_at'], 'the decision date should sit on the engagement');
            Expect::same($before + 1, count($sent), 'exactly one email should go out on delivery');
            Expect::same('dana@example.org', $sent[count($sent) - 1]['to'], 'to the signer');
            Expect::false(str_contains($sent[count($sent) - 1]['body'], 'Twelve have a clear path'), 'the email must not carry the assessment');

            $labels = array_map(static fn (array $e): string => (string) $e['public_label'], $app->timeline()->forEngagement((string) $engagement['id']));
            foreach (['Initial denial set received', 'Assessment started', 'Assessment in our quality check', 'Assessment delivered'] as $needle) {
                Expect::true(in_array($needle, $labels, true), 'the timeline should carry "' . $needle . '"');
            }
            Expect::notNull($app->actionRequests()->openOfKind((string) $engagement['id'], ActionRequestKind::REVIEW_ASSESSMENT), 'the practice should be asked to read it');
            Expect::null($app->actionRequests()->openOfKind((string) $engagement['id'], ActionRequestKind::CONFIRM_RECEIPT_COUNT), 'the receipt request should be closed by delivery');
        },

    'a summary that looks like it carries a person is refused and nothing moves' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner): void {
            [$app, $sent] = $boot($db);
            $engagement = $atSecureRoute($app, $sent);
            $ownerId = $owner($app);
            $service = $app->assessmentService();
            $service->confirmReceipt($engagement, 20, 20, [], $ownerId);
            $service->start($engagement, $ownerId);
            $service->sendToQualityReview($engagement, $ownerId);

            Expect::throws(
                RuntimeException::class,
                static fn () => $service->deliver($engagement, ['summary' => 'Member 123456789 was denied for timely filing and should be appealed.'], $ownerId),
                'a nine-digit run should be refused'
            );
            Expect::same(Stage::ASSESSMENT_QA, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'nothing should have moved');
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->workBatchService()->fieldsFromInput(['payer_label' => 'DOB 01/02/1980 payer']),
                'a payer label carrying a date of birth should be refused'
            );
        },

    'the practice reading the assessment is what moves it to decision pending' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner, $delivered, $asClient): void {
            [$app, $sent] = $boot($db);
            $engagement = $delivered($app, $sent, $atSecureRoute($app, $sent), $owner($app));
            $client = $asClient($app, $engagement);

            Expect::true($app->assessmentService()->markRead($engagement, $client['user_id']), 'the first read should move it');
            Expect::same(Stage::CLIENT_DECISION_PENDING, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'decision pending');
            Expect::false($app->assessmentService()->markRead($engagement, $client['user_id']), 'a second read moves nothing');
            Expect::notNull($app->actionRequests()->openOfKind((string) $engagement['id'], ActionRequestKind::CHOOSE_SCOPE), 'the decision should be asked for');

            $events = $app->timeline()->forEngagement((string) $engagement['id']);
            $last = $events[count($events) - 1];
            Expect::same('client', (string) $last['actor_type'], 'the read is credited to the practice');
        },

    'the practice confirms the count in its own voice and the request closes' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner, $asClient): void {
            [$app, $sent] = $boot($db);
            $engagement = $atSecureRoute($app, $sent);
            $app->assessmentService()->confirmReceipt($engagement, 20, 20, [], $owner($app));
            $client = $asClient($app, $engagement);

            $app->assessmentService()->clientConfirmsReceipt($engagement, $client['contact_id'], $client['user_id']);
            $row = $app->assessments()->forEngagement((string) $engagement['id']);
            Expect::notNull($row['client_confirmed_at'], 'the client confirmation should be stamped');
            Expect::null($app->actionRequests()->openOfKind((string) $engagement['id'], ActionRequestKind::CONFIRM_RECEIPT_COUNT), 'the request should close');
            Expect::same(Stage::RECEIPT_CONFIRMED, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'confirming moves no stage');
        },

    'choosing recovery stops at scope selected and cannot reach recovery active' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner, $delivered, $asClient): void {
            [$app, $sent] = $boot($db);
            $engagement = $delivered($app, $sent, $atSecureRoute($app, $sent), $owner($app));
            $client = $asClient($app, $engagement);
            $app->assessmentService()->markRead($engagement, $client['user_id']);

            $before = count($sent);
            $row = $app->assessmentService()->decide($engagement, ClientDecision::RECOVERY_SCOPE, 'Start with the commercial ones.', $client['contact_id'], $client['user_id']);
            Expect::same(ClientDecision::RECOVERY_SCOPE, (string) $row['decision'], 'the decision should be stored');

            $after = $app->engagements()->find((string) $engagement['id']);
            Expect::same(Stage::RECOVERY_SCOPE_SELECTED, (string) $after['stage'], 'recovery goes as far as scope selected');
            Expect::null($after['closed_at'], 'it is not closed');
            Expect::false(Stage::canMove(Stage::RECOVERY_SCOPE_SELECTED, Stage::RECOVERY_ACTIVE), 'no edge skips the agreement');
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->engagementService()->move((string) $engagement['id'], Stage::RECOVERY_ACTIVE, 'x', 'x', null),
                'forcing recovery active should be refused by the machine'
            );

            $items = $app->checklistService()->sync((string) $engagement['id']);
            $keys = array_map(static fn (array $i): string => (string) $i['item_key'], $items);
            Expect::true(in_array(Checklist::SCOPE_SELECTED, $keys, true), 'the recovery checklist should appear');
            Expect::true(in_array(Checklist::RECOVERY_AGREEMENT, $keys, true), 'with the agreement item on it');
            $byKey = [];
            foreach ($items as $item) {
                $byKey[(string) $item['item_key']] = $item;
            }
            Expect::notNull($byKey[Checklist::DECISION_RECORDED]['completed_at'], 'the decision item should be done');
            Expect::notNull($byKey[Checklist::SCOPE_SELECTED]['completed_at'], 'scope selected should be done');
            Expect::null($byKey[Checklist::RECOVERY_AGREEMENT]['completed_at'], 'the agreement item should be open');

            Expect::same($before + 1, count($sent), 'she should be emailed the decision');
            Expect::same('owner@example.org', $sent[count($sent) - 1]['to'], 'at her own address');
        },

    'no further action and internal use both close the engagement with the decision recorded' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner, $delivered, $asClient): void {
            foreach ([ClientDecision::NO_FURTHER_ACTION, ClientDecision::INTERNAL_USE] as $choice) {
                migrateDown($db);
                migrateUp($db);
                Bootstrap::resetInstance();
                [$app, $sent] = $boot($db);
                $engagement = $delivered($app, $sent, $atSecureRoute($app, $sent), $owner($app));
                $client = $asClient($app, $engagement);
                $app->assessmentService()->markRead($engagement, $client['user_id']);

                $row = $app->assessmentService()->decide($engagement, $choice, null, $client['contact_id'], $client['user_id']);
                Expect::same($choice, (string) $row['decision'], 'the decision should be stored');
                $after = $app->engagements()->find((string) $engagement['id']);
                Expect::same(Stage::CLOSED_NO_RECOVERY, (string) $after['stage'], $choice . ' should close without recovery');
                Expect::notNull($after['closed_at'], 'closing should stamp closed_at');

                $labels = array_map(static fn (array $e): string => (string) $e['public_label'], $app->timeline()->forEngagement((string) $engagement['id']));
                Expect::true(in_array(ClientDecision::timelineLabel($choice), $labels, true), 'the timeline should carry the choice');
                Expect::true(in_array('Engagement closed', $labels, true), 'and the close');

                $app->clientAccess()->signOut();
            }
        },

    'more information keeps the decision open, asks her, and her answer hands it back' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner, $delivered, $asClient): void {
            [$app, $sent] = $boot($db);
            $ownerId = $owner($app);
            $engagement = $delivered($app, $sent, $atSecureRoute($app, $sent), $ownerId);
            $client = $asClient($app, $engagement);
            $app->assessmentService()->markRead($engagement, $client['user_id']);

            Expect::throws(
                RuntimeException::class,
                static fn () => $app->assessmentService()->decide($engagement, ClientDecision::MORE_INFORMATION, '', $client['contact_id'], $client['user_id']),
                'a question with no question should be refused'
            );
            $row = $app->assessmentService()->decide($engagement, ClientDecision::MORE_INFORMATION, 'Which payers are the twelve with?', $client['contact_id'], $client['user_id']);
            Expect::null($row['decision'], 'a question is not a decision');
            Expect::same(Stage::CLIENT_DECISION_PENDING, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'the stage stays');

            $question = $app->actionRequests()->openOfKind((string) $engagement['id'], ActionRequestKind::ANSWER_QUESTION);
            Expect::notNull($question, 'a request should wait on her');
            Expect::same(ActionRequestKind::OWNER_SOFT_APPEALS, (string) $question['owner'], 'owned by Soft Appeals');
            Expect::null($app->actionRequests()->openOfKind((string) $engagement['id'], ActionRequestKind::CHOOSE_SCOPE), 'the decision request should close while she answers');
            $app->clientAccess()->signOut();

            $app->assessmentService()->answer($engagement, $question, 'Two commercial payers and one managed Medicaid plan.', $ownerId);
            $answered = $app->actionRequests()->find((string) $question['id']);
            Expect::same(ActionRequestKind::STATUS_DONE, (string) $answered['status'], 'her answer closes it');
            Expect::same('Two commercial payers and one managed Medicaid plan.', (string) $answered['response'], 'with the answer on it');
            Expect::notNull($app->actionRequests()->openOfKind((string) $engagement['id'], ActionRequestKind::CHOOSE_SCOPE), 'the decision is handed back');

            $client = $asClient($app, $engagement);
            $final = $app->assessmentService()->decide($engagement, ClientDecision::RECOVERY_SCOPE, null, $client['contact_id'], $client['user_id']);
            Expect::same(ClientDecision::RECOVERY_SCOPE, (string) $final['decision'], 'they can decide after the answer');
        },

    'a decision is refused before delivery and refused twice' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner, $delivered, $asClient): void {
            [$app, $sent] = $boot($db);
            $engagement = $atSecureRoute($app, $sent);
            $client = $asClient($app, $engagement);
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->assessmentService()->decide($engagement, ClientDecision::RECOVERY_SCOPE, null, $client['contact_id'], $client['user_id']),
                'deciding at secure intake ready should be refused'
            );
            $app->clientAccess()->signOut();

            $engagement = $delivered($app, $sent, $engagement, $owner($app));
            $client = $asClient($app, $engagement);
            $app->assessmentService()->markRead($engagement, $client['user_id']);
            $app->assessmentService()->decide($engagement, ClientDecision::RECOVERY_SCOPE, null, $client['contact_id'], $client['user_id']);
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->assessmentService()->decide($engagement, ClientDecision::NO_FURTHER_ACTION, null, $client['contact_id'], $client['user_id']),
                'a second decision should be refused'
            );
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->assessmentService()->decide($engagement, 'maybe', null, $client['contact_id'], $client['user_id']),
                'a fifth option should be refused'
            );
        },

    'a viewer can read the room and decide nothing' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner, $delivered): void {
            [$app, $sent] = $boot($db);
            $engagement = $delivered($app, $sent, $atSecureRoute($app, $sent), $owner($app));
            $organizationId = (string) $engagement['organization_id'];

            $viewerId = $app->users()->create('viewer@example.org');
            $app->memberships()->grant($viewerId, Role::VIEWER, $organizationId);
            $app->session()->start();
            $app->session()->establish(\SoftAppeals\Auth\SessionManager::KIND_CLIENT, $viewerId, $organizationId);

            Expect::true($app->authorization()->can(\SoftAppeals\Domain\Permission::ROOM_VIEW, $organizationId), 'a viewer reads the room');
            Expect::false($app->authorization()->can(\SoftAppeals\Domain\Permission::DECISION_RECORD, $organizationId), 'a viewer cannot decide');
            Expect::false($app->authorization()->can(\SoftAppeals\Domain\Permission::RECEIPT_CONFIRM, $organizationId), 'a viewer cannot confirm the count');
            Expect::throws(
                \SoftAppeals\Security\AuthorizationException::class,
                static fn () => $app->authorization()->require(\SoftAppeals\Domain\Permission::DECISION_RECORD, $organizationId),
                'requiring it should throw'
            );
        },

    'the checklist is a projection of the timeline, including for history written before it existed' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute): void {
            [$app, $sent] = $boot($db);
            $engagement = $atSecureRoute($app, $sent);

            // Nothing has synced yet: the table is empty for this engagement.
            Expect::same(0, count($app->checklistItems()->forEngagement((string) $engagement['id'])), 'no items before the first sync');

            $items = $app->checklistService()->sync((string) $engagement['id']);
            Expect::same(count(Checklist::initial()), count($items), 'the initial list should be seeded');
            $byKey = [];
            foreach ($items as $item) {
                $byKey[(string) $item['item_key']] = $item;
            }
            foreach ([Checklist::PREFERENCES_CONFIRMED, Checklist::BAA_EXECUTED, Checklist::REVIEW_AUTH_EXECUTED, Checklist::SECURE_OPENED] as $done) {
                Expect::notNull($byKey[$done]['completed_at'], $done . ' should be done from the timeline');
                Expect::notNull($byKey[$done]['source_event_id'], $done . ' should name the event that did it');
            }
            foreach ([Checklist::INITIAL_SET_RECEIVED, Checklist::ASSESSMENT_DELIVERED, Checklist::DECISION_RECORDED] as $open) {
                Expect::null($byKey[$open]['completed_at'], $open . ' should be open');
            }
            Expect::false(array_key_exists(Checklist::SCOPE_SELECTED, $byKey), 'the recovery list should not appear yet');

            // The BAA item is dated when the BAA was executed, not when synced.
            $baaEvent = $app->timeline()->find((string) $byKey[Checklist::BAA_EXECUTED]['source_event_id']);
            Expect::same((string) $baaEvent['created_at'], (string) $byKey[Checklist::BAA_EXECUTED]['completed_at'], 'the completion time is the event time');
        },

    'the batch card shows the practice only the permitted fields' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner): void {
            [$app, $sent] = $boot($db);
            $engagement = $atSecureRoute($app, $sent);
            $ownerId = $owner($app);
            $app->assessmentService()->confirmReceipt($engagement, 20, 20, $app->workBatchService()->fieldsFromInput([
                'payer_label' => 'Big Commercial Payer', 'denied_amount' => '18,400.00', 'earliest_deadline' => '2026-12-01', 'deadline_confirmed' => 'yes',
            ]), $ownerId);
            $batch = $app->workBatches()->forEngagement((string) $engagement['id'])[0];

            $card = $app->workBatchService()->card($batch);
            Expect::null($card['payer'], 'an unapproved payer label is held back');
            Expect::same('$18,400.00', $card['denied'], 'money is formatted from cents');
            Expect::true($card['confirmed'], 'the confirmed flag travels');
            Expect::same('Confirmed', $card['deadline_words'], 'and is labelled');
            foreach (array_keys($card) as $key) {
                Expect::true(in_array($key, [
                    'ref', 'label', 'payer', 'count', 'denied', 'stage', 'owner', 'action',
                    'deadline', 'deadline_days', 'confirmed', 'deadline_words',
                ], true), 'the card carries no field beyond section 15.7: ' . $key);
            }

            $app->workBatchService()->update($engagement, $batch, ['payer_label_approved' => true, 'stage' => BatchStage::RECOMMENDED], $ownerId);
            $card = $app->workBatchService()->card($app->workBatches()->forEngagement((string) $engagement['id'])[0]);
            Expect::same('Big Commercial Payer', $card['payer'], 'an approved label is shown');
            Expect::same('Recommended for action', $card['stage'], 'in the practice\'s words');
            Expect::same(1, $app->workBatches()->totals((string) $engagement['id'])['recommended'], 'and counted');
        },

    'two tabs cannot both change one batch' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner): void {
            [$app, $sent] = $boot($db);
            $engagement = $atSecureRoute($app, $sent);
            $ownerId = $owner($app);
            $app->assessmentService()->confirmReceipt($engagement, 20, 20, [], $ownerId);
            $stale = $app->workBatches()->forEngagement((string) $engagement['id'])[0];

            $app->workBatchService()->update($engagement, $stale, ['label' => 'First tab'], $ownerId);
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->workBatchService()->update($engagement, $stale, ['label' => 'Second tab'], $ownerId),
                'the second tab, holding the old version, should be refused'
            );
            Expect::same('First tab', (string) $app->workBatches()->forEngagement((string) $engagement['id'])[0]['label'], 'the first write stands');
        },

    'an action request nobody designed cannot be opened, and one is never opened twice' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner): void {
            [$app, $sent] = $boot($db);
            $engagement = $atSecureRoute($app, $sent);
            $ownerId = $owner($app);
            $service = $app->actionRequestService();

            Expect::throws(
                RuntimeException::class,
                static fn () => $service->open($engagement, 'please_upload_the_claims', null, null, $ownerId),
                'an invented kind should be refused'
            );
            $service->open($engagement, ActionRequestKind::OPEN_SECURE_CHANNEL, null, null, $ownerId);
            Expect::throws(
                RuntimeException::class,
                static fn () => $service->open($engagement, ActionRequestKind::OPEN_SECURE_CHANNEL, null, null, $ownerId),
                'the same open request twice should be refused'
            );
            Expect::throws(
                RuntimeException::class,
                static fn () => $service->open($engagement, ActionRequestKind::PROVIDE_INFORMATION, 'Send member 987-65-4321 details', null, $ownerId),
                'a note carrying an SSN shape should be refused'
            );
            Expect::true(ActionRequestKind::directsToSecureChannel(ActionRequestKind::OPEN_SECURE_CHANNEL), 'the secure kinds say so');
            Expect::null(ActionRequestKind::portalAction(ActionRequestKind::OPEN_SECURE_CHANNEL), 'and offer no portal button');
        },
];
