<?php
declare(strict_types=1);

/**
 * Phase 2 acceptance, the intake half.
 *
 *   one intake submission creates one intake even after browser retry
 *   the owner can accept, clarify, decline, or hold an inquiry
 *   dashboard counts match database queries
 *
 * Each one is a named case, so a failure names the criterion it broke.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\FitDecision;
use SoftAppeals\Domain\IntakeStatus;
use SoftAppeals\Domain\Stage;

/** The answers a real submission of the long form carries. */
$answers = [
    'organization'      => 'Fictional Behavioral Health LLC',
    'name'              => 'A Person',
    'email'             => 'a.person@example.org',
    'role'              => 'Administrator or practice manager',
    'organization_type' => 'Behavioral health',
    'state'             => 'Maryland',
    'denial_volume'     => '51 to 100',
    'denied_value'      => '$25,001 to $50,000',
    'time_sensitive'    => 'Yes, some have approaching deadlines',
];

$submit = static function (Bootstrap $app, array $answers, string $payload = 'raw-body-one'): array {
    return $app->intakeService()->record('soft-appeals-start', $answers, $payload);
};

return [

    'one submission creates one intake, however many times the browser retries' =>
        static function (Bootstrap $app, Database $db) use ($answers, $submit): void {
            $first = $submit($app, $answers);
            Expect::true($first['created'], 'the first one is stored');

            $second = $submit($app, $answers);
            Expect::false($second['created'], 'the retry is recognised, not stored again');
            Expect::same($first['id'], $second['id'], 'both point at the same record');

            Expect::same(
                1,
                (int) $db->value('SELECT COUNT(*) FROM sa_intakes'),
                'the table holds exactly one row'
            );
        },

    'a different submission from the same person is a different inquiry' =>
        static function (Bootstrap $app, Database $db) use ($answers, $submit): void {
            $submit($app, $answers, 'raw-body-one');
            $submit($app, $answers + ['context' => 'One more thing'], 'raw-body-two');

            Expect::same(
                2,
                (int) $db->value('SELECT COUNT(*) FROM sa_intakes'),
                'two genuinely different submissions are two inquiries'
            );
        },

    'the promoted columns match the answers, and nothing is invented' =>
        static function (Bootstrap $app, Database $db) use ($answers, $submit): void {
            $id = $submit($app, $answers)['id'];
            $row = $app->intakes()->find($id);

            Expect::notNull($row, 'the intake is there');
            Expect::same('Fictional Behavioral Health LLC', (string) $row['organization_name'], 'the organization');
            Expect::same('a.person@example.org', (string) $row['contact_email'], 'the address, normalised');
            Expect::same('MD', (string) $row['state'], 'Maryland as a code');
            Expect::same('51 to 100', (string) $row['denial_volume_band'], 'the band, as a band');
            Expect::same(1, (int) $row['time_sensitive'], 'they flagged deadlines');
            Expect::same(IntakeStatus::RECEIVED, (string) $row['status'], 'it starts as received');
            Expect::null($row['organization_id'], 'no organization exists until she accepts');
        },

    'accepting opens an engagement and walks it to terms ready' =>
        static function (Bootstrap $app, Database $db) use ($answers, $submit): void {
            $id = $submit($app, $answers)['id'];

            $result = $app->intakeService()->review(
                $id,
                FitDecision::ACCEPT,
                'Behavioral health in Maryland, and they flagged deadlines.',
                null,
                EngagementTerms::FEE_CONTINGENCY_25,
                EngagementTerms::CHANNEL_DECIDE_LATER,
                'within ten business days of the paperwork'
            );

            Expect::same(IntakeStatus::ACCEPTED, $result['status'], 'the inquiry is accepted');
            Expect::notNull($result['engagement_id'], 'an engagement was opened');

            $engagement = $app->engagements()->find((string) $result['engagement_id']);
            Expect::same(Stage::TERMS_READY, (string) $engagement['stage'], 'it is waiting on the terms');
            Expect::same(2500, (int) $engagement['fee_rate_bps'], '25 percent is 2500 basis points');
            Expect::same(
                'within ten business days of the paperwork',
                (string) $engagement['assessment_window'],
                'the window she typed'
            );

            // The organization is created as a prospect, not as active. It
            // becomes active when there is an executed agreement behind it.
            $organization = $app->organizations()->find((string) $engagement['organization_id']);
            Expect::same('prospect', (string) $organization['status'], 'a prospect until the paperwork');

            $intake = $app->intakes()->find($id);
            Expect::same(
                (string) $engagement['organization_id'],
                (string) $intake['organization_id'],
                'the inquiry is linked to the organization it created'
            );
        },

    'the timeline has no hole in it' =>
        static function (Bootstrap $app, Database $db) use ($answers, $submit): void {
            $id = $submit($app, $answers)['id'];
            $result = $app->intakeService()->review($id, FitDecision::ACCEPT, null, null);

            $events = $app->timeline()->forEngagement((string) $result['engagement_id']);
            Expect::same(3, count($events), 'opened, reviewed for fit, terms being prepared');

            $stages = array_map(static fn (array $e): string => (string) $e['to_stage'], $events);
            Expect::same(
                [Stage::INQUIRY_RECEIVED, Stage::FIT_REVIEW, Stage::TERMS_READY],
                $stages,
                'the machine was walked, not jumped'
            );
            foreach ($events as $event) {
                Expect::false(
                    trim((string) $event['public_label']) === '',
                    'every line on a client-visible timeline has to say something'
                );
            }
        },

    'nothing is emailed by a fit review' =>
        static function (Bootstrap $app, Database $db) use ($answers, $submit): void {
            $id = $submit($app, $answers)['id'];
            $app->intakeService()->review($id, FitDecision::ACCEPT, null, null);

            Expect::same(
                0,
                (int) $db->value('SELECT COUNT(*) FROM sa_communications'),
                'accepting is not sending'
            );
            Expect::same(
                0,
                (int) $db->value('SELECT COUNT(*) FROM sa_invitations'),
                'and it mints no link'
            );
        },

    'clarify and hold keep the inquiry open, decline closes it' =>
        static function (Bootstrap $app, Database $db) use ($answers, $submit): void {
            foreach (
                [
                    FitDecision::CLARIFY => IntakeStatus::CLARIFICATION,
                    FitDecision::HOLD    => IntakeStatus::HOLD,
                    FitDecision::DECLINE => IntakeStatus::DECLINED,
                ] as $decision => $expected
            ) {
                $id = $submit($app, $answers, 'raw-body-' . $decision)['id'];
                $result = $app->intakeService()->review($id, $decision, 'a reason', null);

                Expect::same($expected, $result['status'], $decision . ' records as ' . $expected);
                Expect::null($result['engagement_id'], $decision . ' opens no engagement');

                $row = $app->intakes()->find($id);
                Expect::same($decision, (string) $row['fit_decision'], 'the decision is kept');
                Expect::same('a reason', (string) $row['fit_note'], 'and so is the reason');
                Expect::notNull($row['reviewed_at'], 'and when it happened');
            }

            Expect::same(
                2,
                count($app->intakes()->unresolved()),
                'clarify and hold stay on the board, declined does not'
            );
        },

    'the ones sent to her own address clear in one action' =>
        static function (Bootstrap $app, Database $db) use ($answers, $submit): void {
            $owner = 'nanafrimpgskc@gmail.com';

            // Three of her own test runs, and one real practice.
            foreach (['a', 'b', 'c'] as $seed) {
                $submit($app, ['organization' => 'E2E Harbor Family Medicine',
                    'name' => 'Browser Start', 'email' => $owner], 'self-' . $seed);
            }
            $real = $submit($app, $answers, 'a-real-one')['id'];

            Expect::same(4, count($app->intakes()->unresolved()), 'four are open');

            $cleared = $app->intakeService()->dismissSelfAddressed($owner, null);

            Expect::same(3, $cleared, 'the three addressed to her cleared');
            Expect::same(
                1,
                count($app->intakes()->unresolved()),
                'the real practice is untouched and still waiting'
            );
            Expect::same(
                $real,
                (string) $app->intakes()->unresolved()[0]['id'],
                'and it is the right one'
            );

            $one = $db->one("SELECT * FROM sa_intakes WHERE payload_sha256 = :h",
                ['h' => hash('sha256', 'self-a')]);
            Expect::same(IntakeStatus::DUPLICATE, (string) $one['status'], 'marked as not real');
            Expect::same(FitDecision::NOT_REAL, (string) $one['fit_decision'], 'with the decision kept');
            Expect::true(
                str_contains((string) $one['fit_note'], $owner),
                'and a note saying why, naming the address it matched on'
            );

            // Still on the record. Cleared is not deleted.
            Expect::same(4, (int) $db->value('SELECT COUNT(*) FROM sa_intakes'), 'nothing was deleted');
            Expect::same(
                0,
                (int) $db->value('SELECT COUNT(*) FROM sa_engagements'),
                'and clearing opens nothing'
            );

            Expect::same(
                0,
                $app->intakeService()->dismissSelfAddressed($owner, null),
                'running it again clears nothing, because nothing is left open'
            );
        },

    'an address that is not hers clears nothing' =>
        static function (Bootstrap $app, Database $db) use ($answers, $submit): void {
            $submit($app, $answers);
            Expect::same(
                0,
                $app->intakeService()->dismissSelfAddressed('someone.else@example.org', null),
                'the rule is one exact address, not a pattern'
            );
            Expect::same(1, count($app->intakes()->unresolved()), 'the inquiry is still there');
            Expect::same(
                0,
                $app->intakeService()->dismissSelfAddressed('', null),
                'a blank address clears nothing rather than everything'
            );
            Expect::same(1, count($app->intakes()->unresolved()), 'still there');
        },

    'an invented decision is refused' =>
        static function (Bootstrap $app, Database $db) use ($answers, $submit): void {
            $id = $submit($app, $answers)['id'];
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->intakeService()->review($id, 'approve_everything', null, null),
                'the server takes four decisions and no others'
            );
        },

    'reviewing the same inquiry twice does not open a second engagement' =>
        static function (Bootstrap $app, Database $db) use ($answers, $submit): void {
            $id = $submit($app, $answers)['id'];
            $first = $app->intakeService()->review($id, FitDecision::ACCEPT, null, null);
            $second = $app->intakeService()->review(
                $id,
                FitDecision::ACCEPT,
                'Changed the fee basis.',
                null,
                EngagementTerms::FEE_FIXED
            );

            Expect::same($first['engagement_id'], $second['engagement_id'], 'the same engagement');
            Expect::same(
                1,
                (int) $db->value('SELECT COUNT(*) FROM sa_engagements'),
                'one practice, one enquiry, one engagement'
            );

            $engagement = $app->engagements()->find((string) $second['engagement_id']);
            Expect::same(EngagementTerms::FEE_FIXED, (string) $engagement['fee_basis'], 'the terms were updated');
            Expect::null($engagement['fee_rate_bps'], 'a fixed fee carries no percentage');
        },

    'the dashboard pipeline counts match the database' =>
        static function (Bootstrap $app, Database $db) use ($answers, $submit): void {
            // Three inquiries: one accepted, one held, one untouched.
            $accepted = $submit($app, $answers, 'body-a')['id'];
            $held = $submit($app, $answers, 'body-b')['id'];
            $submit($app, $answers, 'body-c');

            $app->intakeService()->review($accepted, FitDecision::ACCEPT, null, null);
            $app->intakeService()->review($held, FitDecision::HOLD, null, null);

            $pipeline = $app->engagements()->pipeline();
            Expect::same(
                (int) $db->value("SELECT COUNT(*) FROM sa_engagements WHERE stage = 'terms_ready'"),
                $pipeline['inquiry'],
                'the inquiry bucket counts the engagements the database has at terms ready'
            );
            Expect::same(0, $pipeline['recovery_active'], 'nothing is in recovery');
            Expect::same(
                count(Stage::deskBuckets()),
                count($pipeline),
                'every bucket is present, even the empty ones'
            );

            Expect::same(
                (int) $db->value(
                    "SELECT COUNT(*) FROM sa_intakes WHERE status IN ('received','in_review','clarification','hold')"
                ),
                count($app->intakes()->unresolved()),
                'the open queue matches the database'
            );
        },

    'an engagement cannot skip a gate' =>
        static function (Bootstrap $app, Database $db) use ($answers, $submit): void {
            $id = $submit($app, $answers)['id'];
            $result = $app->intakeService()->review($id, FitDecision::ACCEPT, null, null);
            $engagementId = (string) $result['engagement_id'];

            Expect::throws(
                RuntimeException::class,
                static fn () => $app->engagements()->transition($engagementId, Stage::SECURE_INTAKE_READY),
                'terms ready to secure intake is not an edge, and the PHI gate is the reason'
            );

            $still = $app->engagements()->find($engagementId);
            Expect::same(Stage::TERMS_READY, (string) $still['stage'], 'the refused move changed nothing');
        },

    'two tabs cannot both advance the same engagement' =>
        static function (Bootstrap $app, Database $db) use ($answers, $submit): void {
            $id = $submit($app, $answers)['id'];
            $result = $app->intakeService()->review($id, FitDecision::ACCEPT, null, null);
            $engagementId = (string) $result['engagement_id'];

            $stale = (int) $app->engagements()->find($engagementId)['row_version'];

            Expect::true(
                $app->engagements()->transition($engagementId, Stage::TERMS_SENT, $stale),
                'the first tab wins'
            );
            Expect::false(
                $app->engagements()->transition($engagementId, Stage::TERMS_SENT, $stale),
                'the second tab, holding the version it read a minute ago, is refused'
            );
        },
];
