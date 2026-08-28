<?php
declare(strict_types=1);

/**
 * The Desk.
 *
 * One route, one authentication guard, one CSRF setup, several views. Section
 * 12.1 says the Desk is /sa-desk.php and section 10.1 says no key in the URL,
 * so everything the command centre does happens behind this one door rather
 * than in a scatter of pages that would each have to repeat the guard and would
 * each be a chance to forget it.
 *
 * Phase 2 lights up the home dashboard with the real pipeline, the inquiry
 * queue, the review drawer, the terms preview, and the importer that brings her
 * existing leads in off the server. Phase 4 adds the Agreements view: generate,
 * send, countersign, correct. Work batches and recoveries are still ahead.
 *
 * ADR-004: session, not a key in the URL.
 * Section 10.1: an unauthorized caller is answered with a 404, not a 403, so
 * the page cannot be discovered by watching status codes.
 */

use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\FitDecision;
use SoftAppeals\Domain\IntakeStatus;
use SoftAppeals\Domain\Permission;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Security\Headers;
use SoftAppeals\Services\LegacyLeadImporter;
use SoftAppeals\Views\Desk;

$app = require __DIR__ . '/src/SoftAppeals/boot.php';

Headers::send();

$session = $app->session();
$session->start();

// Not signed in. Send them to the form rather than 404ing, because this is the
// front door for the one person who is supposed to be here.
if (!$session->isAuthenticated() || $session->kind() !== 'admin') {
    header('Location: /soft-appeals-login.php', true, 303);
    exit;
}

if (!$app->config()->isConfigured()) {
    http_response_code(503);
    header('Retry-After: 600');
    echo \SoftAppeals\Views\NotConfigured::render(
        $app->config()->string('SA_APP_ENV'),
        $app->config()->readiness(),
        !$app->config()->isProduction()
    );
    exit;
}

// Configured, but the database may still refuse. A wrong password and an
// unopened file look identical from a 500, and the difference is the whole
// answer, so it is named here rather than left to a correlation reference.
$probe = \SoftAppeals\Database::probe($app->config());
if (!$probe['ok']) {
    http_response_code(503);
    header('Retry-After: 600');
    echo \SoftAppeals\Views\NotConfigured::render(
        $app->config()->string('SA_APP_ENV'),
        $app->config()->readiness() + ['connects' => false, 'reason' => $probe['reason']],
        !$app->config()->isProduction()
    );
    exit;
}

$app->requireSecrets();

// Bring the schema up if it is behind. No SSH on this account means no command
// line, so the application carries its own migrations. Off in production unless
// SA_AUTO_MIGRATE says otherwise.
$app->prepareDatabase();

$user = $app->auth()->currentUser();
if ($user === null) {
    // The account was deactivated while the session was alive. Read from the
    // database on every request is what makes that take effect on this click.
    $session->destroy();
    header('Location: /soft-appeals-login.php', true, 303);
    exit;
}

$authorization = $app->authorization();

// Every action is checked on the server. This one throws an
// AuthorizationException, which the error handler answers with a 404.
$authorization->require(Permission::DESK_VIEW);

$csrf = $app->csrf();
$clock = $app->clock();
$config = $app->config();
$intakes = $app->intakes();
$engagements = $app->engagements();
$communications = $app->communications();
$userId = (string) $user['id'];

