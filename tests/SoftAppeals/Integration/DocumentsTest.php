<?php
declare(strict_types=1);

/**
 * Phase 4 acceptance, the document half.
 *
 *   a signed document cannot be edited
 *   a corrected document creates a new version
 *   signature event references the exact document hash
 *   final executed representation reopens and matches stored hashes
 *   production e-sign remains disabled until blockers are cleared
 *
 * Every case walks the real path from an inquiry arriving: she accepts it, the
 * terms go out, the practice answers the eight questions, the BAA is generated
 * from what they answered, and the signing token is read out of the message the
 * mail layer was actually handed. A test that minted its own token would prove
 * the repository works and would prove nothing about the workflow.
 *
 * No test here opens a socket and no test here writes into the real vault. The
 * transport is a closure that records what it was given, and the vault is a
 * throwaway directory named in the config each case boots against.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\DocumentTemplates;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\PreferenceForm;
use SoftAppeals\Domain\FitDecision;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\DocumentRepository;
use SoftAppeals\Repositories\InvitationRepository;
use SoftAppeals\Repositories\SignatureRepository;
use SoftAppeals\Services\DocumentVault;

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

/**
 * An application that may email anybody, writing into a vault of its own, with
 * the transport captured.
 *
 * @return array{0:Bootstrap,1:ArrayObject<int,array{to:string,subject:string,body:string}>,2:string}
 */
