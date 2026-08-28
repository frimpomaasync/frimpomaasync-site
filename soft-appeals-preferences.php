<?php
declare(strict_types=1);

/**
 * The preferences page. Section 13.2.
 *
 * This is the first page a practice ever sees inside the application, and it is
 * reached from a link in an email. Three rules follow from that and all three
 * are in this file.
 *
 * The token is exchanged for a session and then leaves the URL. A GET carrying
 * ?t= redeems it and answers with a 303 to the bare path, so the link is used
 * once, the address bar holds nothing, and nothing that carries a token lands in
 * browser history, in a Referer header, or in a screenshot.
 *
 * A dead link is a page, not a 404. She sent that link herself. A practice
 * following it and getting the host's error page would read it as her site being
 * broken, so a used, expired or unknown token all land on one plain screen that
 * says what to do next.
 *
 * The organization comes from the session. Nothing on this page reads an
 * organization or an engagement from the request, because a page that did would
 * let one practice answer for another.
 */

use SoftAppeals\Domain\Permission;
use SoftAppeals\Domain\PreferenceForm;
use SoftAppeals\Repositories\InvitationRepository;
use SoftAppeals\Security\AuthorizationException;
use SoftAppeals\Security\CsrfException;
use SoftAppeals\Security\Headers;
use SoftAppeals\Views\Client;

$app = require __DIR__ . '/src/SoftAppeals/boot.php';

Headers::send();

$session = $app->session();
$session->start();

// A freshly deployed site has no configuration yet, and a database can refuse
// even when it is configured. Both are named rather than left to a 500, for the
// same reason the Desk names them: from outside, the two look identical.
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

/**
 * Render one client screen inside the shell and stop.
 *
 * One flat array reaches both the shell and the view inside it, which is the
 * same arrangement the Desk uses. It has to be flat: Views\Client extracts with
 * EXTR_SKIP, so a key called `data` would be skipped over the parameter already
 * named $data and the inner view would be handed the shell's own variables.
 */
$render = static function (string $view, array $vars, string $title, string $note, string $organization = '', ?string $problem = null) use ($config, $showDetail): void {
    Client::render('shell', array_merge($vars, [
        'config'       => $config,
        'view'         => $view,
        'showDetail'   => $showDetail,
        'organization' => $organization,
        'problem'      => $problem,
        'pageTitle'    => $title,
        'headerNote'   => $note,
    ]), $showDetail);
    exit;
};

/** The one screen a link that no longer works lands on. */
$closed = static function (string $headline, string $explanation) use ($render, $config): void {
    http_response_code(410);
    $render('closed', [
        'headline'     => $headline,
        'explanation'  => $explanation,
        'offerSignIn'  => $config->clientLoginEnabled(),
    ], 'Soft Appeals', 'Onboarding');
};

// The portal is switched off. Section 20 keeps it off in production until she
// turns it on, and a practice arriving early is told so rather than shown a
// broken link.
if (!$config->portalEnabled()) {
    http_response_code(503);
    header('Retry-After: 3600');
    $render('closed', [
        'headline'    => 'This page is not open yet.',
        'explanation' => 'Your onboarding is being handled by email for now. '
            . 'Reply to the message that brought you here and it moves forward the same day.',
        'offerSignIn' => false,
    ], 'Soft Appeals', 'Onboarding');
}

// ---------------------------------------------------------------------------
// The invitation exchange. A token in the URL is redeemed and then removed.
// ---------------------------------------------------------------------------
$token = (string) ($_GET['t'] ?? '');
if ($token !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $redeemed = $access->redeemInvitation($token, InvitationRepository::PURPOSE_PREFERENCES);
    if ($redeemed === null) {
        $closed(
            'This link has already been used, or it has expired.',
            'Links are good once and they stop working after fourteen days. '
                . 'Nothing is lost: a new one takes a minute.'
        );
    }

    // 303 to the bare path. The token has done its work and must not stay in
    // the address bar for the rest of the session.
    header('Location: /soft-appeals-preferences.php', true, 303);
    exit;
}

$context = $access->context();
if ($context === null) {
    $closed(
        'This link has already been used, or it has expired.',
        'Links are good once and they stop working after fourteen days. '
            . 'Nothing is lost: a new one takes a minute.'
    );
}

$engagement = $context['engagement'];
if ($engagement === null) {
    $closed(
        'There is nothing to confirm here yet.',
        'This sign-in works, but no engagement is open against it. '
            . 'Write to softappeals@frimpomaasync.com and it gets sorted the same day.'
    );
}

$organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');
$organizationId = (string) $engagement['organization_id'];
$engagementId = (string) $engagement['id'];

