<?php
declare(strict_types=1);

/**
 * Phase 8 acceptance, section 22:
 *
 *   cron jobs are safe to rerun
 *   reminders do not send twice
 *   job failures surface on The Desk
 *
 * and section 17.2 line by line: expired links, one reminder per cadence,
 * overdue internal tasks, deadline groups at 30/14/7/3/1, favorable decisions
 * awaiting verification, open access at closeout, the lock, and the PHI-free
 * health log. Every case runs the job twice and checks the second run made
 * nothing new, because that is the property the whole phase rests on.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\ActionRequestKind;
use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Domain\CloseoutStep;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\RecoveryRecord;
use SoftAppeals\Repositories\AttentionRepository;
use SoftAppeals\Repositories\CommunicationRepository;
use SoftAppeals\Repositories\InvitationRepository;
use SoftAppeals\Repositories\JobRepository;
use SoftAppeals\Services\DigestService;
use SoftAppeals\Services\ReminderService;
use SoftAppeals\Support\Clock;

$walk = require __DIR__ . '/../Support/walk.php';
$boot = $walk['boot'];
$owner = $walk['owner'];
$atSecureRoute = $walk['atSecureRoute'];
$scopeSelected = $walk['scopeSelected'];
$active = $walk['active'];
$overturned = $walk['overturned'];
$batchNamed = $walk['batchNamed'];
$asClient = $walk['asClient'];

$ownerId = static fn (Bootstrap $app): string => (string) $app->users()->findByEmail('owner@example.org')['id'];

/** Count the communications of one template. */
$sentOf = static fn (Database $db, string $template): int => (int) $db->value(
    'SELECT COUNT(*) FROM sa_communications WHERE template_key = :t',
    ['t' => $template]
);

/** Move the application's clock. Everything already built is rebuilt on the new one. */
$travel = static function (Bootstrap $app, ArrayObject $sent, string $modify): void {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $app->useClock(new Clock('America/New_York', $now->modify($modify)));
    // The transport was swapped on the old mailer; swap it again on the new
    // one, still recording into the same outbox.
    $app->mail(static function (string $to, string $subject, string $body) use ($sent): bool {
        $sent->append(['to' => $to, 'subject' => $subject, 'body' => $body]);
        return true;
    });
};

/** An engagement at "assessment delivered" with a decision due in $days days. */
$delivered = static function (Bootstrap $app, ArrayObject $sent, int $days) use ($atSecureRoute, $owner, $batchNamed): array {
    $engagement = $atSecureRoute($app, $sent);
    $ownerId = $owner($app);
    $service = $app->assessmentService();
    $service->confirmReceipt($engagement, 20, 20, $app->workBatchService()->fieldsFromInput([
        'label' => 'Commercial set', 'payer_label' => 'Commercial', 'payer_label_approved' => 'yes',
        'denied_amount' => '18,400.00',
    ]), $ownerId);
    $service->start($engagement, $ownerId);
    $service->sendToQualityReview($engagement, $ownerId);
    $first = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
    $app->workBatchService()->update($engagement, $first, ['stage' => BatchStage::RECOMMENDED], $ownerId);
    $service->deliver($engagement, [
        'summary'                  => 'Twenty denials reviewed. The commercial set has a clear path.',
        'recommended_count'        => 20,
        'recommended_amount_cents' => 1840000,
        'decision_due'             => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $days . ' days')->format('Y-m-d'),
    ], $ownerId);
    return $app->engagements()->findWithOrganization((string) $engagement['id']);
};

