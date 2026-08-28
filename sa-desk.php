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
 * existing leads in off the server. Everything past that is still ahead:
 * documents, work batches, recoveries, the Recovery Room.
 *
 * ADR-004: session, not a key in the URL.
 * Section 10.1: an unauthorized caller is answered with a 404, not a 403, so
 * the page cannot be discovered by watching status codes.
 */

use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\FitDecision;
use SoftAppeals\Domain\IntakeStatus;
use SoftAppeals\Domain\Permission;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Security\Headers;
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

    if ($action === 'leads.import') {
        $csrf->require('leads.import');
        $authorization->require(Permission::INTAKE_REVIEW);

        $report = $app->importer()->import();
        $session->flash(
            'desk_ok',
            $report['created'] . ' imported, ' . $report['skipped'] . ' already there, '
            . $report['invalid'] . ' unusable. '
            . ($report['reconciled']
                ? 'Source and database agree.'
                : 'Source and database do not agree yet. The counts are below.')
        );
        header('Location: /sa-desk.php?view=import', true, 303);
        exit;
    }

    // An action nobody offers. Recorded, then treated as a visit.
    $app->audit()->record('desk.unknown_action', 'denied', 'page', null, ['reason' => 'unknown action']);
    header('Location: /sa-desk.php', true, 303);
    exit;
}

// ---------------------------------------------------------------------------
// Reads.
// ---------------------------------------------------------------------------
$app->audit()->record('desk.view', 'success', 'page', null);

$view = (string) ($_GET['view'] ?? 'home');
$allowedViews = ['home', 'inquiries', 'terms', 'import', 'audit'];
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
}

if ($view === 'import') {
    $data['report'] = $app->importer()->inspect();
    $data['importerPath'] = $app->importer()->metricsPath();
}

if ($view === 'audit') {
    $data['auditRows'] = $app->audit()->recent(40);
    $data['communicationRows'] = $communications->recent(20);
}

Desk::render('shell', $data, $data['showDetail']);
