<?php
declare(strict_types=1);

/**
 * The Recovery Room. Section 15.
 *
 * One route, and it is also the client sign-in, because the room is where a
 * practice is trying to get to and a separate login URL would be one more thing
 * to lose. No session means the sign-in screen; a session means the room.
 *
 * Phase 3 builds the shell and the overview. The eight other sections in section
 * 15.3 are shown in the rail and marked, because a practice that can see the
 * whole map knows what is coming.
 *
 * The organization is derived from the session and never accepted from the
 * browser. Section 15.1 says that outright, and it is the rule that matters most
 * here: these practices compete with each other in the same state, and an
 * organization id read from a request would be the whole leak.
 */

use SoftAppeals\Domain\ActionRequestKind;
use SoftAppeals\Domain\ApprovalState;
use SoftAppeals\Domain\ClientDecision;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\Permission;
use SoftAppeals\Domain\Role;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Security\AuthorizationException;
use SoftAppeals\Security\CsrfException;
use SoftAppeals\Security\Headers;
use SoftAppeals\Security\RateLimitException;
use SoftAppeals\Views\Client;

$app = require __DIR__ . '/src/SoftAppeals/boot.php';

Headers::send();

$session = $app->session();
$session->start();

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
$app->prepareDatabase();

$config = $app->config();
$clock = $app->clock();
$csrf = $app->csrf();
$access = $app->clientAccess();
$showDetail = !$config->isProduction();

/** The sign-in screen, on the app shell, and stop. */
$signIn = static function (string $step, string $email, ?string $notice, ?string $problem) use ($config, $csrf, $showDetail): void {
    Client::render('shell', [
        'config'       => $config,
        'view'         => 'signin',
        'showDetail'   => $showDetail,
        'organization' => '',
        'pageTitle'    => 'Sign in · Soft Appeals',
        'headerNote'   => 'Recovery Room',

        'csrf'         => $csrf,
        'step'         => $step,
        'email'        => $email,
        'notice'       => $notice,
        // The shell prints this above the card and the sign-in view prints it
        // beside the field it belongs to. One value, shown once, by the view.
        'problem'      => $problem,
    ], $showDetail);
    exit;
};

if (!$config->portalEnabled()) {
    http_response_code(503);
    header('Retry-After: 3600');
    Client::render('shell', [
        'config'       => $config,
        'view'         => 'closed',
        'showDetail'   => $showDetail,
        'organization' => '',
        'problem'      => null,
        'pageTitle'    => 'Soft Appeals',
        'headerNote'   => 'Recovery Room',

        'headline'    => 'Your Recovery Room is not open yet.',
        'explanation' => 'Everything is being handled by email for now, and '
            . 'nothing is waiting on you. Write to softappeals@frimpomaasync.com for '
            . 'where anything stands.',
        'offerSignIn' => false,
    ], $showDetail);
    exit;
}

