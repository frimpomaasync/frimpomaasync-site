<?php
// The Soft Appeals lead capture.
//
// Every Soft Appeals form used to post straight to the form relay, which
// notified Nana Frimpongmaa and sent the person who filled the form nothing at
// all. They landed on a thanks page that told them not to reply with patient
// information to a confirmation message that never arrived.
//
// This endpoint replaces that. It takes the submission, stores one line and one
// full copy on this server, emails Nana Frimpongmaa everything, and emails the
// person their own confirmation with their answers echoed back. The relay stays
// on as a fallback for the owner notification, so a lead is never lost even if
// the mail server is down.
//
// Same shape as audit-lead.php, which does this for the two assessments.
//
// Replies, in plain text, so the page can tell the person the truth:
//   ok      the confirmation was sent and the lead was saved
//   logged  the lead was saved, the confirmation could not be sent
//   no      rejected (bad email, unknown form, throttled, or a bot)
//
// A submission that arrives without Accept: application/json is a browser with
// scripts off posting the form natively. That one gets a redirect to the thanks
// page instead of a word of plain text.

header('X-Content-Type-Options: nosniff');

$wantsJson = strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
if (!$wantsJson) { header('Content-Type: text/html; charset=utf-8'); }
else { header('Content-Type: text/plain; charset=utf-8'); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('no'); }

// ---------------------------------------------------------------------------
// The four forms, and the labels the person actually saw on screen.
//
// The confirmation echoes their answers back using these labels and in this
// order. That is the whole point of the echo: they get a record of what they
// sent, so their next email is about the claims and not about what they think
// they told me. A field missing from this list is never emailed, which also
// means a field somebody invents and posts by hand goes nowhere.
// ---------------------------------------------------------------------------
$FORMS = [
  'soft-appeals-maryland' => [
    'owner'   => 'Maryland denial review request',
    'subject' => 'Your denial review request is in',
    'thanks'  => '/soft-appeals-start-thanks',
    'fields'  => [
      'organization'     => 'Practice or organization',
      'name'             => 'Your name',
      'email'            => 'Work email',
      'role'             => 'Your role',
      'state'            => 'State',
      'practice_type'    => 'Practice type',
      'clinicians'       => 'Clinicians',
      'denial_age'       => 'Age of the denials',
      'current_handling' => 'Handled today',
      'carelon_audit'    => 'Carelon interest check',
    ],
  ],
  'soft-appeals-start' => [
    'owner'   => 'Denial review request',
    'subject' => 'Your denial review request is in',
    'thanks'  => '/soft-appeals-start-thanks',
    'fields'  => [
      'organization'      => 'Organization',
      'name'              => 'Your name',
      'email'             => 'Work email',
      'role'              => 'Your role',
      'organization_type' => 'Organization type',
      'state'             => 'State',
      'denial_volume'     => 'Denied claims unresolved',
      'denied_value'      => 'Value involved',
      'current_handling'  => 'Handled today',
      'billing_company'   => 'Billing company',
      'payers'            => 'Payers involved',
      'denial_types'      => 'Types of denials',
      'denial_outcomes'   => 'What happens to them',
      'denial_age'        => 'Age of the denials',
      'time_sensitive'    => 'Time-sensitive',
      'goals'             => 'What would make this useful',
      'context'           => 'Anything else',
    ],
  ],
  'soft-appeals-contact' => [
    'owner'   => 'Question',
    'subject' => 'Your question is in',
    'thanks'  => '/soft-appeals-contact-thanks',
    'fields'  => [
      'inquiry_type' => 'What you asked about',
      'name'         => 'Your name',
      'email'        => 'Work email',
      'organization' => 'Organization',
      'role'         => 'Your role',
      'topics'       => 'Topics',
      'question'     => 'Your question',
    ],
  ],
  'soft-appeals-due-diligence' => [
    'owner'   => 'Vendor due-diligence request',
    'subject' => 'Your due diligence request is in',
    'thanks'  => '/soft-appeals-contact-thanks',
    'fields'  => [
      'name'         => 'Your name',
      'email'        => 'Work email',
      'organization' => 'Organization',
      'requester'    => 'Your role in the review',
      'requested'    => 'Requested',
      'requirements' => 'Your requirements',
    ],
  ],
];

// Every control character goes from the short fields, carriage return and line
// feed included. A name reaches the Subject line of an email, and a name
// carrying a line break could write its own mail headers from there.
$clean = function ($v, $len) {
  $v = is_string($v) ? $v : '';
  $v = preg_replace('/[\x00-\x1F\x7F]/', ' ', $v);
  return mb_substr(trim($v), 0, $len);
};

