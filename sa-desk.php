<?php
declare(strict_types=1);

/**
 * The Desk.
 *
 * Phase 1 ships the shell: authentication, authorization, the audit trail, and
 * a foundation panel that proves each of those is actually wired. The pipeline,
 * the inquiry queue and the deadline board arrive in Phase 2, when there is
 * something to put in them.
 *
 * ADR-004: session, not a key in the URL.
 * Section 10.1: an unauthorized caller is answered with a 404, not a 403, so
 * the page cannot be discovered by watching status codes.
 */

use SoftAppeals\Domain\Permission;
use SoftAppeals\Domain\Role;
use SoftAppeals\Security\Headers;

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

$app->requireSecrets();

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

// Sign out. A write, so it carries a CSRF token like every other write.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    $app->csrf()->require('logout');
    $app->auth()->logout();
    header('Location: /soft-appeals-login.php', true, 303);
    exit;
}

$app->audit()->record('desk.view', 'success', 'page', null);

$csrf = $app->csrf();
$clock = $app->clock();
$config = $app->config();
$db = $app->database();

$roles = $authorization->roles(null);
$canAudit = $authorization->can(Permission::AUDIT_VIEW);

$counts = [
    'organizations' => (int) $db->value('SELECT COUNT(*) FROM sa_organizations'),
    'contacts'      => (int) $db->value('SELECT COUNT(*) FROM sa_contacts'),
    'users'         => (int) $db->value('SELECT COUNT(*) FROM sa_users WHERE active = 1'),
    'audit'         => (int) $db->value('SELECT COUNT(*) FROM sa_audit_events'),
];

$recentAudit = $canAudit ? $app->audit()->recent(12) : [];

$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$demo = $config->bool('SA_DEMO_MODE');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>The Desk &middot; Soft Appeals</title>
<link rel="icon" href="/favicon.svg">
<!-- .sa-console is her existing operational shell, live on the Recovery Lab.
     ADR-005: reuse it rather than invent second chrome. -->
