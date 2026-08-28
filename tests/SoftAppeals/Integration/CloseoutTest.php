<?php
declare(strict_types=1);

/**
 * Phase 7 acceptance, section 22, the closeout half:
 *
 *   closeout cannot complete while required access remains open
 *   final records show who confirmed each closeout step
 *
 * and section 7.4 as a sequence that cannot be skipped: resolved batches,
 * financial reconciliation, final report, access review, data disposition,
 * closed. Every case walks to a payer decision through Support/walk.php and
 * then closes through the service, one step at a time, checking the gate at
 * each.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Domain\CloseoutStep;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\InvoiceStatus;
use SoftAppeals\Domain\RecoveryRecord;
use SoftAppeals\Domain\Role;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Domain\SubmissionEventType;

$walk = require __DIR__ . '/../Support/walk.php';
$boot = $walk['boot'];
$active = $walk['active'];
$overturned = $walk['overturned'];
$asClient = $walk['asClient'];
$batchNamed = $walk['batchNamed'];
$labelsOf = $walk['labelsOf'];

$ownerId = static fn (Bootstrap $app): string => (string) $app->users()->findByEmail('owner@example.org')['id'];

$stageOf = static fn (Bootstrap $app, array $engagement): string => (string) $app->engagements()->find((string) $engagement['id'])['stage'];

/** Verify the whole overturn, invoice it, issue it: what reconciliation needs. */
$moneyDone = static function (Bootstrap $app, array $engagement) use ($batchNamed, $ownerId): array {
    $money = $app->reconciliationService();
    $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
    $row = $money->verify($engagement, $batch, ['amount' => '7,000.00', 'source' => RecoveryRecord::SOURCE_REMITTANCE], $ownerId($app));
    $invoice = $money->createInvoice($engagement, $ownerId($app));
    $issued = $money->issueInvoice($engagement, $invoice, [], $ownerId($app));
    return ['row' => $row, 'invoice' => $issued];
};

/** From a payer decision to "access review", the money done and the report written. */
$atAccessReview = static function (Bootstrap $app, ArrayObject $sent) use ($overturned, $moneyDone, $ownerId): array {
    $engagement = $overturned($app, $sent);
    $closeout = $app->closeoutService();
    $closeout->begin($engagement, $ownerId($app));
    $moneyDone($app, $engagement);
    $closeout->confirmReconciliation($engagement, 'Reconciled against the remittance.', $ownerId($app));
    $closeout->confirmFinalReport(
        $engagement,
        'Twenty denials were reviewed. Twelve were submitted to the commercial payer at first level; eight were overturned and four upheld. Seven thousand dollars was verified as received and invoiced.',
        $ownerId($app)
    );
    return $app->engagements()->findWithOrganization((string) $engagement['id']);
};

