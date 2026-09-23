<?php
// The Blueprint email series, in one place. Five emails: the delivery mail
// that lead.php sends the moment someone takes the Blueprint, then four more
// that cron/blueprint-drip.php sends on days 2, 4, 6 and 8. Copy changes
// happen here and nowhere else.
//
// Each email ships as multipart/alternative: a plain-text body and an HTML
// body styled like the site (ink #101426, copper #C2501C, paper, the serif
// stack, mono eyebrows). System fonts only; no images, no remote requests.
// Included by lead.php (web) and blueprint-drip.php (CLI); never run directly.
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'drip-emails.php') { http_response_code(404); exit('Not here.'); }

const DRIP_REPLY_TO = 'hello@frimpomaasync.com';
const DRIP_FROM_NAME = 'Nana Frimpongmaa';

// The four-message text-back script, word for word from /free. The email
// carries it so the reader can act the same day without clicking anything.
const DRIP_SCRIPT_LINES = [
  '1 · "Hi, you\'ve reached [your business]. Sorry we missed you. What do you need?"',
  '2 · "Got it. What area are you in, and how soon do you need it?"',
  '3 · "We can do [day] at [time] or [day] at [time]. Which works?"',
  '4 · "Booked. You\'ll get a reminder the night before. Reply here anytime."',
];

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

// Renders one email in the site's look. $b keys:
//   eyebrow  string, the mono line above the heading
//   heading  string, may hold <em> for the copper italic word
//   paras    list of paragraphs (plain text, already safe)
//   steps    optional list of numbered lines (mono copper numbers, like the site)
//   script   optional list of quoted script lines in a paper box
//   button   optional ['label' => ..., 'href' => ...]
//   after    optional list of paragraphs under the button
function drip_render(array $b, string $email): string {
  $serif = "'Iowan Old Style',Palatino,'Palatino Linotype',Georgia,serif";
  $mono = "ui-monospace,'SF Mono',Menlo,Consolas,monospace";
  $h = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  $out = '<!doctype html><html><body style="margin:0;padding:0;background:#F8F8F9;">'
       . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F8F8F9;"><tr><td align="center" style="padding:28px 14px;">'
       . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">'
       . '<tr><td style="background:#FFFFFF;border:1px solid rgba(16,20,38,0.10);border-radius:12px;padding:34px 30px;">'
       . '<div style="font-family:' . $mono . ';font-size:11px;letter-spacing:0.16em;text-transform:uppercase;color:#C2501C;padding-bottom:14px;">' . $h($b['eyebrow']) . '</div>'
       . '<h1 style="margin:0 0 16px;font-family:' . $serif . ';font-size:26px;line-height:1.25;font-weight:600;color:#101426;">' . str_replace(['&lt;em&gt;', '&lt;/em&gt;'], ['<em style="color:#C2501C;">', '</em>'], $h($b['heading'])) . '</h1>';
  foreach ($b['paras'] as $p) {
    $out .= '<p style="margin:0 0 14px;font-family:' . $serif . ';font-size:15.5px;line-height:1.6;color:rgba(16,20,38,0.78);">' . $h($p) . '</p>';
  }
  if (!empty($b['steps'])) {
    $out .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 14px;">';
    foreach ($b['steps'] as $i => $s) {
      $out .= '<tr><td valign="top" style="font-family:' . $mono . ';font-size:11px;letter-spacing:0.1em;color:#C2501C;padding:6px 12px 0 0;">0' . ($i + 1) . '</td>'
            . '<td style="font-family:' . $serif . ';font-size:15px;line-height:1.55;color:rgba(16,20,38,0.78);padding-top:4px;">' . $h($s) . '</td></tr>';
    }
    $out .= '</table>';
  }
  if (!empty($b['script'])) {
    $out .= '<div style="background:#F8F8F9;border:1px solid rgba(16,20,38,0.10);border-radius:8px;padding:16px 18px;margin:6px 0 16px;">';
    foreach ($b['script'] as $line) {
      $out .= '<p style="margin:0 0 10px;font-family:' . $serif . ';font-size:14.5px;line-height:1.55;color:#101426;">' . $h($line) . '</p>';
    }
    $out .= '</div>';
  }
  if (!empty($b['button'])) {
    $out .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 6px;"><tr><td style="background:#101426;border-radius:5px;">'
          . '<a href="' . $h($b['button']['href']) . '" style="display:inline-block;padding:13px 20px;font-family:' . $serif . ';font-size:14px;color:#FFFFFF;text-decoration:none;">' . $h($b['button']['label']) . ' &#8594;</a>'
          . '</td></tr></table>';
  }
  foreach ($b['after'] ?? [] as $p) {
    $out .= '<p style="margin:14px 0 0;font-family:' . $serif . ';font-size:15px;line-height:1.6;color:rgba(16,20,38,0.78);">' . $h($p) . '</p>';
  }
  $out .= '<p style="margin:24px 0 0;font-family:' . $serif . ';font-size:15px;line-height:1.6;color:#101426;">Nana Frimpongmaa<br>'
        . '<a href="https://frimpomaasync.com" style="color:#C2501C;text-decoration:none;">frimpomaasync.com</a></p>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 8px 0;font-family:' . $serif . ';font-size:12.5px;line-height:1.6;color:rgba(16,20,38,0.55);" align="center">frimpomaasync.com · Maryland<br>'
        . 'No more emails? <a href="' . $h(drip_unsub_url($email)) . '" style="color:rgba(16,20,38,0.55);">One click and these stop.</a></td></tr>'
        . '</table></td></tr></table></body></html>';
  return $out;
}

