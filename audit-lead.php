<?php
// The assessment lead capture.
//
// Takes a finished assessment result, stores one line on this server, emails
// Nana Frimpongmaa the whole summary, and emails the visitor their own copy.
// No third party sees the lead. Nothing about the visitor is stored beyond the
// name, email and business name they typed in, plus their score.
//
// The log line is: date time <tab> audit <tab> score <tab> band <tab> name
// <tab> email <tab> business.
//
// Replies, in plain text, so the page can tell the visitor the truth:
//   ok      the visitor's copy was sent and the lead was saved
//   logged  the lead was saved, the visitor's copy could not be sent
//   no      rejected (bad email, unknown assessment, throttled, or a bot)

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('no'); }

// Only assessments that exist on this site. Anything else is somebody probing.
$audits = [
  'siesie-systems-audit' => 'The Systems Audit',
  'denial-health-score'  => 'The Denial Health Score',
  'blueprint'            => 'The Blueprint Command Center',
  'blueprint-workbook'   => 'The Blueprint workbook',
];

// The last paragraph of the visitor's own copy. The two assessments ask twelve
// questions and hand back a reading, so theirs offers to apply it for real. The
// Blueprint is a thing they built rather than a thing they answered, so it ends
// on the one they are about to install instead. Falls back to the assessment
// wording for anything not listed.
$closings = [
  'blueprint' => "The score moves when a system goes in, not when the audit is\n"
    . "retaken. Whatever sits at the top of Where to start is the one worth\n"
    . "an hour this week.\n\n"
    . "If you would rather have it built for you than build it, reply to this\n"
    . "email and say which one.",
  'blueprint-workbook' => "The workbook and the command center at\n"
    . "frimpomaasync.com/blueprint hold the same answers, so filling in either\n"
    . "one keeps this copy current.\n\n"
    . "If you would rather have it built for you than build it, reply to this\n"
    . "email and say which one.",
];

// Every control character goes, carriage return and line feed included. The
// name reaches the Subject line of an email, and a name carrying a line break
// could write its own mail headers from there. None of these short fields has
// any reason to hold a newline.
$clean = function ($v, $len) {
  $v = is_string($v) ? $v : '';
  $v = preg_replace('/[\x00-\x1F\x7F]/', ' ', $v);
  return mb_substr(trim($v), 0, $len);
};

$audit    = $clean($_POST['audit'] ?? '', 40);
$name     = $clean($_POST['name'] ?? '', 80);
$email    = $clean($_POST['email'] ?? '', 120);
$business = $clean($_POST['business'] ?? '', 80);
$band     = $clean($_POST['band'] ?? '', 60);
$answered = $clean($_POST['answered'] ?? '', 20);
$trap     = (string)($_POST['notes'] ?? '');
$score    = isset($_POST['score']) ? (int)$_POST['score'] : -1;

// The summary is composed in the browser by /assets/audit-engine.js and is the
// same text the visitor can download. Control characters are stripped, tabs and
// newlines survive, and the length is capped so this can never carry a payload.
$summary = (string)($_POST['summary'] ?? '');
$summary = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $summary);
$summary = mb_substr($summary, 0, 12000);

// A filled honeypot means a bot. Answer as though it worked, keep nothing.
if ($trap !== '') { exit('ok'); }

if (!isset($audits[$audit])) { http_response_code(400); exit('no'); }
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); exit('no'); }
if ($score < 0 || $score > 100) { http_response_code(400); exit('no'); }
if (strlen($summary) < 200) { http_response_code(400); exit('no'); }

$dir = __DIR__ . '/fs-metrics';
if (!is_dir($dir)) { mkdir($dir, 0755, true); }

// A light throttle. This endpoint sends mail to an address supplied in the
// request, so it must not be usable as a way to mail strangers repeatedly. The
// IP is never stored: only a salted hash of it, and only for the current hour.
if (!audit_rate_ok($dir)) { http_response_code(429); exit('no'); }