// The long fields keep their newlines, because a person typed paragraphs into
// them. Everything else that cannot be typed goes.
$cleanLong = function ($v, $len) {
  $v = is_string($v) ? $v : '';
  $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v);
  return mb_substr(trim($v), 0, $len);
};

$source = $clean($_POST['source'] ?? '', 60);
if (!isset($FORMS[$source])) { http_response_code(400); exit('no'); }
$form = $FORMS[$source];

// A filled honeypot means a bot. Answer as though it worked, keep nothing.
$trap = (string)($_POST['company_website'] ?? '') . (string)($_POST['notes'] ?? '');
if (trim($trap) !== '') {
  if ($wantsJson) { exit('ok'); }
  header('Location: ' . $form['thanks'], true, 303);
  exit;
}

$LONG = ['question', 'requirements', 'context', 'current_handling'];

$answers = [];
foreach ($form['fields'] as $key => $label) {
  $raw = $_POST[$key] ?? ($_POST[$key . '[]'] ?? null);
  if (is_array($raw)) {
    $parts = [];
    foreach (array_slice($raw, 0, 30) as $one) {
      $one = $clean($one, 120);
      if ($one !== '') { $parts[] = $one; }
    }
    $value = implode(', ', $parts);
  } elseif (in_array($key, $LONG, true)) {
    $value = $cleanLong($raw, 4000);
  } else {
    $value = $clean($raw, 200);
  }
  if ($value !== '') { $answers[$key] = $value; }
}

$name  = $answers['name'] ?? '';
$email = $answers['email'] ?? '';
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); exit('no'); }

$organization = $answers['organization'] ?? '';

$dir = __DIR__ . '/fs-metrics';
if (!is_dir($dir)) { mkdir($dir, 0755, true); }

// A light throttle. This endpoint sends mail to an address supplied in the
// request, so it must not be usable as a way to mail strangers repeatedly. The
// IP is never stored: only a salted hash of it, and only for the current hour.
if (!sa_rate_ok($dir)) { http_response_code(429); exit('no'); }

// ---------------------------------------------------------------------------
// Keep it. One line to skim, one file to read.
// ---------------------------------------------------------------------------
$whenUtc = gmdate('Y-m-d H:i');

$file = $dir . '/sa-leads.log';
if (!is_file($file) || filesize($file) < 2000000) {
  $t = function ($s) { return str_replace("\t", ' ', $s); };
  file_put_contents(
    $file,
    $whenUtc . "\t" . $source . "\t" . $t($name) . "\t" . $t($email) . "\t" . $t($organization) . "\n",
    FILE_APPEND | LOCK_EX
  );
}

$full = '';
foreach ($form['fields'] as $key => $label) {
  if (!isset($answers[$key])) { continue; }
  $full .= $label . ': ' . $answers[$key] . "\n";
}

$vault = $dir . '/sa-leads';
if (!is_dir($vault)) { mkdir($vault, 0755, true); }
$stamp = gmdate('Ymd-His') . '-' . substr(hash('sha256', $email . $source), 0, 8);
file_put_contents(
  $vault . '/' . $stamp . '.txt',
  "Form:  " . $form['owner'] . "\nWhen:  " . $whenUtc . " UTC\n\n" . $full,
  LOCK_EX
);
sa_prune($vault, 400);

require __DIR__ . '/fs-mail.php';
$cfg = fs_mail_config();

// ---------------------------------------------------------------------------
// Tell her. Everything they typed, in the order they typed it.
// ---------------------------------------------------------------------------
$ownerSubject = $form['owner'] . ': ' . $name . ($organization !== '' ? ' at ' . $organization : '');
$ownerBody = "Somebody filled a Soft Appeals form on frimpomaasync.com.\n\n"
  . "Form:  " . $form['owner'] . "\n"
  . "When:  " . $whenUtc . " UTC\n\n"
  . str_repeat('=', 66) . "\n\n"
  . $full . "\n"
  . str_repeat('=', 66) . "\n\n"
  . "They have their own copy of this, with the same answers, sent to "
  . $email . ".\nReply to this email to reach them.\n";

$ownerSent = false;
if ($cfg) {
  $ownerSent = fs_smtp_send($cfg, 'nanafrimpgskc@gmail.com', $ownerSubject, $ownerBody, $email);
}
if (!$ownerSent) { sa_relay_fallback($ownerSubject, $source, $form, $answers, $whenUtc); }

