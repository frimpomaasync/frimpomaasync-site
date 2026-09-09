<?php
// Today's calendar on the phone, for the /day/ planner.
//
// Google Calendar gives every calendar a private iCal address ("Secret address
// in iCal format" under the calendar's settings). She pastes it once into the
// planner; it is stored here under her planner key and never shown again.
// The planner then asks this file for today's events and gets the same shape
// the Claude copy gets from the connector, so one renderer serves both.
//
//   GET    cal.php?k=KEY[&date=YYYY-MM-DD][&tz=Area/City][&refresh=1]
//          -> {"events":[{summary,start:{dateTime|date},end:{...},status}]}
//          -> {"needs":"url"} when no address is saved yet
//   POST   cal.php?k=KEY   body {"url":"https://..."}  -> saves the address
//   POST   cal.php?k=KEY   body {"forget":true}        -> removes it
//
// The feed is cached for 10 minutes. Recurring events are expanded for
// DAILY, WEEKLY (BYDAY), MONTHLY (same day) and YEARLY rules with INTERVAL,
// UNTIL, COUNT and EXDATE, and a RECURRENCE-ID override replaces the
// generated instance. That covers school alarms, standing calls and holidays.

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json');

$key = isset($_GET['k']) ? (string)$_GET['k'] : '';
if (!preg_match('/^[a-f0-9]{32}$/', $key)) { http_response_code(400); exit('{"error":"key"}'); }

$dir = __DIR__ . '/data';
if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "# Planner state and calendar cache. Nothing here is served to a browser.\nRequire all denied\n"); }
$id = hash('sha256', $key);
$urlFile = $dir . '/cal-' . $id . '.url';
$cacheFile = $dir . '/cal-' . $id . '.ics';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
  $b = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
  if (!empty($b['forget'])) { @unlink($urlFile); @unlink($cacheFile); exit('{"ok":true}'); }
  $url = trim((string)($b['url'] ?? ''));
  // webcal:// is what some apps hand out; it is https underneath.
  $url = preg_replace('~^webcal://~i', 'https://', $url);
  if (!preg_match('~^https://[^\s"\'<>]{10,600}$~', $url)) { http_response_code(400); exit('{"error":"url"}'); }
  @file_put_contents($urlFile, $url);
  @unlink($cacheFile);
  exit('{"ok":true}');
}

if (!is_file($urlFile)) { exit('{"needs":"url"}'); }
$url = trim((string)file_get_contents($urlFile));

$tzName = isset($_GET['tz']) && preg_match('~^[A-Za-z_]+/[A-Za-z_\-+0-9]+$~', (string)$_GET['tz']) ? (string)$_GET['tz'] : 'America/New_York';
try { $tz = new DateTimeZone($tzName); } catch (Throwable $e) { $tz = new DateTimeZone('America/New_York'); }
$dateStr = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) ? (string)$_GET['date'] : (new DateTime('now', $tz))->format('Y-m-d');
$dayStart = new DateTime($dateStr . ' 00:00:00', $tz);
$dayEnd = (clone $dayStart)->modify('+1 day');

// ---------- fetch, with a 10 minute cache and a stale fallback ----------
$fresh = is_file($cacheFile) && (time() - filemtime($cacheFile)) < 600 && empty($_GET['refresh']);
$ics = $fresh ? (string)file_get_contents($cacheFile) : '';
if ($ics === '') {
  $body = false;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'frimpomaasync-day/1.0']);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) { $body = false; }
  } else {
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'follow_location' => 1, 'user_agent' => 'frimpomaasync-day/1.0']]);
    $body = @file_get_contents($url, false, $ctx);
  }
  if ($body !== false && stripos($body, 'BEGIN:VCALENDAR') !== false) { @file_put_contents($cacheFile, $body); $ics = $body; }
  elseif (is_file($cacheFile)) { $ics = (string)file_get_contents($cacheFile); }
  else { http_response_code(502); exit('{"error":"fetch"}'); }
}

// ---------- parse ----------
$ics = str_replace(["\r\n", "\r"], "\n", $ics);
$ics = preg_replace("/\n[ \t]/", '', $ics); // unfold continuation lines
$blocks = [];
if (preg_match_all('/BEGIN:VEVENT\n(.*?)\nEND:VEVENT/s', $ics, $m)) { $blocks = $m[1]; }

function ical_unescape(string $s): string { return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $s); }

