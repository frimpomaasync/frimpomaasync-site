<?php
// The free shelf gate. Takes a name, an email, and which free thing was
// asked for. Stores one line on this server, emails the owner, and sends
// the visitor straight on to the delivery page. No third party sees the lead.
// The log line is: date time <tab> item <tab> name <tab> email.
$items = [
  'blueprint' => 'the Blueprint (PDF)',
  'som'       => 'Som, the capture app',
  'intake'    => 'the client intake system',
  'followup'  => 'the follow-up email system',
  'toolkit'   => 'the one-person toolkit',
];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /free'); exit; }
$item  = isset($_POST['item']) ? (string)$_POST['item'] : '';
$name  = isset($_POST['name']) ? trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string)$_POST['name'])) : '';
$email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
$trap  = isset($_POST['notes']) ? (string)$_POST['notes'] : '';
if (!isset($items[$item])) { header('Location: /free'); exit; }
// A filled honeypot means a bot. Pretend it worked, keep nothing, send no mail.
if ($trap !== '') { header('Location: /free-thanks?item=' . rawurlencode($item), true, 303); exit; }
$name  = mb_substr($name, 0, 80);
$email = mb_substr($email, 0, 120);
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  header('Location: /get/' . rawurlencode($item) . '?err=1', true, 303); exit;
}

$dir = __DIR__ . '/fs-metrics';
if (!is_dir($dir)) { mkdir($dir, 0755, true); }
// One line per lead, capped like the event log so it can never grow without limit.
$file = $dir . '/leads.log';
if (!is_file($file) || filesize($file) < 2000000) {
  $line = gmdate('Y-m-d H:i') . "\t" . $item . "\t" . str_replace("\t", ' ', $name) . "\t" . str_replace("\t", ' ', $email) . "\n";
  file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

// Tell the owner. If the mail ever fails, the lead is still in the log above
// and on the stats page, so nothing is lost.
$subject = 'Free shelf: ' . $name . ' took ' . $items[$item];
$body = "Someone just took a free tool on frimpomaasync.com.\n\n"
      . 'Name:  ' . $name . "\n"
      . 'Email: ' . $email . "\n"
      . 'Took:  ' . $items[$item] . "\n"
      . 'When:  ' . gmdate('Y-m-d H:i') . " UTC\n\n"
      . 'Every lead also sits in the Leads table on the stats page.';
$headers = "From: frimpomaasync.com <noreply@frimpomaasync.com>\r\n"
         . 'Reply-To: ' . $email . "\r\n" // validated above, cannot carry header breaks
         . "Content-Type: text/plain; charset=UTF-8\r\n";
@mail('nanafrimpgskc@gmail.com', $subject, $body, $headers);

// A one-day token unlocks the real file on the delivery page. The secret is
// created on the server the first time it is needed; it never sits in the repo.
$exp = time() + 86400;
$t = hash_hmac('sha256', $item . '|' . $exp, fs_gate_secret());
$parts = preg_split('/\s+/', $name);
header('Location: /free-thanks?item=' . rawurlencode($item) . '&n=' . rawurlencode($parts[0]) . '&exp=' . $exp . '&t=' . $t, true, 303);
exit;

function fs_gate_secret() {
  $f = __DIR__ . '/fs-metrics/secret.key';
  if (!is_file($f)) { file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX); }
  return trim((string)file_get_contents($f));
}