// ---------------------------------------------------------------------------
// Send them their confirmation. This is the thing that did not exist.
// ---------------------------------------------------------------------------
$visitorSent = false;
if ($cfg) {
  $visitorSent = fs_smtp_send(
    $cfg,
    $email,
    $form['subject'],
    sa_confirmation($source, $form, $name, $answers, $full),
    'hello@frimpomaasync.com'
  );
}

if (!$wantsJson) {
  header('Location: ' . $form['thanks'], true, 303);
  exit;
}
exit($visitorSent ? 'ok' : 'logged');


// ---------------------------------------------------------------------------

function sa_first_name($name) {
  $parts = preg_split('/\s+/', trim($name));
  return $parts[0] !== '' ? $parts[0] : $name;
}

// Eastern time, because the practices are in Maryland and a person reading
// "2:41pm" wants their own clock, not UTC.
function sa_when_local() {
  $tz = new DateTimeZone('America/New_York');
  $now = new DateTime('now', $tz);
  return strtolower($now->format('g:ia')) . ' on ' . $now->format('j F');
}

function sa_has($answers, $key, $needle) {
  return isset($answers[$key]) && stripos($answers[$key], $needle) !== false;
}

// The confirmation. Plain text, no HTML, no images, nothing loaded from
// anywhere. Every one of the four ends on a line that is true for the person
// who filled that form and not for the next person, which is the difference
// between correspondence and a receipt.
function sa_confirmation($source, $form, $name, $answers, $full) {
  $hello = "Hello " . sa_first_name($name) . ",\n\n";
  $when  = sa_when_local();

  $phi = "Keep patient information out of email, including a reply to this one. "
       . "No records, no denial letters, no explanation of benefits, no screenshots. "
       . "The secure way to send claim information is its own step, after the "
       . "paperwork that has to come first.\n\n";

  $sig = "Nana Frimpongmaa\nfrimpomaasync.com/soft-appeals\n";

  $sent = "What you sent:\n\n" . $full . "\n";

  if ($source === 'soft-appeals-maryland') {
    $open = "Your request came through at " . $when . ".\n\n"
      . "I read these myself, so the reply comes from me and not from a system. "
      . "Within one business day you get either one business-level question, or "
      . "the first step.\n\n";

    if (sa_has($answers, 'carelon_audit', 'Yes')) {
      $close = "You ticked the Carelon interest check. That is the first thing I "
        . "will look at, because an audit and a pile of denials do not move at the "
        . "same speed, and the order matters.\n\n";
    } elseif (isset($answers['state']) && $answers['state'] !== 'Maryland') {
      $close = "You are outside Maryland. The reply will be straight with you about "
        . "whether this is worth your time, because my method is built on Maryland "
        . "statute and I will not guess with your claims.\n\n";
    } elseif (sa_has($answers, 'practice_type', 'Dental')) {
      $close = "Dental work is priced on a fixed fee rather than a percentage, "
        . "because Maryland's dental practice statute requires it. The reply says "
        . "what that looks like for your practice. The review of your first twenty "
        . "denials is free either way.\n\n";
    } elseif (sa_has($answers, 'clinicians', 'Just me')) {
      $close = "You said it is just you. I will still run the review and tell you "
        . "what I find, including if the answer is that you do not need me.\n\n";
    } else {
      $close = "Nothing is needed from you while you wait.\n\n";
    }

    $close .= "If you want something to read, the denial code decoder is free and "
      . "runs in your browser:\n\nhttps://frimpomaasync.com/soft-appeals-decoder\n\n";

    return $hello . $open . $phi . $sent . $close . $sig;
  }

  if ($source === 'soft-appeals-start') {
    // Their name, email and organization are not answers, they are who they are.
    $count = count(array_diff_key($answers, array_flip(['name', 'email', 'organization'])));
    $open = $count . " answers came through at " . $when . ". That is more than most "
      . "people give me, and it means the reply can be about your denials instead of "
      . "about denials in general.\n\n"
      . "Within one business day you get a reply from me. It either asks one "
      . "business-level question, or it says the review looks like a fit and tells "
      . "you the first step.\n\n";

    if (sa_has($answers, 'time_sensitive', 'approaching deadlines')) {
      $close = "The deadline answer is the one I look at first. If some of those "
        . "claims are close to a filing or appeal cutoff, that changes the order "
        . "everything gets worked in, and it is the part of this that does not "
        . "wait.\n\n";
    } elseif (sa_has($answers, 'time_sensitive', 'not checked')) {
      $close = "You said nobody has checked whether any of these are close to a "
        . "deadline. That is the first thing the review checks, because it is the "
        . "part that does not wait.\n\n";
    } elseif (sa_has($answers, 'billing_company', 'Yes')) {
      $close = "You already work with a billing company. The reply says plainly "
        . "where this sits on top of what she does, and where it would only get in "
        . "the way.\n\n";
    } else {
      $close = "The reply starts with which of these claims deserve attention "
        . "first, because that is the answer that changes what you do on Monday.\n\n";
    }

    return $hello . $open . $phi . $sent . $close . $sig;
  }

  if ($source === 'soft-appeals-contact') {
    $open = "Your question came through at " . $when . ". You get an answer within "
      . "one business day, and where the honest answer is that it depends on the "
      . "claim, it will say that instead of dressing it up.\n\n";

    if (sa_has($answers, 'topics', 'Pricing')) {
      $close = "Short answer on money before the real one: the fee is calculated on "
        . "what actually comes back, so a claim that was already recovered is not "
        . "one you pay for. The full answer, with your own situation in it, comes "
        . "with the reply.\n\n";
    } elseif (sa_has($answers, 'topics', 'Business Associate Agreement')
           || sa_has($answers, 'topics', 'Privacy and security')
           || sa_has($answers, 'topics', 'Vendor due diligence')) {
      $close = "Some of that is already published and written to be forwarded "
        . "rather than summarized second hand:\n\n"
        . "https://frimpomaasync.com/soft-appeals-trust-room\n"
        . "https://frimpomaasync.com/soft-appeals-data-security\n\n";
    } elseif (sa_has($answers, 'topics', 'Free denial review')) {
      $close = "If the answer turns out to be yes, the next step is the free review "
        . "of your first twenty denials, and that happens before any agreement and "
        . "before any money.\n\n";
    } else {
      $close = "Your question gets read against what your organization actually has "
        . "to decide, not against what is easy to answer.\n\n";
    }

    return $hello . $open . $sent . $close . $phi . $sig;
  }

  // Vendor due diligence.
  $open = "Your review request came through at " . $when . ". What your team asked "
    . "for comes back within one business day, in writing, in a form you can "
    . "forward to whoever signs off on it.\n\n";

  $close = "Most of it is already published and written to be forwarded rather than "
    . "summarized second hand:\n\n"
    . "https://frimpomaasync.com/soft-appeals-trust-room\n"
    . "https://frimpomaasync.com/soft-appeals-data-security\n\n"
    . "Where something does not exist yet, the reply says so plainly. That is more "
    . "useful to you than a document written the week you asked for it.\n\n";

  return $hello . $open . $sent . $close . $sig;
}

