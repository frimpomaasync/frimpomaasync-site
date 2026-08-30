<?php
declare(strict_types=1);

/**
 * The same-day fit reply: three drafts built from the inquiry's own answers,
 * sent by her hand and never twice for the same words. The drafts promise
 * nothing, the send is recorded like every other communication, the
 * allowlist still rules, and the inquiry's status does not move.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Repositories\CommunicationRepository;
use SoftAppeals\Services\FitReplyService;

$walk = require __DIR__ . '/../Support/walk.php';
$boot = $walk['boot'];

$answers = [
    'organization'      => 'Fictional Behavioral Health LLC',
    'name'              => 'Dana Owusu',
    'email'             => 'dana@example.org',
    'organization_type' => 'Behavioral health',
    'state'             => 'Maryland',
    'denial_volume'     => '51 to 100',
];

$inquiry = static function (Bootstrap $app, array $over = []) use ($answers): array {
    $result = $app->intakeService()->record(
        'soft-appeals-start',
        array_merge($answers, $over),
        'fit-reply-fixture-' . bin2hex(random_bytes(4))
    );
    return $app->intakes()->find($result['id']);
};

$sentRows = static fn (Database $db): int => (int) $db->value(
    "SELECT COUNT(*) FROM sa_communications WHERE template_key LIKE 'fit_reply_%'"
);

return [

    'the drafts are built from what the inquiry says and promise nothing' =>
        static function (Bootstrap $app, Database $db) use ($boot, $inquiry): void {
            [$app] = $boot($db);
            $drafts = $app->fitReplyService()->drafts($inquiry($app));

            Expect::same(['accept', 'decline', 'question'], array_keys($drafts), 'three drafts, always');
            Expect::true(str_contains($drafts['accept']['subject'], 'Fictional Behavioral Health LLC'), 'the accept subject names the practice');
            Expect::true(str_contains($drafts['accept']['body'], '51 to 100 unresolved denials'), 'the accept body uses their own number');
            Expect::true(str_contains($drafts['accept']['body'], 'keep patient information out of email'), 'the PHI rule rides along');
            Expect::true(str_contains($drafts['decline']['body'], 'not work I can take on'), 'the decline is plain');
            // Volume and state are answered, so the question goes to handling.
            Expect::true(str_contains($drafts['question']['body'], 'handled today'), 'the question asks the one missing fact');
            foreach ($drafts as $draft) {
                Expect::false(str_contains($draft['body'], "\u{2014}"), 'no em dash anywhere');
                Expect::true(str_contains($draft['body'], 'Nana Frimpongmaa'), 'her full name signs every draft');
                Expect::false(stripos($draft['body'], 'guarantee') !== false, 'nothing is guaranteed');
            }
        },

    'a missing volume is the first question; a missing state the second' =>
        static function (Bootstrap $app, Database $db) use ($boot, $inquiry): void {
            [$app] = $boot($db);
            $service = $app->fitReplyService();
            $noVolume = $service->drafts($inquiry($app, ['denial_volume' => '']));
            Expect::true(str_contains($noVolume['question']['body'], 'how many denied claims'), 'no volume, so volume is the question');
            $noState = $service->drafts($inquiry($app, ['state' => '', 'email' => 'other@example.org']));
            Expect::true(str_contains($noState['question']['body'], 'Which state'), 'volume known, state missing, so state is the question');
        },

    'sending records a communication and leaves the inquiry status alone' =>
        static function (Bootstrap $app, Database $db) use ($boot, $inquiry, $sentRows): void {
            [$app, $sent] = $boot($db);
            $intake = $inquiry($app);
            $drafts = $app->fitReplyService()->drafts($intake);

            $result = $app->fitReplyService()->send(
                (string) $intake['id'],
                FitReplyService::KIND_ACCEPT,
                $drafts['accept']['subject'],
                $drafts['accept']['body'],
                null
            );
            Expect::true($result['sent'], 'the mail server took it');
            Expect::same(1, $sentRows($db), 'one row, recorded');
            Expect::same('dana@example.org', $sent[0]['to'], 'to the person who asked');
            Expect::same('received', (string) $app->intakes()->find((string) $intake['id'])['status'], 'the status did not move: the fit review is still hers to make');
        },

    'the same words never send twice; edited words do' =>
        static function (Bootstrap $app, Database $db) use ($boot, $inquiry, $sentRows): void {
            [$app] = $boot($db);
            $intake = $inquiry($app);
            $drafts = $app->fitReplyService()->drafts($intake);
            $service = $app->fitReplyService();

            $service->send((string) $intake['id'], 'question', $drafts['question']['subject'], $drafts['question']['body'], null);
            $again = $service->send((string) $intake['id'], 'question', $drafts['question']['subject'], $drafts['question']['body'], null);
            Expect::same('already sent', $again['reason'], 'the double click is recognised');
            Expect::same(1, $sentRows($db), 'and nothing went out twice');

            $service->send((string) $intake['id'], 'question', $drafts['question']['subject'], $drafts['question']['body'] . "\nP.S. One more thing.", null);
            Expect::same(2, $sentRows($db), 'an edited body is a new message');
        },

    'the allowlist still rules, and the refusal is recorded as its own state' =>
        static function (Bootstrap $app, Database $db) use ($boot, $inquiry): void {
            [$app] = $boot($db, ['SA_MAIL_ALLOWLIST' => 'owner@example.org']);
            $intake = $inquiry($app);
            $drafts = $app->fitReplyService()->drafts($intake);
            $result = $app->fitReplyService()->send((string) $intake['id'], 'accept', $drafts['accept']['subject'], $drafts['accept']['body'], null);
            Expect::false($result['sent'], 'this environment may not email a stranger');
            Expect::same(CommunicationRepository::REFUSED, $result['state'], 'refused, not failed');
        },

    'an unknown kind, an empty body, and a missing inquiry all refuse plainly' =>
        static function (Bootstrap $app, Database $db) use ($boot, $inquiry): void {
            [$app] = $boot($db);
            $intake = $inquiry($app);
            $service = $app->fitReplyService();
            Expect::throws(RuntimeException::class, static fn () => $service->send((string) $intake['id'], 'shout', 's', 'b', null), 'a kind nobody offers');
            Expect::throws(RuntimeException::class, static fn () => $service->send((string) $intake['id'], 'accept', 's', '', null), 'an empty body');
            Expect::throws(RuntimeException::class, static fn () => $service->send('not-an-id', 'accept', 's', 'b', null), 'an inquiry that is not there');
        },
];