return [

    // ------------------------------------------------------------------
    // The lock.
    // ------------------------------------------------------------------

    'a job lock is held by one run at a time, lapses, and releases only to its holder' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db);
            $jobs = $app->jobs();

            $first = $jobs->acquireLock('digest.morning', 600);
            Expect::notNull($first, 'the first acquisition should succeed');
            Expect::null($jobs->acquireLock('digest.morning', 600), 'a second acquisition while held should get nothing');
            Expect::true($jobs->isLocked('digest.morning'), 'and the job should read as locked');

            Expect::false($jobs->releaseLock('digest.morning', 'not-the-token'), 'a stranger cannot release it');
            Expect::true($jobs->releaseLock('digest.morning', (string) $first), 'the holder can');
            Expect::false($jobs->isLocked('digest.morning'), 'and it is free');

            $second = $jobs->acquireLock('digest.morning', 1);
            Expect::notNull($second, 'free means acquirable');
            $app->useClock(new Clock('America/New_York', (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+5 seconds')));
            Expect::notNull($app->jobs()->acquireLock('digest.morning', 600), 'a lapsed lock is taken by the next run');
        },

    'two runs racing for one job: exactly one does the work and the other is recorded as skipped' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db);
            $jobs = $app->jobService();
            $token = $app->jobs()->acquireLock('invitations.expire', 600);
            Expect::notNull($token, 'the lock is taken by the "other" run');

            $result = $jobs->run('invitations.expire', JobRepository::TRIGGER_TEST);
            Expect::same(JobRepository::OUTCOME_SKIPPED, $result['outcome'], 'the second runner skips');
            Expect::null($result['run_id'], 'and opens no run row');

            $app->jobs()->releaseLock('invitations.expire', (string) $token);
            $result = $jobs->run('invitations.expire', JobRepository::TRIGGER_TEST);
            Expect::same(JobRepository::OUTCOME_OK, $result['outcome'], 'once the lock is free the job runs');
            Expect::false($app->jobs()->isLocked('invitations.expire'), 'and releases its lock at the end');
        },

    // ------------------------------------------------------------------
    // The run record, the failure path, the health log.
    // ------------------------------------------------------------------

    'a failing job is recorded as failed, surfaces on the Desk, releases its lock, and the rest still run' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db);
            $jobs = $app->jobService();
            $jobs->withJob('test.explode', 'A job that fails', 'Planted by the test.', static function (): array {
                throw new RuntimeException('the fixture asked for a failure');
            });

            $results = $jobs->runAll(JobRepository::TRIGGER_TEST);
            $byKey = [];
            foreach ($results as $r) {
                $byKey[$r['job']] = $r;
            }
            Expect::same(JobRepository::OUTCOME_FAILED, $byKey['test.explode']['outcome'], 'the planted job fails');
            Expect::true(str_contains($byKey['test.explode']['summary'], 'fixture asked'), 'and says why');
            Expect::same(JobRepository::OUTCOME_OK, $byKey['invitations.expire']['outcome'], 'a job before it ran');
            Expect::same(JobRepository::OUTCOME_OK, $byKey['housekeeping']['outcome'], 'a job after it ran');
            Expect::false($app->jobs()->isLocked('test.explode'), 'the failed job released its lock');

            $health = $jobs->health();
            Expect::same(1, $health['failures_7d'], 'one failure in the last seven days');
            Expect::same(JobRepository::OUTCOME_FAILED, (string) $health['jobs']['test.explode']['last']['outcome'], 'the health view shows it');

            $item = $app->attention()->findByKey('job_failed:test.explode');
            Expect::notNull($item, 'the failure is an attention item');
            Expect::same(AttentionRepository::SEVERITY_URGENT, (string) $item['severity'], 'and an urgent one');
            Expect::same(1, count($app->attention()->openOfKind(AttentionRepository::KIND_JOB_FAILED)), 'and it is open');

            // Fixed: the next run resolves the card.
            $jobs->withJob('test.explode', 'A job that works now', 'Planted by the test.', static fn (): array => ['summary' => 'fine', 'items' => 0]);
            $jobs->run('test.explode', JobRepository::TRIGGER_TEST);
            Expect::same(0, count($app->attention()->openOfKind(AttentionRepository::KIND_JOB_FAILED)), 'a clean run resolves the failure card');

            // The health log holds counts and reasons, never an address.
            $tail = $jobs->logTail(50);
            Expect::true($tail !== [], 'the health log was written');
            foreach ($tail as $line) {
                Expect::false(str_contains($line, '@'), 'no address in the health log: ' . $line);
                Expect::same(6, count(explode("\t", $line)), 'six tab-separated fields: ' . $line);
            }
        },

    'the schedule refuses while the cron flag is off, and the Desk button does not read the flag' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db, ['SA_DEADLINE_CRON_ENABLED' => false]);
            Expect::false($app->config()->cronEnabled(), 'off by the file');
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->jobService()->runAll(JobRepository::TRIGGER_CRON),
                'cron should be refused'
            );
            $results = $app->jobService()->runAll(JobRepository::TRIGGER_DESK);
            Expect::true(count($results) >= 10, 'the Desk button runs them all');

            [$app] = $boot($db, ['SA_APP_ENV' => 'production']);
            Expect::false($app->config()->cronEnabled(), 'unset on production is off');
            [$app] = $boot($db, ['SA_APP_ENV' => 'staging']);
            Expect::true($app->config()->cronEnabled(), 'unset off production is on');
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->jobService()->run('no.such.job', JobRepository::TRIGGER_TEST),
                'an unknown job is refused'
            );
        },

    // ------------------------------------------------------------------
    // Section 17.2, line by line.
    // ------------------------------------------------------------------

    'expire unused invitations: a lapsed unused link is closed, a live one and a used one are left alone' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $travel): void {
            [$app, $sent] = $boot($db);
            $engagement = $atSecureRoute($app, $sent);
            $orgId = (string) $engagement['organization_id'];

            // A fresh live link for another purpose, and a used one from the walk.
            $live = $app->invitations()->mint($orgId, (string) $engagement['id'], 'dana@example.org', InvitationRepository::PURPOSE_INVITE, 3600);
            $used = (int) $db->value('SELECT COUNT(*) FROM sa_invitations WHERE used_at IS NOT NULL');
            Expect::true($used >= 1, 'the walk used at least one link');

            $first = $app->jobService()->run('invitations.expire', JobRepository::TRIGGER_TEST);
            Expect::same(0, $first['items'], 'nothing has lapsed yet');

            $travel($app, $sent, '+2 hours');
            $second = $app->jobService()->run('invitations.expire', JobRepository::TRIGGER_TEST);
            Expect::true($second['items'] >= 1, 'the live link lapsed and was closed');
            $row = $app->invitations()->find((string) $live['id']);
            Expect::notNull($row['revoked_at'], 'closed means revoked');
            Expect::same($used, (int) $db->value('SELECT COUNT(*) FROM sa_invitations WHERE used_at IS NOT NULL'), 'used links are untouched');

            $third = $app->jobService()->run('invitations.expire', JobRepository::TRIGGER_TEST);
            Expect::same(0, $third['items'], 'rerun: nothing more to close');
        },

    'one reminder per cadence period, never twice, and none before the item is eligible' =>
        static function (Bootstrap $app, Database $db) use ($boot, $delivered, $sentOf, $travel): void {
            [$app, $sent] = $boot($db);
            // Decision due in ten days; the practice chose every two weeks.
            $delivered($app, $sent, 10);
            Expect::same(0, $sentOf($db, ReminderService::TEMPLATE_DECISION), 'nothing yet');

            $run = $app->jobService()->run('reminders.client', JobRepository::TRIGGER_TEST);
            Expect::same(0, $run['items'], 'ten days out is before the three-day lead: no reminder');
            Expect::same(0, $sentOf($db, ReminderService::TEMPLATE_DECISION), 'and nothing sent');

            $travel($app, $sent, '+8 days');
            $run = $app->jobService()->run('reminders.client', JobRepository::TRIGGER_TEST);
            Expect::same(1, $run['items'], 'two days before it is due: one reminder');
            Expect::same(1, $sentOf($db, ReminderService::TEMPLATE_DECISION), 'one row');

            $again = $app->jobService()->run('reminders.client', JobRepository::TRIGGER_TEST);
            Expect::same(0, $again['items'], 'the same run again sends nothing');
            Expect::same(1, $sentOf($db, ReminderService::TEMPLATE_DECISION), 'still one row: reminders do not send twice');

            $travel($app, $sent, '+15 days');
            $later = $app->jobService()->run('reminders.client', JobRepository::TRIGGER_TEST);
            Expect::same(0, $later['items'], 'a week later is inside the same two-week period: nothing');

            $travel($app, $sent, '+22 days');
            $next = $app->jobService()->run('reminders.client', JobRepository::TRIGGER_TEST);
            Expect::same(1, $next['items'], 'the next two-week period: one more');
            Expect::same(2, $sentOf($db, ReminderService::TEMPLATE_DECISION), 'two rows across two periods');

            // The email carries no link that could be replayed and no patient.
            $body = '';
            foreach ($sent as $message) {
                if (str_contains($message['subject'], 'reminder')) {
                    $body = $message['body'];
                }
            }
            Expect::true(str_contains($body, 'Recovery Room'), 'it points at the room');
            Expect::false(str_contains($body, '?t='), 'and carries no token');
            Expect::true(str_contains($body, 'Do not reply with patient'), 'and warns off PHI');
        },

    'a practice that chose milestones only is reminded once and never again' =>
        static function (Bootstrap $app, Database $db) use ($boot, $delivered, $sentOf, $travel): void {
            [$app, $sent] = $boot($db);
            $engagement = $delivered($app, $sent, 2);
            $db->update('sa_engagement_preferences', ['communication_cadence' => EngagementTerms::CADENCE_MILESTONES], ['engagement_id' => (string) $engagement['id']]);

            $run = $app->jobService()->run('reminders.client', JobRepository::TRIGGER_TEST);
            Expect::same(1, $run['items'], 'due in two days: the one reminder');
            $travel($app, $sent, '+45 days');
            $run = $app->jobService()->run('reminders.client', JobRepository::TRIGGER_TEST);
            Expect::same(0, $run['items'], 'six weeks on, still nothing more');
            Expect::same(1, $sentOf($db, ReminderService::TEMPLATE_DECISION), 'exactly one, ever');
            Expect::null(ReminderService::periodDays(EngagementTerms::CADENCE_MILESTONES), 'milestones has no period');
            Expect::same(7, ReminderService::periodDays(EngagementTerms::CADENCE_WEEKLY), 'weekly is seven days');
        },

    'an approval waiting on the practice reminds the approver, and a done item stops reminding' =>
        static function (Bootstrap $app, Database $db) use ($boot, $active, $batchNamed, $asClient, $sentOf, $travel, $ownerId): void {
            [$app, $sent] = $boot($db);
            $engagement = $active($app, $sent);
            $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
            $request = $app->recoveryService()->requestApproval($engagement, $batch, ['safe_summary' => 'First-level appeals to the commercial payer.'], $ownerId($app));
            Expect::notNull($request['due_at'], 'an approval carries a default date');

            $travel($app, $sent, '+6 days');
            $run = $app->jobService()->run('reminders.client', JobRepository::TRIGGER_TEST);
            Expect::same(1, $run['items'], 'one approval reminder');
            $to = '';
            foreach ($sent as $message) {
                if (str_contains($message['subject'], 'reminder')) {
                    $to = $message['to'];
                }
            }
            Expect::same('kofi@example.org', $to, 'it goes to the approver the scope named');
            Expect::same(1, $sentOf($db, ReminderService::TEMPLATE_APPROVAL), 'one row');

            $approver = $asClient($app, $engagement, 'kofi@example.org');
            $app->recoveryService()->decide($engagement, $app->approvalRequests()->find((string) $request['id']), \SoftAppeals\Domain\ApprovalState::APPROVED, null, $approver);
            $app->clientAccess()->signOut();

            $travel($app, $sent, '+40 days');
            $run = $app->jobService()->run('reminders.client', JobRepository::TRIGGER_TEST);
            Expect::same(0, $run['items'], 'a decided approval is not reminded');
            Expect::same(1, $sentOf($db, ReminderService::TEMPLATE_APPROVAL), 'still one');
        },

    'deadline groups are surfaced at 30, 14, 7, 3 and 1 day, keyed, rerun-safe, and unconfirmed dates say so' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atSecureRoute, $owner, $batchNamed, $travel): void {
            [$app, $sent] = $boot($db);
            $engagement = $atSecureRoute($app, $sent);
            $ownerId = $owner($app);
            $in = static fn (int $days): string => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $days . ' days')->format('Y-m-d');

            $app->assessmentService()->confirmReceipt($engagement, 20, 20, $app->workBatchService()->fieldsFromInput([
                'label' => 'Commercial set', 'denied_amount' => '18,400.00', 'earliest_deadline' => $in(12), 'deadline_confirmed' => 'yes',
            ]), $ownerId);
            $app->workBatchService()->open($engagement, $app->workBatchService()->fieldsFromInput([
                'label' => 'Unconfirmed set', 'claim_count' => '5', 'denied_amount' => '2,000.00', 'earliest_deadline' => $in(2),
            ]), $ownerId);
            $app->workBatchService()->open($engagement, $app->workBatchService()->fieldsFromInput([
                'label' => 'Far set', 'claim_count' => '3', 'denied_amount' => '1,000.00', 'earliest_deadline' => $in(90), 'deadline_confirmed' => 'yes',
            ]), $ownerId);

            $first = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
            $second = $batchNamed($app, (string) $engagement['id'], 'Unconfirmed set');

            $run = $app->jobService()->run('deadlines.batches', JobRepository::TRIGGER_TEST);
            Expect::same(2, $run['items'], 'two batches are under a threshold; the far one is not');

            $item14 = $app->attention()->findByKey('deadline:' . (string) $first['id'] . ':14');
            Expect::notNull($item14, 'twelve days out lands on the 14-day threshold');
            Expect::same(AttentionRepository::SEVERITY_ACTION, (string) $item14['severity'], 'fourteen days is copper, not red');
            Expect::false(str_contains((string) $item14['label'], 'Unconfirmed'), 'a confirmed date is not called unconfirmed');
            Expect::true(str_contains((string) $item14['detail'], 'Confirmed deadline'), 'and says it is confirmed');

            $item3 = $app->attention()->findByKey('deadline:' . (string) $second['id'] . ':3');
            Expect::notNull($item3, 'two days out lands on the 3-day threshold');
            Expect::true(str_starts_with((string) $item3['label'], 'Unconfirmed date:'), 'an unconfirmed date is labelled as such');
            Expect::true(str_contains((string) $item3['detail'], 'not shown as controlling'), 'and is not called controlling (33.5 rule 3)');
            Expect::false(str_contains((string) $item3['label'], '@'), 'no address in a label');

            $again = $app->jobService()->run('deadlines.batches', JobRepository::TRIGGER_TEST);
            Expect::same(2, $again['items'], 'rerun: the same two');
            Expect::same(2, (int) $db->value('SELECT COUNT(*) FROM sa_attention_items WHERE kind = :k', ['k' => 'deadline']), 'and no new rows: keyed');

            // Time passes: the 14-day item gives way to the 7-day one.
            $travel($app, $sent, '+6 days');
            $app->jobService()->run('deadlines.batches', JobRepository::TRIGGER_TEST);
            Expect::notNull($app->attention()->findByKey('deadline:' . (string) $first['id'] . ':14')['resolved_at'], 'the 14-day item resolved');
            $item7 = $app->attention()->findByKey('deadline:' . (string) $first['id'] . ':7');
            Expect::notNull($item7, 'the 7-day item exists');
            Expect::same(AttentionRepository::SEVERITY_URGENT, (string) $item7['severity'], 'seven days is urgent');
            Expect::notNull($app->attention()->findByKey('deadline:' . (string) $second['id'] . ':1'), 'the unconfirmed one is now past, on the 1-day key');

            // The batch closes: its items resolve.
            $app->workBatchService()->update($engagement, $app->workBatches()->find((string) $first['id']), ['stage' => BatchStage::CLOSED], $ownerId);
            $app->jobService()->run('deadlines.batches', JobRepository::TRIGGER_TEST);
            Expect::notNull($app->attention()->findByKey('deadline:' . (string) $first['id'] . ':7')['resolved_at'], 'a closed batch has no deadline card');
        },

    'favorable decisions awaiting payment verification are surfaced, and verification resolves them' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $batchNamed, $ownerId): void {
            [$app, $sent] = $boot($db);
            $engagement = $overturned($app, $sent);
            $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');

            $run = $app->jobService()->run('payments.pending', JobRepository::TRIGGER_TEST);
            Expect::same(1, $run['items'], 'one overturned batch with nothing verified');
            $item = $app->attention()->findByKey('payment:' . (string) $batch['id']);
            Expect::notNull($item, 'keyed on the batch');
            Expect::true(str_contains((string) $item['detail'], 'No fee exists'), 'and it says no fee exists yet');
            $app->jobService()->run('payments.pending', JobRepository::TRIGGER_TEST);
            Expect::same(1, (int) $db->value('SELECT COUNT(*) FROM sa_attention_items WHERE kind = :k', ['k' => 'payment_pending']), 'rerun-safe');

            $app->reconciliationService()->verify($engagement, $batch, ['amount' => '7,000.00', 'source' => RecoveryRecord::SOURCE_REMITTANCE], $ownerId($app));
            $run = $app->jobService()->run('payments.pending', JobRepository::TRIGGER_TEST);
            Expect::same(0, $run['items'], 'verified: nothing waiting');
            Expect::notNull($app->attention()->findByKey('payment:' . (string) $batch['id'])['resolved_at'], 'and the card resolved');
        },

    'open access at closeout is surfaced until every person is decided' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $ownerId): void {
            [$app, $sent] = $boot($db);
            $engagement = $overturned($app, $sent);
            $closeout = $app->closeoutService();
            $closeout->begin($engagement, $ownerId($app));
            $closeoutRow = $app->closeouts()->forEngagement((string) $engagement['id']);
            Expect::notNull($closeoutRow, 'closeout began');

            $run = $app->jobService()->run('closeout.access', JobRepository::TRIGGER_TEST);
            Expect::same(1, $run['items'], 'one closeout with undecided people');
            $item = $app->attention()->findByKey('closeout_access:' . (string) $closeoutRow['id']);
            Expect::notNull($item, 'keyed on the closeout');
            Expect::true(str_contains((string) $item['label'], 'undecided'), 'says what is open');

            // The access review is not reachable until reconciliation and the
            // report are done, so decide the rows directly, as the service does
            // once the step opens, and prove the job notices.
            foreach ($app->closeouts()->accessRows((string) $closeoutRow['id']) as $row) {
                $app->closeouts()->decideAccess((string) $row['id'], CloseoutStep::ACCESS_RETAINED, $ownerId($app));
            }
            $run = $app->jobService()->run('closeout.access', JobRepository::TRIGGER_TEST);
            Expect::same(0, $run['items'], 'every row decided: nothing open');
            Expect::notNull($app->attention()->findByKey('closeout_access:' . (string) $closeoutRow['id'])['resolved_at'], 'resolved');
        },

    'overdue internal tasks: a question past its date, an approved batch she has not submitted, a follow-up due' =>
        static function (Bootstrap $app, Database $db) use ($boot, $active, $batchNamed, $asClient, $travel, $ownerId): void {
            [$app, $sent] = $boot($db);
            $engagement = $active($app, $sent);
            $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
            $request = $app->recoveryService()->requestApproval($engagement, $batch, ['safe_summary' => 'First-level appeals to the commercial payer.'], $ownerId($app));
            $approver = $asClient($app, $engagement, 'kofi@example.org');
            $app->recoveryService()->decide($engagement, $request, \SoftAppeals\Domain\ApprovalState::APPROVED, null, $approver);
            $app->clientAccess()->signOut();

            $run = $app->jobService()->run('tasks.internal', JobRepository::TRIGGER_TEST);
            Expect::same(0, $run['items'], 'approved today is not overdue yet');

            $travel($app, $sent, '+4 days');
            $run = $app->jobService()->run('tasks.internal', JobRepository::TRIGGER_TEST);
            Expect::same(1, $run['items'], 'four days without a submission is overdue');
            $item = $app->attention()->findByKey('submission:' . (string) $request['id']);
            Expect::notNull($item, 'keyed on the approval');
            Expect::same(AttentionRepository::KIND_SUBMISSION, (string) $item['kind'], 'of the submission kind');

            $event = $app->recoveryService()->recordSubmission($engagement, $app->workBatches()->find((string) $batch['id']), [
                'claim_count' => '12', 'amount' => '11,200.00',
                'follow_up'   => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+5 days')->format('Y-m-d'),
            ], $ownerId($app));
            $run = $app->jobService()->run('tasks.internal', JobRepository::TRIGGER_TEST);
            Expect::same(0, $run['items'], 'submitted, and the follow-up is not due yet');
            Expect::notNull($app->attention()->findByKey('submission:' . (string) $request['id'])['resolved_at'], 'the submission card resolved');

            $travel($app, $sent, '+10 days');
            $run = $app->jobService()->run('tasks.internal', JobRepository::TRIGGER_TEST);
            Expect::same(1, $run['items'], 'the follow-up came due');
            Expect::notNull($app->attention()->findByKey('followup:' . (string) $event['id']), 'keyed on the event');
        },

    // ------------------------------------------------------------------
    // The digest. Once a day, counts only.
    // ------------------------------------------------------------------

    'the morning digest is counts only, goes to the owner once per day, and waits for the digest hour' =>
        static function (Bootstrap $app, Database $db) use ($boot, $delivered, $sentOf): void {
            [$app, $sent] = $boot($db, ['SA_DIGEST_HOUR' => '0']);
            $delivered($app, $sent, 2);
            $app->jobService()->run('deadlines.batches', JobRepository::TRIGGER_TEST);

            $digest = $app->digestService()->build();
            Expect::true($digest['counts']['client_actions_due'] >= 1, 'a decision due in two days counts');
            $text = $app->digestService()->text($digest);
            Expect::true(str_contains($text, 'Good morning.'), 'the section 17.3 opening');
            Expect::false(str_contains($text, 'Fictional Behavioral Health'), 'no practice name in the digest');
            Expect::false(str_contains($text, '@'), 'no address in the digest');
            Expect::false(str_contains($text, 'Commercial set'), 'no batch label in the digest');
            Expect::false(str_contains($text, '$'), 'no dollar figure in the digest');

            $run = $app->jobService()->run('digest.morning', JobRepository::TRIGGER_TEST);
            Expect::same(1, $run['items'], 'sent');
            Expect::same(1, $sentOf($db, DigestService::TEMPLATE), 'one row');
            $to = '';
            foreach ($sent as $message) {
                if (str_contains($message['subject'], 'this morning')) {
                    $to = $message['to'];
                }
            }
            Expect::same('owner@example.org', $to, 'to her, at SA_OWNER_EMAIL');

            $run = $app->jobService()->run('digest.morning', JobRepository::TRIGGER_TEST);
            Expect::same(0, $run['items'], 'the second run the same day sends nothing');
            Expect::true(str_contains($run['summary'], 'already sent'), 'and says so');
            Expect::same(1, $sentOf($db, DigestService::TEMPLATE), 'still one row');

            [$app] = $boot($db, ['SA_DIGEST_HOUR' => '23']);
            $hour = (int) (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('G');
            if ($hour < 23) {
                $run = $app->jobService()->run('digest.morning', JobRepository::TRIGGER_TEST);
                Expect::same(0, $run['items'], 'before the digest hour nothing goes');
                Expect::same(JobRepository::OUTCOME_SKIPPED, $run['outcome'], 'a too-early run is visibly skipped, not a success');
                Expect::true(str_contains($run['summary'], 'not yet'), 'and the run says why');
            }
        },

    'a quiet day says so, in one line' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db);
            $digest = $app->digestService()->build();
            Expect::true($digest['quiet'], 'an empty database is a quiet day');
            Expect::true(str_contains($app->digestService()->text($digest), 'Nothing needs you today'), 'and the text says so');
        },

    // ------------------------------------------------------------------
    // Rerun the lot, twice, and prove nothing doubled.
    // ------------------------------------------------------------------

    'running every job twice creates nothing the second time' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned): void {
            [$app, $sent] = $boot($db, ['SA_DIGEST_HOUR' => '0']);
            $overturned($app, $sent);

            $first = $app->jobService()->runAll(JobRepository::TRIGGER_DESK);
            foreach ($first as $r) {
                Expect::same(JobRepository::OUTCOME_OK, $r['outcome'], $r['job'] . ' should run clean: ' . $r['summary']);
            }
            $items = (int) $db->value('SELECT COUNT(*) FROM sa_attention_items');
            $mail = (int) $db->value('SELECT COUNT(*) FROM sa_communications');
            $backups = count($app->backupService()->all());

            $second = $app->jobService()->runAll(JobRepository::TRIGGER_DESK);
            foreach ($second as $r) {
                Expect::same(JobRepository::OUTCOME_OK, $r['outcome'], $r['job'] . ' should run clean again: ' . $r['summary']);
            }
            Expect::same($items, (int) $db->value('SELECT COUNT(*) FROM sa_attention_items'), 'no new attention rows');
            Expect::same($mail, (int) $db->value('SELECT COUNT(*) FROM sa_communications'), 'no new email');
            Expect::same($backups + 1, count($app->backupService()->all()), 'a backup is the one thing that is new each run, by design');
            $jobCount = count($app->jobService()->definitions());
            Expect::same($jobCount * 2, (int) $db->value('SELECT COUNT(*) FROM sa_job_runs'), 'every job, two runs, one row each');
        },

    'the off-site copy emails the newest backup once per day, and only once' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app, $sent] = $boot($db);
            $none = $app->jobService()->run('backup.offsite', JobRepository::TRIGGER_TEST);
            Expect::same(JobRepository::OUTCOME_OK, $none['outcome'], 'no backup yet is a state, not a failure');
            Expect::true(str_contains($none['summary'], 'no backup'), 'and it says so');

            $app->jobService()->run('backup.daily', JobRepository::TRIGGER_TEST);
            $first = $app->jobService()->run('backup.offsite', JobRepository::TRIGGER_TEST);
            Expect::same(1, $first['items'], 'the newest backup went out');
            $offsite = array_values(array_filter(
                iterator_to_array($sent),
                static fn (array $m): bool => str_starts_with($m['subject'], 'Soft Appeals off-site backup')
            ));
            Expect::same(1, count($offsite), 'one email, to the owner');
            Expect::same('owner@example.org', $offsite[0]['to'], 'the owner address from the config');
            Expect::true(str_contains($offsite[0]['body'], 'SHA-256:'), 'carrying the hash to check against');

            $second = $app->jobService()->run('backup.offsite', JobRepository::TRIGGER_TEST);
            Expect::same(0, $second['items'], 'the same day sends nothing twice');
            Expect::true(str_contains($second['summary'], 'already sent'), 'and says why');
        },

    'an attention item can be marked seen, stays on the record, and is not shown again' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $ownerId): void {
            [$app, $sent] = $boot($db);
            $overturned($app, $sent);
            $app->jobService()->run('payments.pending', JobRepository::TRIGGER_TEST);
            $open = $app->attention()->open();
            Expect::same(1, count($open), 'one card');
            Expect::true($app->attention()->dismiss((string) $open[0]['id'], $ownerId($app)), 'seen');
            Expect::false($app->attention()->dismiss((string) $open[0]['id'], $ownerId($app)), 'seen once only');
            Expect::same(0, count($app->attention()->open()), 'no card');
            $app->jobService()->run('payments.pending', JobRepository::TRIGGER_TEST);
            Expect::same(0, count($app->attention()->open()), 'the job touching it does not bring the card back');
            Expect::same(1, (int) $db->value('SELECT COUNT(*) FROM sa_attention_items'), 'and the row is still there');
        },
];