// ---------------------------------------------------------------------------
// Writes. Every one carries a CSRF token bound to its own action name, so a
// token minted for the sign-out button cannot be replayed against a send.
// Every one answers with a redirect, so a reload never repeats it.
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'logout') {
        $csrf->require('logout');
        $app->auth()->logout();
        header('Location: /soft-appeals-login.php', true, 303);
        exit;
    }

    if ($action === 'intake.review') {
        $csrf->require('intake.review');
        $authorization->require(Permission::INTAKE_REVIEW);

        $intakeId = (string) ($_POST['intake'] ?? '');
        $decision = (string) ($_POST['decision'] ?? '');
        $note = trim((string) ($_POST['note'] ?? ''));
        $feeBasis = (string) ($_POST['fee_basis'] ?? EngagementTerms::FEE_NOT_SET);
        $channel = (string) ($_POST['secure_channel'] ?? '');
        $window = trim((string) ($_POST['assessment_window'] ?? ''));

        try {
            $result = $app->intakeService()->review(
                $intakeId,
                $decision,
                $note === '' ? null : mb_substr($note, 0, 4000),
                $userId,
                EngagementTerms::isValidFee($feeBasis) ? $feeBasis : EngagementTerms::FEE_NOT_SET,
                EngagementTerms::isValidChannel($channel) ? $channel : null,
                $window === '' ? null : mb_substr($window, 0, 60)
            );

            if ($decision === FitDecision::ACCEPT && $result['engagement_ref'] !== null) {
                // Straight into the preview. Accepting is not sending, and the
                // next thing she should see is the email she has not sent yet.
                $session->flash('desk_ok', 'Accepted. Read the terms before anything goes out.');
                header('Location: /sa-desk.php?view=terms&e=' . urlencode($result['engagement_ref']), true, 303);
                exit;
            }

            $session->flash(
                'desk_ok',
                'Recorded as ' . IntakeStatus::label($result['status']) . '. Nothing was emailed.'
            );
        } catch (\RuntimeException $e) {
            $session->flash('desk_problem', $e->getMessage());
        }

        header('Location: /sa-desk.php?view=inquiries', true, 303);
        exit;
    }

    if ($action === 'terms.send') {
        $csrf->require('terms.send');
        $authorization->require(Permission::TERMS_SEND);

        $ref = (string) ($_POST['engagement'] ?? '');
        $sequence = (int) ($_POST['send_sequence'] ?? 0);
        $cadence = (string) ($_POST['cadence'] ?? '');

        $engagement = $engagements->findByPublicRef($ref);
        if ($engagement === null) {
            $session->flash('desk_problem', 'That engagement reference is not one of hers.');
            header('Location: /sa-desk.php', true, 303);
            exit;
        }

        try {
            if (EngagementTerms::isValidCadence($cadence)) {
                $engagements->setTerms(
                    (string) $engagement['id'],
                    (string) $engagement['fee_basis'],
                    $engagement['secure_channel_type'] === null
                        ? null
                        : (string) $engagement['secure_channel_type'],
                    $engagement['assessment_window'] === null
                        ? null
                        : (string) $engagement['assessment_window'],
                    $cadence
                );
            }

            // Re-read: setTerms just changed the row, and the preview and the
            // send both work from what is actually stored.
            $joined = $engagements->findWithOrganization((string) $engagement['id']);
            if ($joined === null) {
                throw new \RuntimeException('That engagement is not there any more.');
            }
            $result = $app->termsService()->send($joined, max(0, $sequence), $userId);

            // Staging refuses to email a real practice, so the one-time link
            // exists only inside a message the mail layer declined to send.
            // Without this there is no way to walk the client side at all.
            // TermsService returns null here on production, whatever this page
            // does with it.
            if ($result['link'] !== null) {
                $session->flash('desk_link', (string) $result['link']);
            }

            $session->flash(
                $result['sent'] ? 'desk_ok' : 'desk_problem',
                $result['sent']
                    ? ($result['resent'] ? 'Sent again. The previous link is dead.' : 'Sent.')
                        . ' The link stops working ' . $clock->displayDateTime((string) $result['expires_at']) . '.'
                    : 'Not sent: ' . $result['reason'] . '. The terms are still marked as issued, '
                        . 'and the attempt is on the record.'
            );
        } catch (\RuntimeException $e) {
            $session->flash('desk_problem', $e->getMessage());
        }

        header('Location: /sa-desk.php?view=terms&e=' . urlencode($ref), true, 303);
        exit;
    }

    if ($action === 'intake.dismiss_self') {
        $csrf->require('intake.dismiss_self');
        $authorization->require(Permission::INTAKE_REVIEW);

        // The address comes from the configuration, never from the request, so
        // this button can only ever clear her own inbox and no one else's.
        $cleared = $app->intakeService()->dismissSelfAddressed(
            $config->string('SA_OWNER_EMAIL'),
            $userId
        );

        $session->flash(
            'desk_ok',
            $cleared === 0
                ? 'Nothing was addressed to you, so nothing changed.'
                : $cleared . ' cleared as not a real enquiry. They are off the board and still on the record.'
        );
        header('Location: /sa-desk.php?view=inquiries', true, 303);
        exit;
    }

    if ($action === 'leads.import') {
        $csrf->require('leads.import');
        $authorization->require(Permission::INTAKE_REVIEW);

        // The request names a source, never a folder. The key is looked up in
        // the same short list the page offered, so no POST can point the
        // importer at a directory nobody chose.
        $source = (string) ($_POST['source'] ?? 'self');
        $path = LegacyLeadImporter::pathForSource($source, __DIR__);
        if ($path === null) {
            $session->flash('desk_problem', 'That is not one of the two lead folders.');
            header('Location: /sa-desk.php?view=import', true, 303);
            exit;
        }

        $report = $app->importer($path)->import();
        $session->flash(
            'desk_ok',
            $report['created'] . ' imported, ' . $report['skipped'] . ' already there, '
            . $report['invalid'] . ' unusable. '
            . ($report['reconciled']
                ? 'Source and database agree.'
                : 'Source and database do not agree yet. The counts are below.')
        );
        header('Location: /sa-desk.php?view=import&source=' . urlencode($source), true, 303);
        exit;
    }

    // -----------------------------------------------------------------
    // Phase 4. Documents and signing.
    //
    // Every one of these looks the engagement up by its public reference and
    // then finds the document THROUGH that engagement. A document id straight
    // off a POST would be a way to act on somebody else's agreement by guessing
    // a value, and there is no code path here that accepts one.
    // -----------------------------------------------------------------
    $documentActions = [
        'document.generate',
        'document.send',
        'document.countersign',
        'document.correct',
        'document.void',
        'engagement.open_secure_route',
    ];

    if (in_array($action, $documentActions, true)) {
        $csrf->require($action);

        $ref = (string) ($_POST['engagement'] ?? '');
        $engagement = $engagements->findByPublicRef($ref);
        $joined = $engagement === null
            ? null
            : $engagements->findWithOrganization((string) $engagement['id']);

        if ($joined === null) {
            $session->flash('desk_problem', 'That engagement reference is not one of hers.');
            header('Location: /sa-desk.php?view=documents', true, 303);
            exit;
        }

        $documents = $app->documentService();
        $back = '/sa-desk.php?view=documents&e=' . urlencode($ref);

        /** One version of this engagement's documents, found by reference. */
        $findDocument = static function (string $documentRef) use ($app, $joined): ?array {
            if ($documentRef === '') {
                return null;
            }
            foreach ($app->documents()->forEngagement((string) $joined['id']) as $row) {
                if ((string) $row['public_ref'] === $documentRef) {
                    return $row;
                }
            }
            return null;
        };

        try {
            if ($action === 'document.generate') {
                $authorization->require(Permission::DOCUMENT_GENERATE);
                $kind = (string) ($_POST['kind'] ?? '');
                $document = $documents->generate($joined, $kind, $userId);
                $session->flash(
                    'desk_ok',
                    DocumentKind::label($kind) . ' generated as version '
                    . (int) $document['version'] . '. Read it before it goes anywhere.'
                );
            }

            if ($action === 'document.send') {
                $authorization->require(Permission::DOCUMENT_GENERATE);
                $document = $findDocument((string) ($_POST['document'] ?? ''));
                if ($document === null) {
                    throw new \RuntimeException('That document is not on this engagement.');
                }
                $result = $documents->send($document, $joined, $userId);

                // Staging refuses to email a real practice, so the signing link
                // exists only inside a message the mail layer declined to send.
                // Null on production, gated on the environment, exactly as the
                // terms link is.
                if ($result['link'] !== null) {
                    $session->flash('desk_link', (string) $result['link']);
                }

                $session->flash(
                    $result['sent'] ? 'desk_ok' : 'desk_problem',
                    $result['sent']
                        ? 'Sent for signature. The link stops working '
                            . $clock->displayDateTime((string) $result['expires_at']) . '.'
                        : 'Not emailed: ' . $result['reason'] . '. The document is still marked '
                            . 'as issued and the attempt is on the record.'
                );
            }

            if ($action === 'document.countersign') {
                $authorization->require(Permission::DOCUMENT_COUNTERSIGN);
                $document = $findDocument((string) ($_POST['document'] ?? ''));
                if ($document === null) {
                    throw new \RuntimeException('That document is not on this engagement.');
                }
                $documents->countersign($document, $joined, [
                    'typed_name'  => (string) ($_POST['typed_name'] ?? ''),
                    'typed_title' => trim((string) ($_POST['typed_title'] ?? '')) === ''
                        ? null
                        : mb_substr(trim((string) $_POST['typed_title']), 0, 120),
                    'consent'     => (string) ($_POST['consent'] ?? '') === 'yes',
                ], $userId);
                $session->flash(
                    'desk_ok',
                    'Countersigned and executed. The practice has been told, and its copy is '
                    . 'in the Recovery Room.'
                );
            }

            if ($action === 'document.correct') {
                $authorization->require(Permission::DOCUMENT_GENERATE);
                $document = $findDocument((string) ($_POST['document'] ?? ''));
                if ($document === null) {
                    throw new \RuntimeException('That document is not on this engagement.');
                }
                $replacement = $documents->correct(
                    $document,
                    $joined,
                    (string) ($_POST['reason'] ?? ''),
                    $userId
                );
                $session->flash(
                    'desk_ok',
                    'Version ' . (int) $document['version'] . ' is void and version '
                    . (int) $replacement['version'] . ' has taken its place. The old one is '
                    . 'still on the record, exactly as it was.'
                );
            }

            if ($action === 'document.void') {
                $authorization->require(Permission::DOCUMENT_GENERATE);
                $document = $findDocument((string) ($_POST['document'] ?? ''));
                if ($document === null) {
                    throw new \RuntimeException('That document is not on this engagement.');
                }
                $documents->void($document, $joined, (string) ($_POST['reason'] ?? ''), $userId);
                $session->flash('desk_ok', 'Voided. Nothing was deleted.');
            }

            if ($action === 'engagement.open_secure_route') {
                // The PHI gate, section 6 Gate A. This is the one move that
                // both agreements exist to unlock, so it lives with them: an
                // executed review authorization that opened nothing would be a
                // signature with no effect.
                $authorization->require(Permission::ENGAGEMENT_MANAGE);
                $app->engagementService()->move(
                    (string) $joined['id'],
                    Stage::SECURE_INTAKE_READY,
                    'The secure route is open',
                    'engagement.secure_route_open',
                    $userId
                );
                $session->flash(
                    'desk_ok',
                    'The secure route is open. This practice may now send denials.'
                );
            }
        } catch (\RuntimeException $e) {
            $session->flash('desk_problem', $e->getMessage());
        }

        header('Location: ' . $back, true, 303);
        exit;
    }

    // An action nobody offers. Recorded, then treated as a visit.
    $app->audit()->record('desk.unknown_action', 'denied', 'page', null, ['reason' => 'unknown action']);
    header('Location: /sa-desk.php', true, 303);
    exit;
}