// ---------------------------------------------------------------------------
// Writes. Each carries a CSRF token bound to its own action, and each answers
// with a redirect or a rendered screen, never with a repeatable POST.
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

    if ($action === 'client.sign_out') {
        $csrf->require('client.sign_out');
        $access->signOut();
        header('Location: /soft-appeals-room.php', true, 303);
        exit;
    }

    if (!$config->clientLoginEnabled()) {
        $signIn(
            'email',
            $email,
            null,
            'Signing in is not switched on yet. Write to softappeals@frimpomaasync.com '
                . 'and you get an answer the same day.'
        );
    }

    if ($action === 'client.code.request') {
        $issued = null;
        try {
            $csrf->require('client.code.request');
            $issued = $access->requestLoginCode($email);
        } catch (CsrfException) {
            $signIn('email', $email, null, 'That form had expired. Try again.');
        } catch (RateLimitException $e) {
            $minutes = max(1, (int) ceil($e->retryAfterSeconds / 60));
            $signIn('email', $email, null, 'Too many requests. Try again in ' . $minutes
                . ' minute' . ($minutes === 1 ? '' : 's') . '.');
        }

        // The same screen whatever happened, including for an address nobody
        // has ever invited. A form that says "no such account" is a way to find
        // out which practices she works with.
        //
        // Off production the code is printed here, once, because staging will
        // not email a real practice and the room could otherwise never be
        // entered a second time. ClientAccessService returns null for it on
        // production whatever this page does with it.
        $shown = $issued !== null && $issued['code'] !== null
            ? 'This environment will not email a real practice, so the code is shown here '
                . 'instead and is not shown again: ' . $issued['code']
            : null;
        $signIn('code', $email, $shown, null);
    }

    if ($action === 'client.code.verify') {
        try {
            $csrf->require('client.code.verify');
            $verified = $access->verifyLoginCode($email, (string) ($_POST['code'] ?? ''));
        } catch (CsrfException) {
            $signIn('code', $email, null, 'That form had expired. Ask for a new code.');
        } catch (RateLimitException $e) {
            $minutes = max(1, (int) ceil($e->retryAfterSeconds / 60));
            $signIn('code', $email, null, 'Too many attempts. Try again in ' . $minutes
                . ' minute' . ($minutes === 1 ? '' : 's') . '.');
        }

        if ($verified === null) {
            $signIn('code', $email, null, 'That code did not match, or it has run out. '
                . 'Codes last ten minutes and work once.');
        }

        header('Location: /soft-appeals-room.php', true, 303);
        exit;
    }

    // -----------------------------------------------------------------
    // Phase 5. The two things a practice does in the room: confirm the
    // aggregate count, and give the one decision. Both need a session, both
    // are permission-checked against the organization on that session, and
    // neither takes anything at patient level.
    // -----------------------------------------------------------------
    if ($action === 'client.confirm_receipt' || $action === 'client.decide') {
        $context = $access->context();
        if ($context === null || $context['engagement'] === null) {
            header('Location: /soft-appeals-room.php', true, 303);
            exit;
        }
        $engagement = $context['engagement'];
        $organizationId = (string) $context['organization_id'];
        $userId = (string) $context['user']['id'];

        try {
            $csrf->require($action);

            if ($action === 'client.confirm_receipt') {
                $app->authorization()->require(Permission::RECEIPT_CONFIRM, $organizationId);
                $app->assessmentService()->clientConfirmsReceipt($engagement, $context['contact_id'], $userId);
                $session->flash('client_ok', 'Thank you. The count is confirmed on both sides.');
                header('Location: /soft-appeals-room.php?section=requests', true, 303);
                exit;
            }

            $app->authorization()->require(Permission::DECISION_RECORD, $organizationId);
            $decision = (string) ($_POST['decision'] ?? '');
            if (!ClientDecision::isValid($decision)) {
                throw new \RuntimeException('Choose one of the four.');
            }
            if (ClientDecision::closes($decision) && (string) ($_POST['confirm_close'] ?? '') !== 'yes') {
                throw new \RuntimeException(
                    'That choice closes this engagement. Tick the box to say you mean it.'
                );
            }
            $app->assessmentService()->decide(
                $engagement,
                $decision,
                (string) ($_POST['note'] ?? ''),
                $context['contact_id'],
                $userId
            );
            $session->flash('client_ok', match ($decision) {
                ClientDecision::RECOVERY_SCOPE    => 'Recorded. We are preparing the recovery agreement, and nothing is submitted anywhere until you have signed it.',
                ClientDecision::MORE_INFORMATION  => 'Sent. We answer here in the room, and the decision comes back to you after that.',
                ClientDecision::INTERNAL_USE      => 'Recorded. The assessment is yours to use, and this engagement is closed with nothing owed.',
                default                           => 'Recorded. This engagement is closed with nothing owed.',
            });
        } catch (CsrfException) {
            $session->flash('client_problem', 'That form had expired. Try again.');
        } catch (AuthorizationException) {
            $session->flash('client_problem', 'Your sign-in cannot do that. The organization admin or the authorized signer can.');
        } catch (RateLimitException) {
            $session->flash('client_problem', 'Too many attempts. Try again in a minute.');
        } catch (\RuntimeException $e) {
            $session->flash('client_problem', $e->getMessage());
        }

        header('Location: /soft-appeals-room.php?section=assessment', true, 303);
        exit;
    }

    // -----------------------------------------------------------------
    // Phase 6. Gate C, decided by the practice. The request is found
    // THROUGH the session's engagement, the permission is checked against
    // the session's organization, and the same click twice is one decision.
    // -----------------------------------------------------------------
    if ($action === 'client.approval_decide') {
        $context = $access->context();
        if ($context === null || $context['engagement'] === null) {
            header('Location: /soft-appeals-room.php', true, 303);
            exit;
        }
        $engagement = $context['engagement'];
        $organizationId = (string) $context['organization_id'];

        try {
            $csrf->require('client.approval_decide');
            $app->authorization()->require(Permission::APPROVAL_DECIDE, $organizationId);

            $request = $app->approvalRequests()->findForEngagement(
                (string) ($_POST['approval'] ?? ''),
                (string) $engagement['id']
            );
            if ($request === null) {
                throw new \RuntimeException('That approval request is not one of yours.');
            }
            $state = (string) ($_POST['decision'] ?? '');
            if (!ApprovalState::isDecision($state)) {
                throw new \RuntimeException('Choose approve or return.');
            }
            if ($state === ApprovalState::APPROVED && (string) ($_POST['reviewed'] ?? '') !== 'yes') {
                throw new \RuntimeException(
                    'Tick the box to say you reviewed the materials in the secure route.'
                );
            }
            $result = $app->recoveryService()->decide($engagement, $request, $state, (string) ($_POST['note'] ?? ''), [
                'organization_id' => $organizationId,
                'user_id'         => (string) $context['user']['id'],
                'contact_id'      => $context['contact_id'],
            ]);
            $session->flash('client_ok', $result['already']
                ? 'That was already recorded. Nothing was recorded twice.'
                : ($state === ApprovalState::APPROVED
                    ? 'Approved. We submit it to the payer and you see the status here.'
                    : 'Returned with your note. We revise it and ask again.'));
        } catch (CsrfException) {
            $session->flash('client_problem', 'That form had expired. Try again.');
        } catch (AuthorizationException) {
            $session->flash('client_problem', 'Your sign-in cannot decide this. The organization admin or the named submission approver can.');
        } catch (RateLimitException) {
            $session->flash('client_problem', 'Too many attempts. Try again in a minute.');
        } catch (\RuntimeException $e) {
            $session->flash('client_problem', $e->getMessage());
        }

        header('Location: /soft-appeals-room.php?section=approvals', true, 303);
        exit;
    }

    // An action nobody offers. Recorded, then treated as a visit.
    $app->audit()->record('client.unknown_action', 'denied', 'page', null, ['reason' => 'unknown action']);
    header('Location: /soft-appeals-room.php', true, 303);
    exit;
}

