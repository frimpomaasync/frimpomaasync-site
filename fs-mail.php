<?php
// Minimal first-party SMTP sender for the free-shelf notifications.
// Sends through the host's mail server as notify@frimpomaasync.com.
// The credentials live in fs-metrics/smtp.json on the server only,
// never in this public repo. Included by lead.php and wire-notify.php.
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'fs-mail.php') { http_response_code(404); exit('Not here.'); }

function fs_mail_config() {
  $f = __DIR__ . '/fs-metrics/smtp.json';
  if (!is_file($f)) { return null; }
  $j = json_decode((string)file_get_contents($f), true);
  return (is_array($j) && !empty($j['user']) && !empty($j['pass'])) ? $j : null;
}

// $html, when given, is sent as a multipart/alternative beside the text, so
// a phone shows the styled version and a text-only client the plain one.
// $fromName is the display name on the From line. The site's own forms keep
// the default; the Soft Appeals application passes a person's name.
function fs_smtp_send($cfg, $to, $subject, $body, $replyTo = '', $html = '', $fromName = 'frimpomaasync.com') {
  $fp = @stream_socket_client('ssl://smtp.hostinger.com:465', $errno, $err, 10);
  if (!$fp) { return false; }
  stream_set_timeout($fp, 12);
  // Read one full (possibly multi-line) reply, return its 3-digit code.
  $reply = function () use ($fp) {
    $line = '';
    while (($s = fgets($fp, 515)) !== false) { $line = $s; if (strlen($s) < 4 || $s[3] !== '-') break; }
    return substr($line, 0, 3);
  };
  $say = function ($cmd) use ($fp) { fwrite($fp, $cmd . "\r\n"); };
  $step = function ($cmd, $want) use ($say, $reply) { $say($cmd); return $reply() === $want; };

  $ok = $reply() === '220'
     && $step('EHLO frimpomaasync.com', '250')
     && $step('AUTH LOGIN', '334')
     && $step(base64_encode($cfg['user']), '334')
     && $step(base64_encode($cfg['pass']), '235')
     && $step('MAIL FROM:<' . $cfg['user'] . '>', '250')
     && $step('RCPT TO:<' . $to . '>', '250')
     && $step('DATA', '354');
  if ($ok) {
    $fromName = preg_replace('/[\r\n"<>]/', '', (string) $fromName);
    $headers = 'From: "' . $fromName . '" <' . $cfg['user'] . ">\r\n"
             . 'To: <' . $to . ">\r\n"
             . ($replyTo !== '' ? 'Reply-To: ' . $replyTo . "\r\n" : '')
             . 'Subject: ' . $subject . "\r\n"
             . 'Date: ' . gmdate('r') . "\r\n"
             . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@frimpomaasync.com>' . "\r\n"
             . "MIME-Version: 1.0\r\n";
    if ($html === '') {
      $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
      $text = str_replace("\r\n", "\n", $body);
      $text = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $text)); // dot-stuffing
      $say($headers . "\r\n" . $text . "\r\n.");
    } else {
      // Two bodies, base64 so long lines and leading dots need no care.
      $boundary = 'fs-' . bin2hex(random_bytes(12));
      $headers .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n";
      $part = function ($type, $content) use ($boundary) {
        return '--' . $boundary . "\r\n"
             . 'Content-Type: ' . $type . "; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($content), 76, "\r\n");
      };
      $say($headers . "\r\n" . $part('text/plain', $body) . $part('text/html', $html) . '--' . $boundary . "--\r\n.");
    }
    $ok = $reply() === '250';
  }
  $say('QUIT');
  fclose($fp);
  return $ok;
}
