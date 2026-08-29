<?php
declare(strict_types=1);

/**
 * The client timeline reads in the order things happened.
 *
 * This is a regression test for something that was on screen in a real
 * Recovery Room on 2026-08-28. The practice's own history read:
 *
 *     Your assessment terms are being prepared.
 *     Your enquiry was reviewed for fit.
 *     Your enquiry was received and opened for review.
 *
 * backwards, because opening an engagement writes those three events inside one
 * transaction, timestamps are stored to the second, and the tie was broken by a
 * random UUID.
 *
 * The test drives the real path rather than inserting rows: an inquiry arrives,
 * she accepts it, and the three events are written the way the application
 * writes them. A test that planted its own rows would prove the ORDER BY works
 * and prove nothing about whether the application fills the column.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\FitDecision;

$answers = [
    'organization'      => 'Fictional Primary Care LLC',
    'name'              => 'A Person',
    'email'             => 'a.person@example.org',
    'organization_type' => 'Primary care',
    'state'             => 'Maryland',
    'denial_volume'     => '40 to 80',
];

/** An accepted inquiry, which writes three timeline events in one transaction. */
$accepted = static function (Bootstrap $app, array $answers): string {
    $intake = $app->intakeService()->record(
        'soft-appeals-start',
        $answers,
        'raw-body-' . bin2hex(random_bytes(4))
    );
    $result = $app->intakeService()->review(
        $intake['id'],
        FitDecision::ACCEPT,
        null,
        null,
        EngagementTerms::FEE_CONTINGENCY_25,
        EngagementTerms::CHANNEL_DECIDE_LATER,
        null
    );
    return (string) $result['engagement_id'];
};

return [

    'the three events an opening writes come back in the order they happened' =>
        static function (Bootstrap $app, Database $db) use ($answers, $accepted): void {
            $engagementId = $accepted($app, $answers);

            $types = array_map(
                static fn (array $event): string => (string) $event['event_type'],
                $app->timeline()->forEngagement($engagementId)
            );

            Expect::same(
                ['engagement.opened', 'engagement.fit_review', 'engagement.terms_ready'],
                $types,
                'the story must read received, then reviewed, then terms prepared'
            );
        },

    'every event carries its own place in the story' =>
        static function (Bootstrap $app, Database $db) use ($answers, $accepted): void {
            $engagementId = $accepted($app, $answers);

            $sequences = array_map(
                static fn (array $event): int => (int) $event['seq'],
                $app->timeline()->forEngagement($engagementId)
            );

            Expect::same([1, 2, 3], $sequences, 'the sequence should count from one, in order');
        },

    'a later event is added to the end, not into the middle' =>
        static function (Bootstrap $app, Database $db) use ($answers, $accepted): void {
            $engagementId = $accepted($app, $answers);

            $app->timeline()->record(
                $engagementId,
                'engagement.later',
                'Something else happened.'
            );

            $events = $app->timeline()->forEngagement($engagementId);

            Expect::same(4, count($events), 'there should be four events now');
            Expect::same(
                'engagement.later',
                (string) $events[3]['event_type'],
                'the newest event belongs last'
            );
            Expect::same(4, (int) $events[3]['seq'], 'and it should carry the next number');
        },

    'two engagements count separately' =>
        static function (Bootstrap $app, Database $db) use ($answers, $accepted): void {
            $first = $accepted($app, $answers);

            $second = $answers;
            $second['organization'] = 'Fictional Behavioral Health LLC';
            $second['email'] = 'someone.else@example.org';
            $secondId = $accepted($app, $second);

            $sequencesFor = static fn (string $id): array => array_map(
                static fn (array $event): int => (int) $event['seq'],
                $app->timeline()->forEngagement($id)
            );

            Expect::same([1, 2, 3], $sequencesFor($first), 'the first engagement counts from one');
            Expect::same(
                [1, 2, 3],
                $sequencesFor($secondId),
                'and so does the second, rather than carrying on from the first'
            );
        },

    'the ordering survives ids that sort the wrong way round' =>
        static function (Bootstrap $app, Database $db) use ($answers, $accepted): void {
            $engagementId = $accepted($app, $answers);

            // Force the exact failure the sequence exists to prevent: same
            // second, and ids in the opposite order to the events. One time in
            // six a real UUIDv4 does this on its own.
            $events = $app->timeline()->forEngagement($engagementId);
            $ids = ['zzzzzzzz', 'mmmmmmmm', 'aaaaaaaa'];
            foreach ($events as $index => $event) {
                $db->run(
                    'UPDATE sa_status_events SET id = :new, created_at = :when WHERE id = :old',
                    [
                        'new'  => $ids[$index],
                        'when' => '2026-08-28 12:00:00',
                        'old'  => (string) $event['id'],
                    ]
                );
            }

            $types = array_map(
                static fn (array $event): string => (string) $event['event_type'],
                $app->timeline()->forEngagement($engagementId)
            );

            Expect::same(
                ['engagement.opened', 'engagement.fit_review', 'engagement.terms_ready'],
                $types,
                'the sequence, not the id, is what orders the story'
            );
        },

];