// Returns ['dt' => DateTime, 'allDay' => bool] or null.
function ical_time(string $raw, array $params, DateTimeZone $tz): ?array {
  $raw = trim($raw);
  if ($raw === '') { return null; }
  $allDay = (($params['VALUE'] ?? '') === 'DATE') || preg_match('/^\d{8}$/', $raw);
  try {
    if ($allDay) { return ['dt' => new DateTime(substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2) . ' 00:00:00', $tz), 'allDay' => true]; }
    $z = substr($raw, -1) === 'Z';
    $core = rtrim($raw, 'Z');
    $zone = $z ? new DateTimeZone('UTC') : $tz;
    if (!$z && !empty($params['TZID'])) { try { $zone = new DateTimeZone($params['TZID']); } catch (Throwable $e) { $zone = $tz; } }
    $dt = DateTime::createFromFormat('Ymd\THis', $core, $zone);
    if (!$dt) { $dt = DateTime::createFromFormat('Ymd\THi', $core, $zone); }
    if (!$dt) { return null; }
    $dt->setTimezone($tz);
    return ['dt' => $dt, 'allDay' => false];
  } catch (Throwable $e) { return null; }
}

function ical_props(string $block): array {
  $out = [];
  foreach (explode("\n", $block) as $line) {
    if (strpos($line, ':') === false) { continue; }
    [$left, $value] = explode(':', $line, 2);
    $parts = explode(';', $left);
    $name = strtoupper(array_shift($parts));
    $params = [];
    foreach ($parts as $p) { if (strpos($p, '=') !== false) { [$pk, $pv] = explode('=', $p, 2); $params[strtoupper($pk)] = trim($pv, '"'); } }
    $out[] = [$name, $params, $value];
  }
  return $out;
}

$events = [];
$overrides = []; // UID|Ymd of the instance replaced by a RECURRENCE-ID row
$generated = []; // rows produced from RRULE, dropped when an override exists

