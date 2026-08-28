<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Auth\AuthorizationService;
use SoftAppeals\Config;
use SoftAppeals\Database;
use SoftAppeals\Domain\ActionRequestKind;
use SoftAppeals\Domain\ApprovalState;
use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\Permission;
use SoftAppeals\Domain\Role;
use SoftAppeals\Domain\SafeText;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Domain\SubmissionEventType;
use SoftAppeals\Repositories\ApprovalRequestRepository;
use SoftAppeals\Repositories\ContactRepository;
use SoftAppeals\Repositories\DocumentRepository;
use SoftAppeals\Repositories\EngagementRepository;
use SoftAppeals\Repositories\MembershipRepository;
use SoftAppeals\Repositories\InvoiceRepository;
use SoftAppeals\Repositories\PreferenceRepository;
use SoftAppeals\Repositories\RecoveryRepository;
use SoftAppeals\Repositories\RecoveryScopeRepository;
use SoftAppeals\Repositories\StatusEventRepository;
use SoftAppeals\Repositories\SubmissionEventRepository;
use SoftAppeals\Repositories\UserRepository;
use SoftAppeals\Repositories\WorkBatchRepository;
use SoftAppeals\Support\Clock;
use SoftAppeals\Support\Money;

/**
 * Recovery, section 7.3, from the practice choosing it to the payer's answer.
 * Phase 6.
 *
 * Three gates run through this class and each one is a check on the server
 * rather than a button that is hidden.
 *
 *   Gate B  No recovery work starts before the Recovery Services Agreement
 *           and the Approved Recovery Scope are both executed. activate() is
 *           the only door to "recovery active" and it reads both documents
 *           off the database before it moves anything. Domain\Stage has no
 *           other edge into that stage.
 *
 *   Gate C  Nothing is recorded as submitted to a payer without an approval
 *           behind it. recordSubmission() refuses without an approved,
 *           unused approval request on the batch, and the migration refuses
 *           a submitted event with no approval id.
 *
 *   Money   Nothing here calculates a fee. A submission, a payer decision and
 *           an expected reimbursement are all recorded, and none of them
 *           creates an invoice-ready amount. Section 19. The fee block the
 *           room shows reads a verified figure that this phase never writes,
 *           so it shows $0.00 and says why.
 *
 * The data boundary holds throughout: a scope names batches, an approval
 * carries a screened summary and two aggregates, an event carries a count, a
 * dollar figure and a date. No method here takes a claim.
 */
final class RecoveryService
{
    public const TEMPLATE_APPROVAL_REQUESTED = 'approval_requested';
    public const TEMPLATE_APPROVAL_DECIDED   = 'approval_decided';
    public const TEMPLATE_STATUS_UPDATE      = 'status_update_available';

    /** How long an approval request waits before the Desk calls it overdue. */
    public const DEFAULT_APPROVAL_DAYS = 7;

    private Config $config;
    private Database $db;
    private Clock $clock;
    private RecoveryScopeRepository $scopes;
    private ApprovalRequestRepository $approvals;
    private SubmissionEventRepository $events;
    private WorkBatchRepository $batches;
    private EngagementRepository $engagements;
    private DocumentRepository $documents;
    private ContactRepository $contacts;
    private UserRepository $users;
    private MembershipRepository $memberships;
    private PreferenceRepository $preferences;
    private StatusEventRepository $timeline;
    private EngagementService $engagementService;
    private WorkBatchService $batchService;
    private ChecklistService $checklist;
    private ActionRequestService $requests;
    private AuthorizationService $authorization;
    private MailService $mail;
    private AuditService $audit;
    private RecoveryRepository $recoveries;
    private InvoiceRepository $invoices;

    public function __construct(
        Config $config,
        Database $db,
        Clock $clock,
        RecoveryScopeRepository $scopes,
        ApprovalRequestRepository $approvals,
        SubmissionEventRepository $events,
        WorkBatchRepository $batches,
        EngagementRepository $engagements,
        DocumentRepository $documents,
        ContactRepository $contacts,
        UserRepository $users,
        MembershipRepository $memberships,
        PreferenceRepository $preferences,
        StatusEventRepository $timeline,
        EngagementService $engagementService,
        WorkBatchService $batchService,
        ChecklistService $checklist,
        ActionRequestService $requests,
        AuthorizationService $authorization,
        MailService $mail,
        AuditService $audit,
        RecoveryRepository $recoveries,
        InvoiceRepository $invoices
    ) {
        $this->recoveries = $recoveries;
        $this->invoices = $invoices;
        $this->config = $config;
        $this->db = $db;
        $this->clock = $clock;
        $this->scopes = $scopes;
        $this->approvals = $approvals;
        $this->events = $events;
        $this->batches = $batches;
        $this->engagements = $engagements;
        $this->documents = $documents;
        $this->contacts = $contacts;
        $this->users = $users;
        $this->memberships = $memberships;
        $this->preferences = $preferences;
        $this->timeline = $timeline;
        $this->engagementService = $engagementService;
        $this->batchService = $batchService;
        $this->checklist = $checklist;
        $this->requests = $requests;
        $this->authorization = $authorization;
        $this->mail = $mail;
        $this->audit = $audit;
    }

    // ------------------------------------------------------------------
    // Reading.
    // ------------------------------------------------------------------

    /**
     * The scope with its batches and its approver joined on, or null while
     * she has not recorded one.
     *
     * @param array<string,mixed> $engagement
     * @return array<string,mixed>|null
     */
    public function scope(array $engagement): ?array
    {
        $row = $this->scopes->forEngagement((string) $engagement['id']);
        if ($row === null) {
            return null;
        }
        $row['batches'] = $this->scopes->batches((string) $row['id']);
        $row['batch_ids'] = array_map(static fn (array $b): string => (string) $b['id'], $row['batches']);
        $row['approver'] = $row['approver_contact_id'] === null
            ? null
            : $this->contacts->find((string) $row['approver_contact_id']);
        $count = 0;
        $cents = 0;
        foreach ($row['batches'] as $batch) {
            $count += (int) $batch['claim_count'];
            $cents += (int) $batch['denied_amount_cents'];
        }
        $row['claim_count'] = $count;
        $row['denied_cents'] = $cents;
        $row['fee_rate_label'] = self::feeRateLabel((string) $row['fee_basis'], $row['fee_rate_bps'] === null ? null : (int) $row['fee_rate_bps']);
        return $row;
    }

