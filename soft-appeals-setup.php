<?php
declare(strict_types=1);

/**
 * First run. Creates the owner admin, once.
 *
 * There is no SSH on this account, so `php database/seeds/fixtures.php` cannot
 * be run to create the first account. This page does that job and then closes
 * behind itself.
 *
 * WHAT KEEPS THIS FROM BEING A BACK DOOR
 * --------------------------------------
 * It exists only while nobody holds the owner_admin role. The moment one does,
 * every request here is answered with the site's ordinary 404 and the form is
 * unreachable for good. There is no flag to flip and no way to reopen it except
 * deleting the account it created.
 *
 * It also refuses to run at all unless the private config file is present, so
 * the window between the first deploy and the config being written is not a
 * window in which a stranger can claim the account. On a fresh deploy nothing
 * here works until she has put the config on the server, and the moment she
 * has, she is the one visiting.
 *
 * Everything else the page does is the same discipline as the rest: a CSRF
 * token, a rate limit, a password minimum, an audit row, and a session
 * rotation once the account exists.
 */

use SoftAppeals\Auth\AuthService;
use SoftAppeals\Domain\Role;
use SoftAppeals\Security\CsrfException;
use SoftAppeals\Security\Headers;
use SoftAppeals\Security\RateLimitException;

$app = require __DIR__ . '/src/SoftAppeals/boot.php';

Headers::send();

// No config on the server means no secrets, and the boot below would fail
// anyway. Answering 404 rather than an error page means a half-deployed site
// gives a stranger nothing at all.
try {
    $app->requireSecrets();
} catch (\Throwable) {
    http_response_code(404);
    exit('Not here.');
}

// A refused database is the difference between "wrong password" and "nothing
// happened", and a 404 here says neither. Off production, name it.
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

try {
    $app->prepareDatabase();
} catch (\Throwable $e) {
    $app->errors()->handle($e);
}

$seeds = $app->seeds();

// The door closes permanently the moment an owner exists.
if (!$seeds->needsOwner()) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><title>Not here.</title>'
        . '<p style="font-family:system-ui;padding:15vh 8vw">Not here. '
        . '<a href="/soft-appeals-login" style="color:#C2501C">Sign in</a>.</p>');
}

$session = $app->session();
$session->start();
$csrf = $app->csrf();
$config = $app->config();

$error = null;
$done = false;

