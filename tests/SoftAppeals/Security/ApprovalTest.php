<?php
declare(strict_types=1);

/**
 * Who may decide an approval request. Section 8.2 and section 22, Phase 6:
 * "only an authorized approver can decide an approval request".
 *
 * Each case signs in as a different person at the same practice and asks
 * for the same decision. The organization admin and the named submission
 * approver get it. The authorized signer, the billing contact, the
 * compliance contact and the viewer are refused, on the server, with the
 * request left pending. A person at a different practice is refused before
 * the role is even looked at.
 *
 * The walk to "recovery active" is the same one RecoveryTest takes, and it
 * is repeated here rather than shared so that a change to the integration
 * helpers cannot quietly change what this file proves.
 */

use SoftAppeals\Auth\SessionManager;
use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\ApprovalState;
use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Domain\ClientDecision;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\FitDecision;
use SoftAppeals\Domain\PreferenceForm;
use SoftAppeals\Domain\Role;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\InvitationRepository;

$boot = static function (Database $db): array {
    $vault = sys_get_temp_dir() . '/sa-ap-vault-' . bin2hex(random_bytes(4));
    $path = sys_get_temp_dir() . '/sa-ap-config-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($path, '<?php return ' . var_export([
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
    ], true) . ";\n");
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

$signAsPractice = static function (Bootstrap $app, ArrayObject $sent, array $engagement, string $kind, bool $redeem = true): void {
    if ($redeem) {
        $token = '';
        foreach ($sent as $message) {
            if (preg_match('~soft-appeals-sign\?t=([0-9a-f]+)~', $message['body'], $m) === 1) {
                $token = $m[1];
            }
        }
        Expect::notNull($app->clientAccess()->redeemInvitation($token, InvitationRepository::PURPOSE_SIGN), 'the signing invitation should redeem');
    }
    $context = $app->clientAccess()->context();
    $signContext = [
        'organization_id' => (string) $engagement['organization_id'],
        'engagement'      => $app->engagements()->findWithOrganization((string) $engagement['id']),
        'contact_id'      => $context['contact_id'],
    ];
    $document = $app->signingService()->pending($signContext);
    Expect::notNull($document, 'something should be waiting');
    Expect::same($kind, (string) $document['kind'], 'the right document is offered');
    $app->signingService()->sign($document, $signContext + ['user_id' => (string) $context['user']['id']], [
        'typed_name' => 'Dana Owusu', 'typed_title' => 'Practice owner',
        'typed_organization' => (string) $engagement['legal_name'],
        'consent' => true, 'document_sha256' => (string) $document['content_sha256'],
    ]);
};

