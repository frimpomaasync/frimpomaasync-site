<?php
declare(strict_types=1);

/**
 * The no-form intake, end to end against a fake mailbox: an unread forwarded
 * email becomes an inquiry row the Desk can review, the raw message is kept
 * whole in private storage, the message is marked read only after its row
 * exists, and a second run over the same mailbox creates nothing new. Plus
 * the two quiet states: switched off, and no credentials.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\IntakeForms;
use SoftAppeals\Repositories\JobRepository;

$walk = require __DIR__ . '/../Support/walk.php';
$boot = $walk['boot'];

$message = static fn (string $id, string $from, string $subject, string $body): string =>
    "From: {$from}\r\nTo: start@frimpomaasync.com\r\nSubject: {$subject}\r\nMessage-ID: <{$id}>\r\n"
    . "Date: Mon, 24 Aug 2026 09:15:00 -0400\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n\r\n{$body}\r\n";

/**
 * A mailbox session over an ArrayObject. Messages whose uid is in $seen stop
 * being returned, exactly as \Seen behaves on a real server.
 */
$fakeMailbox = static function (ArrayObject $messages, ArrayObject $seen): callable {
    return static function () use ($messages, $seen): array {
        return [
            'unseen' => static function (int $max) use ($messages, $seen): array {
                $out = [];
                foreach ($messages as $one) {
                    if (!in_array($one['uid'], (array) $seen->getArrayCopy(), true)) {
                        $out[] = $one;
                    }
                    if (count($out) >= $max) {
                        break;
                    }
                }
                return $out;
            },
            'seen'  => static function (string $uid) use ($seen): void {
                $seen->append($uid);
            },
            'close' => static function (): void {
            },
        ];
    };
};

$creds = ['SA_INTAKE_MAILBOX_USER' => 'start@frimpomaasync.com', 'SA_INTAKE_MAILBOX_PASS' => 'a-mailbox-password'];

