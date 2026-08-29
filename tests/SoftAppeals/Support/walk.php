<?php
declare(strict_types=1);

/**
 * The walk every Phase 7 test starts from: an inquiry, both Gate A agreements,
 * the secure route, the assessment, the decision, the scope, the Gate B pair,
 * recovery active, an approval, a submission, and a payer decision. Copied
 * from RecoveryTest so that a change to those helpers cannot quietly change
 * what the money tests prove, and shared between the two Phase 7 files so
 * the walk is written once here.
 *
 * Returns an array of closures. Not a test file: run.php only reads *Test.php.
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

/**
 * Active, then one approval, one submission and a partial payer decision on
 * the commercial set: 12 claims submitted for $11,200.00, 8 overturned for
 * $7,000.00, follow-up closed. The fixture SA-ENG-YW6X7M on staging sits
 * exactly here. Returns the engagement, refreshed.
 */
$overturned = static function (Bootstrap $app, ArrayObject $sent) use ($active, $asClient, $batchNamed): array {
    $engagement = $active($app, $sent);
    $recovery = $app->recoveryService();
    $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
    $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];
    $request = $recovery->requestApproval($engagement, $batch, ['safe_summary' => 'First-level appeals to the commercial payer.'], $ownerId);
    $approver = $asClient($app, $engagement, 'kofi@example.org');
    $recovery->decide($engagement, $request, ApprovalState::APPROVED, null, $approver);
    $app->clientAccess()->signOut();
    $event = $recovery->recordSubmission($engagement, $app->workBatches()->find((string) $batch['id']), [
        'claim_count' => '12', 'amount' => '11,200.00', 'follow_up' => '2026-09-28',
    ], $ownerId);
    $recovery->recordPayerResponse($engagement, $app->workBatches()->find((string) $batch['id']), [
        'event_type' => SubmissionEventType::DECISION_PARTIAL, 'claim_count' => '8', 'amount' => '7,000.00',
    ], $ownerId);
    $recovery->completeFollowUp($engagement, $event, $ownerId);
    return $app->engagements()->findWithOrganization((string) $engagement['id']);
};

return [
    'boot'           => $boot,
    'owner'          => $owner,
    'signAsPractice' => $signAsPractice,
    'atSecureRoute'  => $atSecureRoute,
    'asClient'       => $asClient,
    'batchNamed'     => $batchNamed,
    'scopeSelected'  => $scopeSelected,
    'recordScope'    => $recordScope,
    'pairExecuted'   => $pairExecuted,
    'active'         => $active,
    'labelsOf'       => $labelsOf,
    'overturned'     => $overturned,
];