function sa_rate_ok($dir) {
  $f = $dir . '/sa-rate.json';
  $salt_f = $dir . '/secret.key';
  if (!is_file($salt_f)) { file_put_contents($salt_f, bin2hex(random_bytes(32)), LOCK_EX); }
  $salt = trim((string)file_get_contents($salt_f));
  $who = hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? '?') . gmdate('YmdH'), $salt);

  $seen = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
  if (!is_array($seen)) { $seen = []; }
  $hour = gmdate('YmdH');
  foreach ($seen as $k => $v) {
    if (!isset($v['h']) || $v['h'] !== $hour) { unset($seen[$k]); }
  }
  $n = isset($seen[$who]) ? (int)$seen[$who]['n'] : 0;
  if ($n >= 5) { file_put_contents($f, json_encode($seen), LOCK_EX); return false; }
  $seen[$who] = ['h' => $hour, 'n' => $n + 1];
  file_put_contents($f, json_encode($seen), LOCK_EX);
  return true;
}

function sa_prune($dir, $keep) {
  $files = glob($dir . '/*.txt');
  if (!is_array($files) || count($files) <= $keep) { return; }
  sort($files);
  foreach (array_slice($files, 0, count($files) - $keep) as $old) { @unlink($old); }
}

// The same relay every form used before this endpoint existed. It only runs when
// the mail server refused, and it carries the headline plus a pointer, because
// the full submission is already on this server in fs-metrics/sa-leads.
function sa_relay_fallback($subject, $source, $form, $answers, $whenUtc) {
  $payload = [
    '_subject' => $subject,
    'source'   => $source,
    'form'     => $form['owner'],
    'when_utc' => $whenUtc,
    'note'     => 'Mail server refused. The full submission is on the server in fs-metrics/sa-leads.',
  ];
  foreach (['name', 'email', 'organization'] as $k) {
    if (isset($answers[$k])) { $payload[$k] = $answers[$k]; }
  }
  if (!function_exists('curl_init')) { return; }
  $ch = curl_init('https://formspree.io/f/mnjkqydb');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($payload),
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 6,
  ]);
  curl_exec($ch);
  curl_close($ch);
}
