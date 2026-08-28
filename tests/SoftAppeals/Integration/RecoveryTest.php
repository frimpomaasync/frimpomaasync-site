<?php
declare(strict_types=1);

/**
 * Phase 6 acceptance, section 22.
 *
 *   recovery cannot activate before agreement execution
 *   only an authorized approver can decide an approval request
 *     (Security/ApprovalTest, which signs in as each role)
 *   double submission does not create duplicate approval events
 *   approval notices contain no PHI
 *   payer-response state does not automatically create a fee
 *
 * Every case walks the real path: an inquiry, both Gate A agreements, the
 * secure route, the assessment, the practice's decision, and only then the
 * scope, the Gate B pair and the recovery. A test that planted an engagement
 * at "recovery active" by writing the column would prove nothing about the
 * gates this phase exists to enforce.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\ActionRequestKind;
use SoftAppeals\Domain\ApprovalState;
use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Domain\Checklist;
use SoftAppeals\Domain\ClientDecision;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\FitDecision;
use SoftAppeals\Domain\PreferenceForm;
use SoftAppeals\Domain\Role;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Domain\SubmissionEventType;
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
    $vault = sys_get_temp_dir() . '/sa-rc-vault-' . bin2hex(random_bytes(4));
    $path = sys_get_temp_dir() . '/sa-rc-config-' . bin2hex(random_bytes(4)) . '.php';

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

/** Sign one SENT document as the practice, from the newest signing token in the outbox. */
$signAsPractice = static function (Bootstrap $app, ArrayObject $sent, array $engagement, string $kind, bool $redeem = true): void {
    if ($redeem) {
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
    }
    $context = $app->clientAccess()->context();
    $signContext = [
        'organization_id' => (string) $engagement['organization_id'],
        'engagement'      => $app->engagements()->findWithOrganization((string) $engagement['id']),
        'contact_id'      => $context['contact_id'],
    ];
    $document = $app->signingService()->pending($signContext);
    Expect::notNull($document, 'something should be waiting for the signer');
    Expect::same($kind, (string) $document['kind'], 'the document offered should be the ' . $kind);
    $signed = $app->signingService()->sign($document, $signContext + ['user_id' => (string) $context['user']['id']], [
        'typed_name'         => 'Dana Owusu',
        'typed_title'        => 'Practice owner',
        'typed_organization' => (string) $engagement['legal_name'],
        'consent'            => true,
        'document_sha256'    => (string) $document['content_sha256'],
    ]);
    Expect::true($signed['signed'], 'the practice should be able to sign the ' . $kind);
};

/** From nothing to "secure intake ready". */
$atSecureRoute = static function (Bootstrap $app, ArrayObject $sent) use ($answers, $preferences, $owner, $signAsPractice): array {
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
        $signAsPractice($app, $sent, $engagement, $kind);
        $app->clientAccess()->signOut();
        $app->documentService()->countersign($app->documents()->find((string) $document['id']), $engagement, [
            'typed_name'  => 'Nana Frimpongmaa',
            'typed_title' => 'Owner',
            'consent'     => true,
        ], $owner($app));
    }

    $app->engagementService()->move($engagementId, Stage::SECURE_INTAKE_READY, 'The secure route is open', 'engagement.secure_route_open', $owner($app));

    return $app->engagements()->findWithOrganization($engagementId);
};

/** A client session for one address at this practice. */
$asClient = static function (Bootstrap $app, array $engagement, string $email = 'dana@example.org'): array {
    $contact = $app->contacts()->findByEmail((string) $engagement['organization_id'], $email);
    $user = $app->users()->findByEmail($email);
    Expect::notNull($user, 'the user should exist: ' . $email);
    $app->session()->start();
    $app->session()->establish(\SoftAppeals\Auth\SessionManager::KIND_CLIENT, (string) $user['id'], (string) $engagement['organization_id']);
    return [
        'organization_id' => (string) $engagement['organization_id'],
        'user_id'         => (string) $user['id'],
        'contact_id'      => $contact === null ? null : (string) $contact['id'],
    ];
};

/** One batch on the engagement, by its label. Two batches opened in the same second sort by reference, not by age. */
$batchNamed = static function (Bootstrap $app, string $engagementId, string $label): array {
    foreach ($app->workBatches()->forEngagement($engagementId) as $batch) {
        if ((string) $batch['label'] === $label) {
            return $batch;
        }
    }
    throw new RuntimeException('No batch named ' . $label);
};

