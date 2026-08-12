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
// The free shelf: same log, its own table.
$forder = ['free_gate_opened','free_delivered','blueprint_downloaded','som_opened','free_page_opened','script_copied'];
$flabel = ['free_gate_opened'=>'Opened a gate','free_delivered'=>'Took a tool','blueprint_downloaded'=>'Blueprint PDF','som_opened'=>'Opened Som','free_page_opened'=>'Read a system page','script_copied'=>'Copied the script'];
// Leads: date time, item, name, email. One line per person who took something.
$leads = [];
$lfile = __DIR__ . '/fs-metrics/leads.log';
if (is_file($lfile)) {
  foreach (file($lfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $c = explode("\t", $line);
    if (count($c) >= 4) $leads[] = $c;
  }
}
$leads = array_reverse($leads);
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
<p class="sub">Anonymous counts · date, event, page · leads listed by name at the bottom, because they gave it</p>
<div class="tw"><table><thead><tr><th>Day</th><?php foreach ($order as $e) echo '<th>'.$label[$e].'</th>'; ?></tr></thead><tbody>
<tr class="tot"><td>All time</td><?php foreach ($order as $e) echo '<td>'.($totals[$e] ?? 0).'</td>'; ?></tr>
<?php foreach (array_slice($days,0,60,true) as $d=>$ev) { echo '<tr><td>'.htmlspecialchars($d).'</td>'; foreach ($order as $e) echo '<td>'.($ev[$e] ?? 0).'</td>'; echo '</tr>'; } ?>
</tbody></table></div>

<h1 style="margin-top:36px">The free shelf<span style="color:#C2501C">.</span></h1>
<p class="sub">Gates opened, tools taken, downloads, reads · anonymous counts from the same log</p>
<div class="tw"><table><thead><tr><th>Day</th><?php foreach ($forder as $e) echo '<th>'.$flabel[$e].'</th>'; ?></tr></thead><tbody>
<tr class="tot"><td>All time</td><?php foreach ($forder as $e) echo '<td>'.($totals[$e] ?? 0).'</td>'; ?></tr>
<?php foreach (array_slice($days,0,60,true) as $d=>$ev) { $row=0; foreach ($forder as $e) $row += ($ev[$e] ?? 0); if (!$row) continue; echo '<tr><td>'.htmlspecialchars($d).'</td>'; foreach ($forder as $e) echo '<td>'.($ev[$e] ?? 0).'</td>'; echo '</tr>'; } ?>
</tbody></table></div>

<h1 style="margin-top:36px">Leads<span style="color:#C2501C">.</span></h1>
<p class="sub"><?php echo count($leads); ?> all time · newest first · each one also arrived in your email when it happened</p>
<div class="tw"><table><thead><tr><th>When (UTC)</th><th>Took</th><th style="text-align:left">Name</th><th style="text-align:left">Email</th></tr></thead><tbody>
<?php if (!$leads) echo '<tr><td colspan="4" style="text-align:left">None yet. The moment someone takes a free tool, they appear here.</td></tr>';
foreach (array_slice($leads,0,200) as $L) { echo '<tr><td>'.htmlspecialchars($L[0]).'</td><td>'.htmlspecialchars($L[1]).'</td><td style="text-align:left">'.htmlspecialchars($L[2]).'</td><td style="text-align:left">'.htmlspecialchars($L[3]).'</td></tr>'; } ?>
</tbody></table></div>
</div></body></html>
