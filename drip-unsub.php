<?php
// One-click stop for the Blueprint emails. The link in every email carries
// an HMAC of the address, so nobody can unsubscribe someone else by guessing.
// The stop list is one line per address in fs-metrics/drip-unsub.log, and
// the drip job reads it before every send.
require __DIR__ . '/cron/drip-emails.php';

$email = strtolower(trim((string) ($_GET['e'] ?? '')));
$t = (string) ($_GET['t'] ?? '');
$ok = $email !== ''
   && filter_var($email, FILTER_VALIDATE_EMAIL)
   && hash_equals(hash_hmac('sha256', 'unsub|' . $email, drip_secret()), $t);

if ($ok) {
  $f = __DIR__ . '/fs-metrics/drip-unsub.log';
  $already = is_file($f) && strpos((string) file_get_contents($f), "\t" . $email . "\n") !== false;
  if (!$already && (!is_file($f) || filesize($f) < 2000000)) {
    file_put_contents($f, gmdate('Y-m-d H:i') . "\t" . $email . "\n", FILE_APPEND | LOCK_EX);
  }
}

http_response_code($ok ? 200 : 400);
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $ok ? 'Done' : 'That link did not work'; ?> | frimpomaasync.com</title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <style>
    body{font-family:'Iowan Old Style',Palatino,Georgia,serif;background:#F8F8F9;color:#101426;margin:0;display:grid;min-height:100vh;place-items:center}
    .card{background:#FFFFFF;border:1px solid rgba(16,20,38,.10);border-radius:12px;padding:32px 28px;max-width:420px;margin:20px}
    h1{font-size:26px;margin:0 0 10px}
    p{font-size:15px;line-height:1.6;color:rgba(16,20,38,.72);margin:0}
    a{color:#C2501C}
  </style>
</head>
<body>
  <div class="card">
    <?php if ($ok) { ?>
      <h1>Done.</h1>
      <p>No more emails from me. The Blueprint stays yours, and the <a href="/free">free shelf</a> stays open.</p>
    <?php } else { ?>
      <h1>That link did not work.</h1>
      <p>Open the stop link from the email one more time, or write to hello@frimpomaasync.com and I take you off by hand.</p>
    <?php } ?>
  </div>
</body>
</html>
