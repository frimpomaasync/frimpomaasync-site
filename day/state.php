<?php
// One list on every device, for the /day/ planner.
//
// The planner page generates a 32-hex key once and keeps it on the device.
// Every device that knows the key reads and writes the same state file here.
// Nothing else identifies her: no login, no email, no cookie. The key never
// enters this repo and never appears in a URL she would share; it travels
// only through the pairing link she opens on her own other phone.
//
//   GET  state.php?k=KEY          -> the saved state as JSON, 404 when none yet
//   GET  state.php?k=KEY&brief=1  -> a plain-text summary for the 4:44 brief
//   POST state.php?k=KEY          -> body is the whole state JSON, saved atomically
//
// Files live in day/data/, which the .htaccess there keeps off the web. A
// brief-KEYHASH.txt sits beside each state file so a same-host cron job can
// read the planner without the key.

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
// The server runs on UTC; her day runs on the East Coast. 'due today' must mean her today.
date_default_timezone_set('America/New_York');

// No key: only "does any list exist here, and how fresh" so the setup can be confirmed from outside.
if (!empty($_GET['ping'])) {
  header('Content-Type: application/json');
  $files = glob(__DIR__ . '/data/state-*.json') ?: [];
  $newest = 0; foreach ($files as $f) { $newest = max($newest, filemtime($f)); }
  exit(json_encode(['lists' => count($files), 'newest_min_ago' => $newest ? intdiv(time() - $newest, 60) : null]));
}

$key = isset($_GET['k']) ? (string)$_GET['k'] : '';
if (!preg_match('/^[a-f0-9]{32}$/', $key)) { http_response_code(400); header('Content-Type: application/json'); exit('{"error":"key"}'); }

$dir = __DIR__ . '/data';
if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "# Planner state and calendar cache. Nothing here is served to a browser.\nRequire all denied\n"); }

$id = hash('sha256', $key);
$stateFile = $dir . '/state-' . $id . '.json';
$briefFile = $dir . '/brief-' . $id . '.txt';

function day_brief(array $s): string {
  $now = time();
  $open = array_values(array_filter($s['tasks'] ?? [], fn($t) => empty($t['done'])));
  $lines = ['Planner, ' . date('D M j', $now)];
  $due = [];
  foreach ($open as $t) {
    if (!empty($t['due'])) {
      $d = strtotime($t['due']);
      if ($d !== false) { $days = (int)floor(($d - strtotime('today')) / 86400); if ($days <= 3) { $due[] = [$days, $t]; } }
    }
  }
  usort($due, fn($a, $b) => $a[0] <=> $b[0]);
  if ($due) {
    $lines[] = 'Hard dates:';
    foreach ($due as [$days, $t]) {
      // PHP 8 refuses an unparenthesized nested ternary, so each branch is wrapped.
      if ($days < 0) { $when = 'overdue by ' . (-$days) . ' day' . ($days === -1 ? '' : 's'); }
      elseif ($days === 0) { $when = 'due today'; }
      elseif ($days === 1) { $when = 'due tomorrow'; }
      else { $when = 'due in ' . $days . ' days'; }
      $lines[] = '  ' . $t['name'] . ' (' . $when . ')';
    }
  }
  $tomorrowPick = null;
  if (!empty($s['tomorrow']['id']) && ($s['tomorrow']['date'] ?? '') === date('Y-m-d', $now)) {
    foreach ($open as $t) { if ($t['id'] === $s['tomorrow']['id']) { $tomorrowPick = $t['name']; } }
  }
  if ($tomorrowPick) { $lines[] = 'Decided last night: ' . $tomorrowPick; }
  $counts = ['alpha' => 0, 'beta' => 0, 'phoenix' => 0];
  foreach ($open as $t) { $l = $t['lane'] ?? 'phoenix'; if (isset($counts[$l])) { $counts[$l]++; } }
  $lines[] = 'Open: ' . $counts['alpha'] . ' full tank, ' . $counts['beta'] . ' half tank, ' . $counts['phoenix'] . ' empty tank';
  $log = $s['log'] ?? [];
  $weekAgo = ($now - 7 * 86400) * 1000;
  $recent = array_values(array_filter($log, fn($l) => ($l['t'] ?? 0) >= $weekAgo));
  if ($recent) {
    $avg = array_sum(array_map(fn($l) => (float)($l['e'] ?? 0), $recent)) / count($recent);
    $wiped = count(array_filter($recent, fn($l) => ($l['d'] ?? '') === 'crash' || ($l['e'] ?? 5) <= 2));
    $lines[] = 'Last 7 days: ' . count($recent) . ' check-ins, average energy ' . number_format($avg, 1) . ', wiped out ' . $wiped;
  }
  $rec = array_values(array_filter($s['receipts'] ?? [], fn($r) => ($r['t'] ?? 0) >= $weekAgo));
  if ($rec) {
    $mins = array_sum(array_map(fn($r) => (int)($r['min'] ?? 0), $rec));
    $lines[] = 'Done this week: ' . round($mins / 60, 1) . ' h across ' . count($rec) . ' tasks';
    $outs = array_values(array_filter($rec, fn($r) => !empty($r['out'])));
    if ($outs) { $lines[] = 'Came out of it:'; foreach (array_slice($outs, -5) as $r) { $lines[] = '  ' . $r['name'] . ': ' . $r['out']; } }
  }
  return implode("\n", $lines) . "\n";
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
  $raw = file_get_contents('php://input', false, null, 0, 600000);
  // {"forget":true} removes everything stored under this key.
  if ($raw !== false && strlen($raw) < 64) { $f = json_decode($raw, true); if (is_array($f) && !empty($f['forget'])) { @unlink($stateFile); @unlink($briefFile); header('Content-Type: application/json'); exit('{"ok":true}'); } }
  if ($raw === false || strlen($raw) >= 600000) { http_response_code(413); header('Content-Type: application/json'); exit('{"error":"size"}'); }
  $s = json_decode($raw, true);
  if (!is_array($s) || !isset($s['tasks']) || !is_array($s['tasks'])) { http_response_code(400); header('Content-Type: application/json'); exit('{"error":"shape"}'); }
  if (empty($s['updated'])) { $s['updated'] = (int)round(microtime(true) * 1000); }
  // Never overwrite a newer save from another device.
  if (is_file($stateFile)) {
    $cur = json_decode((string)file_get_contents($stateFile), true);
    if (is_array($cur) && ($cur['updated'] ?? 0) > $s['updated']) {
      header('Content-Type: application/json');
      http_response_code(409);
      exit(json_encode(['error' => 'stale', 'state' => $cur]));
    }
  }
  $tmp = $stateFile . '.tmp';
  if (@file_put_contents($tmp, json_encode($s)) === false || !@rename($tmp, $stateFile)) { http_response_code(500); header('Content-Type: application/json'); exit('{"error":"write"}'); }
  @file_put_contents($briefFile, day_brief($s));
  header('Content-Type: application/json');
  exit(json_encode(['ok' => true, 'updated' => $s['updated']]));
}

if (!is_file($stateFile)) { http_response_code(404); header('Content-Type: application/json'); exit('{}'); }

if (!empty($_GET['brief'])) {
  header('Content-Type: text/plain; charset=utf-8');
  $s = json_decode((string)file_get_contents($stateFile), true);
  exit(is_array($s) ? day_brief($s) : "Planner: nothing saved yet.\n");
}

header('Content-Type: application/json');
readfile($stateFile);