    /**
     * The approver the practice named on its preferences, as the default
     * for the scope form. The scope's own approver wins once recorded.
     *
     * @param array<string,mixed> $engagement
     * @return array<string,mixed>|null
     */
    public function preferredApprover(array $engagement): ?array
    {
        $preferences = $this->preferences->forEngagement((string) $engagement['id']);
        if ($preferences === null || $preferences['approver_contact_id'] === null) {
            return null;
        }
        return $this->contacts->find((string) $preferences['approver_contact_id']);
    }

    /**
     * Where the two Gate B documents stand.
     *
     * @param array<string,mixed> $engagement
     * @return array{agreement:?array<string,mixed>,scope_document:?array<string,mixed>,both_executed:bool}
     */
    public function agreementStatus(array $engagement): array
    {
        $engagementId = (string) $engagement['id'];
        $agreement = $this->documents->current($engagementId, DocumentKind::RECOVERY_AGREEMENT);
        $scopeDocument = $this->documents->current($engagementId, DocumentKind::APPROVED_SCOPE);
        $executed = static fn (?array $d): bool
            => $d !== null && (string) $d['status'] === DocumentStatus::EXECUTED;
        return [
            'agreement'      => $agreement,
            'scope_document' => $scopeDocument,
            'both_executed'  => $executed($agreement) && $executed($scopeDocument),
        ];
    }