/** A pending approval request on an active recovery, and the owner's id. */
$pendingApproval = static function (Bootstrap $app, ArrayObject $sent) use ($signAsPractice): array {
    $ownerId = $app->users()->create('owner@example.org');
    $app->memberships()->grant($ownerId, Role::OWNER_ADMIN);

    $intake = $app->intakeService()->record('soft-appeals-start', [
        'organization' => 'Fictional Behavioral Health LLC', 'name' => 'Dana Owusu', 'email' => 'dana@example.org',
        'organization_type' => 'Behavioral health', 'state' => 'Maryland', 'denial_volume' => '51 to 100',
    ], 'raw-' . bin2hex(random_bytes(4)));
    $review = $app->intakeService()->review($intake['id'], FitDecision::ACCEPT, null, null, EngagementTerms::FEE_CONTINGENCY_25, EngagementTerms::CHANNEL_CLIENT_SYSTEM, 'within ten business days');
    $engagementId = (string) $review['engagement_id'];
    $engagement = $app->engagements()->findWithOrganization($engagementId);
    $app->termsService()->send($engagement, 0, null);

    $token = '';
    foreach ($sent as $message) {
        if (preg_match('~soft-appeals-preferences\.php\?t=([0-9a-f]+)~', $message['body'], $m) === 1) {
            $token = $m[1];
        }
    }
    Expect::notNull($app->clientAccess()->redeemInvitation($token, InvitationRepository::PURPOSE_PREFERENCES), 'the preferences link should redeem');
    $context = $app->clientAccess()->context();
    // The row as it is NOW, after the terms went out. The one read before the
    // send is a stage behind, and confirm() reads the stage off what it is given.
    $engagement = $app->engagements()->findWithOrganization($engagementId);
    $saved = $app->preferencesService()->confirm($engagement, [
        'communication_cadence' => EngagementTerms::CADENCE_BIWEEKLY,
        'secure_channel'        => EngagementTerms::CHANNEL_CLIENT_SYSTEM,
        'billing_partner'       => PreferenceForm::PARTNER_YES,
        'signer_name' => 'Dana Owusu', 'signer_role' => 'Practice owner', 'signer_email' => 'dana@example.org',
        'approver_name' => '', 'approver_role' => '', 'approver_email' => '',
        'billing_name' => 'Bea Ledger', 'billing_role' => 'Finance', 'billing_email' => 'bea@example.org',
        'initial_payer_group' => 'Commercial', 'procurement_notes' => '',
    ], (string) $context['user']['id'], $context['contact_id']);
    Expect::true($saved['saved'], 'the preferences should save: ' . implode('; ', $saved['errors']));
    $app->clientAccess()->signOut();

    foreach ([DocumentKind::BAA, DocumentKind::REVIEW_AUTHORIZATION] as $kind) {
        $engagement = $app->engagements()->findWithOrganization($engagementId);
        $document = $app->documentService()->generate($engagement, $kind, null);
        $app->documentService()->send($document, $engagement, null);
        $signAsPractice($app, $sent, $engagement, $kind);
        $app->clientAccess()->signOut();
        $app->documentService()->countersign($app->documents()->find((string) $document['id']), $engagement, [
            'typed_name' => 'Nana Frimpongmaa', 'typed_title' => 'Owner', 'consent' => true,
        ], $ownerId);
    }
    $app->engagementService()->move($engagementId, Stage::SECURE_INTAKE_READY, 'The secure route is open', 'engagement.secure_route_open', $ownerId);
    $engagement = $app->engagements()->findWithOrganization($engagementId);

    $assessment = $app->assessmentService();
    $assessment->confirmReceipt($engagement, 20, 20, $app->workBatchService()->fieldsFromInput(['label' => 'Initial set', 'denied_amount' => '18,400.00']), $ownerId);
    $assessment->start($engagement, $ownerId);
    $assessment->sendToQualityReview($engagement, $ownerId);
    $batch = $app->workBatches()->forEngagement($engagementId)[0];
    $app->workBatchService()->update($engagement, $batch, ['stage' => BatchStage::RECOMMENDED], $ownerId);
    $assessment->deliver($engagement, ['summary' => 'Twenty denials reviewed, all with a clear path at first level.', 'recommended_count' => 20, 'recommended_amount_cents' => 1840000, 'decision_due' => null], $ownerId);

    $dana = $app->users()->findByEmail('dana@example.org');
    $danaContact = $app->contacts()->findByEmail((string) $engagement['organization_id'], 'dana@example.org');
    $app->session()->start();
    $app->session()->establish(SessionManager::KIND_CLIENT, (string) $dana['id'], (string) $engagement['organization_id']);
    $assessment->markRead($engagement, (string) $dana['id']);
    $assessment->decide($engagement, ClientDecision::RECOVERY_SCOPE, null, (string) $danaContact['id'], (string) $dana['id']);
    $app->clientAccess()->signOut();
    $engagement = $app->engagements()->findWithOrganization($engagementId);

    $app->recoveryService()->recordScope($engagement, [
        'fee_basis' => EngagementTerms::FEE_CONTINGENCY_25, 'fee_rate' => '',
        'summary' => 'The commercial denials in the initial set, first-level appeals.',
        'batch_refs' => [(string) $batch['public_ref']],
        'approver_name' => 'Kofi Mensah', 'approver_email' => 'kofi@example.org', 'approver_role' => 'Revenue cycle lead',
    ], $ownerId);
    $pair = $app->documentService()->generateRecoveryPair($engagement, $ownerId);
    $app->documentService()->send($pair['agreement'], $engagement, $ownerId);
    $signAsPractice($app, $sent, $engagement, DocumentKind::RECOVERY_AGREEMENT);
    $signAsPractice($app, $sent, $engagement, DocumentKind::APPROVED_SCOPE, false);
    $app->clientAccess()->signOut();
    $app->documentService()->countersign($app->documents()->find((string) $pair['agreement']['id']), $engagement, [
        'typed_name' => 'Nana Frimpongmaa', 'typed_title' => 'Owner', 'consent' => true,
    ], $ownerId);
    $app->recoveryService()->activate($engagement, $ownerId);
    $engagement = $app->engagements()->findWithOrganization($engagementId);

    $request = $app->recoveryService()->requestApproval($engagement, $app->workBatches()->find((string) $batch['id']), [
        'safe_summary' => 'First-level appeals for the initial set, to the commercial payer.',
    ], $ownerId);

    return [$engagement, $request, $ownerId];
};

/** Sign in as one address, creating the user with one role if it does not exist. */
$signInAs = static function (Bootstrap $app, array $engagement, string $email, ?string $roleIfNew = null): array {
    $organizationId = (string) $engagement['organization_id'];
    $user = $app->users()->findByEmail($email);
    if ($user === null) {
        $id = $app->users()->create($email);
        if ($roleIfNew !== null) {
            $app->memberships()->grant($id, $roleIfNew, $organizationId);
        }
        $user = $app->users()->find($id);
    }
    $contact = $app->contacts()->findByEmail($organizationId, $email);
    $app->session()->start();
    $app->session()->establish(SessionManager::KIND_CLIENT, (string) $user['id'], $organizationId);
    return [
        'organization_id' => $organizationId,
        'user_id'         => (string) $user['id'],
        'contact_id'      => $contact === null ? null : (string) $contact['id'],
    ];
};