// ---------------------------------------------------------------------------
// Opening one stored document.
//
// The executed record and the document body live in the private vault, which
// the web server denies outright. That is the point, and it is also why this
// exists: without a door the application opens itself, an executed agreement
// and its audit certificate were written, hashed, verified on every read, and
// readable by nobody.
//
// The reference names a document, and the document is looked up THROUGH the
// engagement it belongs to, so a reference alone reaches nothing.
// ---------------------------------------------------------------------------
$open = (string) ($_GET['open'] ?? '');
if ($open !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $ref = (string) ($_GET['e'] ?? '');
    $engagement = $ref === '' ? null : $engagements->findByPublicRef($ref);
    $document = null;
    if ($engagement !== null) {
        foreach ($app->documents()->forEngagement((string) $engagement['id']) as $row) {
            if ((string) $row['public_ref'] === $open) {
                $document = $row;
            }
        }
    }

    if ($document === null) {
        $app->audit()->record('document.open', 'denied', 'document', null, [
            'reason' => 'not a document on that engagement',
        ]);
        http_response_code(404);
        exit('Not here.');
    }

    $which = (string) ($_GET['part'] ?? 'executed');
    $service = $app->documentService();
    $contents = $which === 'body'
        ? $service->body($document)
        : $service->executedRecord($document);

    if ($contents === null) {
        $app->audit()->record('document.open', 'failure', 'document', (string) $document['id'], [
            'reason' => 'nothing stored for that part yet',
        ], (string) $document['organization_id']);
        $session->flash(
            'desk_problem',
            $which === 'body'
                ? 'The document body is not in the vault.'
                : 'There is no executed record yet. One is written when the document is executed.'
        );
        header('Location: /sa-desk.php?view=documents&e=' . urlencode($ref), true, 303);
        exit;
    }

    $app->audit()->record('document.open', 'success', 'document', (string) $document['id'], [
        'document_kind'    => (string) $document['kind'],
        'document_version' => (string) $document['version'],
        'source'           => $which,
    ], (string) $document['organization_id']);

    // Its own headers, not the Desk's. The record is a self-contained page with
    // inline styles and nothing else: no script, no image, no font, no request
    // of any kind. The policy below says exactly that, so even a record written
    // years from now cannot reach the network from inside this tab.
    header('Content-Type: ' . ($which === 'body' ? 'text/plain' : 'text/html') . '; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Disposition: inline; filename="' . $open . ($which === 'body' ? '.txt' : '.html') . '"');
    echo $contents;
    exit;
}