$boot = static function (Database $db, array $overrides = []): array {
    $vault = sys_get_temp_dir() . '/sa-doc-vault-' . bin2hex(random_bytes(4));
    $path = sys_get_temp_dir() . '/sa-doc-config-' . bin2hex(random_bytes(4)) . '.php';

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
 * An engagement whose practice has answered the eight questions, which is the
 * state every document workflow begins from.
 *
 * @return array<string,mixed> the engagement, joined with its organization
 */
$confirmed = static function (Bootstrap $app, ArrayObject $sent, array $answers, array $preferences): array {
    $intake = $app->intakeService()->record(
        'soft-appeals-start',
        $answers,
        'raw-body-' . bin2hex(random_bytes(4))
    );
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

    $redeemed = $app->clientAccess()->redeemInvitation($token, InvitationRepository::PURPOSE_PREFERENCES);
    Expect::notNull($redeemed, 'the preferences invitation should redeem');

    $context = $app->clientAccess()->context();
    Expect::notNull($context, 'redeeming should open a client session');

    $result = $app->preferencesService()->confirm(
        $app->engagements()->findWithOrganization($engagementId),
        $preferences,
        (string) $context['user']['id'],
        $context['contact_id']
    );
    Expect::true($result['saved'], 'the preferences should save: ' . json_encode($result['errors'] ?? []));

    $app->clientAccess()->signOut();

    return $app->engagements()->findWithOrganization($engagementId);
};

/** The signing token out of the message the mail layer was handed. */
$signTokenFrom = static function (ArrayObject $sent): string {
    $token = '';
    foreach ($sent as $message) {
        if (preg_match('~soft-appeals-sign\?t=([0-9a-f]+)~', $message['body'], $m) === 1) {
            $token = $m[1];
        }
    }
    return $token;
};

/**
 * Her own staff account, because a countersignature is credited to a real user
 * row and the seeder does not plant one in a test database.
 */
$owner = static function (Bootstrap $app): string {
    $existing = $app->users()->findByEmail('owner@example.org');
    if ($existing !== null) {
        return (string) $existing['id'];
    }
    $id = $app->users()->create('owner@example.org');
    $app->memberships()->grant($id, \SoftAppeals\Domain\Role::OWNER_ADMIN);
    return $id;
};

/**
 * The practice signs, using the real link out of the real message.
 *
 * @return array<string,mixed> the document as it stands after signing
 */
$clientSigns = static function (
    Bootstrap $app,
    array $document,
    array $engagement,
    string $token,
    string $typedName = 'Dana Owusu'
): array {
    $redeemed = $app->clientAccess()->redeemInvitation($token, InvitationRepository::PURPOSE_SIGN);
    Expect::notNull($redeemed, 'the signing invitation should redeem');

    $context = $app->clientAccess()->context();
    Expect::notNull($context, 'redeeming a signing link should open a client session');

    $result = $app->signingService()->sign($document, [
        'organization_id' => (string) $engagement['organization_id'],
        'engagement'      => $engagement,
        'contact_id'      => $context['contact_id'],
        'user_id'         => (string) $context['user']['id'],
    ], [
        'typed_name'         => $typedName,
        'typed_title'        => 'Practice owner',
        'typed_organization' => (string) $engagement['legal_name'],
        'consent'            => true,
        'document_sha256'    => (string) $document['content_sha256'],
    ]);
    Expect::true($result['signed'], 'the signature should be applied');

    $app->clientAccess()->signOut();

    return $app->documents()->find((string) $document['id']);
};

return [

    'a BAA cannot be generated before the practice has confirmed its preferences' =>
        static function (Bootstrap $app, Database $db) use ($answers, $boot): void {
            [$app, $sent] = $boot($db);

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
            $engagement = $app->engagements()->findWithOrganization((string) $review['engagement_id']);

            $check = $app->documentService()->canGenerate($engagement, DocumentKind::BAA);
            Expect::false($check['ok'], 'a BAA at terms_ready should be refused');

            Expect::throws(
                RuntimeException::class,
                static fn () => $app->documentService()->generate($engagement, DocumentKind::BAA),
                'generating a BAA before preferences should throw'
            );

            Expect::same(
                0,
                count($app->documents()->forEngagement((string) $engagement['id'])),
                'nothing should have been written'
            );
            foreach ($sent as $message) {
                Expect::false(
                    str_contains($message['body'], 'soft-appeals-sign'),
                    'a refused generation must not have emailed anything'
                );
            }
        },

    'generating writes a draft into the vault and hashes what it wrote' =>
        static function (Bootstrap $app, Database $db) use ($answers, $preferences, $boot, $confirmed): void {
            [$app, $sent, $vault] = $boot($db);
            $engagement = $confirmed($app, $sent, $answers, $preferences);

            $document = $app->documentService()->generate($engagement, DocumentKind::BAA, null);

            Expect::same(DocumentStatus::DRAFT, (string) $document['status'], 'it should be a draft');
            Expect::same(1, (int) $document['version'], 'it should be version 1');
            Expect::same(
                DocumentTemplates::TEMPLATE_VERSION,
                (string) $document['template_version'],
                'it should carry the exact template version'
            );
            Expect::same(64, strlen((string) $document['content_sha256']), 'it should carry a full hash');

            $stored = $vault . '/' . (string) $document['private_path'];
            Expect::true(is_file($stored), 'the body should be on disk inside the vault');
            Expect::same(
                (string) $document['content_sha256'],
                hash('sha256', (string) file_get_contents($stored)),
                'the stored hash should be the hash of the stored bytes'
            );

            // Nothing left the building. Generating is not sending.
            foreach ($sent as $message) {
                Expect::false(
                    str_contains($message['body'], 'soft-appeals-sign'),
                    'generating must not email a signing link'
                );
            }
        },

    'the document names both parties, the signer, and its own reference' =>
        static function (Bootstrap $app, Database $db) use ($answers, $preferences, $boot, $confirmed): void {
            [$app, $sent] = $boot($db);
            $engagement = $confirmed($app, $sent, $answers, $preferences);

            $document = $app->documentService()->generate($engagement, DocumentKind::BAA, null);
            $body = (string) $app->documentService()->body($document);

            foreach ([
                (string) $document['public_ref'],
                'A Fictional Legal Entity LLC',
                'Fictional Behavioral Health LLC',
                'Dana Owusu',
                'dana@example.org',
                DocumentTemplates::TEMPLATE_VERSION,
            ] as $needle) {
                Expect::true(
                    str_contains($body, $needle),
                    'the document should carry "' . $needle . '" on its face'
                );
            }

            Expect::true(
                str_contains($body, 'DRAFT FOR REVIEW'),
                'unapproved wording should say so on the document itself'
            );
        },

    'the same document generated twice hashes the same both times' =>
        static function (Bootstrap $app, Database $db) use ($answers, $preferences, $boot, $confirmed): void {
            [$app, $sent] = $boot($db);
            $engagement = $confirmed($app, $sent, $answers, $preferences);

            $context = [
                'document_ref'        => 'SA-DOC-FIXED1',
                'version'             => '1',
                'effective_date'      => '12 August 2026',
                'provider_legal_name' => 'A Fictional Legal Entity LLC',
                'provider_trade_name' => 'Soft Appeals',
                'client_legal_name'   => 'Fictional Behavioral Health LLC',
                'signer_name'         => 'Dana Owusu',
                'signer_title'        => 'Practice owner',
                'signer_email'        => 'dana@example.org',
                'secure_channel'      => 'Their own approved environment',
                'assessment_window'   => 'within ten business days',
            ];

            Expect::same(
                hash('sha256', DocumentTemplates::body(DocumentKind::BAA, $context)),
                hash('sha256', DocumentTemplates::body(DocumentKind::BAA, $context)),
                'the same context must produce the same bytes, or no hash means anything'
            );
        },

    'a document with an empty required field is refused rather than rendered blank' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db);

            Expect::throws(
                RuntimeException::class,
                static fn (): string => DocumentTemplates::body(DocumentKind::BAA, [
                    'document_ref'   => 'SA-DOC-FIXED2',
                    'version'        => '1',
                    'effective_date' => '',
                ]),
                'a blank effective date should stop the document being built'
            );
        },

    'sending moves the engagement to BAA pending and mints a one-time link' =>
        static function (Bootstrap $app, Database $db) use ($answers, $preferences, $boot, $confirmed, $signTokenFrom): void {
            [$app, $sent] = $boot($db);
            $engagement = $confirmed($app, $sent, $answers, $preferences);

            $document = $app->documentService()->generate($engagement, DocumentKind::BAA, null);
            $result = $app->documentService()->send($document, $engagement, null);

            Expect::true($result['sent'], 'the signing request should be accepted by the transport');
            Expect::notNull($result['link'], 'off production the link comes back so staging can be walked');

            $after = $app->engagements()->find((string) $engagement['id']);
            Expect::same(Stage::BAA_PENDING, (string) $after['stage'], 'the engagement should be waiting on a signature');

            $reloaded = $app->documents()->find((string) $document['id']);
            Expect::same(DocumentStatus::SENT, (string) $reloaded['status'], 'the document should be out for signature');

            Expect::true($signTokenFrom($sent) !== '', 'the email should carry a signing token');

            // Section 14.4: a notice and a link, never the document.
            $body = '';
            foreach ($sent as $message) {
                if (str_contains($message['body'], 'soft-appeals-sign?t=')) {
                    $body = $message['body'];
                }
            }
            Expect::false(
                str_contains($body, 'BUSINESS ASSOCIATE AGREEMENT'),
                'the email must not carry the document itself'
            );
        },

    'signing and countersigning executes the document and moves the engagement' =>
        static function (Bootstrap $app, Database $db) use (
            $answers, $preferences, $boot, $confirmed, $signTokenFrom, $clientSigns, $owner
        ): void {
            [$app, $sent] = $boot($db);
            $engagement = $confirmed($app, $sent, $answers, $preferences);

            $document = $app->documentService()->generate($engagement, DocumentKind::BAA, null);
            $app->documentService()->send($document, $engagement, null);
            $document = $app->documents()->find((string) $document['id']);

            $signed = $clientSigns($app, $document, $engagement, $signTokenFrom($sent));
            Expect::same(
                DocumentStatus::CLIENT_SIGNED,
                (string) $signed['status'],
                'the practice signing should leave it waiting on her'
            );

            $executed = $app->documentService()->countersign($signed, $engagement, [
                'typed_name'  => 'Nana Frimpongmaa',
                'typed_title' => 'Owner',
                'consent'     => true,
            ], $owner($app));

            Expect::same(DocumentStatus::EXECUTED, (string) $executed['status'], 'it should be executed');
            Expect::notNull($executed['executed_sha256'], 'it should carry an executed hash');
            Expect::notNull($executed['executed_at'], 'it should carry an executed stamp');

            $after = $app->engagements()->find((string) $engagement['id']);
            Expect::same(Stage::BAA_EXECUTED, (string) $after['stage'], 'the engagement should have moved on');

            $signatures = $app->signatures()->forDocument((string) $executed['id']);
            Expect::same(2, count($signatures), 'there should be two signatures');
            foreach ($signatures as $signature) {
                Expect::same(
                    (string) $executed['content_sha256'],
                    (string) $signature['document_sha256'],
                    'every signature must reference the exact document hash'
                );
            }
        },

    'the executed record reopens and matches the hashes it was stored with' =>
        static function (Bootstrap $app, Database $db) use (
            $answers, $preferences, $boot, $confirmed, $signTokenFrom, $clientSigns, $owner
        ): void {
            [$app, $sent] = $boot($db);
            $engagement = $confirmed($app, $sent, $answers, $preferences);

            $document = $app->documentService()->generate($engagement, DocumentKind::BAA, null);
            $app->documentService()->send($document, $engagement, null);
            $document = $app->documents()->find((string) $document['id']);
            $signed = $clientSigns($app, $document, $engagement, $signTokenFrom($sent));

            $executed = $app->documentService()->countersign($signed, $engagement, [
                'typed_name'  => 'Nana Frimpongmaa',
                'typed_title' => 'Owner',
                'consent'     => true,
            ], $owner($app));

            $check = $app->documentService()->verify($executed);
            Expect::true($check['body']['found'], 'the body should still be in the vault');
            Expect::true($check['body']['matches'], 'the body should match its stored hash');
            Expect::notNull($check['executed'], 'there should be an executed record to check');
            Expect::true($check['executed']['found'], 'the executed record should be in the vault');
            Expect::true($check['executed']['matches'], 'the executed record should match its stored hash');

            $record = (string) $app->documentService()->executedRecord($executed);
            foreach ([
                'Audit certificate',
                (string) $executed['content_sha256'],
                (string) $executed['public_ref'],
                'Dana Owusu',
                'Nana Frimpongmaa',
            ] as $needle) {
                Expect::true(
                    str_contains($record, $needle),
                    'the executed record should carry "' . $needle . '"'
                );
            }
        },

    'a document cannot be executed before it is countersigned' =>
        static function (Bootstrap $app, Database $db) use (
            $answers, $preferences, $boot, $confirmed, $signTokenFrom, $clientSigns, $owner
        ): void {
            [$app, $sent] = $boot($db);
            $engagement = $confirmed($app, $sent, $answers, $preferences);

            $document = $app->documentService()->generate($engagement, DocumentKind::BAA, null);
            $app->documentService()->send($document, $engagement, null);
            $document = $app->documents()->find((string) $document['id']);
            $signed = $clientSigns($app, $document, $engagement, $signTokenFrom($sent));

            Expect::throws(
                RuntimeException::class,
                static fn () => $app->documentService()->execute((string) $signed['id'], $engagement, null),
                'a two-party agreement should not execute on one signature'
            );

            $still = $app->documents()->find((string) $signed['id']);
            Expect::same(DocumentStatus::CLIENT_SIGNED, (string) $still['status'], 'it should be untouched');
        },

    'a correction voids the old version, keeps it whole, and creates version 2' =>
        static function (Bootstrap $app, Database $db) use ($answers, $preferences, $boot, $confirmed): void {
            [$app, $sent, $vault] = $boot($db);
            $engagement = $confirmed($app, $sent, $answers, $preferences);

            $first = $app->documentService()->generate($engagement, DocumentKind::BAA, null);
            $firstBody = (string) $app->documentService()->body($first);
            $firstHash = (string) $first['content_sha256'];

            $second = $app->documentService()->correct(
                $first,
                $engagement,
                'The signer changed before it went out',
                null
            );

            Expect::same(2, (int) $second['version'], 'the replacement should be version 2');
            Expect::same(DocumentStatus::DRAFT, (string) $second['status'], 'the replacement should be a draft');

            $old = $app->documents()->find((string) $first['id']);
            Expect::same(DocumentStatus::VOID, (string) $old['status'], 'the old version should be void');
            Expect::same(
                'The signer changed before it went out',
                (string) $old['void_reason'],
                'the void should carry its reason'
            );
            Expect::same(
                (string) $second['id'],
                (string) $old['superseded_by'],
                'the old version should point at what replaced it'
            );

            // The whole point: voiding did not edit the document.
            Expect::same($firstHash, (string) $old['content_sha256'], 'the old hash should be untouched');
            Expect::same(
                $firstBody,
                (string) file_get_contents($vault . '/' . (string) $old['private_path']),
                'the old body should still be byte for byte what it was'
            );
        },

    'two versions of one kind cannot share a version number' =>
        static function (Bootstrap $app, Database $db) use ($answers, $preferences, $boot, $confirmed): void {
            [$app, $sent] = $boot($db);
            $engagement = $confirmed($app, $sent, $answers, $preferences);

            $first = $app->documentService()->generate($engagement, DocumentKind::BAA, null);

            /** @var DocumentRepository $documents */
            $documents = $app->documents();

            Expect::throws(
                RuntimeException::class,
                static function () use ($documents, $engagement, $first): void {
                    $documents->insertReserved(
                        [
                            'id'         => \SoftAppeals\Support\Uuid::v4(),
                            'public_ref' => 'SA-DOC-CLASH1',
                            'version'    => (int) $first['version'],
                        ],
                        (string) $engagement['id'],
                        (string) $engagement['organization_id'],
                        DocumentKind::BAA,
                        [
                            'title'            => 'Business Associate Agreement',
                            'template_version' => DocumentTemplates::TEMPLATE_VERSION,
                            'consent_version'  => DocumentTemplates::CONSENT_VERSION,
                            'content_sha256'   => str_repeat('a', 64),
                            'private_path'     => 'agreements/x/y.txt',
                        ]
                    );
                },
                'a second version 1 of the same kind should be refused by the database'
            );
        },

    'the review authorization waits until the BAA is executed' =>
        static function (Bootstrap $app, Database $db) use (
            $answers, $preferences, $boot, $confirmed, $signTokenFrom, $clientSigns, $owner
        ): void {
            [$app, $sent] = $boot($db);
            $engagement = $confirmed($app, $sent, $answers, $preferences);

            $tooEarly = $app->documentService()->canGenerate($engagement, DocumentKind::REVIEW_AUTHORIZATION);
            Expect::false($tooEarly['ok'], 'the review authorization should wait for the BAA');

            $baa = $app->documentService()->generate($engagement, DocumentKind::BAA, null);
            $app->documentService()->send($baa, $engagement, null);
            $baa = $app->documents()->find((string) $baa['id']);
            $signed = $clientSigns($app, $baa, $engagement, $signTokenFrom($sent));
            $app->documentService()->countersign($signed, $engagement, [
                'typed_name'  => 'Nana Frimpongmaa',
                'typed_title' => 'Owner',
                'consent'     => true,
            ], $owner($app));

            $engagement = $app->engagements()->findWithOrganization((string) $engagement['id']);
            $now = $app->documentService()->canGenerate($engagement, DocumentKind::REVIEW_AUTHORIZATION);
            Expect::true($now['ok'], 'once the BAA is executed the review authorization should be ready');

            $review = $app->documentService()->generate($engagement, DocumentKind::REVIEW_AUTHORIZATION, null);
            $body = (string) $app->documentService()->body($review);
            Expect::true(
                str_contains($body, 'The review is complimentary'),
                'the review authorization should say it is complimentary'
            );
            Expect::true(
                str_contains($body, 'Nothing goes to a payer under this document'),
                'the review authorization should refuse payer submission on its own face'
            );
        },

    'the PHI gate stays shut until both agreements are executed' =>
        static function (Bootstrap $app, Database $db) use (
            $answers, $preferences, $boot, $confirmed, $signTokenFrom, $clientSigns, $owner
        ): void {
            [$app, $sent] = $boot($db);
            $engagement = $confirmed($app, $sent, $answers, $preferences);

            Expect::false(
                Stage::phiGatePassed((string) $engagement['stage']),
                'the gate should be shut at preferences confirmed'
            );

            foreach ([DocumentKind::BAA, DocumentKind::REVIEW_AUTHORIZATION] as $kind) {
                $engagement = $app->engagements()->findWithOrganization((string) $engagement['id']);
                $document = $app->documentService()->generate($engagement, $kind, null);
                $app->documentService()->send($document, $engagement, null);
                $document = $app->documents()->find((string) $document['id']);
                $signed = $clientSigns($app, $document, $engagement, $signTokenFrom($sent));
                $app->documentService()->countersign($signed, $engagement, [
                    'typed_name'  => 'Nana Frimpongmaa',
                    'typed_title' => 'Owner',
                    'consent'     => true,
                ], $owner($app));
            }

            $after = $app->engagements()->find((string) $engagement['id']);
            Expect::same(
                Stage::REVIEW_AUTH_EXECUTED,
                (string) $after['stage'],
                'both agreements executed should leave the paperwork complete'
            );
            Expect::false(
                Stage::phiGatePassed((string) $after['stage']),
                'executing the paperwork must not open the route by itself'
            );

            $app->engagementService()->move(
                (string) $engagement['id'],
                Stage::SECURE_INTAKE_READY,
                'The secure route is open',
                'engagement.secure_route_open',
                null
            );

            $open = $app->engagements()->find((string) $engagement['id']);
            Expect::true(
                Stage::phiGatePassed((string) $open['stage']),
                'opening the route is what passes the gate'
            );
        },

    'production signing is refused whatever the config file says' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db, [
                'SA_APP_ENV'        => 'production',
                'SA_E_SIGN_ENABLED' => true,
            ]);

            Expect::false(
                $app->config()->eSignEnabled(),
                'production signing must stay shut while the section 14.5 blockers stand'
            );
            Expect::true(
                \SoftAppeals\Config::productionSigningBlockers() !== [],
                'the blockers should be listed rather than merely implied'
            );
        },

    'off production the signing link comes back, on production it does not' =>
        static function (Bootstrap $app, Database $db) use ($answers, $preferences, $boot, $confirmed): void {
            [$app, $sent] = $boot($db);
            $engagement = $confirmed($app, $sent, $answers, $preferences);
            $document = $app->documentService()->generate($engagement, DocumentKind::BAA, null);
            $staging = $app->documentService()->send($document, $engagement, null);
            Expect::notNull($staging['link'], 'staging needs the link, because it cannot email one');

            // The same workflow, against a production configuration.
            //
            // Production refuses before it reaches the link, which is the
            // stronger answer than hiding it: there is no production path that
            // mints a signing link at all while the section 14.5 blockers
            // stand. A fresh draft is made first, because the one above has
            // already gone out and "only a draft is sent" would be the reason
            // it refused, which would prove the wrong thing.
            Bootstrap::resetInstance();
            [$live, $liveSent] = $boot($db, ['SA_APP_ENV' => 'production']);
            Expect::false($live->config()->eSignEnabled(), 'production signing should be shut');

            $liveEngagement = $live->engagements()->findWithOrganization((string) $engagement['id']);
            $sentDocument = $live->documents()->current((string) $engagement['id'], DocumentKind::BAA);
            $draft = $live->documentService()->correct(
                $sentDocument,
                $liveEngagement,
                'Reissued for the production check',
                null
            );
            Expect::same(DocumentStatus::DRAFT, (string) $draft['status'], 'the replacement is a draft');

            Expect::throws(
                RuntimeException::class,
                static fn () => $live->documentService()->send($draft, $liveEngagement, null),
                'production should refuse to send a signing link at all'
            );
            Expect::same(0, count($liveSent->getArrayCopy()), 'and nothing should have been emailed');
        },

    'an executed agreement is replaced through the void door, not generated over' =>
        static function (Bootstrap $app, Database $db) use (
            $answers, $preferences, $boot, $confirmed, $signTokenFrom, $clientSigns, $owner
        ): void {
            [$app, $sent] = $boot($db);
            $engagement = $confirmed($app, $sent, $answers, $preferences);

            $document = $app->documentService()->generate($engagement, DocumentKind::BAA, null);
            $app->documentService()->send($document, $engagement, null);
            $document = $app->documents()->find((string) $document['id']);
            $signed = $clientSigns($app, $document, $engagement, $signTokenFrom($sent));
            $executed = $app->documentService()->countersign($signed, $engagement, [
                'typed_name'  => 'Nana Frimpongmaa',
                'typed_title' => 'Owner',
                'consent'     => true,
            ], $owner($app));

            // Generating over it would leave version 1 executed and un-voided
            // while version 2 quietly became the current one, so the agreement
            // both parties signed would stop being the one the portal shows.
            $engagement = $app->engagements()->findWithOrganization((string) $engagement['id']);
            $check = $app->documentService()->canGenerate($engagement, DocumentKind::BAA);
            Expect::false($check['ok'], 'a bare generate over an executed agreement should be refused');
            Expect::true(
                str_contains((string) $check['reason'], 'executed'),
                'and the refusal should say why: ' . (string) $check['reason']
            );

            Expect::throws(
                RuntimeException::class,
                static fn () => $app->documentService()->generate($engagement, DocumentKind::BAA, null),
                'and generate() should refuse it too, not only the button'
            );

            // The door that does work voids it with a reason first.
            $replacement = $app->documentService()->correct(
                $executed,
                $engagement,
                'The fee basis was wrong',
                null
            );
            Expect::same(2, (int) $replacement['version'], 'the replacement should be version 2');

            $old = $app->documents()->find((string) $executed['id']);
            Expect::same(DocumentStatus::VOID, (string) $old['status'], 'the executed one should now be void');
            Expect::same(
                'The fee basis was wrong',
                (string) $old['void_reason'],
                'and it should carry the reason'
            );
            Expect::notNull(
                $old['executed_sha256'],
                'voiding must not erase what was executed'
            );
            Expect::same(
                2,
                count($app->signatures()->forDocument((string) $executed['id'])),
                'and both signatures should still be on it'
            );
        },

    'a client sees only executed copies, and only its own' =>
        static function (Bootstrap $app, Database $db) use (
            $answers, $preferences, $boot, $confirmed, $signTokenFrom, $clientSigns, $owner
        ): void {
            [$app, $sent] = $boot($db);
            $engagement = $confirmed($app, $sent, $answers, $preferences);

            $draft = $app->documentService()->generate($engagement, DocumentKind::BAA, null);
            Expect::same(
                0,
                count($app->documents()->forClient((string) $engagement['id'])),
                'a draft is not the practice business'
            );
            Expect::null(
                $app->documentService()->executedRecord($draft),
                'and there is no executed copy of a draft to open'
            );

            $app->documentService()->send($draft, $engagement, null);
            $sentDoc = $app->documents()->find((string) $draft['id']);
            Expect::same(
                1,
                count($app->documents()->forClient((string) $engagement['id'])),
                'once it is out for signature the practice can see it'
            );
            Expect::null(
                $app->documentService()->executedRecord($sentDoc),
                'but there is still no copy to open until it is executed'
            );

            $signed = $clientSigns($app, $sentDoc, $engagement, $signTokenFrom($sent));
            $executed = $app->documentService()->countersign($signed, $engagement, [
                'typed_name'  => 'Nana Frimpongmaa',
                'typed_title' => 'Owner',
                'consent'     => true,
            ], $owner($app));

            $copy = $app->documentService()->executedRecord($executed);
            Expect::notNull($copy, 'now there is a copy to open');
            Expect::true(
                str_contains((string) $copy, 'Audit certificate'),
                'and it carries the audit certificate'
            );
        },

    'the vault refuses a path that tries to leave it' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db);
            $vault = $app->vault();

            foreach (['../escape.txt', '/etc/passwd', 'a/../../b.txt', ''] as $bad) {
                Expect::throws(
                    RuntimeException::class,
                    static fn (): ?string => $vault->read($bad),
                    'the vault should refuse "' . $bad . '"'
                );
            }

            $path = DocumentVault::documentPath('SA-ENG-AAAAAA', 'SA-DOC-AAAAAA', 1);
            Expect::same(
                'agreements/SA-ENG-AAAAAA/SA-DOC-AAAAAA-v1.txt',
                $path,
                'the vault should build the path it says it builds'
            );
            Expect::same(
                'signatures/SA-DOC-AAAAAA-client.json',
                DocumentVault::signaturePath('SA-DOC-AAAAAA', SignatureRepository::PARTY_CLIENT),
                'and the signature path likewise'
            );
        },
];
