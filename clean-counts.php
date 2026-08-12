<?php
// One-time cleaner: zeroes the free-shelf event counts from the two build
// days (2026-08-11 and 2026-08-12), which were test clicks from the build
// session. The five original funnel events are left alone on every day,
// so real visitor activity is untouched. Token-gated, run once, then
// removed from the repo (the deploy deletes it from the server).
$hash = '5436a2d3717b8dfb934555502852b4a37babd3bee68355cdb65ac02a0b438dda';
$token = (string)($_GET['token'] ?? '');
if (!hash_equals($hash, hash('sha256', $token))) { http_response_code(404); exit('Not here.'); }
header('Content-Type: text/plain; charset=utf-8');
$file = __DIR__ . '/fs-metrics/events.log';
if (!is_file($file)) { exit("No events.log on the server. Nothing to clean.\n"); }
$days = ['2026-08-11', '2026-08-12'];
$freeEvents = ['free_gate_opened', 'free_delivered', 'blueprint_downloaded', 'som_opened', 'free_page_opened', 'script_copied'];
$keep = [];
$removed = [];
foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
  $c = explode("\t", $line);
  if (count($c) >= 2 && in_array($c[0], $days, true) && in_array($c[1], $freeEvents, true)) { $removed[] = $line; }
  else { $keep[] = $line; }
}
file_put_contents($file, $keep ? implode("\n", $keep) . "\n" : '', LOCK_EX);
echo 'Removed ' . count($removed) . " test count(s):\n";
foreach ($removed as $r) { echo '  ' . $r . "\n"; }
echo 'Kept ' . count($keep) . " line(s), including every original funnel event.\n";
