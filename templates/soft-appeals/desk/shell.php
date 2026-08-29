<?php
/**
 * The Desk shell.
 *
 * Everything on this page stands on .sa-console from assets/soft-appeals.css:
 * the navy rail, the pale canvas, the sticky work header, the metric row, the
 * worklist table, the slide-out drawer. ADR-005. The Desk-only rules below are
 * the handful the shell does not already carry, and every class is prefixed
 * sa-desk- so it cannot collide with the shell's own names or with modules.css.
 *
 * @var \SoftAppeals\Bootstrap $app
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Config $config
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var array<string,mixed> $user
 * @var list<string> $roles
 * @var string $view
 * @var array<string,int> $pipeline
 * @var int $documentsNeedingHer
 * @var int $assessmentsNeedingHer
 * @var list<array<string,mixed>> $requestsForHer
 * @var array<string,mixed> $data
 * @var bool $showDetail
 */

use SoftAppeals\Views\Desk;

$e = static fn (?string $value): string => Desk::e($value);
$demo = $config->bool('SA_DEMO_MODE');
$greeting = (int) $clock->now()->setTimezone(new DateTimeZone($config->string('SA_BUSINESS_TIMEZONE')))->format('G');
$greetingWord = $greeting < 12 ? 'Good morning' : ($greeting < 18 ? 'Good afternoon' : 'Good evening');

$needsYou = count($awaitingReview) + count($termsReady) + ($documentsNeedingHer ?? 0) + count($requestsForHer ?? [])
    + ($recoveryNeedingHer ?? 0) + ($moneyNeedingHer ?? 0) + ($closeoutNeedingHer ?? 0) + ($attentionCount ?? 0);

/** The rail. Built here, in the plan's own order, so the map is visible even
 *  where the page behind it is not written yet. Section 12.3. */