/** Through the assessment to "recovery scope selected", with one batch recommended and one not. */
$scopeSelected = static function (Bootstrap $app, ArrayObject $sent, array $engagement, string $ownerId) use ($asClient, $batchNamed): array {
    $service = $app->assessmentService();
    $service->confirmReceipt($engagement, 20, 20, $app->workBatchService()->fieldsFromInput([
        'label' => 'Commercial set', 'payer_label' => 'Commercial', 'payer_label_approved' => 'yes',
        'denied_amount' => '18,400.00', 'earliest_deadline' => '2026-12-01',
    ]), $ownerId);
    $second = $app->workBatchService()->open($engagement, $app->workBatchService()->fieldsFromInput([
        'label' => 'Second set', 'claim_count' => '5', 'denied_amount' => '2,000.00',
    ]), $ownerId);
    $service->start($engagement, $ownerId);
    $service->sendToQualityReview($engagement, $ownerId);

    $first = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
    $app->workBatchService()->update($engagement, $first, ['stage' => BatchStage::RECOMMENDED], $ownerId);

    $service->deliver($engagement, [
        'summary'                  => 'Twenty denials reviewed. The commercial set has a clear path; the second set does not.',
        'recommended_count'        => 20,
        'recommended_amount_cents' => 1840000,
        'decision_due'             => '2026-10-15',
    ], $ownerId);

    $client = $asClient($app, $engagement);
    $service->markRead($engagement, $client['user_id']);
    $service->decide($engagement, ClientDecision::RECOVERY_SCOPE, 'Start with the commercial ones.', $client['contact_id'], $client['user_id']);
    $app->clientAccess()->signOut();

    return $app->engagements()->findWithOrganization((string) $engagement['id']);
};

/** Record a scope naming a new approver and covering the recommended batch. */
$recordScope = static function (Bootstrap $app, array $engagement, string $ownerId, array $overrides = []) use ($batchNamed): array {
    $first = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
    return $app->recoveryService()->recordScope($engagement, array_merge([
        'fee_basis'      => EngagementTerms::FEE_CONTINGENCY_25,
        'fee_rate'       => '',
        'summary'        => 'The commercial timely-filing denials in the initial set, first-level appeals.',
        'batch_refs'     => [(string) $first['public_ref']],
        'approver_name'  => 'Kofi Mensah',
        'approver_email' => 'kofi@example.org',
        'approver_role'  => 'Revenue cycle lead',
    ], $overrides), $ownerId);
};

/** Generate, send, sign both, countersign the agreement: "recovery agreement executed". */
$pairExecuted = static function (Bootstrap $app, ArrayObject $sent, array $engagement, string $ownerId) use ($signAsPractice): array {
    $pair = $app->documentService()->generateRecoveryPair($engagement, $ownerId);
    $app->documentService()->send($pair['agreement'], $engagement, $ownerId);
    $signAsPractice($app, $sent, $engagement, DocumentKind::RECOVERY_AGREEMENT);
    $signAsPractice($app, $sent, $engagement, DocumentKind::APPROVED_SCOPE, false);
    $app->clientAccess()->signOut();
    $app->documentService()->countersign(
        $app->documents()->find((string) $pair['agreement']['id']),
        $engagement,
        ['typed_name' => 'Nana Frimpongmaa', 'typed_title' => 'Owner', 'consent' => true],
        $ownerId
    );
    return $app->engagements()->findWithOrganization((string) $engagement['id']);
};

/** All the way to "recovery active". */
$active = static function (Bootstrap $app, ArrayObject $sent) use ($atSecureRoute, $owner, $scopeSelected, $recordScope, $pairExecuted): array {
    $ownerId = $owner($app);
    $engagement = $scopeSelected($app, $sent, $atSecureRoute($app, $sent), $ownerId);
    $recordScope($app, $engagement, $ownerId);
    $engagement = $pairExecuted($app, $sent, $engagement, $ownerId);
    $app->recoveryService()->activate($engagement, $ownerId);
    return $app->engagements()->findWithOrganization((string) $engagement['id']);
};

$labelsOf = static function (Bootstrap $app, string $engagementId): array {
    return array_map(
        static fn (array $e): string => (string) $e['event_type'],
        $app->timeline()->forEngagement($engagementId)
    );
};

