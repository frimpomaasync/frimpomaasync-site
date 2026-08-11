<?php
// The funnel numbers, for the owner. The key never appears in this file,
// only its SHA-256 hash, so the public repo does not leak it.
$hash = '268b1c61993ea183f4e378171c549461a982656f8709a687e3901a3bb2861862';
$key = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (!hash_equals($hash, hash('sha256', $key))) { http_response_code(404); exit('Not here.'); }
$file = __DIR__ . '/fs-metrics/events.log';
$days = [];
$totals = [];
if (is_file($file)) {
  foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $c = explode("\t", $line);
    if (count($c) < 2) continue;
    $days[$c[0]][$c[1]] = ($days[$c[0]][$c[1]] ?? 0) + 1;
    $totals[$c[1]] = ($totals[$c[1]] ?? 0) + 1;
  }
}
krsort($days);
$order = ['problem_selected','demo_opened','fit_started','fit_submitted','booking_completed'];
$label = ['problem_selected'=>'Picked a leak','demo_opened'=>'Opened the demo','fit_started'=>'Started the fit form','fit_submitted'=>'Sent the fit form','booking_completed'=>'Booked the call'];
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Funnel · frimpomaasync.com</title>
<style>
body{font-family:ui-serif,'Iowan Old Style',Georgia,serif;background:#F8F8F9;color:#101426;margin:0;padding:28px 16px}
.wrap{max-width:680px;margin:0 auto}h1{font-size:26px;font-weight:600;margin:0 0 4px}
.sub{font-size:13px;color:#6E7280;margin:0 0 24px;font-family:-apple-system,system-ui,sans-serif}
.tw{overflow-x:auto}
table{width:100%;border-collapse:collapse;background:#FFF;border:1px solid #E1E2E6;font-family:-apple-system,system-ui,sans-serif;font-size:14px}
th,td{padding:10px 12px;text-align:right;border-bottom:1px solid #E1E2E6;white-space:nowrap}
th:first-child,td:first-child{text-align:left}
th{font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:#6E7280;background:#F8F8F9}
.tot td{font-weight:700;background:#F8F8F9}
</style></head><body><div class="wrap">
<h1>The funnel<span style="color:#C2501C">.</span></h1>
<p class="sub">Anonymous counts only · date, event, page · nothing about who</p>
<div class="tw"><table><thead><tr><th>Day</th><?php foreach ($order as $e) echo '<th>'.$label[$e].'</th>'; ?></tr></thead><tbody>
<tr class="tot"><td>All time</td><?php foreach ($order as $e) echo '<td>'.($totals[$e] ?? 0).'</td>'; ?></tr>
<?php foreach (array_slice($days,0,60,true) as $d=>$ev) { echo '<tr><td>'.htmlspecialchars($d).'</td>'; foreach ($order as $e) echo '<td>'.($ev[$e] ?? 0).'</td>'; echo '</tr>'; } ?>
</tbody></table></div>
</div></body></html>