// ---------------------------------------------------------------------------
// Reads.
// ---------------------------------------------------------------------------
$context = $access->context();
if ($context === null) {
    $signIn('email', '', null, null);
}

$engagement = $context['engagement'];
$organizationId = (string) $context['organization_id'];

try {
    $app->authorization()->require(Permission::ROOM_VIEW, $organizationId);
} catch (AuthorizationException) {
    // A session that is real but holds no role in this organization. Signed out
    // rather than shown an empty room, because the room it would be shown is
    // not one it is entitled to.
    $access->signOut();
    $signIn('email', '', null, 'That sign-in is no longer active. Ask for a new code.');
}

if ($engagement === null) {
    Client::render('shell', [
        'config'       => $config,
        'view'         => 'closed',
        'showDetail'   => $showDetail,
        'organization' => '',
        'problem'      => null,
        'pageTitle'    => 'Your Recovery Room · Soft Appeals',
        'headerNote'   => 'Recovery Room',

        'headline'    => 'There is nothing open here yet.',
        'explanation' => 'You are signed in, but no engagement is running against '
            . 'this organization. Write to softappeals@frimpomaasync.com and it is sorted '
            . 'the same day.',
        'offerSignIn' => false,
    ], $showDetail);
    exit;
}

// ---------------------------------------------------------------------------
// Their own signed copy, out of the vault.
//
// The room tells a practice their agreements stay here rather than in an
// inbox, which is only true if they can actually open one. The reference is
// matched against the documents on THIS session's engagement, so a reference
// belonging to another practice finds nothing, and only an executed record is
// served: a draft is not theirs to read and an unsigned document is not a copy
// of anything.
// ---------------------------------------------------------------------------
$wanted = (string) ($_GET['document'] ?? '');
if ($wanted !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $found = null;
    foreach ($app->documents()->forClient((string) $engagement['id']) as $row) {
        if ((string) $row['public_ref'] === $wanted
            && (string) $row['organization_id'] === $organizationId
        ) {
            $found = $row;
        }
    }

    $record = $found === null ? null : $app->documentService()->executedRecord($found);
    if ($record === null) {
        $app->audit()->record('document.open', 'denied', 'document', null, [
            'reason' => 'no executed copy for that reference on this engagement',
        ], $organizationId);
        $session->flash(
            'client_problem',
            'That copy is not ready yet. A signed copy appears here once both of us have signed.'
        );
        header('Location: /soft-appeals-room.php', true, 303);
        exit;
    }

    $app->audit()->record('document.open', 'success', 'document', (string) $found['id'], [
        'document_kind'    => (string) $found['kind'],
        'document_version' => (string) $found['version'],
        'source'           => 'client',
    ], $organizationId);

    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Disposition: inline; filename="' . $wanted . '.html"');
    echo $record;
    exit;
}

