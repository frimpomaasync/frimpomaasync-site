<?php
declare(strict_types=1);

/**
 * The signing screen. Section 14.3.
 *
 * The same three rules the preferences page follows, for the same reasons.
 *
 * The token is exchanged for a session and then leaves the URL. A GET carrying
 * ?t= redeems it, burns it, opens a client session, and answers with a 303 to
 * the bare path, so nothing carrying a token lands in browser history, in a
 * Referer header, or in a screenshot of a page somebody was reading.
 *
 * A dead link is a page, not a 404. She sent it herself, and a practice
 * following it into the host's error page would read that as her site being
 * broken.
 *
 * The document comes from the session, never from the request. There is a
 * document reference in the form, but it is checked against the one this
 * session is actually being asked to sign rather than used to look one up:
 * a reference that could be used to fetch a document would be a reference
 * somebody could guess.
 *
 * What this page will not do is sign anything on its own judgment. Every one of
 * the checks in section 14.3 lives in SigningService, and this file's job is to
 * carry the answer back to a person in a sentence.
 */

use SoftAppeals\Repositories\InvitationRepository;
use SoftAppeals\Security\CsrfException;
use SoftAppeals\Security\Headers;
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
$signing = $app->signingService();
$showDetail = !$config->isProduction();

/** Render one client screen inside the shell and stop. */
$render = static function (
    string $view,
    array $vars,
    string $title,
    string $organization = '',
    ?string $problem = null
) use ($config, $showDetail): void {
    Client::render('shell', array_merge($vars, [
        'config'       => $config,
        'view'         => $view,
        'showDetail'   => $showDetail,
        'organization' => $organization,
        'problem'      => $problem,
        'pageTitle'    => $title,
        'headerNote'   => 'Signing',
    ]), $showDetail);
    exit;
};

/** The one screen a link that no longer works lands on. */
$closed = static function (string $headline, string $explanation, int $code = 410) use ($render, $config): void {
    http_response_code($code);
    $render('closed', [
        'headline'    => $headline,
        'explanation' => $explanation,
        'offerSignIn' => $config->clientLoginEnabled(),
    ], 'Soft Appeals');
};

if (!$config->portalEnabled()) {
    header('Retry-After: 3600');
    $closed(
        'This page is not open yet.',
        'Your agreements are being handled by email for now. Reply to the message '
            . 'that brought you here and it moves forward the same day.',
        503
    );
}

// Signing is switched off. Said plainly, because a practice that was emailed a
// link and then told nothing would assume the link was the problem.
if (!$config->eSignEnabled()) {
    header('Retry-After: 3600');
    $closed(
        'Signing is not switched on yet.',
        'Your agreement is ready but it is not being signed on screen yet. '
            . 'Write to softappeals@frimpomaasync.com and it comes to you the way that works.',
        503
    );
}

// ---------------------------------------------------------------------------
// The invitation exchange. A token in the URL is redeemed and then removed.
// ---------------------------------------------------------------------------
$token = (string) ($_GET['t'] ?? '');
if ($token !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $redeemed = $access->redeemInvitation($token, InvitationRepository::PURPOSE_SIGN);
    if ($redeemed === null) {
        $closed(
            'This link has already been used, or it has expired.',
            'Links are good once and they stop working after fourteen days. '
                . 'Nothing is lost: a new one takes a minute.'
        );
    }

    header('Location: /soft-appeals-sign.php', true, 303);
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
        'There is nothing to sign here yet.',
        'This sign-in works, but no engagement is open against it. '
            . 'Write to softappeals@frimpomaasync.com and it gets sorted the same day.'
    );
}

$organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');
$organizationId = (string) $engagement['organization_id'];

// The document this session is being asked to sign. Derived, never requested.
$document = $signing->pending([
    'organization_id' => $organizationId,
    'engagement'      => $engagement,
    'contact_id'      => $context['contact_id'],
]);

if ($document === null) {
    $closed(
        'There is nothing waiting for your signature.',
        'Either it has been signed already or it is addressed to somebody else at '
            . 'your organization. Your Recovery Room shows where everything stands.',
        200
    );
}

