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

use SoftAppeals\Domain\ActionRequestKind;
use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\FitDecision;
use SoftAppeals\Domain\IntakeStatus;
use SoftAppeals\Domain\Permission;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\SettingsRepository;
use SoftAppeals\Security\Headers;
use SoftAppeals\Support\Money;
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
        'document.generate_recovery',
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

            if ($action === 'document.generate_recovery') {
                // Gate B, section 6. Both documents, from the recorded scope,
                // in one transaction. Drafts until sent.
                $authorization->require(Permission::DOCUMENT_GENERATE);
                $pair = $documents->generateRecoveryPair($joined, $userId);
                $session->flash(
                    'desk_ok',
                    'Generated: the Recovery Services Agreement as version '
                    . (int) $pair['agreement']['version'] . ' and the Approved Recovery Scope as version '
                    . (int) $pair['scope']['version'] . '. Read both before they go anywhere.'
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

    // -----------------------------------------------------------------
    // Phase 5. The assessment, work batches, action requests, settings.
    //
    // The same rule as the document actions: the engagement is looked up by
    // its public reference, and a batch or a request is found THROUGH it.
    // -----------------------------------------------------------------
    if ($action === 'settings.save') {
        $csrf->require('settings.save');
        $authorization->require(Permission::CONFIG_MANAGE);

        $saved = [];
        foreach (SettingsRepository::keys() as $key) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $value = trim((string) $_POST[$key]);
            $app->settings()->set($key, $value, $userId);
            $app->audit()->record('settings.update', 'success', 'setting', $key, [
                'setting' => $key,
                'reason'  => $value === '' ? 'cleared' : 'set',
            ]);
            $saved[] = $key;
        }
        $session->flash(
            'desk_ok',
            $saved === []
                ? 'Nothing changed.'
                : 'Saved. Every agreement generated from now on carries these names. Documents already generated are untouched.'
        );
        header('Location: /sa-desk.php?view=settings', true, 303);
        exit;
    }

    $assessmentActions = [
        'assessment.confirm_receipt',
        'assessment.start',
        'assessment.quality_review',
        'assessment.return',
        'assessment.deliver',
        'assessment.answer',
        'batch.open',
        'batch.update',
        'request.open',
        'request.complete',
        'request.cancel',
    ];

    if (in_array($action, $assessmentActions, true)) {
        $csrf->require($action);

        $ref = (string) ($_POST['engagement'] ?? '');
        $engagement = $engagements->findByPublicRef($ref);
        $joined = $engagement === null
            ? null
            : $engagements->findWithOrganization((string) $engagement['id']);

        if ($joined === null) {
            $session->flash('desk_problem', 'That engagement reference is not one of hers.');
            header('Location: /sa-desk.php?view=assessments', true, 303);
            exit;
        }

        $assessments = $app->assessmentService();
        $batchService = $app->workBatchService();
        $requestService = $app->actionRequestService();
        $back = '/sa-desk.php?view=assessments&e=' . urlencode($ref);

        try {
            if ($action === 'assessment.confirm_receipt') {
                $authorization->require(Permission::ENGAGEMENT_MANAGE);
                $received = trim((string) ($_POST['received_count'] ?? ''));
                if (preg_match('/^\d{1,6}$/', $received) !== 1) {
                    throw new \RuntimeException('The received count has to be a whole number.');
                }
                $expected = trim((string) ($_POST['expected_count'] ?? ''));
                $expectedInt = $expected === '' ? null : (preg_match('/^\d{1,6}$/', $expected) === 1 ? (int) $expected : null);
                if ($expected !== '' && $expectedInt === null) {
                    throw new \RuntimeException('The expected count has to be a whole number.');
                }
                $fields = $batchService->fieldsFromInput($_POST);
                $assessments->confirmReceipt($joined, (int) $received, $expectedInt, $fields, $userId);
                $session->flash(
                    'desk_ok',
                    'Receipt confirmed: ' . (int) $received . ' denials. The first batch is open and the '
                    . 'practice has been asked to confirm the count.'
                );
            }

            if ($action === 'assessment.start') {
                $authorization->require(Permission::ENGAGEMENT_MANAGE);
                $assessments->start($joined, $userId);
                $session->flash('desk_ok', 'Assessment started. Every received batch is now in review.');
            }

            if ($action === 'assessment.quality_review') {
                $authorization->require(Permission::ENGAGEMENT_MANAGE);
                $assessments->sendToQualityReview($joined, $userId);
                $session->flash('desk_ok', 'In quality review. Deliver it from here, or send it back.');
            }

            if ($action === 'assessment.return') {
                $authorization->require(Permission::ENGAGEMENT_MANAGE);
                $assessments->returnToWork($joined, $userId);
                $session->flash('desk_ok', 'Back in progress.');
            }

            if ($action === 'assessment.deliver') {
                $authorization->require(Permission::ENGAGEMENT_MANAGE);
                $count = trim((string) ($_POST['recommended_count'] ?? ''));
                if ($count !== '' && preg_match('/^\d{1,6}$/', $count) !== 1) {
                    throw new \RuntimeException('The recommended count has to be a whole number.');
                }
                $amount = trim((string) ($_POST['recommended_amount'] ?? ''));
                $cents = null;
                if ($amount !== '') {
                    $cents = Money::parseCents($amount);
                    if ($cents === null) {
                        throw new \RuntimeException('The recommended amount has to be a plain dollar figure, like 12,345.67.');
                    }
                }
                $assessments->deliver($joined, [
                    'summary'                  => (string) ($_POST['summary'] ?? ''),
                    'recommended_count'        => $count === '' ? null : (int) $count,
                    'recommended_amount_cents' => $cents,
                    'decision_due'             => (string) ($_POST['decision_due'] ?? ''),
                ], $userId);
                $session->flash(
                    'desk_ok',
                    'Delivered. The practice has been told it is in their Recovery Room, and the '
                    . 'decision is theirs now.'
                );
            }

            if ($action === 'assessment.answer') {
                $authorization->require(Permission::ENGAGEMENT_MANAGE);
                $request = $app->actionRequests()->findForEngagement((string) ($_POST['request'] ?? ''), (string) $joined['id']);
                if ($request === null) {
                    throw new \RuntimeException('That request is not on this engagement.');
                }
                $assessments->answer($joined, $request, (string) ($_POST['response'] ?? ''), $userId);
                $session->flash('desk_ok', 'Answered. The practice can read it in the room and decide.');
            }

            if ($action === 'batch.open') {
                $authorization->require(Permission::WORK_BATCH_MANAGE);
                $fields = $batchService->fieldsFromInput($_POST);
                $batch = $batchService->open($joined, $fields, $userId);
                $session->flash('desk_ok', 'Batch ' . (string) $batch['public_ref'] . ' is open.');
            }

            if ($action === 'batch.update') {
                $authorization->require(Permission::WORK_BATCH_MANAGE);
                $batch = $app->workBatches()->findForEngagement((string) ($_POST['batch'] ?? ''), (string) $joined['id']);
                if ($batch === null) {
                    throw new \RuntimeException('That batch is not on this engagement.');
                }
                $fields = $batchService->fieldsFromInput($_POST);
                $batchService->update($joined, $batch, $fields, $userId);
                $session->flash('desk_ok', 'Batch ' . (string) $batch['public_ref'] . ' updated.');
            }

            if ($action === 'request.open') {
                $authorization->require(Permission::ENGAGEMENT_MANAGE);
                $kind = (string) ($_POST['kind'] ?? '');
                if (!ActionRequestKind::isValid($kind) || ActionRequestKind::owner($kind) !== ActionRequestKind::OWNER_CLIENT) {
                    throw new \RuntimeException('That is not a request the practice can be asked for.');
                }
                $due = trim((string) ($_POST['due'] ?? ''));
                $dueUtc = null;
                if ($due !== '') {
                    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $due, $m) !== 1
                        || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                    ) {
                        throw new \RuntimeException('The due date has to be a date, like 2026-09-30.');
                    }
                    $dueUtc = $due . ' 12:00:00';
                }
                $note = trim((string) ($_POST['note'] ?? ''));
                $requestService->open($joined, $kind, $note === '' ? null : $note, $dueUtc, $userId);
                $session->flash('desk_ok', 'Asked. It is on their board and they have been emailed that something is waiting.');
            }

            if ($action === 'request.complete' || $action === 'request.cancel') {
                $authorization->require(Permission::ENGAGEMENT_MANAGE);
                $request = $app->actionRequests()->findForEngagement((string) ($_POST['request'] ?? ''), (string) $joined['id']);
                if ($request === null) {
                    throw new \RuntimeException('That request is not on this engagement.');
                }
                if ($action === 'request.complete') {
                    $requestService->complete($joined, $request, $userId, null);
                    $session->flash('desk_ok', 'Marked done.');
                } else {
                    $requestService->cancel($joined, $request, $userId);
                    $session->flash('desk_ok', 'Withdrawn. It stays on the record as withdrawn.');
                }
            }
        } catch (\RuntimeException $e) {
            $session->flash('desk_problem', $e->getMessage());
        }

        header('Location: ' . $back, true, 303);
        exit;
    }

    // -----------------------------------------------------------------
    // Phase 6. The recovery scope, the work starting, approvals,
    // submissions and payer responses.
    //
    // The same rule again: the engagement by public reference, and the
    // batch, the approval or the event found THROUGH it.
    // -----------------------------------------------------------------
    $recoveryActions = [
        'recovery.scope_save',
        'recovery.activate',
        'approval.request',
        'approval.cancel',
        'submission.record',
        'payer.response',
        'followup.done',
    ];

    if (in_array($action, $recoveryActions, true)) {
        $csrf->require($action);

        $ref = (string) ($_POST['engagement'] ?? '');
        $engagement = $engagements->findByPublicRef($ref);
        $joined = $engagement === null
            ? null
            : $engagements->findWithOrganization((string) $engagement['id']);

        if ($joined === null) {
            $session->flash('desk_problem', 'That engagement reference is not one of hers.');
            header('Location: /sa-desk.php?view=recovery', true, 303);
            exit;
        }

        $recovery = $app->recoveryService();
        $back = '/sa-desk.php?view=recovery&e=' . urlencode($ref);

        /** One batch on this engagement, by reference. */
        $findBatch = static function (string $batchRef) use ($app, $joined): array {
            $batch = $batchRef === ''
                ? null
                : $app->workBatches()->findForEngagement($batchRef, (string) $joined['id']);
            if ($batch === null) {
                throw new \RuntimeException('That batch is not on this engagement.');
            }
            return $batch;
        };

        try {
            if ($action === 'recovery.scope_save') {
                $authorization->require(Permission::ENGAGEMENT_MANAGE);
                $refs = $_POST['batch_refs'] ?? [];
                $recovery->recordScope($joined, [
                    'fee_basis'        => (string) ($_POST['fee_basis'] ?? ''),
                    'fee_rate'         => (string) ($_POST['fee_rate'] ?? ''),
                    'summary'          => (string) ($_POST['summary'] ?? ''),
                    'batch_refs'       => is_array($refs) ? array_map('strval', $refs) : [],
                    'approver_contact' => (string) ($_POST['approver_contact'] ?? ''),
                    'approver_name'    => (string) ($_POST['approver_name'] ?? ''),
                    'approver_email'   => (string) ($_POST['approver_email'] ?? ''),
                    'approver_role'    => (string) ($_POST['approver_role'] ?? ''),
                ], $userId);
                $session->flash(
                    'desk_ok',
                    'Scope recorded. The Recovery Services Agreement and the Approved Recovery '
                    . 'Scope are generated from it on the Agreements screen. Documents already '
                    . 'generated are unchanged: void and replace them to carry a changed scope.'
                );
            }

            if ($action === 'recovery.activate') {
                $authorization->require(Permission::ENGAGEMENT_MANAGE);
                $recovery->activate($joined, $userId);
                $session->flash(
                    'desk_ok',
                    'Recovery is active. Put each batch in scope up for approval when its '
                    . 'materials are ready in the secure route.'
                );
            }

            if ($action === 'approval.request') {
                $authorization->require(Permission::WORK_BATCH_MANAGE);
                $batch = $findBatch((string) ($_POST['batch'] ?? ''));
                $row = $recovery->requestApproval($joined, $batch, [
                    'safe_summary' => (string) ($_POST['safe_summary'] ?? ''),
                    'claim_count'  => (string) ($_POST['claim_count'] ?? ''),
                    'amount'       => (string) ($_POST['amount'] ?? ''),
                    'due'          => (string) ($_POST['due'] ?? ''),
                ], $userId);
                $session->flash(
                    'desk_ok',
                    'Asked. ' . (string) $row['public_ref'] . ' is with the approver, who has been '
                    . 'emailed that something is waiting. Nothing goes to the payer until they answer.'
                );
            }

            if ($action === 'approval.cancel') {
                $authorization->require(Permission::WORK_BATCH_MANAGE);
                $request = $app->approvalRequests()->findForEngagement((string) ($_POST['approval'] ?? ''), (string) $joined['id']);
                if ($request === null) {
                    throw new \RuntimeException('That approval request is not on this engagement.');
                }
                $recovery->cancelApproval($joined, $request, $userId);
                $session->flash('desk_ok', 'Withdrawn. The batch is back to recommended and the request stays on the record.');
            }

            if ($action === 'submission.record') {
                $authorization->require(Permission::WORK_BATCH_MANAGE);
                $batch = $findBatch((string) ($_POST['batch'] ?? ''));
                $row = $recovery->recordSubmission($joined, $batch, [
                    'claim_count' => (string) ($_POST['claim_count'] ?? ''),
                    'amount'      => (string) ($_POST['amount'] ?? ''),
                    'occurred'    => (string) ($_POST['occurred'] ?? ''),
                    'follow_up'   => (string) ($_POST['follow_up'] ?? ''),
                    'note'        => (string) ($_POST['note'] ?? ''),
                ], $userId);
                $session->flash(
                    'desk_ok',
                    'Recorded as submitted, ' . (string) $row['public_ref'] . '. The practice has been '
                    . 'told there is a status update. No fee was created; none is until reimbursement is verified.'
                );
            }

            if ($action === 'payer.response') {
                $authorization->require(Permission::WORK_BATCH_MANAGE);
                $batch = $findBatch((string) ($_POST['batch'] ?? ''));
                $row = $recovery->recordPayerResponse($joined, $batch, [
                    'event_type'  => (string) ($_POST['event_type'] ?? ''),
                    'claim_count' => (string) ($_POST['claim_count'] ?? ''),
                    'amount'      => (string) ($_POST['amount'] ?? ''),
                    'occurred'    => (string) ($_POST['occurred'] ?? ''),
                    'follow_up'   => (string) ($_POST['follow_up'] ?? ''),
                    'note'        => (string) ($_POST['note'] ?? ''),
                ], $userId);
                $session->flash(
                    'desk_ok',
                    'Recorded: ' . \SoftAppeals\Domain\SubmissionEventType::staffLabel((string) $row['event_type'])
                    . '. No fee was created; a decision is not a reimbursement.'
                );
            }

            if ($action === 'followup.done') {
                $authorization->require(Permission::WORK_BATCH_MANAGE);
                $event = $app->submissionEvents()->findForEngagement((string) ($_POST['event'] ?? ''), (string) $joined['id']);
                if ($event === null) {
                    throw new \RuntimeException('That follow-up is not on this engagement.');
                }
                $recovery->completeFollowUp($joined, $event, $userId);
                $session->flash('desk_ok', 'Follow-up closed.');
            }
        } catch (\RuntimeException $e) {
            $session->flash('desk_problem', $e->getMessage());
        }

        header('Location: ' . $back, true, 303);
        exit;
    }

    // -----------------------------------------------------------------
    // Phase 7. The money, and the closeout.
    //
    // The same rule a third time: the engagement by public reference, and
    // the batch, the recovery row, the invoice or the access row found
    // THROUGH it. Money is the owner's alone (Permission::RECOVERY_VERIFY),
    // and so is closing (Permission::CLOSEOUT_MANAGE), section 8.1.
    // -----------------------------------------------------------------
    $moneyActions = [
        'recovery.verify',
        'recovery.adjust',
        'invoice.create',
        'invoice.issue',
        'invoice.paid',
        'invoice.void',
    ];
    $closeoutActions = [
        'closeout.begin',
        'closeout.without_recovery',
        'closeout.reconciliation',
        'closeout.final_report',
        'closeout.access_decide',
        'closeout.access_confirm',
        'closeout.disposition',
    ];

    if (in_array($action, $moneyActions, true) || in_array($action, $closeoutActions, true)) {
        $csrf->require($action);

        $isMoney = in_array($action, $moneyActions, true);
        $ref = (string) ($_POST['engagement'] ?? '');
        $engagement = $engagements->findByPublicRef($ref);
        $joined = $engagement === null
            ? null
            : $engagements->findWithOrganization((string) $engagement['id']);

        if ($joined === null) {
            $session->flash('desk_problem', 'That engagement reference is not one of hers.');
            header('Location: /sa-desk.php?view=' . ($isMoney ? 'money' : 'closeout'), true, 303);
            exit;
        }

        $money = $app->reconciliationService();
        $closeout = $app->closeoutService();
        $back = '/sa-desk.php?view=' . ($isMoney ? 'money' : 'closeout') . '&e=' . urlencode($ref);

        try {
            if ($action === 'recovery.verify') {
                $authorization->require(Permission::RECOVERY_VERIFY);
                $batch = $app->workBatches()->findForEngagement((string) ($_POST['batch'] ?? ''), (string) $joined['id']);
                if ($batch === null) {
                    throw new \RuntimeException('That batch is not on this engagement.');
                }
                $row = $money->verify($joined, $batch, [
                    'amount'      => (string) ($_POST['amount'] ?? ''),
                    'source'      => (string) ($_POST['source'] ?? ''),
                    'verified_on' => (string) ($_POST['verified_on'] ?? ''),
                    'qualifies'   => (string) ($_POST['qualifies'] ?? 'yes'),
                    'note'        => (string) ($_POST['note'] ?? ''),
                ], $userId);
                $session->flash(
                    'desk_ok',
                    'Verified: ' . \SoftAppeals\Support\Money::format((int) $row['amount_cents']) . ' on '
                    . (string) $row['public_ref'] . '. The fee on it is '
                    . \SoftAppeals\Support\Money::format((int) $row['fee_cents'])
                    . ', calculated in whole cents at the rate on the agreement, and it is invoice-ready.'
                );
            }

            if ($action === 'recovery.adjust') {
                $authorization->require(Permission::RECOVERY_VERIFY);
                $original = $app->recoveries()->findForEngagement((string) ($_POST['recovery'] ?? ''), (string) $joined['id']);
                if ($original === null) {
                    throw new \RuntimeException('That recovery record is not on this engagement.');
                }
                $row = $money->adjust($joined, $original, [
                    'kind'        => (string) ($_POST['kind'] ?? ''),
                    'amount'      => (string) ($_POST['amount'] ?? ''),
                    'occurred_on' => (string) ($_POST['occurred_on'] ?? ''),
                    'note'        => (string) ($_POST['note'] ?? ''),
                ], $userId);
                $session->flash(
                    'desk_ok',
                    \SoftAppeals\Domain\RecoveryRecord::kindLabel((string) $row['kind']) . ' recorded as '
                    . (string) $row['public_ref'] . ', ' . \SoftAppeals\Support\Money::format((int) $row['amount_cents'])
                    . ' taken back. ' . (string) $original['public_ref'] . ' is untouched; the fee credit of '
                    . \SoftAppeals\Support\Money::format((int) $row['fee_cents']) . ' comes off the next invoice.'
                );
            }

            if ($action === 'invoice.create') {
                $authorization->require(Permission::RECOVERY_VERIFY);
                $invoice = $money->createInvoice($joined, $userId);
                $session->flash(
                    'desk_ok',
                    'Draft invoice ' . (string) $invoice['public_ref'] . ' created for '
                    . \SoftAppeals\Support\Money::format((int) $invoice['total_cents'])
                    . '. Nothing has gone to the practice. Read it, then issue it.'
                );
            }

            if ($action === 'invoice.issue' || $action === 'invoice.paid' || $action === 'invoice.void') {
                $authorization->require(Permission::RECOVERY_VERIFY);
                $invoice = $app->invoices()->findForEngagement((string) ($_POST['invoice'] ?? ''), (string) $joined['id']);
                if ($invoice === null) {
                    throw new \RuntimeException('That invoice is not on this engagement.');
                }
                if ($action === 'invoice.issue') {
                    $issued = $money->issueInvoice($joined, $invoice, ['due_on' => (string) ($_POST['due_on'] ?? '')], $userId);
                    $session->flash(
                        'desk_ok',
                        'Issued: ' . (string) $issued['public_ref'] . ', due '
                        . $clock->displayDate((string) $issued['due_at'])
                        . '. The practice has been told there is an invoice to read in the room. The figure stays in the room.'
                    );
                }
                if ($action === 'invoice.paid') {
                    $money->markPaid($joined, $invoice, [
                        'paid_on' => (string) ($_POST['paid_on'] ?? ''),
                        'note'    => (string) ($_POST['note'] ?? ''),
                    ], $userId);
                    $session->flash('desk_ok', (string) $invoice['public_ref'] . ' is marked paid.');
                }
                if ($action === 'invoice.void') {
                    $money->voidInvoice($joined, $invoice, (string) ($_POST['reason'] ?? ''), $userId);
                    $session->flash('desk_ok', (string) $invoice['public_ref'] . ' is void. Its rows are invoice-ready again; the number is not reused.');
                }
            }

            if ($action === 'closeout.begin') {
                $authorization->require(Permission::CLOSEOUT_MANAGE);
                $closeout->begin($joined, $userId);
                $session->flash(
                    'desk_ok',
                    'Closeout has begun. First step: financial reconciliation. Verify every '
                    . 'overturned batch, invoice every fee, then confirm the money is final.'
                );
            }

            if ($action === 'closeout.without_recovery') {
                $authorization->require(Permission::CLOSEOUT_MANAGE);
                $closeout->closeWithoutRecovery($joined, (string) ($_POST['reason'] ?? ''), $userId);
                $session->flash('desk_ok', 'Closed with no recovery. That is terminal; the record stays.');
                $back = '/sa-desk.php?view=recovery&e=' . urlencode($ref);
            }

            if ($action === 'closeout.reconciliation') {
                $authorization->require(Permission::CLOSEOUT_MANAGE);
                $closeout->confirmReconciliation($joined, (string) ($_POST['note'] ?? ''), $userId);
                $session->flash('desk_ok', 'The money is reconciled and final. Next: the final report.');
            }

            if ($action === 'closeout.final_report') {
                $authorization->require(Permission::CLOSEOUT_MANAGE);
                $closeout->confirmFinalReport($joined, (string) ($_POST['summary'] ?? ''), $userId);
                $session->flash('desk_ok', 'Final report recorded. Next: decide every person\'s access.');
            }

            if ($action === 'closeout.access_decide') {
                $authorization->require(Permission::CLOSEOUT_MANAGE);
                $decision = (string) ($_POST['decision'] ?? '');
                $closeout->decideAccess($joined, (string) ($_POST['row'] ?? ''), $decision, $userId);
                $session->flash(
                    'desk_ok',
                    $decision === \SoftAppeals\Domain\CloseoutStep::ACCESS_REMOVED
                        ? 'Access removed. Their roles at this practice are revoked, any open link is cancelled, and their next request signs them out.'
                        : 'Access retained, on the record.'
                );
            }

            if ($action === 'closeout.access_confirm') {
                $authorization->require(Permission::CLOSEOUT_MANAGE);
                $closeout->confirmAccessReview($joined, (string) ($_POST['note'] ?? ''), $userId);
                $session->flash('desk_ok', 'Access review confirmed. Last step: the data disposition, which closes the engagement.');
            }

            if ($action === 'closeout.disposition') {
                $authorization->require(Permission::CLOSEOUT_MANAGE);
                $record = $closeout->confirmDataDisposition($joined, [
                    'disposition' => (string) ($_POST['disposition'] ?? ''),
                    'note'        => (string) ($_POST['note'] ?? ''),
                ], $userId);
                $session->flash(
                    'desk_ok',
                    'Closed. The closeout record is sealed as ' . (string) $record['public_ref']
                    . ' and the practice has been told it is in the room.'
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
// Opening one issued invoice, out of the vault. Phase 7.
//
// Found THROUGH the engagement, like a document. Only an issued invoice has
// a file; a draft is figures on a row and nothing else.
// ---------------------------------------------------------------------------
$openInvoice = (string) ($_GET['invoice'] ?? '');
if ($openInvoice !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $ref = (string) ($_GET['e'] ?? '');
    $engagement = $ref === '' ? null : $engagements->findByPublicRef($ref);
    $invoice = $engagement === null
        ? null
        : $app->invoices()->findForEngagement($openInvoice, (string) $engagement['id']);
    $text = $invoice === null ? null : $app->reconciliationService()->invoiceText($invoice);

    if ($invoice === null || $text === null) {
        $app->audit()->record('invoice.open', 'denied', 'invoice', null, [
            'reason' => $invoice === null ? 'not an invoice on that engagement' : 'not issued yet',
        ]);
        $session->flash('desk_problem', $invoice === null
            ? 'That invoice is not on that engagement.'
            : 'That invoice is a draft. It is rendered when it is issued.');
        header('Location: /sa-desk.php?view=money&e=' . urlencode($ref), true, 303);
        exit;
    }

    $app->audit()->record('invoice.open', 'success', 'invoice', (string) $invoice['id'], [
        'source' => 'desk',
    ], (string) $invoice['organization_id']);

    header('Content-Type: text/plain; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; base-uri 'none'; form-action 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Disposition: inline; filename="' . $openInvoice . '.txt"');
    echo $text;
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
$allowedViews = ['home', 'inquiries', 'terms', 'documents', 'assessments', 'recovery', 'money', 'closeout', 'import', 'audit', 'settings'];
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
if ($view === 'settings' && !$authorization->can(Permission::CONFIG_MANAGE)) {
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
    $data['batchDeadlines'] = $app->workBatches()->withDeadlines();
    $data['recentTimeline'] = $app->timeline()->recent(8);
}

// Phase 5. What is waiting on her across every assessment, for the cards and
// the rail count. Read on every view so the count is never stale.
$requestsForHer = $app->actionRequests()->openForSoftAppeals();
$data['requestsForHer'] = $requestsForHer;
$data['assessmentsWaiting'] = array_merge(
    $engagements->atStage(Stage::SECURE_INTAKE_READY),
    $engagements->atStage(Stage::RECEIPT_CONFIRMED),
    $engagements->atStage(Stage::ASSESSMENT_IN_PROGRESS),
    $engagements->atStage(Stage::ASSESSMENT_QA)
);
$data['assessmentsNeedingHer'] = count($requestsForHer) + count($data['assessmentsWaiting']);

// Phase 6. What is waiting across every recovery: approvals with a practice,
// approved batches she has not submitted, follow-ups due, and engagements
// sitting at the recovery gates. Read on every view for the rail count.
$data['pendingApprovals'] = $app->approvalRequests()->pendingEverywhere();
$data['awaitingSubmission'] = $app->approvalRequests()->approvedAwaitingSubmission();
$data['followUps'] = $app->submissionEvents()->openFollowUps();
$data['recoveryWaiting'] = array_merge(
    $engagements->atStage(Stage::RECOVERY_SCOPE_SELECTED),
    $engagements->atStage(Stage::RECOVERY_AGREEMENT_EXECUTED)
);
$data['recoveryNeedingHer'] = count($data['awaitingSubmission']) + count($data['recoveryWaiting'])
    + count(array_filter(
        $data['followUps'],
        static fn (array $row): bool => ($clock->daysUntil((string) $row['follow_up_due_at']) ?? 1) <= 0
    ));

// Phase 7. Money waiting on her: overturned batches with nothing verified,
// fees not yet on an invoice, invoices issued and unpaid. And every
// engagement inside closeout. Read on every view for the two rail counts.
$reconciliation = $app->reconciliationService();
$data['awaitingVerification'] = $reconciliation->awaitingVerification();
$data['invoiceReady'] = $reconciliation->invoiceReady();
$data['outstandingInvoices'] = $reconciliation->outstandingInvoices();
$data['moneyNeedingHer'] = count($data['awaitingVerification']) + count($data['invoiceReady']);
$data['closeoutRows'] = $app->closeoutService()->inCloseout();
$data['closeoutOpen'] = array_values(array_filter(
    $data['closeoutRows'],
    static fn (array $row): bool => $row['closeout_closed_at'] === null
));
$data['closeoutNeedingHer'] = count($data['closeoutOpen']);
$data['financeEnabled'] = $config->recoveryFinanceEnabled();
if ($view === 'home') {
    $data['recoverySummary'] = $reconciliation->summaryEverywhere();
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

if ($view === 'assessments') {
    $ref = (string) ($_GET['e'] ?? '');
    $engagement = $ref === '' ? null : $engagements->findByPublicRef($ref);
    $joined = $engagement === null
        ? null
        : $engagements->findWithOrganization((string) $engagement['id']);

    $data['engagement'] = $joined;
    $data['canManage'] = $authorization->can(Permission::ENGAGEMENT_MANAGE);
    $data['canBatches'] = $authorization->can(Permission::WORK_BATCH_MANAGE);

    if ($joined === null) {
        // Every engagement past the gate, and the ones one step short of it.
        $rows = [];
        foreach ($engagements->withOrganizations(false) as $row) {
            $stage = (string) $row['stage'];
            if (Stage::phiGatePassed($stage) || $stage === Stage::REVIEW_AUTH_EXECUTED) {
                $rows[] = $row;
            }
        }
        $data['assessmentRows'] = $rows;
    } else {
        $service = $app->assessmentService();
        $data['overview'] = $service->overview($joined);
        $data['batches'] = $app->workBatches()->forEngagement((string) $joined['id']);
        $data['batchCards'] = array_map(
            static fn (array $b): array => $app->workBatchService()->card($b),
            $data['batches']
        );
        $data['requests'] = $service->requestsFor((string) $joined['id']);
        $data['timeline'] = $app->timeline()->forEngagement((string) $joined['id']);
        $data['signer'] = $app->actionRequestService()->signerContact((string) $joined['id']);
    }
}

if ($view === 'recovery') {
    $ref = (string) ($_GET['e'] ?? '');
    $engagement = $ref === '' ? null : $engagements->findByPublicRef($ref);
    $joined = $engagement === null
        ? null
        : $engagements->findWithOrganization((string) $engagement['id']);

    $data['engagement'] = $joined;
    $data['canManage'] = $authorization->can(Permission::ENGAGEMENT_MANAGE);
    $data['canBatches'] = $authorization->can(Permission::WORK_BATCH_MANAGE);

    if ($joined === null) {
        // Every engagement that has chosen recovery, at whatever point.
        $rows = [];
        foreach ($engagements->withOrganizations(false) as $row) {
            if (in_array((string) $row['stage'], [
                Stage::RECOVERY_SCOPE_SELECTED,
                Stage::RECOVERY_AGREEMENT_PENDING,
                Stage::RECOVERY_AGREEMENT_EXECUTED,
                Stage::RECOVERY_ACTIVE,
                Stage::RECONCILIATION,
                Stage::FINAL_REPORT,
                Stage::ACCESS_REVIEW,
                Stage::DATA_DISPOSITION,
                Stage::CLOSED,
            ], true)) {
                $rows[] = $row;
            }
        }
        $data['recoveryRows'] = $rows;
    } else {
        $recovery = $app->recoveryService();
        $data['scope'] = $recovery->scope($joined);
        $data['preferredApprover'] = $recovery->preferredApprover($joined);
        $data['orgContacts'] = $app->contacts()->forOrganization((string) $joined['organization_id']);
        $data['agreementStatus'] = $recovery->agreementStatus($joined);
        $data['board'] = $recovery->board($joined);
        $data['approvals'] = $recovery->approvals((string) $joined['id']);
        $data['submissions'] = $recovery->submissions((string) $joined['id']);
        $data['feeBlock'] = $recovery->feeBlock($joined);
        $data['timeline'] = $app->timeline()->forEngagement((string) $joined['id']);
        $data['overview'] = $app->assessmentService()->overview($joined);
        $data['generateCheck'] = $app->documentService()->canGenerate($joined, DocumentKind::RECOVERY_AGREEMENT);
        $data['eSignEnabled'] = $config->eSignEnabled();
        $data['canGenerate'] = $authorization->can(Permission::DOCUMENT_GENERATE);
        // Phase 7. Whether closeout can begin from here, and who may.
        $data['canClose'] = $authorization->can(Permission::CLOSEOUT_MANAGE);
        $data['beginCheck'] = $app->closeoutService()->canBegin($joined);
        $data['moneySummary'] = $reconciliation->summary($joined);
    }
}

// Phase 7. The money: one engagement's ledger, its invoices, and the
// verify, adjust and invoice forms. Or, with no engagement, every practice
// with money waiting.
if ($view === 'money') {
    $ref = (string) ($_GET['e'] ?? '');
    $engagement = $ref === '' ? null : $engagements->findByPublicRef($ref);
    $joined = $engagement === null
        ? null
        : $engagements->findWithOrganization((string) $engagement['id']);

    $data['engagement'] = $joined;
    $data['canMoney'] = $authorization->can(Permission::RECOVERY_VERIFY);
    $data['recoverySummary'] = $reconciliation->summaryEverywhere();

    if ($joined === null) {
        $rows = [];
        foreach ($engagements->withOrganizations(false) as $row) {
            if (Stage::phiGatePassed((string) $row['stage'])) {
                $row['money'] = $reconciliation->summary($row);
                $rows[] = $row;
            }
        }
        $data['moneyRows'] = $rows;
    } else {
        $data['moneySummary'] = $reconciliation->summary($joined);
        $data['verifiable'] = $reconciliation->verifiable($joined);
        $data['ledger'] = $reconciliation->ledger($joined);
        $data['invoiceRows'] = $reconciliation->invoices((string) $joined['id']);
        $data['invoiceVerifications'] = [];
        foreach ($data['invoiceRows'] as $invoice) {
            $data['invoiceVerifications'][(string) $invoice['id']] = $reconciliation->verifyInvoice($invoice);
        }
        $data['moneyStageOpen'] = in_array((string) $joined['stage'], [Stage::RECOVERY_ACTIVE, Stage::RECONCILIATION], true);
    }
}

// Phase 7. Closeout: the four steps, the access review, the sealed record.
if ($view === 'closeout') {
    $ref = (string) ($_GET['e'] ?? '');
    $engagement = $ref === '' ? null : $engagements->findByPublicRef($ref);
    $joined = $engagement === null
        ? null
        : $engagements->findWithOrganization((string) $engagement['id']);

    $data['engagement'] = $joined;
    $data['canClose'] = $authorization->can(Permission::CLOSEOUT_MANAGE);

    if ($joined === null) {
        // Every engagement at recovery active is a candidate; every one in a
        // closeout stage is in progress or done.
        $data['closeoutCandidates'] = $engagements->atStage(Stage::RECOVERY_ACTIVE);
    } else {
        $closeoutService = $app->closeoutService();
        $data['closeoutSummary'] = $closeoutService->summary($joined);
        $data['beginCheck'] = $closeoutService->canBegin($joined);
        $data['stepCheck'] = $closeoutService->stepCheck($joined);
        $data['timeline'] = $app->timeline()->forEngagement((string) $joined['id']);
    }
}

if ($view === 'settings') {
    $data['settingsRepository'] = $app->settings();
    $data['legalEntitySource'] = $app->settings()->legalEntitySource($config);
    $data['effectiveLegalEntity'] = $app->settings()->legalEntity($config);
    $data['effectiveTradeName'] = $app->settings()->tradeName($config);
    $data['configLegalEntity'] = trim($config->string('SA_LEGAL_ENTITY'));
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
