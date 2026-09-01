<?php
declare(strict_types=1);

/**
 * The mailbox parser reads what a forwarded email actually carries: the
 * sender, the subject, the first readable text, and the NAMES of the
 * attachments. These cases prove the shapes that arrive in practice: plain
 * text, multipart with a PDF, nested alternative inside mixed, encoded
 * headers, quoted-printable and base64 bodies, and the degenerate messages
 * that must not take the job down.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Services\MailboxService;

$plain = "From: Dana Owusu <dana@example.org>\r\n"
    . "To: start@frimpomaasync.com\r\n"
    . "Subject: Fwd: denied claims piling up\r\n"
    . "Date: Mon, 24 Aug 2026 09:15:00 -0400\r\n"
    . "Message-ID: <abc123@example.org>\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n"
    . "\r\n"
    . "We have about sixty denials sitting since spring. Can you look?\r\n";

$mixed = "From: =?utf-8?B?RGFuYSBPd3VzdQ==?= <dana@example.org>\r\n"
    . "Subject: =?utf-8?Q?Fwd=3A_denial_letter_attached?=\r\n"
    . "Message-ID: <mixed456@example.org>\r\n"
    . "Content-Type: multipart/mixed; boundary=\"outer\"\r\n"
    . "\r\n"
    . "--outer\r\n"
    . "Content-Type: multipart/alternative; boundary=\"inner\"\r\n"
    . "\r\n"
    . "--inner\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n"
    . "Content-Transfer-Encoding: quoted-printable\r\n"
    . "\r\n"
    . "Please see the attached letter =E2=80=94 forwarded from billing.\r\n"
    . "--inner\r\n"
    . "Content-Type: text/html; charset=UTF-8\r\n"
    . "\r\n"
    . "<p>Please see the attached letter.</p>\r\n"
    . "--inner--\r\n"
    . "--outer\r\n"
    . "Content-Type: application/pdf; name=\"denial-letter.pdf\"\r\n"
    . "Content-Disposition: attachment; filename=\"denial-letter.pdf\"\r\n"
    . "Content-Transfer-Encoding: base64\r\n"
    . "\r\n"
    . "JVBERi0xLjQK\r\n"
    . "--outer--\r\n";

return [

    'a plain text message yields sender, subject, id, date and body' =>
        static function (Bootstrap $app, Database $db) use ($plain): void {
            $mail = MailboxService::parse($plain);
            Expect::same('Dana Owusu', $mail['from_name'], 'the display name');
            Expect::same('dana@example.org', $mail['from_email'], 'the address, lowercased');
            Expect::same('Fwd: denied claims piling up', $mail['subject'], 'the subject');
            Expect::same('abc123@example.org', $mail['message_id'], 'the id without its angle brackets');
            Expect::same('2026-08-24 13:15:00', $mail['date'], 'the date, moved to UTC');
            Expect::true(str_contains($mail['body'], 'sixty denials'), 'the body text');
            Expect::same([], $mail['attachments'], 'and no attachment was invented');
            Expect::same(['start@frimpomaasync.com'], $mail['visible_recipients'], 'the intake alias is visible');
            $decision = MailboxService::intakeDecision($mail, 'start@frimpomaasync.com');
            Expect::true($decision['accept'], 'a human message to the intake alias is accepted');
            Expect::true($decision['acknowledge'], 'and may receive the intake acknowledgment');
        },

    'multipart/mixed: the nested text/plain wins, the PDF is a name and never a body' =>
        static function (Bootstrap $app, Database $db) use ($mixed): void {
            $mail = MailboxService::parse($mixed);
            Expect::same('Dana Owusu', $mail['from_name'], 'the base64 encoded-word name decodes');
            Expect::same('Fwd: denial letter attached', $mail['subject'], 'the Q-encoded subject decodes');
            Expect::true(str_contains($mail['body'], 'Please see the attached letter'), 'the quoted-printable plain part is the body');
            Expect::false(str_contains($mail['body'], 'JVBERi'), 'no base64 payload leaks into the text');
            Expect::same(['denial-letter.pdf'], $mail['attachments'], 'the attachment is its name alone');
        },

    'an html-only message is stripped to readable text' =>
        static function (Bootstrap $app, Database $db): void {
            $raw = "From: dana@example.org\r\nSubject: quick question\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                . "<div>Sixty denials.<br>Who do I talk to?</div>";
            $mail = MailboxService::parse($raw);
            Expect::true(str_contains($mail['body'], 'Sixty denials.'), 'the text survives');
            Expect::true(str_contains($mail['body'], 'Who do I talk to?'), 'with the break honoured');
            Expect::false(str_contains($mail['body'], '<'), 'and no tag survives');
        },

    'a bare address with no display name still identifies the sender' =>
        static function (Bootstrap $app, Database $db): void {
            $mail = MailboxService::parse("From: dana@example.org\r\nSubject: hi\r\n\r\nhello");
            Expect::same('', $mail['from_name'], 'no name was invented');
            Expect::same('dana@example.org', $mail['from_email'], 'the address is there');
        },

    'a message with no From and no body parses to empties rather than throwing' =>
        static function (Bootstrap $app, Database $db): void {
            $mail = MailboxService::parse("Subject: orphan\r\n\r\n");
            Expect::same('', $mail['from_email'], 'no sender');
            Expect::same('orphan', $mail['subject'], 'the subject still reads');
            Expect::same('', $mail['body'], 'an empty body is an empty string');
        },

    'mail for the shared sending account is not intake' =>
        static function (Bootstrap $app, Database $db): void {
            $raw = "From: notices@example.org\r\n"
                . "To: notify@frimpomaasync.com\r\n"
                . "Subject: account notice\r\n\r\nhello";
            $decision = MailboxService::intakeDecision(
                MailboxService::parse($raw),
                'start@frimpomaasync.com'
            );
            Expect::false($decision['accept'], 'a different alias never becomes a lead');
            Expect::false($decision['acknowledge'], 'and is never answered by intake');
        },

    'delivery reports and automatic replies are never intake even when sent to the alias' =>
        static function (Bootstrap $app, Database $db): void {
            $bounce = "Return-Path: <>\r\n"
                . "From: Mail Delivery System <mailer-daemon@example.org>\r\n"
                . "To: start@frimpomaasync.com\r\n"
                . "Subject: Delivery Status Notification (Failure)\r\n"
                . "Content-Type: multipart/report; boundary=report\r\n\r\n";
            $bounceDecision = MailboxService::intakeDecision(
                MailboxService::parse($bounce),
                'start@frimpomaasync.com'
            );
            Expect::false($bounceDecision['accept'], 'a delivery report is ignored');
            Expect::false($bounceDecision['acknowledge'], 'a bounce cannot cause backscatter');

            $auto = "From: person@example.org\r\n"
                . "To: start@frimpomaasync.com\r\n"
                . "Auto-Submitted: auto-replied\r\n"
                . "Subject: Away from office\r\n\r\nback next week";
            $autoDecision = MailboxService::intakeDecision(
                MailboxService::parse($auto),
                'start@frimpomaasync.com'
            );
            Expect::false($autoDecision['accept'], 'an automatic reply is ignored');
            Expect::false($autoDecision['acknowledge'], 'and cannot create an acknowledgment loop');
        },
];
