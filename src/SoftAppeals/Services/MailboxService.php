<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Config;

/**
 * The intake mailbox. The way in that asks for nothing.
 *
 * Practice managers forward things. A denial letter that has been sitting in
 * an inbox for a month gets forwarded in eight seconds; a twelve-field form
 * does not get filled in at all. So the site's forms stay, and this class adds
 * the other door: an address a person can forward to, read on a schedule by
 * the intake.mailbox job, each message becoming an ordinary inquiry row that
 * the Desk reviews exactly like a submitted form.
 *
 * The transport is a hand-written IMAP client over TLS, in the same spirit as
 * fs-mail.php: this host offers no Composer and no guarantee of the imap
 * extension, and the four commands needed here (LOGIN, SELECT, UID SEARCH,
 * UID FETCH/STORE) are a page of code with no update cadence.
 *
 * Nothing is deleted, ever. A message is fetched with BODY.PEEK so reading it
 * changes nothing, and it is marked \Seen only after its inquiry row is
 * safely stored. A crash between the two leaves it unseen, and the next run
 * takes it again; the payload hash on sa_intakes makes the second take land
 * on the row the first one made.
 *
 * $session exists for the tests, which must never open a socket. It returns
 * the three verbs the job needs as closures, and the default builds them over
 * a real connection.
 */
final class MailboxService
{
    /** The most messages one run will take. The rest wait for the next run. */
    public const BATCH = 20;

    /** Bytes fetched per message. A forwarded letter fits; a video does not. */
    public const FETCH_BYTES = 2_097_152;

    private Config $config;

    /** @var callable():array{unseen:callable(int):list<array{uid:string,raw:string}>,seen:callable(string):void,close:callable():void} */
    private $session;

    /**
     * @param (callable():array{unseen:callable(int):list<array{uid:string,raw:string}>,seen:callable(string):void,close:callable():void})|null $session
     */
    public function __construct(Config $config, ?callable $session = null)
    {
        $this->config = $config;
        $this->session = $session ?? $this->imapSession();
    }