return [

    'closeout cannot begin while a batch is with the payer, an approval is pending, or a follow-up is open' =>
        static function (Bootstrap $app, Database $db) use ($boot, $active, $asClient, $batchNamed, $stageOf): void {
            [$app, $sent] = $boot($db);
            $engagement = $active($app, $sent);
            $closeout = $app->closeoutService();
            $recovery = $app->recoveryService();
            $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];

            $check = $closeout->canBegin($engagement);
            Expect::false($check['ok'], 'a recommended batch in scope never sent to the payer blocks closeout');
            Expect::true(str_contains((string) $check['reason'], 'never put to the payer'), 'and says so');
            Expect::throws(RuntimeException::class, static fn () => $closeout->begin($engagement, $ownerId), 'begin is refused');
            Expect::same(Stage::RECOVERY_ACTIVE, $stageOf($app, $engagement), 'nothing moved');

            $request = $recovery->requestApproval($engagement, $batch, ['safe_summary' => 'First-level appeals to the commercial payer.'], $ownerId);
            Expect::true(str_contains((string) $closeout->canBegin($engagement)['reason'], 'Awaiting client approval'), 'a pending approval blocks it');

            $approver = $asClient($app, $engagement, 'kofi@example.org');
            $recovery->decide($engagement, $request, \SoftAppeals\Domain\ApprovalState::APPROVED, null, $approver);
            $app->clientAccess()->signOut();
            $event = $recovery->recordSubmission($engagement, $app->workBatches()->find((string) $batch['id']), ['follow_up' => '2026-09-28'], $ownerId);
            Expect::true(str_contains((string) $closeout->canBegin($engagement)['reason'], 'Submitted to the payer'), 'a batch with the payer blocks it');

            $recovery->recordPayerResponse($engagement, $app->workBatches()->find((string) $batch['id']), [
                'event_type' => SubmissionEventType::DECISION_PARTIAL, 'claim_count' => '8', 'amount' => '7,000.00',
            ], $ownerId);
            Expect::true(str_contains((string) $closeout->canBegin($engagement)['reason'], 'follow-up is still open'), 'an open follow-up blocks it');

            $recovery->completeFollowUp($engagement, $event, $ownerId);
            Expect::true($closeout->canBegin($engagement)['ok'], 'resolved batches, nothing open: closeout can begin');
            Expect::false(Stage::canMove(Stage::RECOVERY_ACTIVE, Stage::FINAL_REPORT), 'and no edge skips reconciliation');
            Expect::false(Stage::canMove(Stage::RECOVERY_ACTIVE, Stage::CLOSED), 'or the whole thing');

            $row = $closeout->begin($engagement, $ownerId);
            Expect::same(Stage::RECONCILIATION, $stageOf($app, $engagement), 'financial reconciliation');
            Expect::same(4, count($app->closeouts()->steps((string) $row['id'])), 'four steps seeded');
            Expect::same(2, count($app->closeouts()->accessRows((string) $row['id'])), 'two people hold access: the signer and the approver');
            Expect::throws(RuntimeException::class, static fn () => $closeout->begin($engagement, $ownerId), 'begin twice is refused');
        },

    'reconciliation refuses until every overturned batch is verified and every fee is invoiced, and money closes with it' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $batchNamed, $stageOf): void {
            [$app, $sent] = $boot($db);
            $engagement = $overturned($app, $sent);
            $closeout = $app->closeoutService();
            $money = $app->reconciliationService();
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];
            $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');

            $closeout->begin($engagement, $ownerId);
            $check = $closeout->stepCheck($engagement);
            Expect::same(CloseoutStep::RECONCILIATION, $check['step'], 'the open step is reconciliation');
            Expect::false($check['ok'], 'not yet');
            Expect::true(str_contains((string) $check['reason'], 'no verified figure'), 'because nothing is verified');
            Expect::throws(RuntimeException::class, static fn () => $closeout->confirmReconciliation($engagement, null, $ownerId), 'confirming is refused');

            // Money is still written at this stage.
            $money->verify($engagement, $batch, ['amount' => '7,000.00', 'source' => RecoveryRecord::SOURCE_BANK_DEPOSIT], $ownerId);
            Expect::true(str_contains((string) $closeout->stepCheck($engagement)['reason'], 'not on an invoice'), 'the fee has to be invoiced');
            $invoice = $money->createInvoice($engagement, $ownerId);
            Expect::true(str_contains((string) $closeout->stepCheck($engagement)['reason'], 'still a draft'), 'and issued');
            $money->issueInvoice($engagement, $invoice, [], $ownerId);
            Expect::true($closeout->stepCheck($engagement)['ok'], 'now it can be confirmed, paid or not');

            $closeout->confirmReconciliation($engagement, 'Reconciled against the deposit.', $ownerId);
            Expect::same(Stage::FINAL_REPORT, $stageOf($app, $engagement), 'moved to the final report');
            $summary = $closeout->summary($engagement);
            Expect::notNull($summary['steps'][0]['confirmed_at'], 'the step is stamped');
            Expect::same('owner@example.org', (string) $summary['steps'][0]['confirmed_by_email'], 'with who confirmed it');
            Expect::same('Reconciled against the deposit.', (string) $summary['steps'][0]['note'], 'and the note');
            Expect::null($summary['steps'][1]['confirmed_at'], 'the next is open');

            Expect::throws(RuntimeException::class, static fn () => $closeout->confirmReconciliation($engagement, null, $ownerId), 'a step confirms once');
            Expect::throws(
                RuntimeException::class,
                static fn () => $money->verify($engagement, $app->workBatches()->find((string) $batch['id']), ['amount' => '0.00', 'source' => RecoveryRecord::SOURCE_OTHER], $ownerId),
                'money is final after reconciliation'
            );
            Expect::throws(RuntimeException::class, static fn () => $money->createInvoice($engagement, $ownerId), 'and no invoice is created after it');
        },

    'the final report is screened, kept, and shown to the practice' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $moneyDone, $stageOf): void {
            [$app, $sent] = $boot($db);
            $engagement = $overturned($app, $sent);
            $closeout = $app->closeoutService();
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];
            $closeout->begin($engagement, $ownerId);
            $moneyDone($app, $engagement);

            Expect::throws(RuntimeException::class, static fn () => $closeout->confirmFinalReport($engagement, 'All done, thanks for the work and see you next time.', $ownerId), 'out of order: reconciliation first');
            $closeout->confirmReconciliation($engagement, null, $ownerId);

            Expect::throws(RuntimeException::class, static fn () => $closeout->confirmFinalReport($engagement, 'All done.', $ownerId), 'too short to be a report');
            Expect::throws(RuntimeException::class, static fn () => $closeout->confirmFinalReport($engagement, 'Twenty denials reviewed for patient MRN 4471923 and others, eight overturned, the rest upheld by the payer.', $ownerId), 'a report that carries a person is refused');
            Expect::same(Stage::FINAL_REPORT, $stageOf($app, $engagement), 'nothing moved on a refusal');

            $closeout->confirmFinalReport($engagement, 'Twenty denials were reviewed. Twelve went to the payer, eight were overturned, four were upheld. Seven thousand dollars was verified and invoiced.', $ownerId);
            Expect::same(Stage::ACCESS_REVIEW, $stageOf($app, $engagement), 'moved to the access review');
            $summary = $closeout->summary($engagement);
            Expect::true(str_contains((string) $summary['closeout']['final_summary'], 'Twenty denials'), 'the report is on the closeout');
            Expect::same('owner@example.org', (string) $summary['steps'][1]['confirmed_by_email'], 'with who wrote it');
        },

    'closeout cannot complete while required access remains open; removing access signs the person out' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atAccessReview, $asClient, $stageOf, $labelsOf): void {
            [$app, $sent] = $boot($db);
            $engagement = $atAccessReview($app, $sent);
            $closeout = $app->closeoutService();
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];
            $organizationId = (string) $engagement['organization_id'];

            $summary = $closeout->summary($engagement);
            Expect::same(2, count($summary['access']), 'two people to decide on');
            Expect::same(2, (int) $summary['undecided'], 'both undecided');
            $check = $closeout->stepCheck($engagement);
            Expect::false($check['ok'], 'the review cannot be confirmed');
            Expect::true(str_contains((string) $check['reason'], 'no access decision'), 'because access is open');
            Expect::throws(RuntimeException::class, static fn () => $closeout->confirmAccessReview($engagement, null, $ownerId), 'and confirming is refused');
            Expect::throws(RuntimeException::class, static fn () => $closeout->confirmDataDisposition($engagement, ['disposition' => CloseoutStep::DISPOSITION_RETURNED], $ownerId), 'and closing is refused, out of order and with access open');

            $rows = [];
            foreach ($summary['access'] as $row) {
                $rows[(string) $row['email']] = $row;
            }
            $kofi = $app->users()->findByEmail('kofi@example.org');
            Expect::true($app->memberships()->has((string) $kofi['id'], Role::SUBMISSION_APPROVER, $organizationId), 'the approver holds a role before');

            $closeout->decideAccess($engagement, (string) $rows['kofi@example.org']['id'], CloseoutStep::ACCESS_REMOVED, $ownerId);
            Expect::same([], $app->memberships()->rolesFor((string) $kofi['id'], $organizationId), 'and none after');
            Expect::same(0, (int) $app->users()->find((string) $kofi['id'])['active'], 'the sign-in is deactivated, since it reaches nothing else');
            $asClient($app, $engagement, 'kofi@example.org');
            Expect::null($app->clientAccess()->context(), 'a session they still hold answers nothing');
            $app->clientAccess()->signOut();
            Expect::throws(RuntimeException::class, static fn () => $closeout->decideAccess($engagement, (string) $rows['kofi@example.org']['id'], CloseoutStep::ACCESS_RETAINED, $ownerId), 'decided once');

            Expect::false($closeout->stepCheck($engagement)['ok'], 'one person is still undecided');
            $closeout->decideAccess($engagement, (string) $rows['dana@example.org']['id'], CloseoutStep::ACCESS_RETAINED, $ownerId);
            $dana = $app->users()->findByEmail('dana@example.org');
            Expect::true($app->memberships()->has((string) $dana['id'], Role::AUTHORIZED_SIGNER, $organizationId), 'retained keeps the role');
            Expect::true($closeout->stepCheck($engagement)['ok'], 'everybody is decided');

            $closeout->confirmAccessReview($engagement, null, $ownerId);
            Expect::same(Stage::DATA_DISPOSITION, $stageOf($app, $engagement), 'moved to the data disposition');
            Expect::same('mixed', (string) $closeout->summary($engagement)['closeout']['access_outcome'], 'one removed, one retained');

            // Somebody granted a role after the review was confirmed is still
            // required access, and the close catches it.
            $late = $app->users()->create('late@example.org', null, null);
            $app->memberships()->grant($late, Role::VIEWER, $organizationId);
            Expect::throws(
                RuntimeException::class,
                static fn () => $closeout->confirmDataDisposition($engagement, ['disposition' => CloseoutStep::DISPOSITION_RETURNED], $ownerId),
                'closeout cannot complete while required access remains open'
            );
            Expect::same(Stage::DATA_DISPOSITION, $stageOf($app, $engagement), 'and nothing closed');
            $lateRow = null;
            foreach ($closeout->summary($engagement)['access'] as $row) {
                if ((string) $row['email'] === 'late@example.org') {
                    $lateRow = $row;
                }
            }
            Expect::notNull($lateRow, 'the late person is on the review');
            Expect::throws(RuntimeException::class, static fn () => $closeout->decideAccess($engagement, (string) $lateRow['id'], CloseoutStep::ACCESS_REMOVED, $ownerId), 'but access is decided at the access review stage, which has passed');
            Expect::true(in_array('closeout.access_review', $labelsOf($app, (string) $engagement['id']), true), 'the review is on the timeline');
        },

    'the data disposition closes the engagement, seals a record that reopens and matches, and tells the practice' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atAccessReview, $stageOf, $batchNamed): void {
            [$app, $sent] = $boot($db);
            $engagement = $atAccessReview($app, $sent);
            $closeout = $app->closeoutService();
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];
            foreach ($closeout->summary($engagement)['access'] as $row) {
                $closeout->decideAccess($engagement, (string) $row['id'], (string) $row['email'] === 'dana@example.org' ? CloseoutStep::ACCESS_RETAINED : CloseoutStep::ACCESS_REMOVED, $ownerId);
            }
            $closeout->confirmAccessReview($engagement, null, $ownerId);

            Expect::throws(RuntimeException::class, static fn () => $closeout->confirmDataDisposition($engagement, ['disposition' => 'shredded'], $ownerId), 'a disposition nobody named is refused');
            Expect::throws(RuntimeException::class, static fn () => $closeout->confirmDataDisposition($engagement, ['disposition' => CloseoutStep::DISPOSITION_DESTROYED, 'note' => 'Destroyed the file for patient DOB 01/02/1980'], $ownerId), 'a note that carries a person is refused');
            Expect::same(0, count(array_filter($app->documents()->forEngagement((string) $engagement['id']), static fn (array $d): bool => (string) $d['kind'] === DocumentKind::CLOSEOUT)), 'no record was sealed by a refusal');

            $before = count($sent);
            $record = $closeout->confirmDataDisposition($engagement, [
                'disposition' => CloseoutStep::DISPOSITION_RETURNED,
                'note'        => 'Returned through the secure route on the day of closing.',
            ], $ownerId);

            Expect::same(Stage::CLOSED, $stageOf($app, $engagement), 'closed');
            Expect::true(Stage::isTerminal(Stage::CLOSED), 'which is terminal');
            Expect::notNull($app->engagements()->find((string) $engagement['id'])['closed_at'], 'with a closed stamp on the engagement');

            Expect::same(DocumentKind::CLOSEOUT, (string) $record['kind'], 'the record is a closeout document');
            Expect::same(DocumentStatus::EXECUTED, (string) $record['status'], 'sealed');
            Expect::same(0, count($app->signatures()->forDocument((string) $record['id'])), 'with no signature, because nobody signs a record');
            $verification = $app->documentService()->verify($record);
            Expect::true($verification['body']['matches'], 'the body reopens and matches its hash');
            Expect::true($verification['executed']['matches'], 'and so does the sealed record');
            $body = (string) $app->documentService()->body($record);
            Expect::true(str_contains($body, 'Delivered to: Dana Owusu'), 'delivered to the signer, not signed by her');
            Expect::true(str_contains($body, '$7,000.00 verified'), 'the verified figure');
            Expect::true(str_contains($body, '$1,750.00 at 25 percent'), 'the fee and the rate');
            Expect::true(str_contains($body, 'Returned to the practice'), 'the disposition');
            Expect::true(str_contains($body, 'kofi@example.org'), 'the access review names the people');
            Expect::true(str_contains($body, ': Removed'), 'with the decision');
            Expect::true(str_contains($body, 'Financial reconciliation: confirmed by owner@example.org'), 'who confirmed each step');
            Expect::true(str_contains($body, 'Data disposition: confirmed by owner@example.org'), 'including the last');
            Expect::true(str_contains($body, 'carries no signature'), 'and says it is not signed');
            Expect::false(str_contains($body, 'MRN'), 'and nothing at patient level');
            $executed = (string) $app->documentService()->executedRecord($record);
            Expect::true(str_contains($executed, 'Sealed by'), 'the executed record says it was sealed');
            Expect::false(str_contains($executed, 'The consent that was accepted'), 'and carries no consent block');

            $summary = $closeout->summary($engagement);
            Expect::notNull($summary['closed_at'], 'the closeout is stamped');
            Expect::same('owner@example.org', (string) $summary['closed_by_email'], 'with who closed it');
            Expect::same('Returned to the practice', (string) $summary['disposition'], 'and the disposition');
            Expect::same((string) $record['id'], (string) $summary['record']['id'], 'and the record');
            foreach ($summary['steps'] as $step) {
                Expect::notNull($step['confirmed_at'], (string) $step['label'] . ' is confirmed');
                Expect::same('owner@example.org', (string) $step['confirmed_by_email'], (string) $step['label'] . ' says who');
            }
            Expect::same(5, count($summary['final']), 'five executed documents: BAA, review authorization, recovery agreement, approved scope, and the record');

            Expect::same($before + 1, count($sent), 'the practice was told');
            $last = $sent[count($sent) - 1];
            Expect::same('dana@example.org', $last['to'], 'at the signer');
            Expect::true(str_contains(strtolower($last['subject']), 'closed'), 'that it is closed');
            Expect::true(str_contains($last['body'], 'section=closeout'), 'pointing at the closeout section');
            Expect::false(str_contains($last['body'], '7,000'), 'without the figure');

            // Closed is closed.
            $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
            Expect::throws(RuntimeException::class, static fn () => $app->reconciliationService()->verify($engagement, $batch, ['amount' => '1.00', 'source' => RecoveryRecord::SOURCE_OTHER], $ownerId), 'no money after close');
            Expect::throws(RuntimeException::class, static fn () => $closeout->begin($engagement, $ownerId), 'no second closeout');
            Expect::throws(RuntimeException::class, static fn () => $closeout->confirmDataDisposition($engagement, ['disposition' => CloseoutStep::DISPOSITION_RETURNED], $ownerId), 'no second close');
            Expect::same(1, count(array_filter($app->documents()->forEngagement((string) $engagement['id']), static fn (array $d): bool => (string) $d['kind'] === DocumentKind::CLOSEOUT)), 'one record');
            Expect::same(1, count($app->invoices()->forClient((string) $engagement['id'])), 'the practice sees its one issued invoice');
        },

    'closing with no recovery is allowed when nothing arrived and refused once a cent has' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $batchNamed, $stageOf): void {
            [$app, $sent] = $boot($db);
            $engagement = $overturned($app, $sent);
            $closeout = $app->closeoutService();
            $money = $app->reconciliationService();
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];
            $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');

            Expect::throws(RuntimeException::class, static fn () => $closeout->closeWithoutRecovery($engagement, 'Nothing.', $ownerId), 'a reason has to be a sentence');

            $money->verify($engagement, $batch, ['amount' => '0.00', 'source' => RecoveryRecord::SOURCE_PRACTICE, 'note' => 'Nothing arrived in ninety days.'], $ownerId);
            Expect::true(Stage::canMove(Stage::RECOVERY_ACTIVE, Stage::CLOSED_NO_RECOVERY), 'the edge exists');
            $closeout->closeWithoutRecovery($engagement, 'The payer overturned on paper and never paid; the practice chose not to pursue it.', $ownerId);
            Expect::same(Stage::CLOSED_NO_RECOVERY, $stageOf($app, $engagement), 'closed with no recovery');
            Expect::notNull($app->engagements()->find((string) $engagement['id'])['closed_at'], 'and stamped closed');
            Expect::null($app->closeouts()->forEngagement((string) $engagement['id']), 'no closeout record, because there was nothing to reconcile');
        },

    'one verified cent means the engagement closes through the money, never as no recovery' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $batchNamed, $stageOf): void {
            [$app, $sent] = $boot($db);
            $engagement = $overturned($app, $sent);
            $closeout = $app->closeoutService();
            $money = $app->reconciliationService();
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];
            $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');

            $row = $money->verify($engagement, $batch, ['amount' => '0.01', 'source' => RecoveryRecord::SOURCE_REMITTANCE], $ownerId);
            Expect::same(1, (int) $row['amount_cents'], 'one cent');
            Expect::same(0, (int) $row['fee_cents'], 'a quarter of a cent rounds to no fee');
            Expect::throws(RuntimeException::class, static fn () => $closeout->closeWithoutRecovery($engagement, 'The practice chose to end it here after one cent.', $ownerId), 'one cent verified means it closes through the money');
            Expect::same(Stage::RECOVERY_ACTIVE, $stageOf($app, $engagement), 'and nothing moved');
            Expect::true($closeout->canBegin($engagement)['ok'], 'closeout proper can begin instead');
        },

    'the room reads the closeout: the practice sees steps and documents, money and access by role' =>
        static function (Bootstrap $app, Database $db) use ($boot, $atAccessReview): void {
            [$app, $sent] = $boot($db);
            $engagement = $atAccessReview($app, $sent);
            $closeout = $app->closeoutService();
            $summary = $closeout->summary($engagement);

            Expect::same(4, count($summary['steps']), 'four steps');
            foreach ($summary['steps'] as $step) {
                Expect::true(array_key_exists('client_label', $step), 'each with a client label');
                Expect::false(str_contains((string) $step['client_label'], 'owner@'), 'that names nobody on her side');
            }
            Expect::same('$7,000.00', (string) $summary['money']['verified'], 'the money block is there for the roles that may read it');
            Expect::same(1, count($summary['invoices']), 'and the invoice');
            Expect::same(InvoiceStatus::ISSUED, (string) $summary['invoices'][0]['status'], 'issued');
            Expect::same(2, count($summary['access']), 'and the access rows for the compliance role');
            Expect::same(BatchStage::OVERTURNED, (string) $app->workBatches()->forEngagement((string) $engagement['id'])[0]['stage'], 'the batch reads overturned, as history');
        },
];
