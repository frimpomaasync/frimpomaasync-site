<?php
declare(strict_types=1);

/**
 * The Blueprint drip. Reads the free-shelf lead log, sends the four
 * follow-up emails on days 2, 4, 6 and 8 after someone takes the Blueprint,
 * and remembers what it sent so a second run in the same day finds its
 * work done.
 *
 * CLI only, same rule as soft-appeals-jobs.php. The host's cron screen is
 * the one door:
 *
 *   /usr/bin/php /home/<account>/public_html/cron/blueprint-drip.php run
 *
 * Daily is enough. It sends at most one drip email per lead per run, so a
 * lead can never get two in one day, and it never touches leads that were
 * already in the log before the very first run (the first run seeds them
 * as done, so turning this on never mass-mails the old list).
 *
 * The one decision is the cron line itself; adding it turns the drip on.
 * The kill switch, for a pause without touching cron: fs-metrics/drip.json
 * with {"on": false}. No file means on.
 *
 *   php cron/blueprint-drip.php run       send what is due
 *   php cron/blueprint-drip.php status    counts, switch state, last run
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not here.'); }

$root = dirname(__DIR__);
require $root . '/fs-mail.php';
require __DIR__ . '/drip-emails.php';

const DRIP_STEPS = [
  ['key' => 'missedcall', 'day' => 2],
  ['key' => 'proof',      'day' => 4],
  ['key' => 'ownership',  'day' => 6],
  ['key' => 'door',       'day' => 8],
];

$metrics  = $root . '/fs-metrics';
$leadsLog = $metrics . '/leads.log';
$stateFile = $metrics . '/drip-state.json';
$unsubLog = $metrics . '/drip-unsub.log';
$switchFile = $metrics . '/drip.json';

$cmd = $argv[1] ?? 'status';

$switch = is_file($switchFile) ? json_decode((string) file_get_contents($switchFile), true) : null;
$on = !is_array($switch) || !empty($switch['on']);

$unsubbed = [];
if (is_file($unsubLog)) {
  foreach (file($unsubLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $parts = explode("\t", $line);
    $e = strtolower(trim($parts[1] ?? ''));
    if ($e !== '') { $unsubbed[$e] = true; }
  }
}

// One entry per email address, first blueprint take wins.
$leads = [];
if (is_file($leadsLog)) {
  foreach (file($leadsLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $p = explode("\t", $line);
    if (count($p) < 4 || $p[1] !== 'blueprint') { continue; }
    $email = strtolower(trim($p[3]));
    if ($email === '' || isset($leads[$email])) { continue; }
    $ts = strtotime($p[0] . ' UTC');
    if ($ts === false) { continue; }
    $first = preg_split('/\s+/', trim($p[2]))[0] ?? '';
    $leads[$email] = ['ts' => $ts, 'first' => $first];
  }
}

$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
$fresh = !is_array($state);
if ($fresh) { $state = ['leads' => [], 'last_run' => null]; }

// First run ever: everyone already in the log is marked done, unsent. The
// drip only ever writes to people who arrive after it exists.
if ($fresh) {
  foreach ($leads as $email => $l) {
    $state['leads'][$email] = ['done' => true, 'seeded' => true, 'sent' => []];
  }
  file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
  echo count($leads) . " existing lead(s) seeded as done. Nothing sent, by design.\n";
  if ($cmd !== 'run') { exit; }
}

if ($cmd === 'status') {
  $due = 0; $active = 0;
  foreach ($leads as $email => $l) {
    $s = $state['leads'][$email] ?? ['sent' => []];
    if (!empty($s['done'])) { continue; }
    $active++;
    $days = (int) floor((time() - $l['ts']) / 86400);
    foreach (DRIP_STEPS as $step) {
      if ($days >= $step['day'] && !in_array($step['key'], $s['sent'] ?? [], true)) { $due++; break; }
    }
  }
  echo 'switch: ' . ($on ? 'ON' : 'off') . "\n";
  echo 'blueprint leads: ' . count($leads) . "\n";
  echo 'active in drip: ' . $active . "\n";
  echo 'emails due now: ' . $due . "\n";
  echo 'unsubscribed: ' . count($unsubbed) . "\n";
  echo 'last run: ' . ($state['last_run'] ?? 'never') . "\n";
  exit;
}

if ($cmd !== 'run') { fwrite(STDERR, "Commands: run, status\n"); exit(1); }

if (!$on) { echo "Kill switch is on (fs-metrics/drip.json). Read everything, sent nothing.\n"; exit; }

$cfg = fs_mail_config();
if (!$cfg) { fwrite(STDERR, "No SMTP config (fs-metrics/smtp.json). Sent nothing.\n"); exit(1); }

$sentCount = 0;
foreach ($leads as $email => $l) {
  if (isset($unsubbed[$email])) { continue; }
  if (!isset($state['leads'][$email])) { $state['leads'][$email] = ['sent' => []]; }
  $s = &$state['leads'][$email];
  if (!empty($s['done'])) { continue; }
  $days = (int) floor((time() - $l['ts']) / 86400);
  foreach (DRIP_STEPS as $step) {
    if ($days < $step['day'] || in_array($step['key'], $s['sent'], true)) { continue; }
    $mail = drip_email($step['key'], $l['first'], $email);
    if ($mail && fs_smtp_send($cfg, $email, $mail['subject'], $mail['body'], DRIP_REPLY_TO, '', DRIP_FROM_NAME)) {
      $s['sent'][] = $step['key'];
      $sentCount++;
      echo 'sent ' . $step['key'] . ' to ' . $email . "\n";
    }
    break; // one email per lead per run, never a burst
  }
  if (count($s['sent']) >= count(DRIP_STEPS)) { $s['done'] = true; }
  unset($s);
}

$state['last_run'] = gmdate('Y-m-d H:i') . ' UTC';
file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
echo $sentCount . " email(s) sent.\n";
