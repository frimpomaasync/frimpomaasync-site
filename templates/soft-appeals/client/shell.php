<?php
/**
 * The client shell.
 *
 * One white card on a pale canvas, which is the app shell already in
 * assets/soft-appeals.css: .sa-app, .sa-stage, .sa-screen, .sa-choice. ADR-005
 * says reuse the shells rather than invent a fifth one, and this is the one
 * built for a person answering questions.
 *
 * There is no site navigation on these pages and no JavaScript at all. The form
 * works with scripting switched off, which is why the Content-Security-Policy
 * on these routes can stay as tight as it is.
 *
 * One flat array reaches this shell and the view inside it, the same way the
 * Desk shell works. It has to be flat: Views\Client extracts with EXTR_SKIP and
 * the render method's own parameter is already called $data, so a key of that
 * name would be skipped and the inner view would be handed this shell's
 * variables instead of its own.
 *
 * @var \SoftAppeals\Config $config
 * @var string $view
 * @var array<string,mixed> $data  everything, including the inner view's own
 * @var bool $showDetail
 * @var string $organization
 * @var ?string $problem
 */

use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($pageTitle ?? 'Soft Appeals') ?></title>
<link rel="icon" href="/favicon.svg">
<link rel="stylesheet" href="/assets/soft-appeals.css?v=4">
<style>
  /* No site nav on this page, so the shell's sticky chrome parks at the top of
     the window instead of under a bar that is not there. The override sits on
     the .sa element itself: .sa declares --sa-navh, and a custom property on an
     element beats one inherited from its ancestor however the ancestor got it. */
  body > .sa { --sa-navh: 0px; }
  body { margin: 0; background: var(--sa-canvas, #ececed); }

  /* Everything below is prefixed sa-client- so it cannot collide with the
     shell's own names or with modules.css. */
  .sa-client-quiet { color: var(--sa-mute); font-style: italic; }

  .sa-client-flash {
    width: min(720px, 100%);
    margin: 0 auto clamp(14px, 2vw, 20px);
    padding: 12px 16px;
    border-radius: var(--sa-r);
    font-size: 14px;
    line-height: 1.55;
    border: 1px solid rgba(228, 34, 44, .4);
    background: var(--sa-urgent-soft);
    color: #b4141c;
  }

  .sa-client-block { margin: 0 0 clamp(26px, 4vw, 38px); }
  .sa-client-block:last-of-type { margin-bottom: 0; }

  .sa-client-warn {
    margin: 8px 0 10px;
    font-family: var(--mono);
    font-size: 11px;
    letter-spacing: .04em;
    line-height: 1.55;
    color: var(--sa-mute);
  }

  .sa-client-error {
    margin: 8px 0 0;
    font-size: 13.5px;
    line-height: 1.55;
    color: #b4141c;
  }

  .sa-client-people {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    margin-top: 12px;
  }

  .sa-client-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
    margin-top: clamp(20px, 3vw, 30px);
    padding-top: clamp(18px, 3vw, 26px);
    border-top: 1px solid var(--sa-line);
  }

  .sa-client-foot {
    width: min(720px, 100%);
    margin: clamp(20px, 3vw, 28px) auto 0;
    font-size: 12.5px;
    line-height: 1.7;
    color: var(--sa-mute);
  }

  .sa-client-code {
    font-family: var(--mono);
    font-size: 22px;
    letter-spacing: .35em;
    text-align: center;
  }

  /* The document on the signing screen. Its own scroll rather than the page's,
     so the fields underneath it stay reachable on a phone without scrolling
     past nine pages of agreement to get to them. */
  .sa-client-doc {
    max-height: min(52vh, 460px);
    overflow: auto;
    padding: 16px 18px;
    border: 1px solid var(--sa-line-strong);
    border-radius: var(--sa-r);
    background: #fff;
    font-family: var(--mono);
    font-size: 12.5px;
    line-height: 1.75;
    white-space: pre-wrap;
    word-wrap: break-word;
    -webkit-overflow-scrolling: touch;
  }
  .sa-client-doc:focus-visible { outline: 2px solid var(--sa-action); outline-offset: 2px; }

  .sa-client-docrow {
    display: flex;
    gap: 10px 16px;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid var(--sa-line);
  }
  .sa-client-docrow:last-child { border-bottom: 0; }
</style>
</head>
<body>
<div class="sa">
<div class="sa-app">

  <header class="sa-app-top">
    <p class="sa-app-name"><b>Soft Appeals</b> <span><?= $e($headerNote ?? 'Onboarding') ?></span></p>
    <p class="sa-app-status">
      <?php if ($organization !== ''): ?>
        <span><?= $e($organization) ?></span>
      <?php endif; ?>
      <?php if ($config->string('SA_APP_ENV') !== 'production'): ?>
        <span><?= $e($config->string('SA_APP_ENV')) ?></span>
      <?php endif; ?>
    </p>
  </header>

  <main class="sa-stage">
    <div style="width:min(720px,100%)">
      <?php if ($problem !== null): ?>
        <p class="sa-client-flash" role="alert"><?= $e($problem) ?></p>
      <?php endif; ?>

      <?php Client::render($view, $data, $showDetail); ?>

      <p class="sa-client-foot">
        Questions about any of this go to softappeals@frimpomaasync.com.
        Do not send patient, member, claim or clinical information by email.
      </p>
    </div>
  </main>

</div><!-- /.sa-app -->
</div><!-- /.sa -->
</body>
</html>