$engagementId = (string) $engagement['id'];
$stage = (string) $engagement['stage'];
$organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');

$app->audit()->record('room.view', 'success', 'engagement', $engagementId, [], $organizationId);

$roleLabels = array_map(
    static fn (string $role): string => Role::label($role),
    $context['roles']
);

$preferencesOpen = !$app->preferences()->isConfirmed($engagementId)
    && $app->authorization()->can(Permission::PREFERENCES_CONFIRM, $organizationId);

// Phase 4. The document portal, section 15.3.
//
// Drafts are left out: a draft is a document she is still preparing, and a
// practice watching one appear and change would be watching the drafting rather
// than reading an agreement.
//
// The one that is signable is derived from the session's own contact, so a
// second person at the same practice sees the agreement listed and is not
// offered the button. Holding the role is not the same as being the person this
// document names.
$documents = $app->documents()->forClient($engagementId);

// Anything they have signed that is not executed yet. While one exists, the
// next move is hers and the room says so rather than repeating the stage.
$awaitingHer = null;
foreach ($documents as $row) {
    if (in_array((string) $row['status'], [
        DocumentStatus::CLIENT_SIGNED,
        DocumentStatus::COUNTERSIGNED,
    ], true)) {
        $awaitingHer = $row;
    }
}
$signable = $config->eSignEnabled()
    ? $app->signingService()->pending([
        'organization_id' => $organizationId,
        'engagement'      => $engagement,
        'contact_id'      => $context['contact_id'],
    ])
    : null;

// Phase 5. Which section of the room, section 15.3. Anything unknown is the
// overview, never a 404: a practice following an old link lands somewhere.
$section = (string) ($_GET['section'] ?? 'overview');
if (!in_array($section, ['overview', 'assessment', 'batches', 'requests', 'approvals', 'recovery', 'closeout'], true)) {
    $section = 'overview';
}

// Phase 7. Their own issued invoice, out of the vault. Section 8.2: billing
// or finance, and the organization admin, see invoices; nobody else does.
// Found THROUGH this session's engagement, and only once issued.
$wantedInvoice = (string) ($_GET['invoice'] ?? '');
if ($wantedInvoice !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $allowed = $app->authorization()->can(Permission::FINANCE_VIEW, $organizationId);
    $invoice = $allowed
        ? $app->invoices()->findForEngagement($wantedInvoice, (string) $engagement['id'])
        : null;
    $text = $invoice === null || (string) $invoice['status'] === \SoftAppeals\Domain\InvoiceStatus::DRAFT
        ? null
        : $app->reconciliationService()->invoiceText($invoice);
    if ($text === null) {
        $app->audit()->record('invoice.open', 'denied', 'invoice', null, [
            'reason' => $allowed ? 'no issued invoice with that reference on this engagement' : 'no finance role',
        ], $organizationId);
        $session->flash('client_problem', $allowed
            ? 'That invoice is not ready to read yet.'
            : 'Your sign-in cannot read invoices. The organization admin or the billing contact can.');
        header('Location: /soft-appeals-room.php?section=recovery', true, 303);
        exit;
    }
    $app->audit()->record('invoice.open', 'success', 'invoice', (string) $invoice['id'], [
        'source' => 'client',
    ], $organizationId);
    header('Content-Type: text/plain; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; base-uri 'none'; form-action 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Disposition: inline; filename="' . $wantedInvoice . '.txt"');
    echo $text;
    exit;
}

$assessmentService = $app->assessmentService();

// Opening the assessment is the read that moves "delivered" to "decision
// pending". Any member of the practice opening it counts: the room is theirs.
if ($section === 'assessment' && $stage === Stage::ASSESSMENT_DELIVERED) {
    $assessmentService->markRead($engagement, (string) $context['user']['id']);
    $engagement = $app->engagements()->findWithOrganization($engagementId) ?? $engagement;
    $stage = (string) $engagement['stage'];
}