return [

    'switched off, the job says so and reads nothing' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db, ['SA_INTAKE_MAILBOX_ENABLED' => false] + [
                'SA_INTAKE_MAILBOX_USER' => 'start@frimpomaasync.com',
                'SA_INTAKE_MAILBOX_PASS' => 'a-mailbox-password',
            ]);
            $result = $app->jobService()->run('intake.mailbox', JobRepository::TRIGGER_TEST);
            Expect::same(JobRepository::OUTCOME_OK, $result['outcome'], 'off is a state, not a failure');
            Expect::true(str_contains($result['summary'], 'switched off'), 'and it is named');
        },

    'no credentials, the job says so and opens no connection' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db);
            $result = $app->jobService()->run('intake.mailbox', JobRepository::TRIGGER_TEST);
            Expect::same(JobRepository::OUTCOME_OK, $result['outcome'], 'unconfigured is a state, not a failure');
            Expect::true(str_contains($result['summary'], 'no mailbox credentials'), 'and it is named');
        },

    'a forwarded email becomes an inquiry: row, raw copy, seen mark, an instant acknowledgment, and a rerun makes nothing new' =>
        static function (Bootstrap $app, Database $db) use ($boot, $fakeMailbox, $message, $creds): void {
            [$app, $sent] = $boot($db, $creds);
            $messages = new ArrayObject([
                ['uid' => '7', 'raw' => $message('fwd1@example.org', 'Dana Owusu <dana@example.org>', 'Fwd: denied claims', 'About sixty denials sitting since spring. Attached is what the payer sent.')],
                ['uid' => '9', 'raw' => $message('fwd2@example.org', 'sam@example.net', 'voice note', 'Forwarding the voicemail transcript from our billing lead.')],
            ]);
            $seen = new ArrayObject();
            $app->mailbox($fakeMailbox($messages, $seen));

            $result = $app->jobService()->run('intake.mailbox', JobRepository::TRIGGER_TEST);
            Expect::same(JobRepository::OUTCOME_OK, $result['outcome'], 'the job ran');
            Expect::same(2, $result['items'], 'two new inquiries');
            Expect::same(2, count((array) $seen->getArrayCopy()), 'both messages were marked read, after storing');

            $rows = $app->intakes()->recent(10);
            Expect::same(2, count($rows), 'two rows on the board');
            $byEmail = [];
            foreach ($rows as $row) {
                $byEmail[(string) $row['contact_email']] = $row;
            }
            $dana = $byEmail['dana@example.org'];
            Expect::same(IntakeForms::SOURCE_EMAIL, (string) $dana['source'], 'the source names the door it came through');
            Expect::same('Dana Owusu', (string) $dana['contact_name'], 'the sender is the contact');
            Expect::same('received', (string) $dana['status'], 'and it waits for the fit review like any inquiry');

            $answers = $app->intakes()->answers($dana);
            Expect::same('Fwd: denied claims', $answers['Subject'] ?? '', 'the subject is an answer the drawer prints');
            Expect::true(str_contains($answers['Their message'] ?? '', 'sixty denials'), 'so is the message text');

            $eml = glob($app->config()->privateStoragePath('intake-mail') . '/*.eml') ?: [];
            Expect::same(2, count($eml), 'the raw messages are kept whole');

            // The instant acknowledgment: each sender heard back already.
            $acks = array_values(array_filter(
                iterator_to_array($sent),
                static fn (array $m): bool => $m['subject'] === 'Your email reached Soft Appeals'
            ));
            Expect::same(2, count($acks), 'each sender got an acknowledgment');
            Expect::true(str_contains($acks[0]['body'], 'Hello Dana,'), 'greeting them by their own first name');
            Expect::true(str_contains($acks[0]['body'], 'one business day'), 'with the promise of a same-day-ish answer');
            Expect::true(str_contains($acks[0]['body'], 'patient details out of regular email'), 'and the PHI rule');

            // The same mailbox again: everything is seen, nothing is made.
            $again = $app->jobService()->run('intake.mailbox', JobRepository::TRIGGER_TEST);
            Expect::same(0, $again['items'], 'a rerun creates nothing');
            Expect::same(2, count($app->intakes()->recent(10)), 'and the board did not grow');
            Expect::same(2, count(array_filter(
                iterator_to_array($sent),
                static fn (array $m): bool => $m['subject'] === 'Your email reached Soft Appeals'
            )), 'and nobody was acknowledged twice');
        },

    'the same message unseen again lands on the row it already made' =>
        static function (Bootstrap $app, Database $db) use ($boot, $fakeMailbox, $message, $creds): void {
            [$app] = $boot($db, $creds);
            $one = ['uid' => '4', 'raw' => $message('dup@example.org', 'dana@example.org', 'Fwd: again', 'Same forward, sent twice.')];
            $seen = new ArrayObject();
            $app->mailbox($fakeMailbox(new ArrayObject([$one]), $seen));
            $app->jobService()->run('intake.mailbox', JobRepository::TRIGGER_TEST);

            // A crash after storing but before the seen mark: the message
            // comes back unseen on the next run.
            $seen->exchangeArray([]);
            $result = $app->jobService()->run('intake.mailbox', JobRepository::TRIGGER_TEST);
            Expect::same(JobRepository::OUTCOME_OK, $result['outcome'], 'the rerun is clean');
            Expect::same(0, $result['items'], 'no second row: the payload hash recognised it');
            Expect::same(1, count($app->intakes()->recent(10)), 'one inquiry, once');
        },

    'a message with no sender address is handled without making a row' =>
        static function (Bootstrap $app, Database $db) use ($boot, $fakeMailbox, $creds): void {
            [$app] = $boot($db, $creds);
            $seen = new ArrayObject();
            $app->mailbox($fakeMailbox(new ArrayObject([
                ['uid' => '2', 'raw' => "To: start@frimpomaasync.com\r\nSubject: orphan\r\n\r\nno sender at all"],
            ]), $seen));
            $result = $app->jobService()->run('intake.mailbox', JobRepository::TRIGGER_TEST);
            Expect::same(JobRepository::OUTCOME_OK, $result['outcome'], 'not a failure');
            Expect::same(0, $result['items'], 'no row without an address to answer');
            Expect::same(1, count((array) $seen->getArrayCopy()), 'and it is handled instead of being retried forever');
        },

    'unrelated account mail, bounces and automatic replies are handled without rows or acknowledgments' =>
        static function (Bootstrap $app, Database $db) use ($boot, $fakeMailbox, $creds): void {
            [$app, $sent] = $boot($db, $creds);
            $seen = new ArrayObject();
            $messages = new ArrayObject([
                [
                    'uid' => '20',
                    'raw' => "From: account-notices@example.org\r\nTo: notify@frimpomaasync.com\r\n"
                        . "Subject: Your hosting account\r\n\r\nAn ordinary account notice.",
                ],
                [
                    'uid' => '21',
                    'raw' => "Return-Path: <>\r\nFrom: Mail Delivery System <mailer-daemon@example.org>\r\n"
                        . "To: start@frimpomaasync.com\r\nSubject: Delivery Status Notification (Failure)\r\n"
                        . "Content-Type: multipart/report; boundary=report\r\n\r\n",
                ],
                [
                    'uid' => '22',
                    'raw' => "From: person@example.org\r\nTo: start@frimpomaasync.com\r\n"
                        . "Auto-Submitted: auto-replied\r\nSubject: Away from office\r\n\r\nBack next week.",
                ],
            ]);
            $app->mailbox($fakeMailbox($messages, $seen));

            $result = $app->jobService()->run('intake.mailbox', JobRepository::TRIGGER_TEST);
            Expect::same(JobRepository::OUTCOME_OK, $result['outcome'], 'filtering mail is a clean run');
            Expect::same(0, $result['items'], 'no automated or unrelated message becomes an inquiry');
            Expect::true(str_contains($result['summary'], '3 automated or unrelated ignored'), 'the run reports what it filtered');
            Expect::same(3, count((array) $seen->getArrayCopy()), 'all three are handled once');
            Expect::same(0, count($app->intakes()->recent(10)), 'the board stays empty');
            Expect::same(0, count(array_filter(
                iterator_to_array($sent),
                static fn (array $m): bool => $m['subject'] === 'Your email reached Soft Appeals'
            )), 'no automated sender receives an acknowledgment');
        },
];
