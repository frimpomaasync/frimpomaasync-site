<?php
// One-time cleaner: removes the build-session test rows from
// fs-metrics/leads.log. A test row is any lead whose email is the owner's
// own Gmail, which only the wiring tests ever used. Token-gated, run once,
// then removed from the repo (the deploy deletes it from the server).
$hash = 'ebdc56885afab1cb9dbc9242d814d808037cbead0864af9ef23c717c50840888';
$token = (string)($_GET['token'] ?? '');
if (!hash_equals($hash, hash('sha256', $token))) { http_response_code(404); exit('Not here.'); }
header('Content-Type: text/plain; charset=utf-8');
$file = __DIR__ . '/fs-metrics/leads.log';
if (!is_file($file)) { exit("No leads.log on the server. Nothing to clean.\n"); }
$keep = [];
$removed = [];
foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
  $c = explode("\t", $line);
  if (count($c) >= 4 && strtolower(trim($c[3])) === 'nanafrimpgskc@gmail.com') { $removed[] = $line; }
  else { $keep[] = $line; }
}
file_put_contents($file, $keep ? implode("\n", $keep) . "\n" : '', LOCK_EX);
echo 'Removed ' . count($removed) . " test row(s):\n";
foreach ($removed as $r) { echo '  ' . $r . "\n"; }
echo 'Kept ' . count($keep) . " real row(s).\n";