$overview = $assessmentService->overview($engagement);
// Phase 6. The board carries each batch with its newest approval, so a
// batch the practice has approved reads "approved, submission next" rather
// than asking them to approve it again.
$recovery = $app->recoveryService();
$board = $recovery->board($engagement);
$batchCards = array_map(static fn (array $row): array => $row['card'], $board);
$approvalRows = $recovery->approvals($engagementId);
$pendingApprovals = array_values(array_filter(
    $approvalRows,
    static fn (array $r): bool => (string) $r['state'] === ApprovalState::PENDING
));
$decidedApprovals = array_values(array_filter(
    $approvalRows,
    static fn (array $r): bool => (string) $r['state'] !== ApprovalState::PENDING
));
$canApprove = $app->authorization()->can(Permission::APPROVAL_DECIDE, $organizationId);
$clientRequests = $assessmentService->requestsFor($engagementId, ActionRequestKind::OWNER_CLIENT);
$answered = array_values(array_filter(
    $assessmentService->requestsFor($engagementId, ActionRequestKind::OWNER_SOFT_APPEALS),
    static fn (array $r): bool => $r['response'] !== null
));
$canDecide = $app->authorization()->can(Permission::DECISION_RECORD, $organizationId);
$canConfirmReceipt = $app->authorization()->can(Permission::RECEIPT_CONFIRM, $organizationId);
$openClientRequests = count(array_filter(
    $clientRequests,
    static fn (array $r): bool => (string) $r['status'] === ActionRequestKind::STATUS_OPEN
));

Client::render('room-shell', [
    'config'       => $config,
    'clock'        => $clock,
    'csrf'         => $csrf,
    'view'         => 'room-' . $section,
    'section'      => $section,
    'showDetail'   => $showDetail,
    'organization' => $organization,
    'engagement'   => $engagement,
    'stageLabel'   => $awaitingHer === null
        ? Stage::clientLabel($stage)
        : 'Signed by you, with us to finish',
    'nextLine'     => 'Your denial-recovery work, in one place. See what was reviewed, '
        . 'what needs your attention, what is waiting on a payer, and what has been recovered.',
    'email'        => (string) $context['email'],
    'roleLabels'   => $roleLabels,
    'ok'           => $session->flash('client_ok'),
    'problem'      => $session->flash('client_problem'),

    // Flat, for the same reason every other render on this page is flat: the
    // shell and the view inside it read one array.
    'timeline'        => $app->timeline()->forEngagement($engagementId),
    'chosen'          => $app->preferencesService()->summary($engagementId, true),
    // The stage does not move when a practice signs. It moves when the
    // agreement is EXECUTED, which needs her countersignature, so between the
    // two the stage still reads "waiting for the client signature" and the room
    // was telling a practice to sign a thing they had just signed. Seen on
    // screen. The document they signed is the truer answer for those minutes,
    // so it wins here.
    'nextOwner'       => $awaitingHer === null ? Stage::clientNextOwner($stage) : 'Us',
    'nextAction'      => $awaitingHer === null
        ? Stage::clientNextAction($stage)
        : 'You have signed. We are countersigning it now.',
    'preferencesOpen' => $preferencesOpen,
    'documents'       => $documents,
    'signable'        => $signable,
    'documentCount'   => count($documents),

    // Phase 5.
    'overview'          => $overview,
    'batchCards'        => $batchCards,
    'clientRequests'    => $clientRequests,
    'answered'          => $answered,
    'openRequestCount'  => $openClientRequests,
    'canDecide'         => $canDecide,
    'canConfirmReceipt' => $canConfirmReceipt,
    'stage'             => $stage,

    // Phase 6.
    'board'             => $board,
    'pendingApprovals'  => $pendingApprovals,
    'decidedApprovals'  => $decidedApprovals,
    'pendingApprovalCount' => count($pendingApprovals),
    'canApprove'        => $canApprove,
    'submissions'       => $recovery->submissions($engagementId),
    'feeBlock'          => $recovery->feeBlock($engagement),
    'agreementStatus'   => $recovery->agreementStatus($engagement),
    'scope'             => $recovery->scope($engagement),

    // Phase 7. Invoices for the people section 8.2 lets read them, the
    // closeout as section 15.10 shows it, and whether the rail lists it.
    'canViewFinance'    => $app->authorization()->can(Permission::FINANCE_VIEW, $organizationId),
    'canViewCompliance' => $app->authorization()->can(Permission::COMPLIANCE_VIEW, $organizationId),
    'invoices'          => $app->invoices()->forClient($engagementId),
    'ledger'            => $app->recoveries()->forEngagement($engagementId),
    'closeoutSummary'   => $app->closeoutService()->summary($engagement),
    'inCloseout'        => in_array($stage, [
        Stage::RECONCILIATION, Stage::FINAL_REPORT, Stage::ACCESS_REVIEW,
        Stage::DATA_DISPOSITION, Stage::CLOSED, Stage::CLOSED_NO_RECOVERY,
    ], true),
], $showDetail);
