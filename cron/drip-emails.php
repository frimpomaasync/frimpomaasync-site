<?php
// The Blueprint email series, in one place. Five emails: the delivery mail
// that lead.php sends the moment someone takes the Blueprint, then four more
// that cron/blueprint-drip.php sends on days 2, 4, 6 and 8. Copy changes
// happen here and nowhere else.
// Included by lead.php (web) and blueprint-drip.php (CLI); never run directly.
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'drip-emails.php') { http_response_code(404); exit('Not here.'); }

const DRIP_REPLY_TO = 'hello@frimpomaasync.com';
const DRIP_FROM_NAME = 'Nana Frimpongmaa';

function drip_secret(): string {
  $f = dirname(__DIR__) . '/fs-metrics/secret.key';
  if (!is_file($f)) { file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX); }
  return trim((string) file_get_contents($f));
}

function drip_unsub_url(string $email): string {
  $t = hash_hmac('sha256', 'unsub|' . strtolower($email), drip_secret());
  return 'https://frimpomaasync.com/drip-unsub.php?e=' . rawurlencode($email) . '&t=' . $t;
}

function drip_footer(string $email): string {
  return "\n\n--\nfrimpomaasync.com · Maryland\nNo more emails? One click and these stop: " . drip_unsub_url($email) . "\n";
}

// $key: welcome | missedcall | proof | ownership | door
// $first: the reader's first name. $email: for the unsubscribe link.
// $extra: ['download' => url] for the welcome mail.
// Returns ['subject' => ..., 'body' => ...] or null for an unknown key.
function drip_email(string $key, string $first, string $email, array $extra = []): ?array {
  $hi = 'hello' . ($first !== '' ? ' ' . $first : '') . ',';
  switch ($key) {
    case 'welcome':
      $dl = (string) ($extra['download'] ?? 'https://frimpomaasync.com/get/blueprint');
      return [
        'subject' => 'Your Blueprint is here',
        'body' =>
          $hi . "\n\n" .
          "Your Blueprint is here. One button, one PDF:\n" . $dl . "\n\n" .
          "That link works for 24 hours. If it expires, the gate takes ten seconds to pass again:\n" .
          "https://frimpomaasync.com/get/blueprint\n\n" .
          "Print it and start with the audit on page one. 12 boxes. A box gets checked only when a system does the task instead of you. Then pick one empty box and fix it this week. Momentum beats a perfect plan.\n\n" .
          "Reply with the box that stayed empty and I'll tell you what I'd fix first.\n\n" .
          "Nana Frimpongmaa\nfrimpomaasync.com" . drip_footer($email),
      ];
    case 'missedcall':
      return [
        'subject' => 'The caller you missed is already dialing the next shop',
        'body' =>
          $hi . "\n\n" .
          "You were up a ladder, under a sink, or with a customer. The call went to voicemail. Most voicemails never turn into jobs.\n\n" .
          "The fix is a fast text back. It works whether a person sends it or a system does. The free version is in your Blueprint: the missed-call text-back script, ready to copy today.\n\n" .
          "The done-for-you version never forgets to send it. Watch a missed call get caught in nine seconds, no account, no form:\n" .
          "https://frimpomaasync.com/synkasa-demo/\n\n" .
          "What do you think... would your caller wait nine seconds?\n\n" .
          "Nana Frimpongmaa\nfrimpomaasync.com" . drip_footer($email),
      ];
    case 'proof':
      return [
        'subject' => 'Watch it work before you pay',
        'body' =>
          $hi . "\n\n" .
          "I don't ask anyone to take my word for it.\n\n" .
          "Every build is mapped first, tested, documented, handed over. It lives in accounts you own. You can log in and see it, and no part of the build sits behind an account I control.\n\n" .
          "The evidence page is open before you ever decide:\n" .
          "https://frimpomaasync.com/results\n\n" .
          "Steal the ideas free. Or have me build it. Live in 7 days, or you don't pay.\n\n" .
          "Nana Frimpongmaa\nfrimpomaasync.com" . drip_footer($email),
      ];
    case 'ownership':
      return [
        'subject' => "Who owns it when it's done?",
        'body' =>
          $hi . "\n\n" .
          "One question is worth asking any builder before you pay: who owns it when they walk away?\n\n" .
          "With me, you do. The whole build sits in accounts you own. You can log in and see it. Care is optional, and when it stops, the build stays in your accounts.\n\n" .
          "If a builder burned you before, check that part first. Mine is written on the method page:\n" .
          "https://frimpomaasync.com/method\n\n" .
          "Nana Frimpongmaa\nfrimpomaasync.com" . drip_footer($email),
      ];
    case 'door':
      return [
        'subject' => 'One week with the Blueprint',
        'body' =>
          $hi . "\n\n" .
          "A week since you took the Blueprint. If one box got fixed, that's momentum... keep going, top to bottom.\n\n" .
          "And if you'd rather hand the phone off completely: SynKasa answers every call, text and DM, qualifies the caller, and books the job. Start is \$555. Live in 7 days, or you don't pay.\n\n" .
          "The fit check is one short form:\n" .
          "https://frimpomaasync.com/synkasa-fit\n\n" .
          "Or book 15 minutes and tell me what's going on:\n" .
          "https://calendar.app.google/DkRJFRA3G6W6d8E48\n\n" .
          "Let me know if anything changes.\n\n" .
          "Nana Frimpongmaa\nfrimpomaasync.com" . drip_footer($email),
      ];
  }
  return null;
}