$problem = $session->flash('client_problem');
$errors = [];

// The permission and the tenancy are both checked, on every request, before
// anything on this page renders or writes. A client role is scoped to one
// organization and the session's organization must be that one.
try {
    $app->authorization()->require(Permission::PREFERENCES_CONFIRM, $organizationId);
} catch (AuthorizationException) {
    $closed(
        'This page is not yours to fill in.',
        'The person who received the terms email is the one who confirms these '
            . 'preferences. Ask them to open their link, or write to softappeals@frimpomaasync.com.'
    );
}

// ---------------------------------------------------------------------------
// The write.
// ---------------------------------------------------------------------------
$people = [];
$values = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $csrf->require('preferences.confirm');

        $result = $app->preferencesService()->confirm(
            $engagement,
            $_POST,
            (string) $context['user']['id'],
            $context['contact_id']
        );

        if ($result['saved']) {
            // 303 so a reload of the destination cannot repost the answers.
            $session->flash(
                'client_ok',
                $result['first_confirmation']
                    ? 'Confirmed. Nothing else is needed from you today.'
                    : 'Updated. Your earlier confirmation still stands.'
            );
            header('Location: /soft-appeals-confirmed.php', true, 303);
            exit;
        }

        $errors = $result['errors'];
        $people = $result['people'];
        $values = $_POST;
    } catch (CsrfException) {
        $problem = 'That form had been open a while and the page moved on. '
            . 'Your answers are still below. Send it again.';
        $values = $_POST;
        foreach (PreferenceForm::contactQuestions() as $key => $ignored) {
            $people[$key] = [
                'name'  => (string) ($_POST[$key . '_name'] ?? ''),
                'role'  => (string) ($_POST[$key . '_role'] ?? ''),
                'email' => (string) ($_POST[$key . '_email'] ?? ''),
            ];
        }
    }
}

// ---------------------------------------------------------------------------
// The read. A practice coming back sees what it already answered.
// ---------------------------------------------------------------------------
$stored = $app->preferences()->forEngagement($engagementId);

if ($values === [] && $stored !== null) {
    $values = [
        'communication_cadence' => (string) $stored['communication_cadence'],
        'secure_channel'        => (string) $stored['secure_channel'],
        'billing_partner'       => (string) $stored['billing_partner'],
        'initial_payer_group'   => (string) ($stored['initial_payer_group'] ?? ''),
        'procurement_notes'     => (string) ($stored['procurement_notes'] ?? ''),
    ];
}

if ($values === []) {
    // Nothing answered yet. The cadence the engagement already carries, if she
    // set one when she sent the terms, is offered as the starting point.
    $values = [
        'communication_cadence' => (string) ($engagement['communication_cadence'] ?? ''),
        'secure_channel'        => (string) ($engagement['secure_channel_type'] ?? ''),
        'billing_partner'       => '',
    ];
}

if ($people === []) {
    $contacts = $app->contacts();
    foreach (PreferenceForm::contactQuestions() as $key => $ignored) {
        $contactId = $stored === null ? null : ($stored[$key . '_contact_id'] ?? null);
        $row = $contactId === null ? null : $contacts->find((string) $contactId);
        $people[$key] = [
            'name'  => $row === null ? '' : (string) $row['name'],
            'role'  => $row === null ? '' : (string) ($row['role_title'] ?? ''),
            'email' => $row === null ? '' : (string) $row['work_email'],
        ];
    }
}

if ($errors !== []) {
    http_response_code(422);
}

$live = $app->invitations()->live($organizationId, InvitationRepository::PURPOSE_PREFERENCES);
$expiresNote = $stored !== null && $stored['confirmed_at'] !== null
    ? 'Confirmed ' . $clock->displayDate((string) $stored['confirmed_at']) . '. Changing an answer is fine.'
    : ($live === null
        ? 'You are signed in. This page stays open while you are.'
        : 'Your link stays live until ' . $clock->displayDate((string) $live['expires_at']) . '.');

$app->audit()->record('preferences.view', 'success', 'engagement', $engagementId, [], $organizationId);

$render(
    'preferences',
    [
        'csrf'         => $csrf,
        'engagement'   => $engagement,
        'errors'       => $errors,
        'values'       => $values,
        'people'       => $people,
        'organization' => $organization,
        'expiresNote'  => $expiresNote,
    ],
    // The character, not the entity. The shell escapes the title, so an
    // entity here printed as "&middot;" in the browser tab. Seen on screen.
    'Confirm your onboarding preferences · Soft Appeals',
    'Onboarding',
    $organization,
    $problem
);