    /**
     * The batch board: every batch with its card, the newest approval on it,
     * the newest payer event, and whether it is in scope. Both the Desk and
     * the room read this, so the two never disagree.
     *
     * @param array<string,mixed> $engagement
     * @return list<array<string,mixed>>
     */
    public function board(array $engagement): array
    {
        $engagementId = (string) $engagement['id'];
        $scope = $this->scopes->forEngagement($engagementId);
        $inScope = $scope === null ? [] : $this->scopes->batchIds((string) $scope['id']);

        $out = [];
        foreach ($this->batches->forEngagement($engagementId) as $batch) {
            $batchId = (string) $batch['id'];
            $approval = $this->approvals->latestForBatch($batchId);
            $event = $this->events->latestForBatch($batchId);
            $out[] = [
                'batch'      => $batch,
                'card'       => $this->batchService->card($batch, $approval),
                'staff_stage' => (string) $batch['stage'] === BatchStage::APPROVAL_PENDING
                    && $approval !== null
                    && (string) $approval['state'] === ApprovalState::APPROVED
                    ? 'Approved, submission next'
                    : BatchStage::staffLabel((string) $batch['stage']),
                'in_scope'   => in_array($batchId, $inScope, true),
                'approval'   => $approval,
                'event'      => $event,
                'can_ask'    => in_array($batchId, $inScope, true)
                    && (string) $batch['stage'] === BatchStage::RECOMMENDED
                    && $this->approvals->pendingForBatch($batchId) === null,
                'can_submit' => (string) $batch['stage'] === BatchStage::APPROVAL_PENDING
                    && $this->approvals->approvedUnusedForBatch($batchId) !== null,
                'can_respond' => in_array((string) $batch['stage'], [BatchStage::SUBMITTED, BatchStage::PAYER_REVIEW], true),
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function approvals(string $engagementId): array
    {
        return $this->approvals->forEngagement($engagementId);
    }

    /** @return list<array<string,mixed>> */
    public function submissions(string $engagementId): array
    {
        return $this->events->forEngagement($engagementId);
    }

    /**
     * Section 15.9, the recovery and fee block. Shown once recovery work
     * begins.
     *
     * The verified figure is the sum of the verified rows Phase 7 writes,
     * less what was taken back, and the fee is the sum of the fees on those
     * rows, each calculated in whole cents at the rate snapshotted on it.
     * Nothing here reads a submission or a payer decision as money. Until a
     * reimbursement is verified both read $0.00, and the block says why.
     *
     * @param array<string,mixed> $engagement
     * @return array<string,mixed>
     */
    public function feeBlock(array $engagement): array
    {
        $engagementId = (string) $engagement['id'];
        $scope = $this->scopes->forEngagement($engagementId);
        $totals = $this->events->totals($engagementId);
        $money = $this->recoveries->totals($engagementId);
        $invoiced = $this->invoices->totals($engagementId);
        $rateBps = $scope === null || $scope['fee_rate_bps'] === null ? null : (int) $scope['fee_rate_bps'];
        $agreement = $this->documents->current($engagementId, DocumentKind::RECOVERY_AGREEMENT);

        $invoiceLine = 'Not created';
        if ($invoiced['draft_count'] > 0) {
            $invoiceLine = 'Being prepared';
        } elseif ($invoiced['issued_count'] > 0) {
            $invoiceLine = 'Issued';
        } elseif ($invoiced['paid_cents'] > 0 || $invoiced['invoiced_cents'] > 0) {
            $invoiceLine = 'Paid';
        }

        return [
            'shown'             => Stage::phiGatePassed((string) $engagement['stage'])
                && in_array((string) $engagement['stage'], [
                    Stage::RECOVERY_ACTIVE, Stage::RECONCILIATION, Stage::FINAL_REPORT,
                    Stage::ACCESS_REVIEW, Stage::DATA_DISPOSITION, Stage::CLOSED,
                ], true),
            'verified'          => Money::format($money['net_cents']),
            'verified_cents'    => $money['net_cents'],
            'verified_gross'    => Money::format($money['verified_cents']),
            'taken_back'        => Money::format($money['taken_back_cents']),
            'taken_back_cents'  => $money['taken_back_cents'],
            'rate'              => $scope === null
                ? 'Not set'
                : self::feeRateLabel((string) $scope['fee_basis'], $rateBps),
            'fee'               => Money::format($money['fee_net_cents']),
            'fee_cents'         => $money['fee_net_cents'],
            'invoice'           => $invoiceLine,
            'invoiced'          => Money::format($invoiced['invoiced_cents']),
            'paid'              => Money::format($invoiced['paid_cents']),
            'agreement_ref'     => $agreement === null ? null : (string) $agreement['public_ref'],
            'submitted'         => Money::format($totals['submitted_cents']),
            'submitted_count'   => $totals['submitted_count'],
            'overturned'        => Money::format($totals['overturned_cents']),
            'overturned_count'  => $totals['overturned_count'],
            'upheld'            => Money::format($totals['upheld_cents']),
            'upheld_count'      => $totals['upheld_count'],
        ];
    }

    /** "25% of verified reimbursement", or the basis label when there is no rate. */
    public static function feeRateLabel(string $feeBasis, ?int $rateBps): string
    {
        if ($rateBps !== null) {
            $whole = intdiv($rateBps, 100);
            $rest = $rateBps % 100;
            $percent = $rest === 0 ? (string) $whole : $whole . '.' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT);
            return $percent . ' percent of verified reimbursement';
        }
        return EngagementTerms::feeLabel($feeBasis);
    }

    // ------------------------------------------------------------------
    // Her side: the scope, and starting the work.
    // ------------------------------------------------------------------

    /**
     * Record, or rewrite, the recovery scope.
     *
     * Allowed at "recovery scope selected" and while the agreement is out
     * for signature. Once it is executed the scope is what was signed, and
     * changing it is a new version of both documents, not an edit here.
     *
     * The approver is one of three things: a contact already at the
     * practice, chosen by id; a new person, named and emailed, who becomes a
     * contact, a passwordless user and a submission approver in this
     * organization; or nobody yet, which the documents refuse.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @param array<string,mixed> $input fee_basis, fee_rate, summary,
     *        batch_refs (list), approver_contact, approver_name,
     *        approver_email, approver_role
     * @return array<string,mixed> the scope as stored
     */
    public function recordScope(array $engagement, array $input, ?string $userId = null): array
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];

        $stage = $this->currentStage($engagementId);
        if (!in_array($stage, [Stage::RECOVERY_SCOPE_SELECTED, Stage::RECOVERY_AGREEMENT_PENDING], true)) {
            $this->refuse('recovery.scope', $engagementId, 'record the scope', $stage, Stage::RECOVERY_SCOPE_SELECTED);
        }

        $feeBasis = trim((string) ($input['fee_basis'] ?? ''));
        if (!EngagementTerms::isValidFee($feeBasis) || $feeBasis === EngagementTerms::FEE_NOT_SET) {
            throw new \RuntimeException('Not saved: choose the fee basis the recovery runs on.');
        }

        $rateBps = EngagementTerms::feeRateBps($feeBasis);
        $rateText = trim((string) ($input['fee_rate'] ?? ''));
        if ($rateText !== '') {
            if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $rateText, $m) !== 1) {
                throw new \RuntimeException('Not saved: the fee rate has to be a percentage, like 25 or 22.5.');
            }
            $rateBps = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0', STR_PAD_RIGHT);
            if ($rateBps > 10000) {
                throw new \RuntimeException('Not saved: a fee rate over 100 percent is a typo.');
            }
        }
        if ($feeBasis === EngagementTerms::FEE_CONTINGENCY_25 && $rateBps !== 2500) {
            throw new \RuntimeException('Not saved: the standard contingency basis is 25 percent. Choose "custom" for a different rate.');
        }

        $summary = SafeText::require((string) ($input['summary'] ?? ''), 1000, 'the scope summary');
        if (mb_strlen($summary) < 10) {
            throw new \RuntimeException('Not saved: say what is in scope, in a sentence or two.');
        }

        // The batches, found through the engagement by reference. A batch
        // that is not recommended cannot be in scope: the assessment said
        // not to pursue it, and the scope is what the practice is
        // authorizing on the strength of that assessment.
        $refs = $input['batch_refs'] ?? [];
        if (!is_array($refs) || $refs === []) {
            throw new \RuntimeException('Not saved: choose at least one recommended batch for the scope.');
        }
        $batchIds = [];
        foreach ($refs as $ref) {
            $batch = $this->batches->findForEngagement((string) $ref, $engagementId);
            if ($batch === null) {
                throw new \RuntimeException('Not saved: one of those batches is not on this engagement.');
            }
            if ((string) $batch['stage'] !== BatchStage::RECOMMENDED) {
                throw new \RuntimeException(
                    'Not saved: batch ' . (string) $batch['public_ref'] . ' is "'
                    . BatchStage::staffLabel((string) $batch['stage'])
                    . '". Only a recommended batch goes into the scope.'
                );
            }
            $batchIds[] = (string) $batch['id'];
        }

        $approverId = $this->resolveApprover($organizationId, $input);

        return $this->db->transaction(function () use (
            $engagement,
            $engagementId,
            $organizationId,
            $feeBasis,
            $rateBps,
            $summary,
            $batchIds,
            $approverId,
            $userId
        ): array {
            $before = $this->scopes->forEngagement($engagementId);
            $row = $this->scopes->save($engagementId, $organizationId, [
                'fee_basis'           => $feeBasis,
                'fee_rate_bps'        => $rateBps,
                'summary'             => $summary,
                'approver_contact_id' => $approverId,
            ], $userId);
            $this->scopes->setBatches((string) $row['id'], $batchIds);

            $this->timeline->record(
                $engagementId,
                $before === null ? 'recovery.scope_recorded' : 'recovery.scope_updated',
                $before === null
                    ? 'We recorded the recovery scope: ' . count($batchIds) . ' ' . ($batchIds === [] || count($batchIds) > 1 ? 'batches' : 'batch')
                    : 'We updated the recovery scope',
                null,
                null,
                StatusEventRepository::ACTOR_STAFF,
                $userId,
                ['count' => (string) count($batchIds), 'fee_basis' => $feeBasis]
            );

            $approverChanged = $approverId !== null
                && ($before === null || (string) ($before['approver_contact_id'] ?? '') !== $approverId);
            if ($approverChanged) {
                $this->timeline->record(
                    $engagementId,
                    'recovery.approver_confirmed',
                    'Your submission approver is confirmed',
                    null,
                    null,
                    StatusEventRepository::ACTOR_STAFF,
                    $userId
                );
            }

            $this->audit->record('recovery.scope', 'success', 'recovery_scope', (string) $row['id'], [
                'fee_basis'    => $feeBasis,
                'fee_rate_bps' => $rateBps === null ? null : (string) $rateBps,
                'count'        => (string) count($batchIds),
            ], $organizationId);

            $this->checklist->sync($engagementId);

            return $row;
        });
    }

    /**
     * Start the recovery work. Gate B.
     *
     * @param array<string,mixed> $engagement joined with its organization
     */
    public function activate(array $engagement, ?string $userId = null): void
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];

        $stage = $this->currentStage($engagementId);
        if ($stage !== Stage::RECOVERY_AGREEMENT_EXECUTED) {
            $this->refuse('recovery.activate', $engagementId, 'start the recovery work', $stage, Stage::RECOVERY_AGREEMENT_EXECUTED);
        }

        $status = $this->agreementStatus($engagement);
        if (!$status['both_executed']) {
            $this->audit->record('recovery.activate', 'denied', 'engagement', $engagementId, [
                'reason' => 'the approved scope is not executed',
            ], $organizationId);
            throw new \RuntimeException(
                'The Recovery Services Agreement is executed but the Approved Recovery Scope '
                . 'is not. The practice signs it in the room; recovery starts once both are signed.'
            );
        }

        $scope = $this->scopes->forEngagement($engagementId);
        if ($scope === null || $scope['approver_contact_id'] === null) {
            throw new \RuntimeException('No submission approver is named. Record one on the scope first.');
        }

        $this->db->transaction(function () use ($engagement, $engagementId, $userId): void {
            $this->engagementService->move(
                $engagementId,
                Stage::RECOVERY_ACTIVE,
                'Recovery work started',
                'recovery.activated',
                $userId
            );
            // Section 17: the recovery checklist is live now.
            $this->checklist->sync($engagementId);
        });
    }

    // ------------------------------------------------------------------
    // Her side: asking for an approval, recording what happened.
    // ------------------------------------------------------------------

    /**
     * Put one batch to the practice's approver. Gate C opens here.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @param array<string,mixed> $batch
     * @param array<string,mixed> $input safe_summary, claim_count, amount, due
     * @return array<string,mixed> the request row
     */
    public function requestApproval(array $engagement, array $batch, array $input, ?string $userId = null): array
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];
        $batchId = (string) $batch['id'];

        $this->requireActive($engagementId, 'ask for an approval');
        if ((string) $batch['engagement_id'] !== $engagementId) {
            throw new \RuntimeException('That batch is not on this engagement.');
        }

        $scope = $this->scopes->forEngagement($engagementId);
        if ($scope === null || !$this->scopes->coversBatch((string) $scope['id'], $batchId)) {
            throw new \RuntimeException(
                'Batch ' . (string) $batch['public_ref'] . ' is outside the approved scope. '
                . 'Nothing outside the scope goes to a payer under this agreement.'
            );
        }
        if ($scope['approver_contact_id'] === null) {
            throw new \RuntimeException('No submission approver is named on the scope.');
        }
        $approver = $this->contacts->find((string) $scope['approver_contact_id']);
        if ($approver === null) {
            throw new \RuntimeException('The submission approver is no longer on the record.');
        }

        if (!BatchStage::canMove((string) $batch['stage'], BatchStage::APPROVAL_PENDING)) {
            throw new \RuntimeException(
                'Batch ' . (string) $batch['public_ref'] . ' is "'
                . BatchStage::staffLabel((string) $batch['stage']) . '", and only a recommended batch '
                . 'can be put up for approval.'
            );
        }
        if ($this->approvals->pendingForBatch($batchId) !== null) {
            throw new \RuntimeException('An approval is already waiting on this batch.');
        }

        $summary = SafeText::require((string) ($input['safe_summary'] ?? ''), 500, 'the summary for the approver');
        if (mb_strlen($summary) < 10) {
            throw new \RuntimeException('Not asked: tell the approver what is being sent, in a sentence.');
        }

        $count = $this->readCount($input['claim_count'] ?? null, (int) $batch['claim_count'], 'the claim count');
        $cents = $this->readCents($input['amount'] ?? null, (int) $batch['denied_amount_cents'], 'the amount');
        $dueUtc = $this->readDate($input['due'] ?? null, 'the date it is asked for by');
        if ($dueUtc === null) {
            $dueUtc = substr($this->clock->utcPlusSeconds(self::DEFAULT_APPROVAL_DAYS * 86400), 0, 10) . ' 12:00:00';
        }

        return $this->db->transaction(function () use (
            $engagement,
            $engagementId,
            $organizationId,
            $batch,
            $batchId,
            $approver,
            $summary,
            $count,
            $cents,
            $dueUtc,
            $userId
        ): array {
            $row = $this->approvals->open($engagementId, $organizationId, $batchId, [
                'safe_summary'   => $summary,
                'claim_count'    => $count,
                'amount_cents'   => $cents,
                'requested_from' => (string) $approver['id'],
                'due_at'         => $dueUtc,
            ], $userId);

            $this->moveBatch($batch, BatchStage::APPROVAL_PENDING, [
                'next_owner'  => BatchStage::OWNER_CLIENT,
                'next_action' => 'Approve or return the submission in your Recovery Room',
            ]);

            $this->timeline->record(
                $engagementId,
                'approval.requested',
                'We asked you to approve a submission',
                null,
                null,
                StatusEventRepository::ACTOR_STAFF,
                $userId,
                ['batch_ref' => (string) $batch['public_ref'], 'count' => (string) $count]
            );

            // One card in the room, however many batches are waiting. The
            // Approvals section lists each one.
            if ($this->requests->openOfKind($engagementId, ActionRequestKind::APPROVE_SUBMISSION) === null) {
                $this->requests->open($engagement, ActionRequestKind::APPROVE_SUBMISSION, null, $dueUtc, $userId, false);
            }

            $this->notifyApprover($row, $engagement, $approver);

            $this->audit->record('approval.request', 'success', 'approval_request', (string) $row['id'], [
                'batch_ref'    => (string) $batch['public_ref'],
                'count'        => (string) $count,
                'amount_cents' => (string) $cents,
            ], $organizationId);

            return $row;
        });
    }

    /**
     * Withdraw a pending request. The batch goes back to recommended.
     *
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $request
     */
    public function cancelApproval(array $engagement, array $request, ?string $userId = null): void
    {
        $engagementId = (string) $engagement['id'];
        if ((string) $request['engagement_id'] !== $engagementId) {
            throw new \RuntimeException('That approval request is not on this engagement.');
        }
        $batch = $this->batches->find((string) $request['work_batch_id']);
        if ($batch === null) {
            throw new \RuntimeException('The batch behind that request is gone.');
        }

        $this->db->transaction(function () use ($engagement, $engagementId, $request, $batch, $userId): void {
            $key = hash('sha256', 'approval-cancel:' . (string) $request['id']);
            if (!$this->approvals->decide((string) $request['id'], ApprovalState::CANCELLED, $key, $userId, null, null)) {
                throw new \RuntimeException('That request is not pending any more.');
            }
            $this->moveBatch($batch, BatchStage::RECOMMENDED, [
                'next_owner'  => BatchStage::OWNER_SOFT_APPEALS,
                'next_action' => null,
            ]);
            $this->timeline->record(
                $engagementId,
                'approval.cancelled',
                ApprovalState::timelineLabel(ApprovalState::CANCELLED),
                null,
                null,
                StatusEventRepository::ACTOR_STAFF,
                $userId,
                ['batch_ref' => (string) $batch['public_ref'], 'approval_state' => ApprovalState::CANCELLED]
            );
            $this->closeApprovalCardIfNonePending($engagement, $userId);
            $this->audit->record('approval.cancel', 'success', 'approval_request', (string) $request['id'], [
                'batch_ref' => (string) $batch['public_ref'],
            ], (string) $engagement['organization_id']);
        });
    }

    /**
     * Record that an approved batch went to the payer. Gate C is checked
     * here, against the database, on every call.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @param array<string,mixed> $batch
     * @param array<string,mixed> $input claim_count, amount, occurred, follow_up, note
     * @return array<string,mixed> the event row
     */
    public function recordSubmission(array $engagement, array $batch, array $input, ?string $userId = null): array
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];
        $batchId = (string) $batch['id'];

        $this->requireActive($engagementId, 'record a submission');
        if ((string) $batch['engagement_id'] !== $engagementId) {
            throw new \RuntimeException('That batch is not on this engagement.');
        }

        $approval = $this->approvals->approvedUnusedForBatch($batchId);
        if ($approval === null) {
            $this->audit->record('submission.record', 'denied', 'work_batch', $batchId, [
                'reason' => 'no approval behind it',
            ], $organizationId);
            throw new \RuntimeException(
                'Nothing goes to a payer without the practice\'s approval. Batch '
                . (string) $batch['public_ref'] . ' has no approved, unused approval request on it.'
            );
        }
        if (!BatchStage::canMove((string) $batch['stage'], BatchStage::SUBMITTED)) {
            throw new \RuntimeException(
                'Batch ' . (string) $batch['public_ref'] . ' is "'
                . BatchStage::staffLabel((string) $batch['stage']) . '" and cannot be marked submitted from there.'
            );
        }

        $count = $this->readCount($input['claim_count'] ?? null, (int) $approval['claim_count'], 'the claim count');
        $cents = $this->readCents($input['amount'] ?? null, (int) $approval['amount_cents'], 'the amount');
        $occurred = $this->readDate($input['occurred'] ?? null, 'the date it was sent') ?? $this->clock->nowUtc();
        $followUp = $this->readDate($input['follow_up'] ?? null, 'the follow-up date');
        $note = trim((string) ($input['note'] ?? '')) === ''
            ? null
            : SafeText::require((string) $input['note'], 500, 'the note');

        return $this->db->transaction(function () use (
            $engagement,
            $engagementId,
            $organizationId,
            $batch,
            $batchId,
            $approval,
            $count,
            $cents,
            $occurred,
            $followUp,
            $note,
            $userId
        ): array {
            $row = $this->events->record($engagementId, $organizationId, $batchId, [
                'event_type'          => SubmissionEventType::SUBMITTED,
                'claim_count'         => $count,
                'amount_cents'        => $cents,
                'occurred_at'         => $occurred,
                'note'                => $note,
                'follow_up_due_at'    => $followUp,
                'approval_request_id' => (string) $approval['id'],
            ], $userId);

            $this->moveBatch($batch, BatchStage::SUBMITTED, [
                'submitted_count' => (int) $batch['submitted_count'] + $count,
                'next_owner'      => BatchStage::OWNER_PAYER,
                'next_action'     => SubmissionEventType::nextActionAfter(SubmissionEventType::SUBMITTED),
            ]);

            $this->timeline->record(
                $engagementId,
                'submission.recorded',
                SubmissionEventType::timelineLabel(SubmissionEventType::SUBMITTED),
                null,
                null,
                StatusEventRepository::ACTOR_STAFF,
                $userId,
                [
                    'batch_ref'    => (string) $batch['public_ref'],
                    'event_type'   => SubmissionEventType::SUBMITTED,
                    'count'        => (string) $count,
                    'amount_cents' => (string) $cents,
                ]
            );

            $this->notifyStatusUpdate($engagement, SubmissionEventType::SUBMITTED, (string) $row['id']);

            $this->audit->record('submission.record', 'success', 'submission_event', (string) $row['id'], [
                'batch_ref'    => (string) $batch['public_ref'],
                'count'        => (string) $count,
                'amount_cents' => (string) $cents,
            ], $organizationId);

            $this->checklist->sync($engagementId);

            return $row;
        });
    }

    /**
     * Record what the payer did. The payer-response states of Phase 6.
     *
     * None of these creates a fee. A favorable decision opens a follow-up to
     * verify what arrives, and the verification is the money phase's.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @param array<string,mixed> $batch
     * @param array<string,mixed> $input event_type, claim_count, amount, occurred, follow_up, note
     * @return array<string,mixed> the event row
     */
    public function recordPayerResponse(array $engagement, array $batch, array $input, ?string $userId = null): array
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];
        $batchId = (string) $batch['id'];

        $this->requireActive($engagementId, 'record a payer response');
        if ((string) $batch['engagement_id'] !== $engagementId) {
            throw new \RuntimeException('That batch is not on this engagement.');
        }

        $type = trim((string) ($input['event_type'] ?? ''));
        if (!SubmissionEventType::isResponse($type)) {
            throw new \RuntimeException('That is not one of the payer responses.');
        }
        $to = SubmissionEventType::batchStageAfter($type);
        if (!BatchStage::canMove((string) $batch['stage'], $to)) {
            throw new \RuntimeException(
                'Batch ' . (string) $batch['public_ref'] . ' is "'
                . BatchStage::staffLabel((string) $batch['stage']) . '". A payer response is recorded '
                . 'on a batch that has been submitted.'
            );
        }

        $latest = $this->events->latestForBatch($batchId);
        $count = $this->readCount($input['claim_count'] ?? null, $latest === null ? (int) $batch['claim_count'] : (int) $latest['claim_count'], 'the claim count');
        $cents = $this->readCents($input['amount'] ?? null, $latest === null ? (int) $batch['denied_amount_cents'] : (int) $latest['amount_cents'], 'the amount');
        $occurred = $this->readDate($input['occurred'] ?? null, 'the date it happened') ?? $this->clock->nowUtc();
        $followUp = $this->readDate($input['follow_up'] ?? null, 'the follow-up date');
        $note = trim((string) ($input['note'] ?? '')) === ''
            ? null
            : SafeText::require((string) $input['note'], 500, 'the note');

        return $this->db->transaction(function () use (
            $engagement,
            $engagementId,
            $organizationId,
            $batch,
            $batchId,
            $type,
            $to,
            $count,
            $cents,
            $occurred,
            $followUp,
            $note,
            $userId
        ): array {
            $row = $this->events->record($engagementId, $organizationId, $batchId, [
                'event_type'          => $type,
                'claim_count'         => $count,
                'amount_cents'        => $cents,
                'occurred_at'         => $occurred,
                'note'                => $note,
                'follow_up_due_at'    => $followUp,
                'approval_request_id' => null,
            ], $userId);

            $changes = [
                'next_owner'  => match ($to) {
                    BatchStage::OVERTURNED, BatchStage::UPHELD => BatchStage::OWNER_SOFT_APPEALS,
                    BatchStage::CLOSED => BatchStage::OWNER_OTHER,
                    default => BatchStage::OWNER_PAYER,
                },
                'next_action' => SubmissionEventType::nextActionAfter($type),
            ];
            if (in_array($type, [SubmissionEventType::DECISION_FAVORABLE, SubmissionEventType::DECISION_PARTIAL], true)) {
                $changes['overturned_count'] = (int) $batch['overturned_count'] + $count;
                $changes['next_owner'] = BatchStage::OWNER_PAYER;
            }
            if ($type === SubmissionEventType::DECISION_UNFAVORABLE) {
                $changes['upheld_count'] = (int) $batch['upheld_count'] + $count;
            }
            if ($type === SubmissionEventType::WITHDRAWN) {
                $changes['closed_count'] = (int) $batch['closed_count'] + $count;
            }
            $this->moveBatch($batch, $to, $changes);

            $this->timeline->record(
                $engagementId,
                'submission.' . $type,
                SubmissionEventType::timelineLabel($type),
                null,
                null,
                StatusEventRepository::ACTOR_STAFF,
                $userId,
                [
                    'batch_ref'    => (string) $batch['public_ref'],
                    'event_type'   => $type,
                    'count'        => (string) $count,
                    'amount_cents' => (string) $cents,
                ]
            );

            // The payer wants something claim-level. That goes through the
            // secure route, and the request card says so.
            if ($type === SubmissionEventType::INFORMATION_REQUESTED
                && $this->requests->openOfKind($engagementId, ActionRequestKind::PROVIDE_INFORMATION) === null
            ) {
                $this->requests->open($engagement, ActionRequestKind::PROVIDE_INFORMATION, null, $followUp, $userId);
            }

            if (SubmissionEventType::isDecision($type)) {
                $this->notifyStatusUpdate($engagement, $type, (string) $row['id']);
            }

            $this->audit->record('submission.response', 'success', 'submission_event', (string) $row['id'], [
                'batch_ref'    => (string) $batch['public_ref'],
                'event_type'   => $type,
                'count'        => (string) $count,
                'amount_cents' => (string) $cents,
            ], $organizationId);

            return $row;
        });
    }

    /**
     * Close a follow-up. Section 17: "Submission recorded: create follow-up
     * task; owner confirms dates."
     *
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $event
     */
    public function completeFollowUp(array $engagement, array $event, ?string $userId = null): void
    {
        if ((string) $event['engagement_id'] !== (string) $engagement['id']) {
            throw new \RuntimeException('That follow-up is not on this engagement.');
        }
        if (!$this->events->completeFollowUp((string) $event['id'], $userId)) {
            throw new \RuntimeException('That follow-up is already closed, or never had a date.');
        }
        $this->audit->record('submission.follow_up', 'success', 'submission_event', (string) $event['id'], [
            'reason' => 'done',
        ], (string) $engagement['organization_id']);
    }

    // ------------------------------------------------------------------
    // The practice's side: the decision.
    // ------------------------------------------------------------------

    /**
     * Approve or return one submission. Gate C, decided.
     *
     * Everything is checked here, on the server, against the session:
     * the request belongs to the session's organization, the session holds
     * a role that may approve, the request is still pending, and the same
     * decision arriving twice is answered once. A viewer, a billing contact
     * and a compliance contact all get the same refusal, because none of
     * them holds Permission::APPROVAL_DECIDE.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @param array<string,mixed> $request
     * @param array{organization_id:string,user_id:string,contact_id:?string} $context
     * @return array{decided:bool,already:bool,state:string}
     */
    public function decide(array $engagement, array $request, string $state, ?string $note, array $context): array
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];
        $requestId = (string) $request['id'];

        $refuse = function (string $reason) use ($requestId, $organizationId): void {
            $this->audit->record('approval.decide', 'denied', 'approval_request', $requestId, [
                'reason' => mb_substr($reason, 0, 200),
            ], $organizationId);
            throw new \RuntimeException($reason);
        };

        if ((string) $request['engagement_id'] !== $engagementId
            || (string) $request['organization_id'] !== $organizationId
            || $organizationId !== (string) $context['organization_id']
        ) {
            $refuse('That approval request belongs to a different practice.');
        }
        if (!$this->authorization->can(Permission::APPROVAL_DECIDE, $organizationId)) {
            $refuse('Only your organization admin or your named submission approver can decide this.');
        }
        if (!ApprovalState::isDecision($state)) {
            $refuse('An approval is approved or returned. Nothing else.');
        }

        $cleanNote = $note === null || trim($note) === '' ? null : SafeText::require($note, 500, 'your note');
        if ($state === ApprovalState::RETURNED && $cleanNote === null) {
            $refuse('Say why it is being returned, so it can be put right.');
        }

        // The same click twice is the same decision once.
        $key = hash('sha256', 'approval:' . $requestId . ':' . (string) $context['user_id'] . ':' . $state);
        $existing = $this->approvals->findByIdempotencyKey($key);
        if ($existing !== null) {
            return ['decided' => true, 'already' => true, 'state' => (string) $existing['state']];
        }

        if ((string) $request['state'] !== ApprovalState::PENDING) {
            $refuse('This request was already ' . ApprovalState::clientLabel((string) $request['state']) . '.');
        }

        $batch = $this->batches->find((string) $request['work_batch_id']);
        if ($batch === null) {
            $refuse('The batch behind this request is no longer there.');
        }

        $this->db->transaction(function () use (
            $engagement,
            $engagementId,
            $organizationId,
            $request,
            $requestId,
            $batch,
            $state,
            $cleanNote,
            $key,
            $context
        ): void {
            if (!$this->approvals->decide($requestId, $state, $key, (string) $context['user_id'], $context['contact_id'], $cleanNote)) {
                throw new \RuntimeException('This request was decided a moment ago. Nothing was recorded twice.');
            }

            if ($state === ApprovalState::APPROVED) {
                // Approved. The batch stays at "awaiting approval" until the
                // submission is recorded against this approval; the card reads
                // the approval and says "approved, submission next".
                $this->moveBatch($batch, BatchStage::APPROVAL_PENDING, [
                    'next_owner'  => BatchStage::OWNER_SOFT_APPEALS,
                    'next_action' => 'Approved. We are submitting it to the payer',
                ]);
            } else {
                $this->moveBatch($batch, BatchStage::RECOMMENDED, [
                    'next_owner'  => BatchStage::OWNER_SOFT_APPEALS,
                    'next_action' => 'Returned with your note. We revise it and ask again',
                ]);
            }

            $this->timeline->record(
                $engagementId,
                'approval.' . $state,
                ApprovalState::timelineLabel($state),
                null,
                null,
                StatusEventRepository::ACTOR_CLIENT,
                (string) $context['user_id'],
                ['batch_ref' => (string) $batch['public_ref'], 'approval_state' => $state]
            );

            $this->closeApprovalCardIfNonePending($engagement, (string) $context['user_id']);

            $this->audit->record('approval.decide', 'success', 'approval_request', $requestId, [
                'approval_state'  => $state,
                'batch_ref'       => (string) $batch['public_ref'],
                'idempotency_key' => $key,
            ], $organizationId);

            $this->notifyDecided($engagement, $request, $batch, $state);

            $this->checklist->sync($engagementId);
        });

        return ['decided' => true, 'already' => false, 'state' => $state];
    }

    // ------------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------------

    /**
     * The approver for the scope: an existing contact by id, a new person by
     * name and email, or nobody. A new person becomes a contact, a
     * passwordless user and a submission approver in this organization,
     * the same way the preferences page does it.
     *
     * @param array<string,mixed> $input
     */
    private function resolveApprover(string $organizationId, array $input): ?string
    {
        $chosen = trim((string) ($input['approver_contact'] ?? ''));
        $name = SafeText::requireLine((string) ($input['approver_name'] ?? ''), 120, 'the approver name');
        $email = strtolower(trim((string) ($input['approver_email'] ?? '')));
        $role = SafeText::requireLine((string) ($input['approver_role'] ?? ''), 80, 'the approver title');

        if ($name !== '' || $email !== '') {
            if ($name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException('Not saved: a new approver needs a name and a work email.');
            }
            $contact = $this->contacts->upsert($organizationId, $name, $email, $role === '' ? null : $role);
            $user = $this->users->findByEmail($email);
            $userId = $user === null
                ? $this->users->create($email, null, $contact['id'])
                : (string) $user['id'];
            $this->memberships->grant($userId, Role::SUBMISSION_APPROVER, $organizationId);
            return $contact['id'];
        }

        if ($chosen === '') {
            return null;
        }
        $contact = $this->contacts->find($chosen);
        if ($contact === null || (string) $contact['organization_id'] !== $organizationId) {
            throw new \RuntimeException('Not saved: that approver is not a contact at this practice.');
        }
        // Holding the role is what lets them decide. A contact named on the
        // preferences page as something else gets it here.
        $user = $this->users->findByEmail((string) $contact['work_email']);
        $userId = $user === null
            ? $this->users->create((string) $contact['work_email'], null, (string) $contact['id'])
            : (string) $user['id'];
        $this->memberships->grant($userId, Role::SUBMISSION_APPROVER, $organizationId);
        return (string) $contact['id'];
    }

    /**
     * Move a batch by the recovery rules, with the row-version guard.
     *
     * @param array<string,mixed> $batch
     * @param array<string,mixed> $changes
     */
    private function moveBatch(array $batch, string $to, array $changes): void
    {
        $changes['stage'] = $to;
        $fresh = $this->batches->find((string) $batch['id']);
        if ($fresh === null) {
            throw new \RuntimeException('That batch is gone.');
        }
        if (!$this->batches->patch((string) $batch['id'], $changes, (int) $fresh['row_version'])) {
            throw new \RuntimeException('This batch changed while you were looking at it. Reload and try again.');
        }
    }

    /** @param array<string,mixed> $engagement */
    private function closeApprovalCardIfNonePending(array $engagement, ?string $userId): void
    {
        if ($this->approvals->pendingForEngagement((string) $engagement['id']) === []) {
            $this->requests->closeKind($engagement, ActionRequestKind::APPROVE_SUBMISSION, $userId);
        }
    }

    private function readCount(mixed $raw, int $fallback, string $what): int
    {
        $text = trim((string) ($raw ?? ''));
        if ($text === '') {
            return $fallback;
        }
        if (preg_match('/^\d{1,6}$/', $text) !== 1) {
            throw new \RuntimeException('Not saved: ' . $what . ' has to be a whole number.');
        }
        return (int) $text;
    }

    private function readCents(mixed $raw, int $fallback, string $what): int
    {
        $text = trim((string) ($raw ?? ''));
        if ($text === '') {
            return $fallback;
        }
        $cents = Money::parseCents($text);
        if ($cents === null) {
            throw new \RuntimeException('Not saved: ' . $what . ' has to be a plain dollar figure, like 12,345.67.');
        }
        return $cents;
    }

    private function readDate(mixed $raw, string $what): ?string
    {
        $text = trim((string) ($raw ?? ''));
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            throw new \RuntimeException('Not saved: ' . $what . ' has to be a date, like 2026-09-30.');
        }
        return $text . ' 12:00:00';
    }

    private function currentStage(string $engagementId): string
    {
        $row = $this->engagements->find($engagementId);
        if ($row === null) {
            throw new \RuntimeException('No such engagement.');
        }
        return (string) $row['stage'];
    }

    private function requireActive(string $engagementId, string $verb): void
    {
        $stage = $this->currentStage($engagementId);
        if ($stage !== Stage::RECOVERY_ACTIVE) {
            $this->refuse('recovery.refused', $engagementId, $verb, $stage, Stage::RECOVERY_ACTIVE);
        }
    }

    private function refuse(string $action, string $engagementId, string $verb, string $stage, string $expected): void
    {
        $this->audit->record($action, 'denied', 'engagement', $engagementId, [
            'reason'     => 'cannot ' . $verb,
            'from_stage' => $stage,
            'to_stage'   => $expected,
        ]);
        throw new \RuntimeException(
            'You cannot ' . $verb . ' at "' . Stage::staffLabel($stage) . '". It needs to be at "'
            . Stage::staffLabel($expected) . '".'
        );
    }

    // ------------------------------------------------------------------
    // The three emails. Section 16.1: a notice and a link, never the item.
    // ------------------------------------------------------------------

    /**
     * Section 16.2, template 12. The approver is told something is waiting.
     * The summary, the count and the amount stay in the room.
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $approver
     */
    private function notifyApprover(array $request, array $engagement, array $approver): void
    {
        $room = rtrim($this->config->string('SA_APP_URL'), '/') . '/soft-appeals-room?section=approvals';
        $organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');

        $lines = [];
        $lines[] = 'Hello ' . self::firstName((string) $approver['name']) . ',';
        $lines[] = '';
        $lines[] = wordwrap(
            'A submission for ' . $organization . ' is waiting for your approval in the '
            . 'Soft Appeals Recovery Room. Nothing goes to the payer until you have '
            . 'approved it there.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = 'Open the room: ' . $room;
        if ($request['due_at'] !== null) {
            $lines[] = '';
            $lines[] = 'It is asked for by ' . $this->clock->displayDate((string) $request['due_at']) . '.';
        }
        $lines[] = '';
        $lines[] = wordwrap(
            'Do not reply with patient, member, claim, clinical, or other protected '
            . 'health information. The appeal materials are in the secure route you chose.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = 'Nana Frimpongmaa';
        $lines[] = 'Soft Appeals';

        $this->mail->send(
            (string) $approver['work_email'],
            'A Soft Appeals approval is waiting for you',
            implode("\n", $lines) . "\n",
            self::TEMPLATE_APPROVAL_REQUESTED,
            (string) $engagement['id'],
            (string) $engagement['organization_id'],
            hash('sha256', (string) $request['id'] . '|' . self::TEMPLATE_APPROVAL_REQUESTED)
        );
    }

    /**
     * She is told what they decided, at her own address. The note travels
     * only after the screen, and only to her.
     *
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $request
     * @param array<string,mixed> $batch
     */
    private function notifyDecided(array $engagement, array $request, array $batch, string $state): void
    {
        $organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');
        $desk = rtrim($this->config->string('SA_APP_URL'), '/')
            . '/sa-desk.php?view=recovery&e=' . rawurlencode((string) $engagement['public_ref']);

        $lines = [];
        $lines[] = $organization . ' ' . ($state === ApprovalState::APPROVED ? 'approved' : 'returned')
            . ' the submission on batch ' . (string) $batch['public_ref'] . '.';
        $lines[] = '';
        $lines[] = 'Open it on the Desk: ' . $desk;
        $lines[] = '';
        $lines[] = 'Soft Appeals';

        $this->mail->send(
            $this->config->string('SA_OWNER_EMAIL'),
            ($state === ApprovalState::APPROVED ? 'Approved: ' : 'Returned: ') . $organization,
            implode("\n", $lines) . "\n",
            self::TEMPLATE_APPROVAL_DECIDED,
            (string) $engagement['id'],
            (string) $engagement['organization_id'],
            hash('sha256', (string) $request['id'] . '|' . self::TEMPLATE_APPROVAL_DECIDED . '|' . $state)
        );
    }

    /**
     * Section 16.2, template 13. A status update is in the room. Which one
     * is not in the email.
     *
     * @param array<string,mixed> $engagement
     */
    private function notifyStatusUpdate(array $engagement, string $type, string $eventId): void
    {
        $signer = $this->requests->signerContact((string) $engagement['id']);
        if ($signer === null) {
            return;
        }
        $room = rtrim($this->config->string('SA_APP_URL'), '/') . '/soft-appeals-room?section=recovery';
        $organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');

        $lines = [];
        $lines[] = 'Hello ' . self::firstName((string) $signer['name']) . ',';
        $lines[] = '';
        $lines[] = wordwrap(
            'There is a new status update for ' . $organization . ' in your Soft Appeals '
            . 'Recovery Room, under Recovery.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = 'Open the room: ' . $room;
        $lines[] = '';
        $lines[] = wordwrap(
            'Do not reply with patient, member, claim, clinical, or other protected '
            . 'health information.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = 'Nana Frimpongmaa';
        $lines[] = 'Soft Appeals';

        $this->mail->send(
            (string) $signer['work_email'],
            'A Soft Appeals status update is ready',
            implode("\n", $lines) . "\n",
            self::TEMPLATE_STATUS_UPDATE,
            (string) $engagement['id'],
            (string) $engagement['organization_id'],
            hash('sha256', $eventId . '|' . self::TEMPLATE_STATUS_UPDATE . '|' . $type)
        );
    }

    private static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        return $parts === [] || $parts[0] === '' ? 'there' : $parts[0];
    }
}