// ---------------------------------------------------------------------------
// Reads.
// ---------------------------------------------------------------------------
$app->audit()->record('desk.view', 'success', 'page', null);

$view = (string) ($_GET['view'] ?? 'home');
$allowedViews = ['home', 'inquiries', 'terms', 'documents', 'import', 'audit'];
if (!in_array($view, $allowedViews, true)) {
    $view = 'home';
}

$canAudit = $authorization->can(Permission::AUDIT_VIEW);
$canReview = $authorization->can(Permission::INTAKE_REVIEW);
$canSendTerms = $authorization->can(Permission::TERMS_SEND);

if ($view === 'audit' && !$canAudit) {
    $view = 'home';
}
if (($view === 'import') && !$canReview) {
    $view = 'home';
}

$pipeline = $engagements->pipeline();
$intakeCounts = $intakes->countsByStatus();

// Everything still waiting on a decision counts as an inquiry too. An intake
// that has not been accepted has no engagement behind it, so the engagement
// pipeline cannot see it, and a pipeline that cannot see the queue is not a
// pipeline.
$openIntakes = $intakes->unresolved();
$pipeline['inquiry'] += count($openIntakes);

$awaitingReview = $intakes->awaitingReview();
$termsReady = $engagements->atStage(Stage::TERMS_READY);