const SA_MIN_PASSWORD = 14;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $csrf->require('setup');

        // The same bucket the login form uses. A setup page is a password-
        // setting endpoint and deserves the same throttle as a
        // password-checking one.
        $app->rateLimiter()->hit('admin.login', 'setup:' . $app->hmac()->ipDigest('login'));

        $email = AuthService::normalizeEmail((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'That does not look like an email address.';
        } elseif (strlen($password) < SA_MIN_PASSWORD) {
            $error = 'Use at least ' . SA_MIN_PASSWORD . ' characters. Longer beats complicated.';
        } elseif ($password !== $confirm) {
            $error = 'The two passwords do not match.';
        } else {
            $db = $app->database();
            $userId = $db->transaction(function () use ($app, $seeds, $email, $password): string {
                // Re-check inside the transaction. Two people submitting this
                // form at the same instant must not both become the owner.
                if (!$seeds->needsOwner()) {
                    throw new RuntimeException('An owner already exists.');
                }
                $existing = $app->users()->findByEmail($email);
                $id = $existing !== null
                    ? (string) $existing['id']
                    : $app->users()->create($email, AuthService::hashPassword($password));
                if ($existing !== null) {
                    $app->users()->updatePasswordHash($id, AuthService::hashPassword($password));
                }
                $app->memberships()->grant($id, Role::OWNER_ADMIN, null);
                return $id;
            });

            $app->audit()->record('setup.owner_created', 'success', 'user', $userId);
            $done = true;
        }
    } catch (CsrfException) {
        $error = 'That form had expired. Reload the page and try again.';
    } catch (RateLimitException $e) {
        $minutes = max(1, (int) ceil($e->retryAfterSeconds / 60));
        $error = 'Too many attempts. Try again in ' . $minutes . ' minute'
            . ($minutes === 1 ? '' : 's') . '.';
    } catch (\Throwable $e) {
        $app->errors()->handle($e);
    }
}

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// What the database looks like right now, so the page can prove it worked
// rather than assert it.
$db = $app->database();
$state = [
    'tables'        => count($app->schema()->appliedNames()),
    'organizations' => $db->tableExists('sa_organizations')
        ? (int) $db->value('SELECT COUNT(*) FROM sa_organizations') : 0,
    'contacts'      => $db->tableExists('sa_contacts')
        ? (int) $db->value('SELECT COUNT(*) FROM sa_contacts') : 0,
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>First run &middot; Soft Appeals</title>
<link rel="icon" href="/favicon.svg">
<style>
  :root{
    --ink:#101426; --copper:#C2501C; --paper:#FFFFFF; --paper2:#F8F8F9;
    --rule:#E1E2E6; --muted:#6E7280; --warn:#8A2B12; --warn-bg:#FBF1EC;
    --ok:#1F6B45; --ok-bg:#EFF5F1;
  }
  *{box-sizing:border-box}
  body{
    margin:0; background:var(--paper2); color:var(--ink);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;
    line-height:1.6; display:flex; align-items:center; justify-content:center;
    min-height:100vh; padding:24px;
  }
  .card{background:var(--paper); border:1px solid var(--rule); max-width:30rem; width:100%; padding:2.25rem 2rem 2rem}
  .eyebrow{
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:.65rem; letter-spacing:.18em; text-transform:uppercase;
    color:var(--muted); margin:0 0 .5rem;
  }
  h1{font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;
     font-size:1.75rem; font-weight:600; margin:0 0 .75rem}
  h1 span{color:var(--copper)}
  p{margin:0 0 1rem}
  .lede{color:var(--muted); font-size:.95rem}
  label{display:block; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
        font-size:.65rem; letter-spacing:.14em; text-transform:uppercase;
        color:var(--muted); margin:0 0 .4rem}
  input{width:100%; font-size:16px; padding:.7rem .8rem; margin:0 0 1.15rem;
        border:1px solid var(--rule); border-radius:0; background:var(--paper);
        color:var(--ink); font-family:inherit}
  input:focus{outline:2px solid var(--copper); outline-offset:1px; border-color:var(--copper)}
  button{width:100%; font-size:.8rem; letter-spacing:.14em; text-transform:uppercase;
         font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
         background:var(--copper); color:#fff; border:0; padding:.85rem 1rem; cursor:pointer}
  button:hover{background:#a8440f}
  button:focus-visible{outline:2px solid var(--ink); outline-offset:2px}
  .error{background:var(--warn-bg); border-left:3px solid var(--warn); color:var(--warn);
         padding:.7rem .9rem; margin:0 0 1.25rem; font-size:.9rem}
  .ok{background:var(--ok-bg); border-left:3px solid var(--ok); color:var(--ok);
      padding:.9rem 1rem; margin:0 0 1.25rem}
  .state{margin:1.5rem 0 0; padding:.85rem 1rem; background:var(--paper2);
         font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
         font-size:.68rem; letter-spacing:.06em; color:var(--muted)}
  .state b{color:var(--ink)}
  .hint{font-size:.82rem; color:var(--muted); margin:-.7rem 0 1.15rem}
  a{color:var(--copper)}
</style>
</head>
<body>
<main class="card">
  <p class="eyebrow">Soft Appeals &middot; <?= $e($config->string('SA_APP_ENV')) ?></p>

<?php if ($done): ?>
  <h1>You are the owner<span>.</span></h1>
  <div class="ok">
    The account exists and the database is ready. Nothing else here will ever
    open again: this page answers 404 from now on.
  </div>
  <p><a href="/soft-appeals-login">Sign in to the Desk &rarr;</a></p>
<?php else: ?>
  <h1>First run<span>.</span></h1>
  <p class="lede">
    The database is built. Pick the account that owns it. This page works once,
    then closes for good.
  </p>

  <?php if ($error !== null): ?>
    <p class="error"><?= $e($error) ?></p>
  <?php endif; ?>

  <form method="post" action="/soft-appeals-setup.php" autocomplete="on">
    <?= $csrf->field('setup') ?>

    <label for="email">Your email</label>
    <input type="email" id="email" name="email" required autocomplete="username"
           autocapitalize="none" autocorrect="off" spellcheck="false"
           value="<?= $e((string) ($_POST['email'] ?? $config->string('SA_OWNER_EMAIL'))) ?>">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required
           autocomplete="new-password" minlength="<?= SA_MIN_PASSWORD ?>">
    <p class="hint">
      <?= SA_MIN_PASSWORD ?> characters or more. Four unrelated words beat one
      clever word with symbols in it.
    </p>

    <label for="password_confirm">Password again</label>
    <input type="password" id="password_confirm" name="password_confirm" required
           autocomplete="new-password" minlength="<?= SA_MIN_PASSWORD ?>">

    <button type="submit">Create the owner account &rarr;</button>
  </form>
<?php endif; ?>

  <div class="state">
    <b><?= (int) $state['tables'] ?></b> migrations applied &middot;
    <b><?= (int) $state['organizations'] ?></b> organizations &middot;
    <b><?= (int) $state['contacts'] ?></b> contacts
    <?php if ($config->bool('SA_DEMO_MODE')): ?>
      <br>Demo mode. Every organization is invented.
    <?php endif; ?>
  </div>
</main>
</body>
</html>
