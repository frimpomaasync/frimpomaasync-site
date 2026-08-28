<?php
declare(strict_types=1);

/**
 * The page a practice lands on after confirming its preferences.
 *
 * Its own route rather than a query string on the form, so a reload is a plain
 * GET and cannot repost eight answers. The preferences page redirects here with
 * a 303 and this page reads what was stored, which means the words on screen
 * come from the database rather than from what the browser was told a moment
 * ago.
 *
 * A person who arrives here without having confirmed anything is sent back to
 * the form. Nothing on this page is reachable without a client session, and the
 * organization comes from that session, never from the request.
 */

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
$showDetail = !$config->isProduction();

if (!$config->portalEnabled()) {
    header('Location: /soft-appeals', true, 303);
    exit;
}

$context = $app->clientAccess()->context();
if ($context === null || $context['engagement'] === null) {
    header('Location: /soft-appeals-preferences.php', true, 303);
    exit;
}

$engagement = $context['engagement'];
$engagementId = (string) $engagement['id'];

// Nothing confirmed. Back to the form rather than a congratulation for a thing
// that did not happen.
if (!$app->preferences()->isConfirmed($engagementId)) {
    header('Location: /soft-appeals-preferences.php', true, 303);
    exit;
}

$organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');

// One flat array. Views\Client extracts with EXTR_SKIP, so a nested `data`
// key would be skipped over the parameter of that name and the view inside the
// shell would be handed the shell's own variables instead of its own.
Client::render('shell', [
    'config'       => $config,
    'view'         => 'confirmed',
    'showDetail'   => $showDetail,
    'organization' => $organization,
    'problem'      => null,
    'pageTitle'    => 'Preferences confirmed · Soft Appeals',
    'headerNote'   => 'Onboarding',

    'engagement'   => $engagement,
    'chosen'       => $app->preferencesService()->summary($engagementId),
    'roomOpen'     => $config->portalEnabled(),
], $showDetail);