return [

    'the named submission approver can decide, and the decision is credited to them' =>
        static function (Bootstrap $app, Database $db) use ($boot, $pendingApproval, $signInAs): void {
            [$app, $sent] = $boot($db);
            [$engagement, $request] = $pendingApproval($app, $sent);
            $context = $signInAs($app, $engagement, 'kofi@example.org');
            Expect::true($app->authorization()->can(\SoftAppeals\Domain\Permission::APPROVAL_DECIDE, (string) $engagement['organization_id']), 'the approver holds the permission');
            $result = $app->recoveryService()->decide($engagement, $request, ApprovalState::APPROVED, null, $context);
            Expect::true($result['decided'] && !$result['already'], 'decided');
            $stored = $app->approvalRequests()->find((string) $request['id']);
            Expect::same(ApprovalState::APPROVED, (string) $stored['state'], 'approved');
            Expect::same($context['user_id'], (string) $stored['decision_by'], 'by the approver');
            Expect::same($context['contact_id'], (string) $stored['decision_contact_id'], 'as the contact they are');
        },

    'the organization admin can decide too' =>
        static function (Bootstrap $app, Database $db) use ($boot, $pendingApproval, $signInAs): void {
            [$app, $sent] = $boot($db);
            [$engagement, $request] = $pendingApproval($app, $sent);
            // Dana came in on the terms link, which grants org_admin, and was
            // named authorized signer on top. The admin half is what decides.
            $context = $signInAs($app, $engagement, 'dana@example.org');
            $result = $app->recoveryService()->decide($engagement, $request, ApprovalState::RETURNED, 'Cite the contract clause first.', $context);
            Expect::true($result['decided'], 'the admin decided');
            Expect::same(ApprovalState::RETURNED, (string) $app->approvalRequests()->find((string) $request['id'])['state'], 'returned');
        },

    'the billing contact, a viewer, a compliance contact and a bare signer are all refused and the request stays pending' =>
        static function (Bootstrap $app, Database $db) use ($boot, $pendingApproval, $signInAs): void {
            [$app, $sent] = $boot($db);
            [$engagement, $request] = $pendingApproval($app, $sent);
            $organizationId = (string) $engagement['organization_id'];

            $people = [
                ['bea@example.org',    null,             'the billing contact'],
                ['view@example.org',   Role::VIEWER,     'a viewer'],
                ['it@example.org',     Role::COMPLIANCE, 'a compliance contact'],
                ['signer2@example.org', Role::AUTHORIZED_SIGNER, 'a second authorized signer'],
            ];
            foreach ($people as [$email, $role, $who]) {
                $context = $signInAs($app, $engagement, $email, $role);
                Expect::false($app->authorization()->can(\SoftAppeals\Domain\Permission::APPROVAL_DECIDE, $organizationId), $who . ' does not hold the permission');
                Expect::throws(
                    RuntimeException::class,
                    static fn () => $app->recoveryService()->decide($engagement, $request, ApprovalState::APPROVED, null, $context),
                    $who . ' is refused'
                );
                Expect::same(ApprovalState::PENDING, (string) $app->approvalRequests()->find((string) $request['id'])['state'], 'still pending after ' . $who);
                $app->clientAccess()->signOut();
            }

            $denied = array_filter(
                $app->audit()->forObject('approval_request', (string) $request['id']),
                static fn (array $row): bool => (string) $row['action'] === 'approval.decide' && (string) $row['outcome'] === 'denied'
            );
            Expect::same(4, count($denied), 'every refusal is on the audit trail');
        },

    'a person at a different practice cannot reach the request at all' =>
        static function (Bootstrap $app, Database $db) use ($boot, $pendingApproval): void {
            [$app, $sent] = $boot($db);
            [$engagement, $request] = $pendingApproval($app, $sent);

            $otherOrg = $app->organizations()->create('Other Fictional Clinic', 'Other Fictional Clinic', null, 'Maryland', \SoftAppeals\Repositories\OrganizationRepository::STATUS_PROSPECT);
            $intruderId = $app->users()->create('admin@other.example.org');
            $app->memberships()->grant($intruderId, Role::ORG_ADMIN, $otherOrg);
            $app->session()->start();
            $app->session()->establish(SessionManager::KIND_CLIENT, $intruderId, $otherOrg);

            // The route finds requests THROUGH the session's engagement, so a
            // reference alone reaches nothing.
            Expect::null($app->approvalRequests()->findForEngagement((string) $request['public_ref'], 'no-such-engagement'), 'the reference finds nothing outside its engagement');

            // And the service refuses even a row handed to it directly.
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->recoveryService()->decide($engagement, $request, ApprovalState::APPROVED, null, [
                    'organization_id' => $otherOrg, 'user_id' => $intruderId, 'contact_id' => null,
                ]),
                'a different practice is refused'
            );
            Expect::same(ApprovalState::PENDING, (string) $app->approvalRequests()->find((string) $request['id'])['state'], 'still pending');
        },
];
