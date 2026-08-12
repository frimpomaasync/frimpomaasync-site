<?php
// Token-checked delivery for gated files. The token comes from lead.php and
// lives for one day. Without it the file cannot be reached at all, because
// the real file sits in /vault behind a deny-all .htaccess.
$files = [
  'blueprint' => ['vault/automated-small-business-blueprint.pdf', 'application/pdf', 'automated-small-business-blueprint.pdf', 'blueprint_downloaded'],
];
$item = isset($_GET['item']) ? (string)$_GET['item'] : '';
$exp  = isset($_GET['exp']) ? (int)$_GET['exp'] : 0;
$t    = isset($_GET['t']) ? (string)$_GET['t'] : '';
$sf = __DIR__ . '/fs-metrics/secret.key';
$ok = isset($files[$item]) && $exp >= time() && $t !== '' && is_file($sf)
   && hash_equals(hash_hmac('sha256', $item . '|' . $exp, trim((string)file_get_contents($sf))), $t);
// A dead or missing token lands on the gate, not on an error.
if (!$ok) { header('Location: /get/' . rawurlencode($item !== '' ? $item : 'blueprint'), true, 302); exit; }
$path = __DIR__ . '/' . $files[$item][0];
if (!is_file($path)) { http_response_code(404); exit('Not here.'); }

// Count the download in the same log the funnel counter uses.
$log = __DIR__ . '/fs-metrics/events.log';
if (!is_file($log) || filesize($log) < 2000000) {
  file_put_contents($log, gmdate('Y-m-d') . "\t" . $files[$item][3] . "\t/download\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: ' . $files[$item][1]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $files[$item][2] . '"');
header('Cache-Control: private, no-store');
readfile($path);