foreach ($blocks as $block) {
  $props = ical_props($block);
  $get = function (string $n) use ($props) { foreach ($props as $p) { if ($p[0] === $n) { return $p; } } return null; };
  $status = strtoupper((string)($get('STATUS')[2] ?? ''));
  if ($status === 'CANCELLED') { continue; }
  $ds = $get('DTSTART'); if (!$ds) { continue; }
  $start = ical_time($ds[2], $ds[1], $tz); if (!$start) { continue; }
  $de = $get('DTEND');
  $end = $de ? ical_time($de[2], $de[1], $tz) : null;
  if (!$end) {
    $dur = $get('DURATION');
    $e = clone $start['dt'];
    if ($dur && preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', trim($dur[2]), $dm)) {
      $e->modify('+' . (int)($dm[1] ?? 0) . ' day +' . (int)($dm[2] ?? 0) . ' hour +' . (int)($dm[3] ?? 0) . ' minute');
    } else { $e->modify($start['allDay'] ? '+1 day' : '+1 hour'); }
    $end = ['dt' => $e, 'allDay' => $start['allDay']];
  }
  $summary = ical_unescape((string)($get('SUMMARY')[2] ?? ''));
  $desc = ical_unescape((string)($get('DESCRIPTION')[2] ?? ''));
  $uid = (string)($get('UID')[2] ?? md5($block));
  $lenSec = max(0, $end['dt']->getTimestamp() - $start['dt']->getTimestamp());
  $allDay = $start['allDay'];

  $emit = function (DateTime $s, bool $fromRule) use (&$events, &$generated, $lenSec, $allDay, $summary, $desc, $status, $uid, $dayStart, $dayEnd, $tz) {
    $e = (clone $s)->modify('+' . $lenSec . ' seconds');
    if ($allDay && $lenSec === 0) { $e = (clone $s)->modify('+1 day'); }
    // overlap with the day
    if ($s >= $dayEnd || $e <= $dayStart) { return; }
    $row = [
      'summary' => $summary,
      'description' => mb_substr($desc, 0, 200),
      'status' => strtolower($status ?: 'confirmed'),
      'start' => $allDay ? ['date' => $s->format('Y-m-d')] : ['dateTime' => $s->format('c')],
      'end' => $allDay ? ['date' => $e->format('Y-m-d')] : ['dateTime' => $e->format('c')],
      'uid' => $uid,
    ];
    if ($fromRule) { $generated[$uid . '|' . $s->format('Ymd')] = $row; } else { $events[] = $row; }
  };

  $rid = $get('RECURRENCE-ID');
  if ($rid) {
    $r = ical_time($rid[2], $rid[1], $tz);
    if ($r) { $overrides[$uid . '|' . $r['dt']->format('Ymd')] = true; }
    $emit($start['dt'], false);
    continue;
  }

  $rr = $get('RRULE');
  if (!$rr) { $emit($start['dt'], false); continue; }

  // ---- recurring: does an instance land on the requested day? ----
  $rule = [];
  foreach (explode(';', $rr[2]) as $kv) { if (strpos($kv, '=') !== false) { [$rk, $rv] = explode('=', $kv, 2); $rule[strtoupper($rk)] = $rv; } }
  $freq = strtoupper($rule['FREQ'] ?? '');
  $interval = max(1, (int)($rule['INTERVAL'] ?? 1));
  $ex = [];
  foreach ($props as $p) { if ($p[0] === 'EXDATE') { foreach (explode(',', $p[2]) as $x) { $t = ical_time($x, $p[1], $tz); if ($t) { $ex[$t['dt']->format('Ymd')] = true; } } } }
  $until = null;
  if (!empty($rule['UNTIL'])) { $u = ical_time($rule['UNTIL'], [], $tz); if ($u) { $until = $u['dt']; } }
  $count = isset($rule['COUNT']) ? (int)$rule['COUNT'] : null;

  $first = $start['dt'];
  $firstDay = new DateTime($first->format('Y-m-d') . ' 00:00:00', $tz);
  // candidate instance on the requested day, at the event's own time
  $cand = new DateTime($dateStr . ' ' . $first->format('H:i:s'), $tz);
  // an instance that started yesterday and runs past midnight also counts
  $candidates = [$cand, (clone $cand)->modify('-1 day')];
  foreach ($candidates as $c) {
    $cDay = new DateTime($c->format('Y-m-d') . ' 00:00:00', $tz);
    if ($cDay < $firstDay) { continue; }
    if ($until && $c > $until) { continue; }
    $daysBetween = (int)$firstDay->diff($cDay)->days;
    $hit = false; $n = null; // n = which instance number this would be (1-based) for COUNT
    if ($freq === 'DAILY') { $hit = $daysBetween % $interval === 0; $n = intdiv($daysBetween, $interval) + 1; }
    elseif ($freq === 'WEEKLY') {
      $map = ['SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6];
      $byday = !empty($rule['BYDAY']) ? array_map(fn($d) => $map[strtoupper(preg_replace('/^[-+]?\d+/', '', $d))] ?? -1, explode(',', $rule['BYDAY'])) : [(int)$first->format('w')];
      $wkst = $map[strtoupper($rule['WKST'] ?? 'MO')] ?? 1;
      $weekOf = function (DateTime $d) use ($wkst) { $w = (int)$d->format('w'); $back = ($w - $wkst + 7) % 7; return (clone $d)->modify('-' . $back . ' day'); };
      $weeks = (int)$weekOf($firstDay)->diff($weekOf($cDay))->days / 7;
      $hit = in_array((int)$c->format('w'), $byday, true) && ((int)$weeks) % $interval === 0;
      if ($hit && $count !== null) {
        // count instances from the first up to this one
        $n = 0; $cur = clone $firstDay;
        while ($cur <= $cDay && $n < 5000) { $wk = (int)$weekOf($firstDay)->diff($weekOf($cur))->days / 7; if (((int)$wk) % $interval === 0 && in_array((int)$cur->format('w'), $byday, true) && $cur >= $firstDay) { $n++; } $cur->modify('+1 day'); }
      }
    }
    elseif ($freq === 'MONTHLY') {
      $hit = (int)$c->format('j') === (int)$first->format('j') && empty($rule['BYDAY']);
      if ($hit) { $months = ((int)$cDay->format('Y') - (int)$firstDay->format('Y')) * 12 + ((int)$cDay->format('n') - (int)$firstDay->format('n')); $hit = $months % $interval === 0; $n = intdiv($months, $interval) + 1; }
    }
    elseif ($freq === 'YEARLY') {
      $hit = $c->format('m-d') === $first->format('m-d');
      if ($hit) { $years = (int)$cDay->format('Y') - (int)$firstDay->format('Y'); $hit = $years % $interval === 0; $n = intdiv($years, $interval) + 1; }
    }
    if (!$hit) { continue; }
    if ($count !== null && $n !== null && $n > $count) { continue; }
    if (isset($ex[$c->format('Ymd')])) { continue; }
    $emit($c, true);
  }
}

foreach ($generated as $k => $row) { if (!isset($overrides[$k])) { $events[] = $row; } }
usort($events, function ($a, $b) {
  $sa = $a['start']['dateTime'] ?? ($a['start']['date'] . 'T00:00:00');
  $sb = $b['start']['dateTime'] ?? ($b['start']['date'] . 'T00:00:00');
  return strcmp($sa, $sb);
});
foreach ($events as &$e) { unset($e['uid']); }
unset($e);

echo json_encode(['events' => $events, 'date' => $dateStr, 'cached' => $fresh]);