return [

    'recovery cannot activate before the agreement is executed, and not before the scope is signed either' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner, $scopeSelected, $recordScope, $signAsPractice, $batchNamed): void {
            [$app, $sent] = $boot($db);
            $ownerId = $owner($app);
            $engagement = $scopeSelected($app, $sent, $atSecureRoute($app, $sent), $ownerId);
            $recovery = $app->recoveryService();

            Expect::throws(RuntimeException::class, static fn () => $recovery->activate($engagement, $ownerId), 'activating at scope selected should be refused');
            Expect::false(Stage::canMove(Stage::RECOVERY_SCOPE_SELECTED, Stage::RECOVERY_ACTIVE), 'no edge skips the agreement');
            Expect::false(Stage::canMove(Stage::RECOVERY_AGREEMENT_PENDING, Stage::RECOVERY_ACTIVE), 'no edge skips execution');

            $recordScope($app, $engagement, $ownerId);
            $pair = $app->documentService()->generateRecoveryPair($engagement, $ownerId);
            $app->documentService()->send($pair['agreement'], $engagement, $ownerId);
            Expect::same(Stage::RECOVERY_AGREEMENT_PENDING, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'sending moves to pending');

            // The practice signs the agreement only. The scope stays unsigned.
            $signAsPractice($app, $sent, $engagement, DocumentKind::RECOVERY_AGREEMENT);
            $app->clientAccess()->signOut();
            $app->documentService()->countersign($app->documents()->find((string) $pair['agreement']['id']), $engagement, [
                'typed_name' => 'Nana Frimpongmaa', 'typed_title' => 'Owner', 'consent' => true,
            ], $ownerId);
            Expect::same(Stage::RECOVERY_AGREEMENT_EXECUTED, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'the agreement executed');

            $status = $recovery->agreementStatus($engagement);
            Expect::false($status['both_executed'], 'the scope is not executed yet');
            Expect::throws(RuntimeException::class, static fn () => $recovery->activate($engagement, $ownerId), 'activating without the executed scope should be refused');
            Expect::same(Stage::RECOVERY_AGREEMENT_EXECUTED, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'nothing moved');
        },

    'the scope is recorded from recommended batches only, names the approver, and completes the checklist item' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner, $scopeSelected, $recordScope, $batchNamed): void {
            [$app, $sent] = $boot($db);
            $ownerId = $owner($app);
            $engagement = $scopeSelected($app, $sent, $atSecureRoute($app, $sent), $ownerId);
            $notRecommended = $batchNamed($app, (string) $engagement['id'], 'Second set');

            Expect::throws(
                RuntimeException::class,
                static fn () => $recordScope($app, $engagement, $ownerId, ['batch_refs' => [(string) $notRecommended['public_ref']]]),
                'a batch that is not recommended cannot be in scope'
            );
            Expect::throws(
                RuntimeException::class,
                static fn () => $recordScope($app, $engagement, $ownerId, ['fee_basis' => EngagementTerms::FEE_NOT_SET]),
                'a scope needs a fee basis'
            );
            Expect::throws(
                RuntimeException::class,
                static fn () => $recordScope($app, $engagement, $ownerId, ['fee_basis' => EngagementTerms::FEE_CONTINGENCY_25, 'fee_rate' => '30']),
                'the standard basis is 25, a different rate is custom'
            );
            Expect::throws(
                RuntimeException::class,
                static fn () => $recordScope($app, $engagement, $ownerId, ['summary' => 'Patient MRN 4471923 and the others']),
                'a summary that looks like it carries a person is refused'
            );
            Expect::null($app->recoveryScopes()->forEngagement((string) $engagement['id']), 'nothing was stored by a refused scope');

            $scope = $recordScope($app, $engagement, $ownerId);
            Expect::same(2500, (int) $scope['fee_rate_bps'], 'contingency is 2500 basis points');
            Expect::notNull($scope['approver_contact_id'], 'the approver is a contact');
            Expect::notNull($scope['approver_confirmed_at'], 'and is stamped confirmed');

            $approver = $app->contacts()->find((string) $scope['approver_contact_id']);
            Expect::same('kofi@example.org', (string) $approver['work_email'], 'the new approver was created');
            $user = $app->users()->findByEmail('kofi@example.org');
            Expect::notNull($user, 'with a passwordless user');
            Expect::true($app->memberships()->has((string) $user['id'], Role::SUBMISSION_APPROVER, (string) $engagement['organization_id']), 'holding the approver role');

            $full = $app->recoveryService()->scope($engagement);
            Expect::same(1, count($full['batches']), 'one batch in scope');
            Expect::same(20, (int) $full['claim_count'], 'twenty claims');
            Expect::same(1840000, (int) $full['denied_cents'], 'in integer cents');

            $items = $app->checklistService()->sync((string) $engagement['id']);
            $byKey = [];
            foreach ($items as $item) {
                $byKey[(string) $item['item_key']] = $item;
            }
            Expect::notNull($byKey[Checklist::APPROVER_CONFIRMED]['completed_at'], 'the approver item is done on the event');
            Expect::null($byKey[Checklist::RECOVERY_AGREEMENT]['completed_at'], 'the agreement item is open');

            // A custom rate is allowed on the custom basis.
            $custom = $recordScope($app, $engagement, $ownerId, ['fee_basis' => EngagementTerms::FEE_CUSTOM, 'fee_rate' => '22.5']);
            Expect::same(2250, (int) $custom['fee_rate_bps'], '22.5 percent is 2250 basis points');
            Expect::same(1, count($app->database()->all('SELECT id FROM sa_recovery_scopes')), 'one scope per engagement, rewritten not duplicated');
        },

    'the recovery pair is refused without a scope, and generated from it with the scope on its face' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner, $scopeSelected, $recordScope, $batchNamed): void {
            [$app, $sent] = $boot($db);
            $ownerId = $owner($app);
            $engagement = $scopeSelected($app, $sent, $atSecureRoute($app, $sent), $ownerId);
            $documents = $app->documentService();

            $check = $documents->canGenerate($engagement, DocumentKind::RECOVERY_AGREEMENT);
            Expect::false($check['ok'], 'no scope, no agreement');
            Expect::true(str_contains((string) $check['reason'], 'scope'), 'and the reason says so');
            Expect::throws(RuntimeException::class, static fn () => $documents->generateRecoveryPair($engagement, $ownerId), 'generating the pair without a scope is refused');
            Expect::same(0, count($app->documents()->forEngagement((string) $engagement['id'])) - 2, 'nothing was generated beyond the two Gate A documents');

            $recordScope($app, $engagement, $ownerId);
            Expect::true($documents->canGenerate($engagement, DocumentKind::RECOVERY_AGREEMENT)['ok'], 'with a scope the agreement can be generated');
            Expect::true($documents->canGenerate($engagement, DocumentKind::APPROVED_SCOPE)['ok'], 'and so can the scope document');

            $pair = $documents->generateRecoveryPair($engagement, $ownerId);
            Expect::same(DocumentStatus::DRAFT, (string) $pair['agreement']['status'], 'the agreement is a draft');
            Expect::same(DocumentStatus::DRAFT, (string) $pair['scope']['status'], 'the scope is a draft');
            Expect::same(EngagementTerms::FEE_CONTINGENCY_25, (string) $pair['agreement']['fee_basis'], 'the fee basis is stamped on the row');

            $first = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
            $agreementBody = (string) $documents->body($pair['agreement']);
            $scopeBody = (string) $documents->body($pair['scope']);
            Expect::true(str_contains($agreementBody, 'DRAFT FOR REVIEW'), 'the agreement says it is a draft');
            Expect::true(str_contains($agreementBody, '25 percent of verified reimbursement'), 'the agreement names the rate');
            Expect::true(str_contains($agreementBody, 'Kofi Mensah'), 'the agreement names the approver');
            Expect::true(str_contains($agreementBody, 'A favorable decision from the payer does not create a fee'), 'section 19 is on its face');
            Expect::true(str_contains($scopeBody, (string) $first['public_ref']), 'the scope names the batch by reference');
            Expect::true(str_contains($scopeBody, '$18,400.00'), 'and its denied value');
            Expect::false(str_contains($scopeBody, 'MRN'), 'and nothing at patient level');

            Expect::false($documents->canGenerate($engagement, DocumentKind::RECOVERY_AGREEMENT)['ok'], 'a second bare generate is refused while a version is open');
        },

    'sending the agreement sends the scope alongside it, in one email that carries no scope detail' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner, $scopeSelected, $recordScope, $batchNamed): void {
            [$app, $sent] = $boot($db);
            $ownerId = $owner($app);
            $engagement = $scopeSelected($app, $sent, $atSecureRoute($app, $sent), $ownerId);
            $recordScope($app, $engagement, $ownerId);
            $pair = $app->documentService()->generateRecoveryPair($engagement, $ownerId);

            $before = count($sent);
            $result = $app->documentService()->send($pair['agreement'], $engagement, $ownerId);
            Expect::true($result['sent'], 'the agreement went out');
            Expect::same($before + 1, count($sent), 'exactly one email for the pair');
            $last = $sent[count($sent) - 1];
            Expect::same('dana@example.org', $last['to'], 'to the authorized signer');
            Expect::true(str_contains($last['body'], 'Approved Recovery Scope'), 'the email says the scope follows');
            Expect::false(str_contains($last['body'], 'timely-filing'), 'the email does not carry the scope summary');
            Expect::false(str_contains($last['body'], 'Kofi'), 'nor the approver');

            Expect::same(DocumentStatus::SENT, (string) $app->documents()->find((string) $pair['agreement']['id'])['status'], 'the agreement is out');
            Expect::same(DocumentStatus::SENT, (string) $app->documents()->find((string) $pair['scope']['id'])['status'], 'and so is the scope');
            Expect::same(Stage::RECOVERY_AGREEMENT_PENDING, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'the stage moved once');
        },

    'the practice signs the agreement first, then the scope, which executes on their signature alone' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner, $scopeSelected, $recordScope, $signAsPractice, $batchNamed): void {
            [$app, $sent] = $boot($db);
            $ownerId = $owner($app);
            $engagement = $scopeSelected($app, $sent, $atSecureRoute($app, $sent), $ownerId);
            $recordScope($app, $engagement, $ownerId);
            $pair = $app->documentService()->generateRecoveryPair($engagement, $ownerId);
            $app->documentService()->send($pair['agreement'], $engagement, $ownerId);

            // The agreement is offered first while both are waiting.
            $signAsPractice($app, $sent, $engagement, DocumentKind::RECOVERY_AGREEMENT);
            Expect::same(DocumentStatus::CLIENT_SIGNED, (string) $app->documents()->find((string) $pair['agreement']['id'])['status'], 'the agreement waits on her countersignature');

            // Then the scope, on the same session, and it executes at once.
            $signAsPractice($app, $sent, $engagement, DocumentKind::APPROVED_SCOPE, false);
            $scopeDocument = $app->documents()->find((string) $pair['scope']['id']);
            Expect::same(DocumentStatus::EXECUTED, (string) $scopeDocument['status'], 'the one-party scope executed on the practice signature');
            Expect::notNull($scopeDocument['executed_sha256'], 'with an executed record');
            Expect::same(1, count($app->signatures()->forDocument((string) $scopeDocument['id'])), 'and exactly one signature');
            Expect::true($app->documentService()->verify($scopeDocument)['executed']['matches'], 'which verifies');
            Expect::same(Stage::RECOVERY_AGREEMENT_PENDING, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'the stage waits on the agreement, not the scope');
            $app->clientAccess()->signOut();

            $app->documentService()->countersign($app->documents()->find((string) $pair['agreement']['id']), $engagement, [
                'typed_name' => 'Nana Frimpongmaa', 'typed_title' => 'Owner', 'consent' => true,
            ], $ownerId);
            Expect::same(Stage::RECOVERY_AGREEMENT_EXECUTED, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'the agreement executed');
            Expect::true($app->recoveryService()->agreementStatus($engagement)['both_executed'], 'both are executed');

            $app->recoveryService()->activate($engagement, $ownerId);
            Expect::same(Stage::RECOVERY_ACTIVE, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'recovery is active');
            Expect::throws(RuntimeException::class, static fn () => $app->recoveryService()->activate($engagement, $ownerId), 'activating twice is refused');

            $items = $app->checklistService()->sync((string) $engagement['id']);
            $byKey = [];
            foreach ($items as $item) {
                $byKey[(string) $item['item_key']] = $item;
            }
            Expect::notNull($byKey[Checklist::RECOVERY_AGREEMENT]['completed_at'], 'the agreement item is done');
            Expect::null($byKey[Checklist::FIRST_APPROVAL]['completed_at'], 'no approval yet');
            Expect::null($byKey[Checklist::FIRST_SUBMISSION]['completed_at'], 'no submission yet');
        },

    'an approval request needs an active recovery, a batch in scope and an approver, and emails only a notice' =>
        static function (Bootstrap $app, Database $db) use ($boot, $active, $batchNamed): void {
            [$app, $sent] = $boot($db);
            $engagement = $active($app, $sent);
            $recovery = $app->recoveryService();
            $inScope = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
            $outside = $batchNamed($app, (string) $engagement['id'], 'Second set');
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];

            Expect::throws(
                RuntimeException::class,
                static fn () => $recovery->requestApproval($engagement, $outside, ['safe_summary' => 'Appeals for the second set to the payer.'], $ownerId),
                'a batch outside the scope cannot go to a payer'
            );
            Expect::throws(
                RuntimeException::class,
                static fn () => $recovery->requestApproval($engagement, $inScope, ['safe_summary' => 'Patient DOB 01/02/1980 appeal'], $ownerId),
                'a summary that carries a person is refused'
            );

            $before = count($sent);
            $request = $recovery->requestApproval($engagement, $inScope, [
                'safe_summary' => 'First-level appeals for the timely-filing denials, to the commercial payer.',
                'claim_count'  => '12',
                'amount'       => '11,200.00',
                'due'          => '2026-09-20',
            ], $ownerId);
            Expect::same(ApprovalState::PENDING, (string) $request['state'], 'pending');
            Expect::same(12, (int) $request['claim_count'], 'the count is stored');
            Expect::same(1120000, (int) $request['amount_cents'], 'in integer cents');
            Expect::same(BatchStage::APPROVAL_PENDING, (string) $app->workBatches()->find((string) $inScope['id'])['stage'], 'the batch is awaiting approval');
            Expect::notNull($app->actionRequests()->openOfKind((string) $engagement['id'], ActionRequestKind::APPROVE_SUBMISSION), 'the room has a card for it');

            Expect::same($before + 1, count($sent), 'one notice went out');
            $last = $sent[count($sent) - 1];
            Expect::same('kofi@example.org', $last['to'], 'to the approver');
            Expect::false(str_contains($last['body'], 'timely-filing'), 'the notice does not carry the summary');
            Expect::false(str_contains($last['body'], '11,200'), 'nor the amount');
            Expect::true(str_contains($last['body'], 'section=approvals'), 'it points at the approvals section');

            Expect::throws(
                RuntimeException::class,
                static fn () => $recovery->requestApproval($engagement, $inScope, ['safe_summary' => 'A second request on the same batch.'], $ownerId),
                'one pending request per batch'
            );
        },

    'double submission does not create duplicate approval events' =>
        static function (Bootstrap $app, Database $db) use ($boot, $active, $asClient, $labelsOf, $batchNamed): void {
            [$app, $sent] = $boot($db);
            $engagement = $active($app, $sent);
            $recovery = $app->recoveryService();
            $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];
            $request = $recovery->requestApproval($engagement, $batch, ['safe_summary' => 'First-level appeals to the commercial payer.'], $ownerId);

            $approver = $asClient($app, $engagement, 'kofi@example.org');
            $before = count($sent);
            $first = $recovery->decide($engagement, $request, ApprovalState::APPROVED, null, $approver);
            Expect::false($first['already'], 'the first decision is new');
            $second = $recovery->decide($engagement, $request, ApprovalState::APPROVED, null, $approver);
            Expect::true($second['already'], 'the same click again is the same decision');
            Expect::same(ApprovalState::APPROVED, $second['state'], 'and says what it was');

            $stored = $app->approvalRequests()->find((string) $request['id']);
            Expect::same(ApprovalState::APPROVED, (string) $stored['state'], 'approved once');
            Expect::notNull($stored['decision_at'], 'with a stamp');
            Expect::same(1, count(array_filter($labelsOf($app, (string) $engagement['id']), static fn (string $t): bool => $t === 'approval.approved')), 'one timeline line');
            Expect::same($before + 1, count($sent), 'one email to her, not two');
            Expect::same('owner@example.org', $sent[count($sent) - 1]['to'], 'at her address');

            // A different answer after the fact is refused, not recorded over the top.
            $fresh = $app->approvalRequests()->find((string) $request['id']);
            Expect::throws(
                RuntimeException::class,
                static fn () => $recovery->decide($engagement, $fresh, ApprovalState::RETURNED, 'Changed my mind.', $approver),
                'a second, different decision is refused'
            );
            Expect::same(ApprovalState::APPROVED, (string) $app->approvalRequests()->find((string) $request['id'])['state'], 'still approved');

            // By label, not by index: two batches opened in the same second
            // sort by reference, and the board order followed the dice.
            $card = null;
            foreach ($recovery->board($engagement) as $row) {
                if ((string) $row['batch']['label'] === 'Commercial set') {
                    $card = $row['card'];
                }
            }
            Expect::notNull($card, 'the commercial set is on the board');
            Expect::same('Approved by you, submission next', (string) $card['stage'], 'the card says approved rather than asking again');
            Expect::null($app->actionRequests()->openOfKind((string) $engagement['id'], ActionRequestKind::APPROVE_SUBMISSION), 'the room card closed');

            $items = $app->checklistService()->sync((string) $engagement['id']);
            $byKey = [];
            foreach ($items as $item) {
                $byKey[(string) $item['item_key']] = $item;
            }
            Expect::notNull($byKey[Checklist::FIRST_APPROVAL]['completed_at'], 'the first approval is on the checklist');
        },

    'a returned approval hands the batch back with the note, and a fresh request can follow' =>
        static function (Bootstrap $app, Database $db) use ($boot, $active, $asClient, $batchNamed): void {
            [$app, $sent] = $boot($db);
            $engagement = $active($app, $sent);
            $recovery = $app->recoveryService();
            $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];
            $request = $recovery->requestApproval($engagement, $batch, ['safe_summary' => 'First-level appeals to the commercial payer.'], $ownerId);

            $approver = $asClient($app, $engagement, 'kofi@example.org');
            Expect::throws(
                RuntimeException::class,
                static fn () => $recovery->decide($engagement, $request, ApprovalState::RETURNED, '', $approver),
                'returning needs a note'
            );
            Expect::throws(
                RuntimeException::class,
                static fn () => $recovery->decide($engagement, $request, ApprovalState::RETURNED, 'Check patient SSN 123-45-6789 first', $approver),
                'a note that carries a person is refused'
            );
            $recovery->decide($engagement, $request, ApprovalState::RETURNED, 'Please cite the contract clause in the cover letter.', $approver);
            $app->clientAccess()->signOut();

            $stored = $app->approvalRequests()->find((string) $request['id']);
            Expect::same(ApprovalState::RETURNED, (string) $stored['state'], 'returned');
            Expect::same('Please cite the contract clause in the cover letter.', (string) $stored['decision_note'], 'with the note');
            Expect::same(BatchStage::RECOMMENDED, (string) $app->workBatches()->find((string) $batch['id'])['stage'], 'the batch is back to recommended');
            Expect::null($app->approvalRequests()->approvedUnusedForBatch((string) $batch['id']), 'nothing is approved');
            Expect::throws(
                RuntimeException::class,
                static fn () => $recovery->recordSubmission($engagement, $app->workBatches()->find((string) $batch['id']), [], $ownerId),
                'nothing can be submitted on a returned approval'
            );

            $again = $recovery->requestApproval($engagement, $app->workBatches()->find((string) $batch['id']), ['safe_summary' => 'Revised: first-level appeals citing the contract clause.'], $ownerId);
            Expect::same(ApprovalState::PENDING, (string) $again['state'], 'a fresh request is pending');
            Expect::same(2, count($app->approvalRequests()->forEngagement((string) $engagement['id'])), 'both requests stay on the record');
        },

    'a submission cannot be recorded without an approval, uses the approval once, and is the first submission on the checklist' =>
        static function (Bootstrap $app, Database $db) use ($boot, $active, $asClient, $labelsOf, $batchNamed): void {
            [$app, $sent] = $boot($db);
            $engagement = $active($app, $sent);
            $recovery = $app->recoveryService();
            $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];

            Expect::throws(
                RuntimeException::class,
                static fn () => $recovery->recordSubmission($engagement, $batch, [], $ownerId),
                'Gate C: no approval, no submission'
            );
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->submissionEvents()->record((string) $engagement['id'], (string) $engagement['organization_id'], (string) $batch['id'], [
                    'event_type' => SubmissionEventType::SUBMITTED, 'claim_count' => 1, 'amount_cents' => 100,
                    'occurred_at' => '2026-09-01 12:00:00', 'note' => null, 'follow_up_due_at' => null, 'approval_request_id' => null,
                ], $ownerId),
                'and the database refuses a submitted event with no approval id, whatever the code does'
            );

            $request = $recovery->requestApproval($engagement, $batch, ['safe_summary' => 'First-level appeals to the commercial payer.'], $ownerId);
            $approver = $asClient($app, $engagement, 'kofi@example.org');
            $recovery->decide($engagement, $request, ApprovalState::APPROVED, null, $approver);
            $app->clientAccess()->signOut();

            $before = count($sent);
            $event = $recovery->recordSubmission($engagement, $app->workBatches()->find((string) $batch['id']), [
                'claim_count' => '12', 'amount' => '11,200.00', 'occurred' => '2026-09-02', 'follow_up' => '2026-10-02',
                'note' => 'Sent by the payer portal with the contract clause cited.',
            ], $ownerId);
            Expect::same(SubmissionEventType::SUBMITTED, (string) $event['event_type'], 'submitted');
            Expect::same((string) $request['id'], (string) $event['approval_request_id'], 'pointing at the approval that allowed it');
            Expect::same('2026-10-02 12:00:00', (string) $event['follow_up_due_at'], 'with a follow-up');

            $after = $app->workBatches()->find((string) $batch['id']);
            Expect::same(BatchStage::SUBMITTED, (string) $after['stage'], 'the batch is submitted');
            Expect::same(12, (int) $after['submitted_count'], 'and counts what went');
            Expect::same(BatchStage::OWNER_PAYER, (string) $after['next_owner'], 'waiting on the payer');

            Expect::same($before + 1, count($sent), 'the practice was told there is a status update');
            Expect::same('dana@example.org', $sent[count($sent) - 1]['to'], 'at the signer');
            Expect::false(str_contains($sent[count($sent) - 1]['body'], 'contract clause'), 'without the note');

            Expect::throws(
                RuntimeException::class,
                static fn () => $recovery->recordSubmission($engagement, $app->workBatches()->find((string) $batch['id']), [], $ownerId),
                'an approval is used once'
            );

            Expect::true(in_array('submission.recorded', $labelsOf($app, (string) $engagement['id']), true), 'the timeline says it went');
            $items = $app->checklistService()->sync((string) $engagement['id']);
            $byKey = [];
            foreach ($items as $item) {
                $byKey[(string) $item['item_key']] = $item;
            }
            Expect::notNull($byKey[Checklist::FIRST_SUBMISSION]['completed_at'], 'the first submission is on the checklist');
            Expect::same(12, count($items), 'all twelve items exist');

            Expect::same(1, count($app->submissionEvents()->openFollowUps()), 'one follow-up is open');
            $recovery->completeFollowUp($engagement, $event, $ownerId);
            Expect::same(0, count($app->submissionEvents()->openFollowUps()), 'and it closes');
        },

    'payer responses move the batch and never create a fee' =>
        static function (Bootstrap $app, Database $db) use ($boot, $active, $asClient, $batchNamed): void {
            [$app, $sent] = $boot($db);
            $engagement = $active($app, $sent);
            $recovery = $app->recoveryService();
            $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];

            Expect::throws(
                RuntimeException::class,
                static fn () => $recovery->recordPayerResponse($engagement, $batch, ['event_type' => SubmissionEventType::DECISION_FAVORABLE], $ownerId),
                'a response needs a submission first'
            );

            $request = $recovery->requestApproval($engagement, $batch, ['safe_summary' => 'First-level appeals to the commercial payer.'], $ownerId);
            $approver = $asClient($app, $engagement, 'kofi@example.org');
            $recovery->decide($engagement, $request, ApprovalState::APPROVED, null, $approver);
            $app->clientAccess()->signOut();
            $recovery->recordSubmission($engagement, $app->workBatches()->find((string) $batch['id']), ['claim_count' => '12', 'amount' => '11,200.00'], $ownerId);

            $fresh = static fn (): array => $app->workBatches()->find((string) $batch['id']);

            $recovery->recordPayerResponse($engagement, $fresh(), ['event_type' => SubmissionEventType::PAYER_ACKNOWLEDGED], $ownerId);
            Expect::same(BatchStage::PAYER_REVIEW, (string) $fresh()['stage'], 'acknowledged means with the payer');

            $recovery->recordPayerResponse($engagement, $fresh(), ['event_type' => SubmissionEventType::INFORMATION_REQUESTED, 'follow_up' => '2026-09-30'], $ownerId);
            Expect::same(BatchStage::PAYER_REVIEW, (string) $fresh()['stage'], 'still with the payer');
            Expect::notNull($app->actionRequests()->openOfKind((string) $engagement['id'], ActionRequestKind::PROVIDE_INFORMATION), 'the practice is asked, through the secure route');

            $before = count($sent);
            $decision = $recovery->recordPayerResponse($engagement, $fresh(), [
                'event_type' => SubmissionEventType::DECISION_PARTIAL, 'claim_count' => '8', 'amount' => '7,000.00',
                'note' => 'Eight of twelve overturned at first level.',
            ], $ownerId);
            Expect::same(BatchStage::OVERTURNED, (string) $fresh()['stage'], 'a partial decision is an overturn');
            Expect::same(8, (int) $fresh()['overturned_count'], 'counting what was overturned');
            Expect::same($before + 1, count($sent), 'the practice was told');
            Expect::false(str_contains($sent[count($sent) - 1]['body'], 'Eight of twelve'), 'without the detail');

            // Section 19. Nothing here is a fee. The recovery table exists
            // since Phase 7 and nothing in this phase writes to it.
            Expect::false($db->columnExists('sa_submission_events', 'fee_cents'), 'no fee column on an event');
            Expect::false($db->columnExists('sa_approval_requests', 'fee_cents'), 'no fee column on an approval');
            Expect::same(0, count($app->recoveries()->forEngagement((string) $engagement['id'])), 'a payer decision wrote no recovery row');
            $block = $recovery->feeBlock($engagement);
            Expect::true($block['shown'], 'the fee block is shown once recovery is active');
            Expect::same('$0.00', (string) $block['verified'], 'nothing is verified');
            Expect::same('$0.00', (string) $block['fee'], 'so no fee');
            Expect::same('Not created', (string) $block['invoice'], 'and no invoice');
            Expect::same('$7,000.00', (string) $block['overturned'], 'what the payer said is shown as what the payer said');
            Expect::same('25 percent of verified reimbursement', (string) $block['rate'], 'beside the rate it would apply at');

            $totals = $app->submissionEvents()->totals((string) $engagement['id']);
            Expect::same(1120000, (int) $totals['submitted_cents'], 'submitted cents add up');
            Expect::same(700000, (int) $totals['overturned_cents'], 'overturned cents add up');

            Expect::throws(
                RuntimeException::class,
                static fn () => $recovery->recordPayerResponse($engagement, $fresh(), ['event_type' => SubmissionEventType::PAYER_ACKNOWLEDGED], $ownerId),
                'an overturned batch is terminal for the payer loop'
            );
            Expect::same(Stage::RECOVERY_ACTIVE, (string) $app->engagements()->find((string) $engagement['id'])['stage'], 'the engagement stays active for the money phase');
        },

    'the room reads the board, the approvals and the recovery block from the same source as the Desk' =>
        static function (Bootstrap $app, Database $db) use ($boot, $active, $batchNamed): void {
            [$app, $sent] = $boot($db);
            $engagement = $active($app, $sent);
            $recovery = $app->recoveryService();
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];
            $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
            $recovery->requestApproval($engagement, $batch, ['safe_summary' => 'First-level appeals to the commercial payer.'], $ownerId);

            $board = $recovery->board($engagement);
            Expect::same(2, count($board), 'two batches on the board');
            $rows = [];
            foreach ($board as $row) {
                $rows[(string) $row['batch']['label']] = $row;
            }
            Expect::true($rows['Commercial set']['in_scope'], 'the commercial set is in scope');
            Expect::false($rows['Second set']['in_scope'], 'the second set is not');
            $card = $rows['Commercial set']['card'];
            Expect::same('Waiting for your approval', (string) $card['stage'], 'the card reads as the practice reads it');
            Expect::same('Awaiting client approval', (string) $rows['Commercial set']['staff_stage'], 'and the Desk reads it its own way');
            Expect::same('You', (string) $card['owner'], 'and waits on them');
            foreach (['payer', 'count', 'denied', 'stage', 'owner', 'action', 'deadline', 'confirmed'] as $key) {
                Expect::true(array_key_exists($key, $card), 'the card has ' . $key);
            }
            Expect::false(array_key_exists('claims', $card), 'and nothing that lists a claim');
            Expect::false(array_key_exists('staff_stage', $card), 'and nothing written for the Desk');

            $pending = $app->approvalRequests()->pendingForEngagement((string) $engagement['id']);
            Expect::same(1, count($pending), 'one approval pending for the room');
            Expect::same(1, count($app->approvalRequests()->pendingEverywhere()), 'and one on the Desk board');
            Expect::same(0, count($app->approvalRequests()->approvedAwaitingSubmission()), 'nothing approved yet');
        },
];