// $key: welcome | missedcall | proof | ownership | door
// $first: the reader's first name. $email: for the unsubscribe link.
// $extra: ['download' => url] for the welcome mail.
// Returns ['subject' => ..., 'body' => ..., 'html' => ...] or null for an unknown key.
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
          "Do this today:\n" .
          "1. Print it, or open it on the big screen.\n" .
          "2. Start with the audit on page one. 12 boxes. A box gets checked only when a system does the task instead of you.\n" .
          "3. Pick one empty box and fix it this week. Momentum beats a perfect plan.\n\n" .
          "Reply with the box that stayed empty and I'll tell you what I'd fix first.\n\n" .
          "Nana Frimpongmaa\nfrimpomaasync.com" . drip_footer($email),
        'html' => drip_render([
          'eyebrow' => 'The Blueprint · delivered',
          'heading' => 'Your Blueprint is <em>here.</em>',
          'paras' => [
            $hi,
            'One button, one PDF. The link works for 24 hours; if it expires, the gate takes ten seconds to pass again.',
            'Do this today:',
          ],
          'steps' => [
            'Print it, or open it on the big screen.',
            'Start with the audit on page one. 12 boxes. A box gets checked only when a system does the task instead of you.',
            'Pick one empty box and fix it this week. Momentum beats a perfect plan.',
          ],
          'button' => ['label' => 'Download the Blueprint (PDF)', 'href' => $dl],
          'after' => ["Reply with the box that stayed empty and I'll tell you what I'd fix first."],
        ], $email),
      ];
    case 'missedcall':
      $scriptText = implode("\n\n", DRIP_SCRIPT_LINES);
      return [
        'subject' => 'The caller you missed is already dialing the next shop',
        'body' =>
          $hi . "\n\n" .
          "You were up a ladder, under a sink, or with a customer. The call went to voicemail. Most voicemails never turn into jobs.\n\n" .
          "The fix is a fast text back. It works whether a person sends it or a system does. Here is the four-message script, ready to save in your notes today. Replace the brackets with your business, your area, and two real times.\n\n" .
          $scriptText . "\n\n" .
          "If nobody books after the first text, call within the hour.\n\n" .
          "The done-for-you version never forgets to send it. Watch a missed call get caught in nine seconds, no account, no form:\n" .
          "https://frimpomaasync.com/synkasa-demo/\n\n" .
          "What do you think... would your caller wait nine seconds?\n\n" .
          "Nana Frimpongmaa\nfrimpomaasync.com" . drip_footer($email),
        'html' => drip_render([
          'eyebrow' => 'Day 2 · the missed call',
          'heading' => 'The caller you missed is already dialing the <em>next shop.</em>',
          'paras' => [
            $hi,
            'You were up a ladder, under a sink, or with a customer. The call went to voicemail. Most voicemails never turn into jobs.',
            'The fix is a fast text back. It works whether a person sends it or a system does. Here is the four-message script, ready to save in your notes today. Replace the brackets with your business, your area, and two real times.',
          ],
          'script' => DRIP_SCRIPT_LINES,
          'button' => ['label' => 'Watch a missed call get caught in nine seconds', 'href' => 'https://frimpomaasync.com/synkasa-demo/'],
          'after' => [
            'If nobody books after the first text, call within the hour.',
            'What do you think... would your caller wait nine seconds?',
          ],
        ], $email),
      ];
    case 'proof':
      return [
        'subject' => 'Watch it work before you pay',
        'body' =>
          $hi . "\n\n" .
          "I don't ask anyone to take my word for it.\n\n" .
          "Every build is mapped first, tested, documented, handed over. It lives in accounts you own. You can log in and see it, and no part of the build sits behind an account I control.\n\n" .
          "Try this today: call your own business number after close, then wait. Whatever comes back is what every missed caller gets. That is the whole audit, and it takes one minute.\n\n" .
          "Then open the evidence page and steal what helps:\n" .
          "https://frimpomaasync.com/results\n\n" .
          "Steal the ideas free. Or have me build it. Live in 7 days, or you don't pay.\n\n" .
          "Nana Frimpongmaa\nfrimpomaasync.com" . drip_footer($email),
        'html' => drip_render([
          'eyebrow' => 'Day 4 · the proof',
          'heading' => 'Watch it work <em>before you pay.</em>',
          'paras' => [
            $hi,
            "I don't ask anyone to take my word for it.",
            'Every build is mapped first, tested, documented, handed over. It lives in accounts you own. You can log in and see it, and no part of the build sits behind an account I control.',
            'Try this today: call your own business number after close, then wait. Whatever comes back is what every missed caller gets. That is the whole audit, and it takes one minute.',
          ],
          'button' => ['label' => 'Open the evidence page', 'href' => 'https://frimpomaasync.com/results'],
          'after' => ["Steal the ideas free. Or have me build it. Live in 7 days, or you don't pay."],
        ], $email),
      ];
    case 'ownership':
      return [
        'subject' => "Who owns it when it's done?",
        'body' =>
          $hi . "\n\n" .
          "One question is worth asking any builder before you pay: who owns it when they walk away?\n\n" .
          "With me, you do. The whole build sits in accounts you own. You can log in and see it. Care is optional, and when it stops, the build stays in your accounts.\n\n" .
          "Three questions to ask anyone who builds for you, me included:\n" .
          "1. Whose name is on the accounts it runs in?\n" .
          "2. What stops working the day I cancel?\n" .
          "3. Where is it documented, and can I read that today?\n\n" .
          "My answers are written on the method page:\n" .
          "https://frimpomaasync.com/method\n\n" .
          "Nana Frimpongmaa\nfrimpomaasync.com" . drip_footer($email),
        'html' => drip_render([
          'eyebrow' => 'Day 6 · the ownership question',
          'heading' => "Who owns it when <em>it's done?</em>",
          'paras' => [
            $hi,
            'One question is worth asking any builder before you pay: who owns it when they walk away?',
            'With me, you do. The whole build sits in accounts you own. You can log in and see it. Care is optional, and when it stops, the build stays in your accounts.',
            'Three questions to ask anyone who builds for you, me included:',
          ],
          'steps' => [
            'Whose name is on the accounts it runs in?',
            'What stops working the day I cancel?',
            'Where is it documented, and can I read that today?',
          ],
          'button' => ['label' => 'Read my answers on the method page', 'href' => 'https://frimpomaasync.com/method'],
        ], $email),
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
        'html' => drip_render([
          'eyebrow' => 'Day 8 · the open door',
          'heading' => 'One week with <em>the Blueprint.</em>',
          'paras' => [
            $hi,
            "A week since you took the Blueprint. If one box got fixed, that's momentum... keep going, top to bottom.",
            "And if you'd rather hand the phone off completely: SynKasa answers every call, text and DM, qualifies the caller, and books the job. Start is \$555. Live in 7 days, or you don't pay.",
          ],
          'button' => ['label' => 'Check your SynKasa fit', 'href' => 'https://frimpomaasync.com/synkasa-fit'],
          'after' => [
            'Or book 15 minutes and tell me what is going on: https://calendar.app.google/DkRJFRA3G6W6d8E48',
            'Let me know if anything changes.',
          ],
        ], $email),
      ];
  }
  return null;
}
