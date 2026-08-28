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
        try {
            $csrf->require('client.code.request');
            $access->requestLoginCode($email);
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
        $signIn('code', $email, null, null);
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

Client::render('room-shell', [
    'config'       => $config,
    'clock'        => $clock,
    'csrf'         => $csrf,
    'view'         => 'room-overview',
    'showDetail'   => $showDetail,
    'organization' => $organization,
    'engagement'   => $engagement,
    'stageLabel'   => Stage::clientLabel($stage),
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
    'nextOwner'       => Stage::clientNextOwner($stage),
    'nextAction'      => Stage::clientNextAction($stage),
    'preferencesOpen' => $preferencesOpen,
], $showDetail);