$navBuilt = [
    ['home',        'Home',        null],
    ['inquiries',   'Inquiries',   count($openIntakes) ?: null],
    ['terms',       'Terms',       count($termsReady) ?: null],
    ['documents',   'Agreements',  ($documentsNeedingHer ?? 0) ?: null],
    ['assessments', 'Assessments', ($assessmentsNeedingHer ?? 0) ?: null],
    ['recovery',    'Recovery',    ($recoveryNeedingHer ?? 0) ?: null],
    ['money',       'Money',       ($moneyNeedingHer ?? 0) ?: null],
    ['closeout',    'Closeout',    ($closeoutNeedingHer ?? 0) ?: null],
    ['jobs',        'Automation',  ($attentionCount ?? 0) ?: null],
];
$navLater = [
    'Organizations', 'Engagements',
    'Communications',
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>The Desk &middot; Soft Appeals</title>
<link rel="icon" href="/favicon.svg">
<link rel="stylesheet" href="/assets/soft-appeals.css?v=4">
<link rel="stylesheet" href="/assets/soft-appeals-desk.css?v=5">
<style>
  /* The Desk carries no site navigation, so the shell's sticky chrome parks at
     the top of the window rather than under a bar that is not there. Without
     this the rail and the work header sit 64px down and leave a stripe across
     the top of every screen.
     The override has to sit on the .sa element itself, not on :root: .sa
     declares --sa-navh, and a custom property declared on an element beats one
     inherited from its ancestor however the ancestor got it. */
  body > .sa { --sa-navh: 0px; }
  body { margin: 0; background: var(--sa-canvas, #ececed); }
  .sa-desk-shell { min-height: 100vh; }

  .sa-desk-who {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    font-family: var(--mono); font-size: 10.5px; letter-spacing: .12em;
    text-transform: uppercase; color: var(--sa-mute);
  }
  .sa-desk-signout {
    appearance: none; font: inherit; letter-spacing: inherit; text-transform: inherit;
    background: none; border: 1px solid var(--sa-line-strong); color: var(--sa-mute);
    padding: 6px 12px; border-radius: 999px; cursor: pointer; min-height: 34px;
  }
  .sa-desk-signout:hover { border-color: var(--sa-action); color: var(--sa-action); }

  /* Flash messages. One line, at the top of the workspace, gone on reload. */
  .sa-desk-flash {
    padding: 12px 16px; border-radius: var(--sa-r); font-size: 14px; line-height: 1.5;
    border: 1px solid var(--sa-ok); background: var(--sa-ok-soft); color: #17603c;
  }
  .sa-desk-flash.is-problem {
    border-color: rgba(228,34,44,.4); background: var(--sa-urgent-soft); color: #b4141c;
  }

  /* Action cards. One primary action, one View, per section 12.4. */
  .sa-desk-cards { display: grid; gap: 10px; }
  .sa-desk-card {
    display: flex; gap: 14px; align-items: center; justify-content: space-between;
    flex-wrap: wrap; padding: 14px 16px; background: var(--sa-surface);
    border: 1px solid var(--sa-line); border-left: 3px solid var(--sa-action);
    border-radius: var(--sa-r);
  }
  .sa-desk-card.is-urgent { border-left-color: var(--sa-urgent); }
  .sa-desk-card-t { min-width: 0; }
  .sa-desk-card-t b { display: block; font-weight: 600; font-size: 14.5px; }
  .sa-desk-card-t span { display: block; font-size: 13px; color: var(--sa-mute); margin-top: 2px; }
  .sa-desk-card-a { display: flex; gap: 8px; flex-wrap: wrap; }

  .sa-desk-quiet { color: var(--sa-mute); font-style: italic; }
  .sa-desk-mono { font-family: var(--mono); font-size: 12px; }
  .sa-desk-note {
    font-size: 13px; line-height: 1.6; color: var(--sa-mute);
    padding: 12px 14px; border: 1px dashed var(--sa-line-strong); border-radius: var(--sa-r);
  }

  /* The email preview. A real preview of a plain-text message has to be shown
     in a monospaced block with its own line breaks, or it is not a preview. */
  .sa-desk-email {
    margin: 0; padding: 18px; background: var(--sa-surface-2);
    border: 1px solid var(--sa-line); border-radius: var(--sa-r);
    font-family: var(--mono); font-size: 12.5px; line-height: 1.65;
    white-space: pre-wrap; word-break: break-word; color: var(--sa-ink);
  }

  .sa-desk-list { margin: 0; padding-left: 18px; font-size: 13.5px; line-height: 1.65; }
  .sa-desk-list li { margin-bottom: 5px; }

  .sa-desk-grid2 {
    display: grid; gap: clamp(14px, 2vw, 20px);
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  }

  .sa-rail-nav .is-later {
    opacity: .38; cursor: default; pointer-events: none;
  }

  /* A skip link for keyboard and screen-reader users. Off screen until it
     has focus, then a plain bar at the top. */
  .sa-desk-skip {
    position: absolute; left: -9999px; top: 0; z-index: 50;
    padding: 10px 16px; background: var(--sa-ink, #101426); color: #fff;
    font-family: var(--mono); font-size: 12px; letter-spacing: .1em; text-transform: uppercase;
  }
  .sa-desk-skip:focus { left: 0; outline: 2px solid var(--sa-action); }
  .sa-desk-card-a form { margin: 0; }
  .sa-rail-nav .is-later .sa-count { font-size: 9px; letter-spacing: .12em; }
</style>
</head>
<body>
<a class="sa-desk-skip" href="#desk-main">Skip to the work</a>
<div class="sa sa-desk-shell">

<?php if ($demo): ?>
  <!-- Rule 6 in the header of assets/soft-appeals.css: a fictional-data notice
       is sticky, not one-time. A buyer, or she, landing mid-page must still
       see that some of these practices were invented by the seeder. -->
  <p class="sa-fiction" role="note">
    <b>Demo</b> Some practices on this screen are invented. No patient, no claim, and no real name unless it arrived through her own form.
  </p>
<?php endif; ?>

<div class="sa-console">

<nav class="sa-rail" aria-label="Desk sections">
  <span class="sa-rail-brand">Soft Appeals</span>
  <p class="sa-rail-sub">Recovery command centre</p>

  <p class="sa-rail-group">Work</p>
  <ul class="sa-rail-nav">
    <?php foreach ($navBuilt as [$key, $label, $count]): ?>
      <li>
        <a href="/sa-desk.php?view=<?= $e($key) ?>"
           <?= $view === $key ? 'aria-current="page"' : '' ?>>
          <span><?= $e($label) ?></span>
          <?php if ($count !== null): ?><span class="sa-count"><?= (int) $count ?></span><?php endif; ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>

  <p class="sa-rail-group">Records</p>
  <ul class="sa-rail-nav">
    <?php if ($canReview): ?>
      <li>
        <a href="/sa-desk.php?view=import" <?= $view === 'import' ? 'aria-current="page"' : '' ?>>
          <span>Import old leads</span>
        </a>
      </li>
    <?php endif; ?>
    <?php if ($canAudit): ?>
      <li>
        <a href="/sa-desk.php?view=audit" <?= $view === 'audit' ? 'aria-current="page"' : '' ?>>
          <span>Audit trail</span>
        </a>
      </li>
    <?php endif; ?>
    <?php if (in_array(\SoftAppeals\Domain\Role::OWNER_ADMIN, $roles, true)): ?>
      <li>
        <a href="/sa-desk.php?view=settings" <?= $view === 'settings' ? 'aria-current="page"' : '' ?>>
          <span>Settings</span>
        </a>
      </li>
      <li>
        <a href="/sa-desk.php?view=launch" <?= $view === 'launch' ? 'aria-current="page"' : '' ?>>
          <span>Launch</span>
        </a>
      </li>
    <?php endif; ?>
  </ul>

  <p class="sa-rail-group">Not built yet</p>
  <ul class="sa-rail-nav">
    <?php foreach ($navLater as $label): ?>
      <?php /* A button, not a span, so it picks up the rail's own nav styling
               rather than needing a second copy of it. Disabled, so it is not
               in the tab order and cannot be clicked. */ ?>
      <li><button type="button" class="is-later" disabled>
        <span><?= $e($label) ?></span><span class="sa-count">Later</span>
      </button></li>
    <?php endforeach; ?>
  </ul>

  <div class="sa-rail-foot">
    <span class="sa-rail-stamp"><?= $e($config->string('SA_APP_ENV')) ?></span>
    <div><?= $e((string) $user['email']) ?></div>
    <div>
      <?php foreach ($roles as $role): ?>
        <?= $e(\SoftAppeals\Domain\Role::label($role)) ?><br>
      <?php endforeach; ?>
    </div>
  </div>
</nav>

<div class="sa-work">
  <header class="sa-work-top">
    <div>
      <p class="sa-label"><?= $e($clock->displayDateTime($clock->nowUtc())) ?></p>
      <h1><?= $e($greetingWord) ?>, Nana Frimpongmaa.</h1>
    </div>
    <div class="sa-work-actions sa-desk-who">
      <span><?= $needsYou === 0
        ? 'Nothing needs you right now'
        : $needsYou . ' ' . Desk::plural($needsYou, 'thing') . ' need' . ($needsYou === 1 ? 's' : '') . ' you' ?></span>
      <form method="post" action="/sa-desk.php" style="margin:0">
        <?= $csrf->field('logout') ?>
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="sa-desk-signout">Sign out</button>
      </form>
    </div>
  </header>

  <main class="sa-work-body" id="desk-main" tabindex="-1">
    <?php if ($ok !== null): ?>
      <p class="sa-desk-flash" role="status"><?= $e($ok) ?></p>
    <?php endif; ?>
    <?php if ($problem !== null): ?>
      <p class="sa-desk-flash is-problem" role="alert"><?= $e($problem) ?></p>
    <?php endif; ?>

    <?php Desk::render($view, $data, $showDetail); ?>
  </main>
</div>

</div><!-- /.sa-console -->
</div><!-- /.sa -->

<script src="/assets/soft-appeals.js?v=2" defer></script>
</body>
</html>