<link rel="stylesheet" href="/assets/soft-appeals.css?v=1">
<style>
  /* Desk-only rules. Every class is prefixed sa- per rule 1 in the header of
     assets/soft-appeals.css, which owns the shells and forbids unprefixed
     names because modules.css already claims those. */
  .sa-desk-wrap{max-width:72rem;margin:0 auto;padding:1.5rem 1.25rem 4rem}
  .sa-desk-top{
    display:flex;flex-wrap:wrap;gap:1rem;align-items:baseline;
    justify-content:space-between;border-bottom:1px solid #E1E2E6;
    padding-bottom:1rem;margin-bottom:2rem;
  }
  .sa-desk-title{
    font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;
    font-size:1.9rem;font-weight:600;margin:0;color:#101426;
  }
  .sa-desk-title span{color:#C2501C}
  .sa-desk-who{
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:.65rem;letter-spacing:.12em;text-transform:uppercase;color:#6E7280;
    display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;
  }
  .sa-desk-signout{
    font-family:inherit;font-size:inherit;letter-spacing:inherit;text-transform:inherit;
    background:none;border:1px solid #E1E2E6;color:#6E7280;
    padding:.35rem .7rem;cursor:pointer;
  }
  .sa-desk-signout:hover{border-color:#C2501C;color:#C2501C}
  .sa-desk-eyebrow{
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:.65rem;letter-spacing:.16em;text-transform:uppercase;color:#6E7280;
    margin:2.25rem 0 .75rem;
  }
  .sa-desk-grid{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:1px;
    background:#E1E2E6;border:1px solid #E1E2E6;
  }
  .sa-desk-tile{background:#FFF;padding:1.1rem 1.2rem}
  .sa-desk-tile b{
    display:block;font-family:"Iowan Old Style",Georgia,serif;
    font-size:1.9rem;font-weight:600;font-variant-numeric:tabular-nums;color:#101426;
  }
  .sa-desk-tile span{
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:.62rem;letter-spacing:.12em;text-transform:uppercase;color:#6E7280;
  }
  .sa-desk-panel{background:#FFF;border:1px solid #E1E2E6;padding:1.25rem 1.35rem}
  .sa-desk-panel p{margin:0 0 .75rem;color:#101426}
  .sa-desk-panel p:last-child{margin-bottom:0}
  .sa-desk-tw{overflow-x:auto;border:1px solid #E1E2E6;background:#FFF}
  .sa-desk-table{width:100%;border-collapse:collapse;font-size:.85rem;min-width:38rem}
  .sa-desk-table th,.sa-desk-table td{
    padding:.6rem .8rem;text-align:left;border-bottom:1px solid #E1E2E6;vertical-align:top;
  }
  .sa-desk-table thead th{
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:.6rem;letter-spacing:.13em;text-transform:uppercase;
    color:#6E7280;background:#F8F8F9;white-space:nowrap;
  }
  .sa-desk-table tbody tr:last-child td{border-bottom:0}
  .sa-desk-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.78rem}
  .sa-desk-out-success{color:#1F6B45}
  .sa-desk-out-failure,.sa-desk-out-denied,.sa-desk-out-error{color:#8A2B12}
  .sa-desk-roles{display:flex;gap:.4rem;flex-wrap:wrap;margin:.5rem 0 0}
  .sa-desk-role{
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;
    border:1px solid #C2501C;color:#C2501C;padding:.15rem .5rem;
  }
  .sa-desk-todo{
    border-left:3px solid #C2501C;background:#FBF3EC;padding:1rem 1.2rem;margin:1rem 0 0;
  }
  .sa-desk-todo ul{margin:.5rem 0 0;padding-left:1.1rem}
  .sa-desk-todo li{margin:0 0 .3rem}
</style>
</head>
<body class="sa-console">

<?php if ($demo): ?>
  <!-- Rule 6 in the header of assets/soft-appeals.css: a fictional-data notice
       is sticky, not one-time. A buyer who lands mid-page must still see it. -->
  <div class="sa-fiction" role="note">
    Demo mode. Every organization on this screen is invented. No real practice,
    no patient, no claim.
  </div>
<?php endif; ?>

<div class="sa-desk-wrap">

  <header class="sa-desk-top">
    <h1 class="sa-desk-title">The Desk<span>.</span></h1>
    <div class="sa-desk-who">
      <span><?= $e((string) $user['email']) ?></span>
      <span><?= $e($config->string('SA_APP_ENV')) ?></span>
      <form method="post" action="/sa-desk.php" style="margin:0">
        <?= $csrf->field('logout') ?>
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="sa-desk-signout">Sign out</button>
      </form>
    </div>
  </header>

  <p class="sa-desk-eyebrow">Foundation</p>
  <div class="sa-desk-grid">
    <div class="sa-desk-tile">
      <b><?= (int) $counts['organizations'] ?></b>
      <span>Organizations</span>
    </div>
    <div class="sa-desk-tile">
      <b><?= (int) $counts['contacts'] ?></b>
      <span>Contacts</span>
    </div>
    <div class="sa-desk-tile">
      <b><?= (int) $counts['users'] ?></b>
      <span>Active users</span>
    </div>
    <div class="sa-desk-tile">
      <b><?= (int) $counts['audit'] ?></b>
      <span>Audit events</span>
    </div>
  </div>

  <p class="sa-desk-eyebrow">This session</p>
  <div class="sa-desk-panel">
    <p>
      Signed in as <strong><?= $e((string) $user['email']) ?></strong>.
      <?php if (($user['last_login_at'] ?? null) !== null): ?>
        Last signed in <?= $e($clock->displayDateTime((string) $user['last_login_at'])) ?>.
      <?php endif; ?>
    </p>
    <div class="sa-desk-roles">
      <?php foreach ($roles as $role): ?>
        <span class="sa-desk-role"><?= $e(Role::label($role)) ?></span>
      <?php endforeach; ?>
    </div>
    <div class="sa-desk-todo">
      <p><strong>Phase 1 is the foundation.</strong> What is live on this screen:</p>
      <ul>
        <li>Session login, rotated session id, 30 minute idle and 12 hour absolute timeout</li>
        <li>Server-side authorization on every action, and a 404 rather than a 403 when refused</li>
        <li>A CSRF token on every write, including the sign-out button above</li>
        <li>An append-only audit trail recording successes and refusals alike</li>
      </ul>
      <p style="margin-top:.75rem">
        The pipeline, the inquiry queue, the deadline board and the terms button
        arrive in Phase 2.
      </p>
    </div>
  </div>

  <?php if ($canAudit): ?>
    <p class="sa-desk-eyebrow">Audit trail &middot; newest first</p>
    <div class="sa-desk-tw">
      <table class="sa-desk-table">
        <thead>
          <tr>
            <th>When</th>
            <th>Action</th>
            <th>Outcome</th>
            <th>Object</th>
            <th>Detail</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($recentAudit === []): ?>
          <tr><td colspan="5">Nothing recorded yet.</td></tr>
        <?php else: ?>
          <?php foreach ($recentAudit as $row): ?>
            <tr>
              <td class="sa-desk-mono"><?= $e($clock->displayDateTime((string) $row['created_at'])) ?></td>
              <td class="sa-desk-mono"><?= $e((string) $row['action']) ?></td>
              <td class="sa-desk-mono sa-desk-out-<?= $e((string) $row['outcome']) ?>">
                <?= $e((string) $row['outcome']) ?>
              </td>
              <td class="sa-desk-mono"><?= $e((string) ($row['object_type'] ?? '')) ?></td>
              <td class="sa-desk-mono"><?= $e((string) ($row['metadata'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
