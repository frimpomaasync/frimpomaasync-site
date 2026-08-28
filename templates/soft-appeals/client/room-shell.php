<?php
/**
 * The Recovery Room shell. Section 15.1 to 15.3.
 *
 * The same .sa-console chrome the Desk stands on, because it is the shell built
 * for somebody looking at work rather than reading a page, and ADR-005 says
 * reuse rather than invent. What differs is what is in it: her rail lists the
 * Desk's sections, this one lists the nine in section 15.3, and everything on
 * this side is what the practice is allowed to see.
 *
 * Phase 3 builds the shell and the overview. The other eight sections are shown
 * and marked, not hidden. A practice that can see the whole map knows what is
 * coming, and a rail that grows a new item every fortnight reads as a product
 * that keeps changing shape.
 *
 * One flat array reaches this shell and the view inside it, the same way the
 * Desk shell works. It has to be flat: Views\Client extracts with EXTR_SKIP and
 * the render method's own parameter is already called $data, so a key of that
 * name would be skipped and the inner view would be handed this shell's
 * variables instead of its own.
 *
 * @var \SoftAppeals\Config $config
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var string $view
 * @var array<string,mixed> $data  everything, including the inner view's own
 * @var bool $showDetail
 * @var string $organization
 * @var array<string,mixed>|null $engagement
 * @var string $stageLabel
 * @var string $nextLine
 * @var string $email
 * @var list<string> $roleLabels
 * @var ?string $problem
 * @var ?string $ok
 */

use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);

/**
 * Section 15.3, in the plan's own order.
 *
 * Overview was built in Phase 3 and Agreements in Phase 4, so Agreements has
 * come out of this list and into the one above it. The rest stay listed and
 * marked: a practice that can see the whole map knows what is coming.
 */
$navLater = [
    'Assessment', 'Work batches', 'Action requests', 'Approvals',
    'Recovery', 'Messages', 'Access',
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Your Recovery Room &middot; Soft Appeals</title>
<link rel="icon" href="/favicon.svg">
<link rel="stylesheet" href="/assets/soft-appeals.css?v=2">
<style>
  /* No site nav on this page, so the shell's sticky chrome parks at the top of
     the window. Same override the Desk carries, same reason. */
  body > .sa { --sa-navh: 0px; }
  body { margin: 0; background: var(--sa-canvas, #ececed); }
  .sa-room-shell { min-height: 100vh; }

  .sa-room-who {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    font-family: var(--mono); font-size: 10.5px; letter-spacing: .12em;
    text-transform: uppercase; color: var(--sa-mute);
  }
  .sa-room-signout {
    appearance: none; font: inherit; letter-spacing: inherit; text-transform: inherit;
    background: none; border: 1px solid var(--sa-line-strong); color: var(--sa-mute);
    padding: 6px 12px; border-radius: 999px; cursor: pointer; min-height: 34px;
  }
  .sa-room-signout:hover { border-color: var(--sa-action); color: var(--sa-action); }

  .sa-room-flash {
    padding: 12px 16px; border-radius: var(--sa-r); font-size: 14px; line-height: 1.5;
    border: 1px solid var(--sa-ok); background: var(--sa-ok-soft); color: #17603c;
  }
  .sa-room-flash.is-problem {
    border-color: rgba(228,34,44,.4); background: var(--sa-urgent-soft); color: #b4141c;
  }

  .sa-room-quiet { color: var(--sa-mute); font-style: italic; }
  .sa-room-list { margin: 0; padding-left: 18px; font-size: 13.5px; line-height: 1.65; }
  .sa-room-list li { margin-bottom: 5px; }
  .sa-room-grid2 {
    display: grid; gap: clamp(14px, 2vw, 20px);
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  }
  .sa-room-note {
    font-size: 13px; line-height: 1.6; color: var(--sa-mute);
    padding: 12px 14px; border: 1px dashed var(--sa-line-strong); border-radius: var(--sa-r);
  }

  .sa-rail-nav .is-later { opacity: .38; cursor: default; pointer-events: none; }
  .sa-rail-nav .is-later .sa-count { font-size: 9px; letter-spacing: .12em; }
</style>
</head>
<body>
<div class="sa sa-room-shell">
<div class="sa-console">

<nav class="sa-rail" aria-label="Recovery Room sections">
  <span class="sa-rail-brand">Soft Appeals</span>
  <p class="sa-rail-sub">Your recovery room</p>

  <p class="sa-rail-group">Now</p>
  <ul class="sa-rail-nav">
    <li>
      <a href="/soft-appeals-room.php" aria-current="page"><span>Overview</span></a>
    </li>
    <li>
      <a href="/soft-appeals-room.php#room-agreements"><span>Agreements</span>
        <?php if (($documentCount ?? 0) > 0): ?>
          <span class="sa-count"><?= (int) $documentCount ?></span>
        <?php endif; ?>
      </a>
    </li>
  </ul>

  <p class="sa-rail-group">Not built yet</p>
  <ul class="sa-rail-nav">
    <?php foreach ($navLater as $label): ?>
      <li><button type="button" class="is-later" disabled>
        <span><?= $e($label) ?></span><span class="sa-count">Later</span>
      </button></li>
    <?php endforeach; ?>
  </ul>

  <div class="sa-rail-foot">
    <?php if ($config->string('SA_APP_ENV') !== 'production'): ?>
      <span class="sa-rail-stamp"><?= $e($config->string('SA_APP_ENV')) ?></span>
    <?php endif; ?>
    <div><?= $e($email) ?></div>
    <div>
      <?php foreach ($roleLabels as $label): ?>
        <?= $e($label) ?><br>
      <?php endforeach; ?>
    </div>
  </div>
</nav>

<div class="sa-work">
  <header class="sa-work-top">
    <div>
      <p class="sa-label"><?= $e($stageLabel) ?></p>
      <h1><?= $e($organization) ?></h1>
      <p class="sa-note" style="max-width:60ch"><?= $e($nextLine) ?></p>
    </div>
    <div class="sa-work-actions sa-room-who">
      <form method="post" action="/soft-appeals-room.php" style="margin:0">
        <?= $csrf->field('client.sign_out') ?>
        <input type="hidden" name="action" value="client.sign_out">
        <button type="submit" class="sa-room-signout">Sign out</button>
      </form>
    </div>
  </header>

  <div class="sa-work-body">
    <?php if ($ok !== null): ?>
      <p class="sa-room-flash" role="status"><?= $e($ok) ?></p>
    <?php endif; ?>
    <?php if ($problem !== null): ?>
      <p class="sa-room-flash is-problem" role="alert"><?= $e($problem) ?></p>
    <?php endif; ?>

    <?php Client::render($view, $data, $showDetail); ?>
  </div>
</div>

</div><!-- /.sa-console -->
</div><!-- /.sa -->
</body>
</html>
