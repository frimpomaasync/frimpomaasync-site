<?php
// One-time wiring page for the free-shelf sender. Token-gated: without the
// exact token this page is a 404. The owner pastes the notify@ mailbox
// password here once; the page proves it works by sending a real test email,
// then stores it in fs-metrics/smtp.json (a deny-all folder). This file is
// removed from the repo after the wiring is confirmed, which deletes it
// from the server on the next deploy.
$hash = '4908f59674345832cc30c88b3c40abd262b6aa1ae493965ea56326e5d401cebd';
$token = (string)($_REQUEST['token'] ?? '');
if (!hash_equals($hash, hash('sha256', $token))) { http_response_code(404); exit('Not here.'); }
require __DIR__ . '/fs-mail.php';

$state = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass'])) {
  $cfg = ['host' => 'smtp.hostinger.com', 'port' => 465, 'user' => 'notify@frimpomaasync.com', 'pass' => (string)$_POST['pass']];
  if ($cfg['pass'] === '') {
    $state = 'empty';
  } elseif (fs_smtp_send($cfg, 'nanafrimpgskc@gmail.com', 'The gate is wired to notify@', "This is the wiring test for frimpomaasync.com.\n\nFrom now on, every free-shelf lead notification arrives from notify@frimpomaasync.com, sent by your own site through your own mail server.\n\nNothing to do. This email is the proof it works.")) {
    $dir = __DIR__ . '/fs-metrics';
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    file_put_contents($dir . '/smtp.json', json_encode($cfg), LOCK_EX);
    $state = 'ok';
  } else {
    $state = 'fail';
  }
}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Wire notify | frimpomaasync.com</title>
<style>
body{font-family:ui-serif,'Iowan Old Style',Georgia,serif;background:#F8F8F9;color:#101426;margin:0;padding:28px 16px}
.wrap{max-width:520px;margin:0 auto}h1{font-size:26px;font-weight:600;margin:0 0 6px}
p{font-size:15px;line-height:1.6;color:#4A4E5E;font-family:-apple-system,system-ui,sans-serif}
input[type=password]{width:100%;box-sizing:border-box;font-size:16px;padding:12px;border:1px solid #D5D7DD;border-radius:6px;margin:10px 0 14px}
button{background:#101426;color:#FFF;border:none;border-radius:5px;padding:13px 20px;font-size:14px;cursor:pointer}
button:hover{background:#C2501C}
.ok{border-left:3px solid #1B7A3D;padding:12px 14px;background:#FFF}
.bad{border-left:3px solid #C2501C;padding:12px 14px;background:#FFF}
</style></head><body><div class="wrap">
<h1>Wire the gate to notify@<span style="color:#C2501C">.</span></h1>
<?php if ($state === 'ok'): ?>
  <div class="ok"><p><strong>Done.</strong> The test email is on its way to your Gmail from notify@frimpomaasync.com. Every lead notification now sends from your own domain. You can close this page.</p></div>
<?php else: ?>
  <?php if ($state === 'fail'): ?><div class="bad"><p><strong>That password did not work.</strong> The mail server refused it. Check it matches the notify@ mailbox exactly and try again.</p></div><?php endif; ?>
  <?php if ($state === 'empty'): ?><div class="bad"><p><strong>The field was empty.</strong> Paste the notify@ password and press the button.</p></div><?php endif; ?>
  <p>Paste the password you just set for <strong>notify@frimpomaasync.com</strong>. This page tests it by sending one real email, then stores it on your own server. It is never shown anywhere again.</p>
  <form method="post">
    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
    <input type="password" name="pass" autocomplete="new-password" placeholder="The notify@ mailbox password">
    <button type="submit">Test and wire it →</button>
  </form>
<?php endif; ?>
</div></body></html>