$data = [
    'app'            => $app,
    'clock'          => $clock,
    'config'         => $config,
    'csrf'           => $csrf,
    'user'           => $user,
    'roles'          => $authorization->roles(null),
    'canAudit'       => $canAudit,
    'canReview'      => $canReview,
    'canSendTerms'   => $canSendTerms,
    'view'           => $view,
    'pipeline'       => $pipeline,
    'intakeCounts'   => $intakeCounts,
    'awaitingReview' => $awaitingReview,
    'openIntakes'    => $openIntakes,
    'termsReady'     => $termsReady,
    'ok'             => $session->flash('desk_ok'),
    'problem'        => $session->flash('desk_problem'),
    'stagingLink'    => $session->flash('desk_link'),

    // Off production, a view that fails names the exception. On production it
    // names the section and nothing else. Either way the page is readable
    // rather than blank, which is the whole point.
    'showDetail'     => !$config->isProduction(),
];

if ($view === 'home') {
    $rows = [];
    foreach ($engagements->withOrganizations(true) as $row) {
        $row['last_communication'] = $communications->latestForEngagement((string) $row['id']);
        $rows[] = $row;
    }
    $data['activeEngagements'] = $rows;
    $data['recentIntakes'] = $intakes->recent(10);
    $data['deadlines'] = $engagements->withDecisionDates();
    $data['recentTimeline'] = $app->timeline()->recent(8);
}

if ($view === 'inquiries') {
    $data['inquiries'] = $intakes->recent(50);
    $data['intakeRepository'] = $intakes;
    $data['engagementRepository'] = $engagements;
    $data['ownerEmail'] = strtolower($config->string('SA_OWNER_EMAIL'));
    $data['selfAddressed'] = count($intakes->unresolvedForEmail($data['ownerEmail']));
}