$signContext = [
    'organization_id' => $organizationId,
    'engagement'      => $engagement,
    'contact_id'      => $context['contact_id'],
    'user_id'         => (string) $context['user']['id'],
];

$problem = $session->flash('client_problem');

// What they typed, kept across a refusal.
//
// The consent box was left unticked on the first walk of this page and the
// refusal came back with every field empty, so the signer had to retype their
// legal name to try again. The preferences page keeps its answers on a
// refusal; this one now does too.
$typed = [
    'typed_name'         => '',
    'typed_title'        => null,
    'typed_organization' => null,
    'consent'            => false,
    'confirm'            => false,
];

// ---------------------------------------------------------------------------
// The write.
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $csrf->require('document.sign');

        $posted = (string) ($_POST['document'] ?? '');
        if ($posted !== (string) $document['public_ref']) {
            throw new \RuntimeException(
                'That form was for a different document. Nothing was signed. '
                . 'The one waiting for you is below.'
            );
        }

        if ((string) ($_POST['confirm'] ?? '') !== 'yes') {
            throw new \RuntimeException('Tick the last box to confirm you are signing.');
        }

        $result = $signing->sign($document, $signContext, [
            'typed_name'         => (string) ($_POST['typed_name'] ?? ''),
            'typed_title'        => trim((string) ($_POST['typed_title'] ?? '')) === ''
                ? null
                : mb_substr(trim((string) $_POST['typed_title']), 0, 120),
            'typed_organization' => trim((string) ($_POST['typed_organization'] ?? '')) === ''
                ? null
                : mb_substr(trim((string) $_POST['typed_organization']), 0, 200),
            'consent'            => (string) ($_POST['consent'] ?? '') === 'yes',
            'document_sha256'    => (string) ($_POST['document_sha256'] ?? ''),
        ]);

        // 303 to the room, so a reload of the destination cannot repost a
        // signature. The idempotency key would refuse a second one anyway; this
        // is the layer that means a person never sees it try.
        $session->flash(
            'client_ok',
            $result['already']
                ? 'That was already signed. Nothing was signed twice.'
                : 'Signed. Your copy is here, and it goes to both of us.'
        );
        header('Location: /soft-appeals-room.php', true, 303);
        exit;
    } catch (CsrfException) {
        $problem = 'That form had been open a while and the page moved on. '
            . 'Read it once more and sign again. Nothing was signed.';
    } catch (\RuntimeException $e) {
        $problem = $e->getMessage();
        http_response_code(422);
    }

    $typed = [
        'typed_name'         => (string) ($_POST['typed_name'] ?? ''),
        'typed_title'        => (string) ($_POST['typed_title'] ?? ''),
        'typed_organization' => (string) ($_POST['typed_organization'] ?? ''),
        'consent'            => (string) ($_POST['consent'] ?? '') === 'yes',
        'confirm'            => (string) ($_POST['confirm'] ?? '') === 'yes',
    ];
}

// ---------------------------------------------------------------------------
// The read.
// ---------------------------------------------------------------------------
$screen = $signing->screen($document);

$app->audit()->record(
    'document.sign_view',
    'success',
    'document',
    (string) $document['id'],
    ['document_kind' => (string) $document['kind']],
    $organizationId
);

$render(
    'sign',
    [
        'csrf'             => $csrf,
        'document'         => $screen['document'],
        'kindLabel'        => $screen['kind_label'],
        'body'             => $screen['body'],
        'bodyIntact'       => $screen['body_intact'],
        'contentSha'       => $screen['content_sha'],
        'signer'           => $screen['signer'],
        'consentText'      => $screen['consent_text'],
        'consentVersion'   => $screen['consent_version'],
        'utcNow'           => $screen['utc_now'],
        'localNow'         => $screen['local_now'],
        'organizationName' => (string) $engagement['legal_name'],
        'typed'            => $typed,
    ],
    // The character, not the entity. The shell escapes the title, so an entity
    // here prints as "&middot;" in the browser tab.
    $screen['kind_label'] . ' · Soft Appeals',
    $organization,
    $problem
);