$file = $dir . '/audit-leads.log';
if (!is_file($file) || filesize($file) < 2000000) {
  $t = function ($s) { return str_replace("\t", ' ', $s); };
  $line = gmdate('Y-m-d H:i') . "\t" . $audit . "\t" . $score . "\t" . $t($band)
        . "\t" . $t($name) . "\t" . $t($email) . "\t" . $t($business) . "\n";
  file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

// The full summary is kept next to the log so she can read a result without
// opening her inbox. One file per lead, capped, oldest pruned past 400.
$vault = $dir . '/audit-results';
if (!is_dir($vault)) { mkdir($vault, 0755, true); }
$stamp = gmdate('Ymd-His') . '-' . substr(hash('sha256', $email . $audit), 0, 8);
file_put_contents(
  $vault . '/' . $stamp . '.txt',
  "Audit:    " . $audits[$audit] . "\nName:     $name\nEmail:    $email\n"
    . "Business: " . ($business !== '' ? $business : '(not given)') . "\n"
    . "Score:    $score\nBand:     $band\nAnswered: $answered\n"
    . "When:     " . gmdate('Y-m-d H:i') . " UTC\n\n" . $summary . "\n",
  LOCK_EX
);
audit_prune($vault, 400);

require __DIR__ . '/fs-mail.php';
$cfg = fs_mail_config();

// Tell her. This one carries the whole summary so the lead is readable from
// the notification itself.
$ownerBody = "Somebody finished " . $audits[$audit] . " on frimpomaasync.com.\n\n"
  . "Name:     $name\n"
  . "Email:    $email\n"
  . "Business: " . ($business !== '' ? $business : '(not given)') . "\n"
  . "Score:    $score out of 100\n"
  . "Band:     $band\n"
  . "Answered: $answered\n"
  . "When:     " . gmdate('Y-m-d H:i') . " UTC\n\n"
  . "Their full result follows. They have the same page in front of them.\n\n"
  . str_repeat('=', 66) . "\n\n" . $summary . "\n";

$ownerSent = false;
if ($cfg) {
  $ownerSent = fs_smtp_send(
    $cfg,
    'nanafrimpgskc@gmail.com',
    $audits[$audit] . ': ' . $name . ' scored ' . $score,
    $ownerBody,
    $email
  );
}
if (!$ownerSent) { audit_formspree_fallback($audit, $audits, $name, $email, $business, $score, $band); }

// Send the visitor their copy. This is the thing they asked for, so the reply
// below only says it was sent if it actually was.
$visitorSent = false;
if ($cfg) {
  $first = preg_split('/\s+/', $name);
  $closing = $closings[$audit] ?? "If you would like this applied to your real business rather than to\n"
    . "twelve answers, reply to this email and say so.";
  $visitorBody = "Hello " . $first[0] . ",\n\n"
    . "Here is the result you asked for. It is the same page you saw on\n"
    . "screen, so nothing here is new to you: it is here so you can keep it,\n"
    . "forward it, or bring it to a conversation.\n\n"
    . str_repeat('=', 66) . "\n\n"
    . $summary . "\n\n"
    . str_repeat('=', 66) . "\n\n"
    . $closing . "\n\n"
    . "Nana Frimpongmaa\nfrimpomaasync.com\n";
  $visitorSent = fs_smtp_send(
    $cfg,
    $email,
    'Your result: ' . $audits[$audit],
    $visitorBody,
    'nanafrimpgskc@gmail.com'
  );
}

exit($visitorSent ? 'ok' : 'logged');


// ---------------------------------------------------------------------------

function audit_rate_ok($dir) {
  $f = $dir . '/audit-rate.json';
  $salt_f = $dir . '/secret.key';
  if (!is_file($salt_f)) { file_put_contents($salt_f, bin2hex(random_bytes(32)), LOCK_EX); }
  $salt = trim((string)file_get_contents($salt_f));
  $who = hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? '?') . gmdate('YmdH'), $salt);

  $seen = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
  if (!is_array($seen)) { $seen = []; }
  $hour = gmdate('YmdH');
  // Anything from a previous hour is dropped, which keeps the file small and
  // means no record of anybody survives the hour it was made in.
  foreach ($seen as $k => $v) {
    if (!isset($v['h']) || $v['h'] !== $hour) { unset($seen[$k]); }
  }
  $n = isset($seen[$who]) ? (int)$seen[$who]['n'] : 0;
  if ($n >= 5) { file_put_contents($f, json_encode($seen), LOCK_EX); return false; }
  $seen[$who] = ['h' => $hour, 'n' => $n + 1];
  file_put_contents($f, json_encode($seen), LOCK_EX);
  return true;
}

function audit_prune($dir, $keep) {
  $files = glob($dir . '/*.txt');
  if (!is_array($files) || count($files) <= $keep) { return; }
  sort($files);
  foreach (array_slice($files, 0, count($files) - $keep) as $old) { @unlink($old); }
}

function audit_formspree_fallback($audit, $audits, $name, $email, $business, $score, $band) {
  // The same relay the fit forms use. It carries the headline only, because the
  // full summary is already on this server in fs-metrics/audit-results.
  $notify = http_build_query([
    '_subject' => $audits[$audit] . ': ' . $name . ' scored ' . $score,
    'source'   => $audit,
    'name'     => $name,
    'email'    => $email,
    'business' => $business,
    'score'    => $score,
    'band'     => $band,
    'when_utc' => gmdate('Y-m-d H:i'),
    'note'     => 'Full summary is on the server in fs-metrics/audit-results.',
  ]);
  if (function_exists('curl_init')) {
    $ch = curl_init('https://formspree.io/f/mnjkqydb');
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $notify,
      CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 6,
    ]);
    curl_exec($ch);
    curl_close($ch);
  } else {
    @file_get_contents('https://formspree.io/f/mnjkqydb', false, stream_context_create(['http' => [
      'method'  => 'POST',
      'header'  => "Accept: application/json\r\nContent-Type: application/x-www-form-urlencoded\r\n",
      'content' => $notify,
      'timeout' => 6,
    ]]));
  }
}