    /** True when this installation has an address and a password to read it. */
    public function configured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * The account the reader signs in as.
     *
     * First choice: an account named in the config. When none is named, the
     * site's own sending account (fs-metrics/smtp.json, the same file
     * fs-mail.php sends with) is the mailbox: her email plan holds two
     * mailboxes and both are taken, so the intake address is an alias of
     * notify@ rather than a third account, and reading notify@'s inbox with
     * the credentials the server already holds means no new secret exists
     * anywhere. Off production the same one-level-up rule MailService uses
     * applies, and a machine with neither file simply reports unconfigured.
     *
     * @return array{user:string,pass:string}|null
     */
    private function credentials(): ?array
    {
        $user = trim($this->config->string('SA_INTAKE_MAILBOX_USER'));
        $pass = trim($this->config->string('SA_INTAKE_MAILBOX_PASS'));
        if ($user !== '' && $pass !== '') {
            return ['user' => $user, 'pass' => $pass];
        }

        $path = MailService::smtpConfigPath($this->config->isProduction());
        if ($path === null) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json) || empty($json['user']) || empty($json['pass'])) {
            return null;
        }
        return ['user' => (string) $json['user'], 'pass' => (string) $json['pass']];
    }

    /**
     * Open the mailbox once and hand the verbs to the caller.
     *
     * @template T
     * @param callable(callable(int):list<array{uid:string,raw:string}>,callable(string):void):T $work
     * @return T
     */
    public function withMailbox(callable $work): mixed
    {
        $open = ($this->session)();
        try {
            return $work($open['unseen'], $open['seen']);
        } finally {
            ($open['close'])();
        }
    }

    // ------------------------------------------------------------------
    // Reading one message. Pure, so the tests can feed it fixtures.
    // ------------------------------------------------------------------

    /**
     * Read the parts of a raw RFC 5322 message that become an inquiry.
     *
     * Deliberately modest: the first text/plain part it can find, decoded;
     * the sender, the subject and the date, MIME words unfolded; and the
     * NAMES of the attachments, never their contents. The full raw message
     * is stored beside the inquiry by the job, so nothing this parser
     * declines to understand is lost.
     *
     * @return array{from_name:string,from_email:string,subject:string,message_id:string,date:?string,body:string,attachments:list<string>,routing_recipients:list<string>,visible_recipients:list<string>,auto_submitted:string,precedence:string,content_type:string,return_path:string,list_id:string,auto_response_suppress:string}
     */
    public static function parse(string $raw): array
    {
        [$head, $body] = self::splitMessage($raw);
        $headers = self::headers($head);

        $from = self::address((string) ($headers['from'] ?? ''));
        $subject = self::decodeWords((string) ($headers['subject'] ?? ''));
        $messageId = trim((string) ($headers['message-id'] ?? ''), " \t<>");

        $date = null;
        if (($headers['date'] ?? '') !== '') {
            $parsed = date_create((string) $headers['date']);
            if ($parsed !== false) {
                $date = $parsed->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }

        $attachments = [];
        $text = self::textOf(
            (string) ($headers['content-type'] ?? 'text/plain'),
            (string) ($headers['content-transfer-encoding'] ?? ''),
            $body,
            $attachments,
            0
        );

        $routingRecipients = [];
        foreach (['delivered-to', 'x-original-to', 'envelope-to', 'x-envelope-to'] as $key) {
            $routingRecipients = array_merge(
                $routingRecipients,
                self::addresses((string) ($headers[$key] ?? ''))
            );
        }
        $visibleRecipients = [];
        foreach (['to', 'cc'] as $key) {
            $visibleRecipients = array_merge(
                $visibleRecipients,
                self::addresses((string) ($headers[$key] ?? ''))
            );
        }

        return [
            'from_name'   => $from['name'],
            'from_email'  => $from['email'],
            'subject'     => $subject,
            'message_id'  => $messageId,
            'date'        => $date,
            'body'        => trim($text),
            'attachments' => $attachments,
            'routing_recipients' => array_values(array_unique($routingRecipients)),
            'visible_recipients' => array_values(array_unique($visibleRecipients)),
            'auto_submitted' => strtolower(trim((string) ($headers['auto-submitted'] ?? ''))),
            'precedence' => strtolower(trim((string) ($headers['precedence'] ?? ''))),
            'content_type' => strtolower(trim((string) ($headers['content-type'] ?? 'text/plain'))),
            'return_path' => trim((string) ($headers['return-path'] ?? '')),
            'list_id' => trim((string) ($headers['list-id'] ?? '')),
            'auto_response_suppress' => trim((string) ($headers['x-auto-response-suppress'] ?? '')),
        ];
    }

    /**
     * Decide whether one message is a human inquiry for the intake alias.
     *
     * The account behind the alias is also used for ordinary site mail. A
     * mailbox-wide UNSEEN search therefore needs a strict boundary here: the
     * intake address must appear in a delivery or visible-recipient header,
     * and automated mail must never become a lead or receive an acknowledgment.
     *
     * @param array<string,mixed> $mail
     * @return array{accept:bool,acknowledge:bool,reason:string}
     */
    public static function intakeDecision(array $mail, string $intakeAddress): array
    {
        $target = strtolower(trim($intakeAddress));
        $recipients = array_values(array_unique(array_merge(
            is_array($mail['routing_recipients'] ?? null) ? $mail['routing_recipients'] : [],
            is_array($mail['visible_recipients'] ?? null) ? $mail['visible_recipients'] : []
        )));
        if ($target === '' || !in_array($target, $recipients, true)) {
            return ['accept' => false, 'acknowledge' => false, 'reason' => 'not addressed to the intake alias'];
        }

        $from = strtolower(trim((string) ($mail['from_email'] ?? '')));
        if ($from === '') {
            return ['accept' => false, 'acknowledge' => false, 'reason' => 'no sender address'];
        }
        $local = strstr($from, '@', true);
        $systemLocals = ['mailer-daemon', 'postmaster', 'noreply', 'no-reply', 'donotreply', 'do-not-reply'];
        if ($local !== false && in_array($local, $systemLocals, true)) {
            return ['accept' => false, 'acknowledge' => false, 'reason' => 'automated sender'];
        }

        $autoSubmitted = strtolower(trim((string) ($mail['auto_submitted'] ?? '')));
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return ['accept' => false, 'acknowledge' => false, 'reason' => 'automated submission'];
        }

        $precedence = strtolower(trim((string) ($mail['precedence'] ?? '')));
        if (in_array($precedence, ['bulk', 'list', 'junk', 'auto_reply'], true)) {
            return ['accept' => false, 'acknowledge' => false, 'reason' => 'bulk or list message'];
        }

        $contentType = strtolower((string) ($mail['content_type'] ?? ''));
        if (str_starts_with($contentType, 'multipart/report')
            || str_starts_with($contentType, 'message/delivery-status')
        ) {
            return ['accept' => false, 'acknowledge' => false, 'reason' => 'delivery report'];
        }

        if (trim((string) ($mail['return_path'] ?? '')) === '<>'
            || trim((string) ($mail['list_id'] ?? '')) !== ''
            || trim((string) ($mail['auto_response_suppress'] ?? '')) !== ''
        ) {
            return ['accept' => false, 'acknowledge' => false, 'reason' => 'automated mail headers'];
        }

        $subject = (string) ($mail['subject'] ?? '');
        if (preg_match(
            '/\b(delivery status notification|undeliver(?:able|ed)|mail delivery (?:failed|failure)|failure notice|delivery failure|returned mail)\b/i',
            $subject
        ) === 1) {
            return ['accept' => false, 'acknowledge' => false, 'reason' => 'delivery-failure subject'];
        }

        return ['accept' => true, 'acknowledge' => true, 'reason' => 'human inquiry to intake alias'];
    }

    /** @return array{0:string,1:string} head, body */
    private static function splitMessage(string $raw): array
    {
        $at = strpos($raw, "\r\n\r\n");
        if ($at !== false) {
            return [substr($raw, 0, $at), substr($raw, $at + 4)];
        }
        $at = strpos($raw, "\n\n");
        if ($at !== false) {
            return [substr($raw, 0, $at), substr($raw, $at + 2)];
        }
        return [$raw, ''];
    }

    /**
     * Unfold and lowercase-key the headers. A repeated header keeps its first
     * value, which is the right answer for every header read here.
     *
     * @return array<string,string>
     */
    private static function headers(string $head): array
    {
        $head = preg_replace('/\r?\n[ \t]+/', ' ', $head) ?? $head;
        $out = [];
        foreach (preg_split('/\r?\n/', $head) ?: [] as $line) {
            $at = strpos($line, ':');
            if ($at === false) {
                continue;
            }
            $key = strtolower(trim(substr($line, 0, $at)));
            if ($key !== '' && !isset($out[$key])) {
                $out[$key] = trim(substr($line, $at + 1));
            }
        }
        return $out;
    }

    /** =?utf-8?B?...?= and friends become readable text. */
    private static function decodeWords(string $value): string
    {
        $decoded = function_exists('mb_decode_mimeheader') ? mb_decode_mimeheader($value) : $value;
        return trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $decoded) ?? '');
    }

    /** @return array{name:string,email:string} */
    private static function address(string $from): array
    {
        $from = self::decodeWords($from);
        if (preg_match('/^(.*)<([^<>@\s]+@[^<>\s]+)>\s*$/', $from, $m) === 1) {
            return ['name' => trim($m[1], " \t\"'"), 'email' => strtolower(trim($m[2]))];
        }
        if (preg_match('/([^\s<>]+@[^\s<>]+)/', $from, $m) === 1) {
            return ['name' => '', 'email' => strtolower(trim($m[1], " \t<>\"'"))];
        }
        return ['name' => '', 'email' => ''];
    }

    /** @return list<string> every address named in a recipient header */
    private static function addresses(string $value): array
    {
        if (preg_match_all('/[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $value, $matches) < 1) {
            return [];
        }
        return array_values(array_unique(array_map('strtolower', $matches[0])));
    }

    /**
     * The first readable text in a possibly-multipart body, collecting
     * attachment names along the way. Two levels of nesting is as deep as a
     * forwarded email goes (mixed holding alternative); anything deeper keeps
     * its raw form in the stored .eml.
     *
     * @param list<string> $attachments
     */
    private static function textOf(string $contentType, string $encoding, string $body, array &$attachments, int $depth): string
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));

        if (str_starts_with($type, 'multipart/') && $depth < 3
            && preg_match('/boundary\s*=\s*"?([^";]+)"?/i', $contentType, $m) === 1
        ) {
            $text = '';
            foreach (self::parts($body, trim($m[1])) as $part) {
                [$head, $inner] = self::splitMessage($part);
                $headers = self::headers($head);
                $partType = (string) ($headers['content-type'] ?? 'text/plain');
                $name = self::attachmentName(
                    (string) ($headers['content-disposition'] ?? ''),
                    $partType
                );
                if ($name !== null) {
                    $attachments[] = $name;
                    continue;
                }
                $found = self::textOf(
                    $partType,
                    (string) ($headers['content-transfer-encoding'] ?? ''),
                    $inner,
                    $attachments,
                    $depth + 1
                );
                if ($text === '' && $found !== '') {
                    $text = $found;
                }
            }
            return $text;
        }

        if ($type === 'text/plain' || $type === '') {
            return self::decodeBody($body, $encoding);
        }
        if ($type === 'text/html') {
            $html = self::decodeBody($body, $encoding);
            $html = preg_replace('/<(br|\/p|\/div)[^>]*>/i', "\n", $html) ?? $html;
            return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    /** @return list<string> */
    private static function parts(string $body, string $boundary): array
    {
        $pieces = explode('--' . $boundary, $body);
        // Before the first boundary is a preamble; after "--boundary--" a tail.
        array_shift($pieces);
        $out = [];
        foreach ($pieces as $piece) {
            if (str_starts_with(trim($piece), '--') || trim($piece) === '') {
                continue;
            }
            $out[] = ltrim($piece, "\r\n");
        }
        return $out;
    }

    private static function attachmentName(string $disposition, string $contentType): ?string
    {
        $named = static function (string $header): ?string {
            if (preg_match('/(?:file)?name\s*=\s*"?([^";]+)"?/i', $header, $m) === 1) {
                $name = self::decodeWords(trim($m[1]));
                return $name === '' ? null : mb_substr(basename($name), 0, 120);
            }
            return null;
        };
        if (stripos($disposition, 'attachment') !== false) {
            return $named($disposition) ?? $named($contentType) ?? 'unnamed attachment';
        }
        // An inline part with a filename is still a file a person attached.
        $name = $named($disposition) ?? $named($contentType);
        return $name;
    }

    private static function decodeBody(string $body, string $encoding): string
    {
        $encoding = strtolower(trim($encoding));
        if ($encoding === 'base64') {
            $decoded = base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);
            return $decoded === false ? '' : $decoded;
        }
        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode($body);
        }
        return $body;
    }

    // ------------------------------------------------------------------
    // The real connection. Never reached by a test.
    // ------------------------------------------------------------------

    /**
     * A minimal IMAP session over TLS, the counterpart of fs-mail.php's SMTP.
     *
     * @return callable():array{unseen:callable(int):list<array{uid:string,raw:string}>,seen:callable(string):void,close:callable():void}
     */
    private function imapSession(): callable
    {
        $config = $this->config;
        return function () use ($config): array {
            $host = trim($config->string('SA_INTAKE_MAILBOX_HOST'));
            $port = (int) $config->string('SA_INTAKE_MAILBOX_PORT');
            $account = $this->credentials();
            if ($account === null) {
                throw new \RuntimeException('No mailbox credentials in reach.');
            }
            $user = $account['user'];
            $pass = $account['pass'];

            $fp = @stream_socket_client('ssl://' . $host . ':' . ($port > 0 ? $port : 993), $errno, $err, 15);
            if ($fp === false) {
                throw new \RuntimeException('The intake mailbox did not answer.');
            }
            stream_set_timeout($fp, 20);

            $n = 0;
            // One full response: every untagged line, then the tagged one.
            $command = static function (string $cmd) use ($fp, &$n): array {
                $tag = 'a' . ++$n;
                fwrite($fp, $tag . ' ' . $cmd . "\r\n");
                $lines = [];
                while (($line = fgets($fp, 8192)) !== false) {
                    if (str_starts_with($line, $tag . ' ')) {
                        if (!str_starts_with($line, $tag . ' OK')) {
                            throw new \RuntimeException('The intake mailbox refused ' . strtok($cmd, ' ') . '.');
                        }
                        return $lines;
                    }
                    $lines[] = $line;
                }
                throw new \RuntimeException('The intake mailbox went quiet.');
            };

            // Greeting, then sign in. The password goes as a quoted string with
            // its two special characters escaped, per RFC 9051.
            if (!str_starts_with((string) fgets($fp, 1024), '* OK')) {
                fclose($fp);
                throw new \RuntimeException('The intake mailbox gave no greeting.');
            }
            $quote = static fn (string $s): string => '"' . addcslashes($s, "\\\"") . '"';
            $command('LOGIN ' . $quote($user) . ' ' . $quote($pass));
            $command('SELECT INBOX');

            $unseen = static function (int $max) use ($fp, $command): array {
                $uids = [];
                foreach ($command('UID SEARCH UNSEEN') as $line) {
                    if (preg_match('/^\* SEARCH((?: \d+)+)/i', trim($line), $m) === 1) {
                        $uids = array_map('intval', array_filter(explode(' ', trim($m[1]))));
                    }
                }
                $out = [];
                foreach (array_slice($uids, 0, max(1, $max)) as $uid) {
                    // PEEK, so fetching does not mark it seen: that happens
                    // only after the inquiry row is stored.
                    fwrite($fp, 'f' . $uid . ' UID FETCH ' . $uid . ' (BODY.PEEK[]<0.' . MailboxService::FETCH_BYTES . ">)\r\n");
                    $raw = '';
                    while (($line = fgets($fp, 8192)) !== false) {
                        if (preg_match('/\{(\d+)\}\s*$/', $line, $m) === 1) {
                            $need = (int) $m[1];
                            while ($need > 0 && !feof($fp)) {
                                $chunk = fread($fp, min(65536, $need));
                                if ($chunk === false) {
                                    break;
                                }
                                $raw .= $chunk;
                                $need -= strlen($chunk);
                            }
                            continue;
                        }
                        if (str_starts_with($line, 'f' . $uid . ' ')) {
                            break;
                        }
                    }
                    if ($raw !== '') {
                        $out[] = ['uid' => (string) $uid, 'raw' => $raw];
                    }
                }
                return $out;
            };

            $seen = static function (string $uid) use ($command): void {
                if (preg_match('/^\d+$/', $uid) === 1) {
                    $command('UID STORE ' . $uid . ' +FLAGS (\Seen)');
                }
            };

            $close = static function () use ($fp, $command): void {
                try {
                    $command('LOGOUT');
                } catch (\Throwable) {
                    // Closing is closing.
                }
                @fclose($fp);
            };

            return ['unseen' => $unseen, 'seen' => $seen, 'close' => $close];
        };
    }
}
