<?php
declare(strict_types=1);

/**
 * The Desk login.
 *
 * ADR-004: the Desk writes, so it does not take a key in the URL the way
 * stats.php does. A URL lands in browser history, in the Referer header, in a
 * screenshot, and in server logs, and none of those is a place for a credential
 * that can send an agreement to a practice.
 *
 * The form works without JavaScript. There is no script on this page at all,
 * which is why the Content-Security-Policy can be as tight as it is.
 */

use SoftAppeals\Security\CsrfException;
use SoftAppeals\Security\Headers;
use SoftAppeals\Security\RateLimitException;

$app = require __DIR__ . '/src/SoftAppeals/boot.php';

Headers::send();

$session = $app->session();
$session->start();

// Already signed in. Nothing to do here.
if ($session->isAuthenticated() && $session->kind() === 'admin') {
    header('Location: /sa-desk.php', true, 303);
    exit;
}

// A freshly deployed site has no configuration yet. Say so, rather than
// answering with a 500 that reads as something broken.
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

// Nobody can sign in until an account exists, so a first-time visitor is sent
// to the setup page rather than left guessing at an empty database.
try {
    $app->requireSecrets();
    $app->prepareDatabase();
    if ($app->seeds()->needsOwner()) {
        header('Location: /soft-appeals-setup.php', true, 303);
        exit;
    }
} catch (\Throwable $e) {
    $app->errors()->handle($e);
}

$csrf = $app->csrf();
$error = null;
$notice = $session->flash('login_notice');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $csrf->require('login');
        $app->requireSecrets();

        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        $userId = $app->auth()->attemptAdminLogin($email, $password);

        if ($userId !== null) {
            // 303 so a refresh of the destination cannot repost the password.
            header('Location: /sa-desk.php', true, 303);
            exit;
        }

        // One message for every failure. An unknown account and a wrong
        // password must be indistinguishable, or the form becomes a way to
        // find out which addresses exist.
        $error = 'That did not match. Check the address and the password.';
    } catch (CsrfException) {
        $error = 'That form had expired. Try again.';
    } catch (RateLimitException $e) {
        $minutes = max(1, (int) ceil($e->retryAfterSeconds / 60));
        $error = 'Too many attempts. Try again in ' . $minutes . ' minute'
            . ($minutes === 1 ? '' : 's') . '.';
    } catch (\Throwable $e) {
        $app->errors()->handle($e);
    }
}

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$demo = $app->config()->bool('SA_DEMO_MODE');
$env = $app->config()->string('SA_APP_ENV');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign in &middot; Soft Appeals</title>
<link rel="icon" href="/favicon.svg">
<style>
  /* Deliberately self-contained. This page loads before a session exists and
     must render even if an asset is mid-deploy. System fonts only: a remote
     font request crashes her iPhone. */
  :root{
    --ink:#101426; --copper:#C2501C; --paper:#FFFFFF; --paper2:#F8F8F9;
    --rule:#E1E2E6; --muted:#6E7280; --warn:#8A2B12; --warn-bg:#FBF1EC;
  }
  *{box-sizing:border-box}
  body{
    margin:0; background:var(--paper2); color:var(--ink);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;
    line-height:1.6; display:flex; align-items:center; justify-content:center;
    min-height:100vh; padding:24px;
  }
  .card{
    background:var(--paper); border:1px solid var(--rule);
    max-width:26rem; width:100%; padding:2.25rem 2rem 2rem;
  }
  .eyebrow{
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:.65rem; letter-spacing:.18em; text-transform:uppercase;
    color:var(--muted); margin:0 0 .5rem;
  }
  h1{
    font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;
    font-size:1.75rem; font-weight:600; margin:0 0 1.5rem;
  }
  h1 span{color:var(--copper)}
  label{
    display:block; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:.65rem; letter-spacing:.14em; text-transform:uppercase;
    color:var(--muted); margin:0 0 .4rem;
  }
  /* 16px minimum, or iOS zooms the page the moment a field is focused. */
  input[type=email],input[type=password]{
    width:100%; font-size:16px; padding:.7rem .8rem; margin:0 0 1.15rem;
    border:1px solid var(--rule); border-radius:0; background:var(--paper);
    color:var(--ink); font-family:inherit;
  }
  input:focus{outline:2px solid var(--copper); outline-offset:1px; border-color:var(--copper)}
  button{
    width:100%; font-size:.8rem; letter-spacing:.14em; text-transform:uppercase;
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    background:var(--copper); color:#fff; border:0; padding:.85rem 1rem;
    cursor:pointer;
  }
  button:hover{background:#a8440f}
  button:focus-visible{outline:2px solid var(--ink); outline-offset:2px}
  .error{
    background:var(--warn-bg); border-left:3px solid var(--warn); color:var(--warn);
    padding:.7rem .9rem; margin:0 0 1.25rem; font-size:.9rem;
  }
  .notice{
    background:var(--paper2); border-left:3px solid var(--rule);
    padding:.7rem .9rem; margin:0 0 1.25rem; font-size:.9rem; color:var(--muted);
  }
  .env{
    margin:1.5rem 0 0; padding:.7rem .9rem; background:var(--paper2);
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:.65rem; letter-spacing:.1em; text-transform:uppercase; color:var(--muted);
  }
  .back{
    margin:1.5rem 0 0; font-size:.85rem;
  }
  a{color:var(--copper)}
</style>
</head>
<body>
<main class="card">
  <p class="eyebrow">Soft Appeals</p>
  <h1>The Desk<span>.</span></h1>

  <?php if ($error !== null): ?>
    <p class="error"><?= $e($error) ?></p>
  <?php endif; ?>

  <?php if ($notice !== null): ?>
    <p class="notice"><?= $e($notice) ?></p>
  <?php endif; ?>

  <form method="post" action="/soft-appeals-login.php" autocomplete="on">
    <?= $csrf->field('login') ?>

    <label for="email">Email</label>
    <input type="email" id="email" name="email" required autocomplete="username"
           autocapitalize="none" autocorrect="off" spellcheck="false"
           value="<?= $e((string) ($_POST['email'] ?? '')) ?>">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required
           autocomplete="current-password">

    <button type="submit">Sign in &rarr;</button>
  </form>

  <?php if ($demo || $env !== 'production'): ?>
    <p class="env">
      <?= $e($env) ?><?= $demo ? ' &middot; demo data' : '' ?>
    </p>
  <?php endif; ?>

  <p class="back"><a href="/soft-appeals">Back to Soft Appeals</a></p>
</main>
</body>
</html>