if ($view === 'terms') {
    $ref = (string) ($_GET['e'] ?? '');
    $engagement = $ref === '' ? null : $engagements->findByPublicRef($ref);
    $joined = $engagement === null
        ? null
        : $engagements->findWithOrganization((string) $engagement['id']);
    $data['engagement'] = $joined;
    $data['preview'] = $joined === null ? null : $app->termsService()->preview($joined);
    $data['communications'] = $joined === null
        ? []
        : $communications->forEngagement((string) $joined['id']);
    $data['awaitingTerms'] = $termsReady;

    // What the practice answered on its own form, once it has. Phase 3 put the
    // preferences page in front of them; this is the only place she sees what
    // came back, and without it a confirmation would be a stage change with
    // nothing behind it.
    $data['preferencesSummary'] = $joined === null
        ? []
        : $app->preferencesService()->summary((string) $joined['id']);
    $data['preferencesRow'] = $joined === null
        ? null
        : $app->preferences()->forEngagement((string) $joined['id']);
}

// Phase 4. What is standing still and waiting on her, across every practice.
$awaitingCountersignature = $app->documents()->awaitingCountersignature();
$outForSignature = $app->documents()->outForSignature();
$data['documentsNeedingHer'] = count($awaitingCountersignature);
$data['awaitingCountersignature'] = $awaitingCountersignature;
$data['outForSignature'] = $outForSignature;

if ($view === 'documents') {
    $ref = (string) ($_GET['e'] ?? '');
    $engagement = $ref === '' ? null : $engagements->findByPublicRef($ref);
    $joined = $engagement === null
        ? null
        : $engagements->findWithOrganization((string) $engagement['id']);

    $service = $app->documentService();

    $data['engagement'] = $joined;
    $data['documents'] = $joined === null
        ? []
        : $app->documents()->forEngagement((string) $joined['id']);
    $data['signatures'] = [];
    $data['verifications'] = [];

    if ($joined !== null) {
        foreach ($data['documents'] as $row) {
            $data['signatures'][(string) $row['id']] = $app->signatures()
                ->forDocument((string) $row['id']);

            // Reopened and checked against the hashes on the row, on every
            // read. Section 14.4 asks that the executed record can be proved
            // later; a page that showed it without checking would be showing a
            // claim rather than a fact.
            $data['verifications'][(string) $row['id']] = $service->verify($row);
        }
    }

    // What she can generate next, and why not when she cannot.
    $data['nextKind'] = $joined === null
        ? null
        : DocumentKind::nextForStage((string) $joined['stage']);
    $data['generateChecks'] = [];
    if ($joined !== null) {
        foreach (DocumentKind::live() as $kind) {
            $data['generateChecks'][$kind] = $service->canGenerate($joined, $kind);
        }
    }

    $data['signer'] = $joined === null ? null : $service->signerContact($joined);
    $data['blockers'] = \SoftAppeals\Config::productionSigningBlockers();
    $data['eSignEnabled'] = $config->eSignEnabled();
    $data['canCountersign'] = $authorization->can(Permission::DOCUMENT_COUNTERSIGN);
    $data['canGenerate'] = $authorization->can(Permission::DOCUMENT_GENERATE);

    // Every engagement far enough along to have agreements, for the picker.
    $data['engagementsWithDocuments'] = $engagements->withOrganizations(true);
}

if ($view === 'import') {
    // Every folder this installation can see, each with its own dry run. On
    // staging that is two: its own, which holds nothing, and the live site's
    // one level up, which holds every lead she has ever had.
    $sources = LegacyLeadImporter::sources(__DIR__);
    $chosen = (string) ($_GET['source'] ?? '');
    if (!array_key_exists($chosen, $sources)) {
        // Default to the folder that actually has leads in it.
        $chosen = array_key_exists('parent', $sources) ? 'parent' : 'self';
    }

    $reports = [];
    foreach ($sources as $key => $source) {
        $reports[$key] = $app->importer($source['path'])->inspect();
    }

    $data['sources'] = $sources;
    $data['reports'] = $reports;
    $data['chosen'] = $chosen;
    $data['report'] = $reports[$chosen];
    $data['importerPath'] = $sources[$chosen]['path'];
}

if ($view === 'audit') {
    $data['auditRows'] = $app->audit()->recent(40);
    $data['communicationRows'] = $communications->recent(20);
}

Desk::render('shell', $data, $data['showDetail']);
