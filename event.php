<?php
// First-party funnel counter. Counts a short list of anonymous events, nothing else.
// No cookies, no IP stored, no user agent stored, no third party.
// The log line is: date <tab> event <tab> page path. That is the whole record.
header('Content-Type: text/plain');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST only'); }
$raw = file_get_contents('php://input', false, null, 0, 300);
$in = json_decode($raw, true);
$allowed = ['problem_selected','demo_opened','fit_started','fit_submitted','booking_completed',
            'free_gate_opened','free_delivered','free_page_opened','script_copied','som_opened'];
if (!is_array($in) || !isset($in['e']) || !in_array($in['e'], $allowed, true)) { http_response_code(204); exit; }
$path = isset($in['p']) ? substr(preg_replace('/[^a-zA-Z0-9\/\-_#]/', '', (string)$in['p']), 0, 80) : '';
$line = gmdate('Y-m-d') . "\t" . $in['e'] . "\t" . $path . "\n";
$dir = __DIR__ . '/fs-metrics';
if (!is_dir($dir)) { mkdir($dir, 0755, true); }
// Cap the log at about 2 MB so it can never grow without limit.
$file = $dir . '/events.log';
if (is_file($file) && filesize($file) > 2000000) { http_response_code(204); exit; }
file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
http_response_code(204);
