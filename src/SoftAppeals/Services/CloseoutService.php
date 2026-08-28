<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Config;
use SoftAppeals\Database;
use SoftAppeals\Domain\ActionRequestKind;
use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Domain\CloseoutStep;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\InvoiceStatus;
use SoftAppeals\Domain\Role;
use SoftAppeals\Domain\SafeText;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\ApprovalRequestRepository;
use SoftAppeals\Repositories\CloseoutRepository;
use SoftAppeals\Repositories\ContactRepository;
use SoftAppeals\Repositories\DocumentRepository;
use SoftAppeals\Repositories\EngagementRepository;
use SoftAppeals\Repositories\InvitationRepository;
use SoftAppeals\Repositories\InvoiceRepository;
use SoftAppeals\Repositories\MembershipRepository;
use SoftAppeals\Repositories\RecoveryScopeRepository;
use SoftAppeals\Repositories\StatusEventRepository;
use SoftAppeals\Repositories\SubmissionEventRepository;
use SoftAppeals\Repositories\UserRepository;
use SoftAppeals\Repositories\WorkBatchRepository;
use SoftAppeals\Support\Clock;
use SoftAppeals\Support\Money;

/**
 * Closeout. Section 7.4, section 15.10, and the last five lines of section 22.
 *
 *   Resolved batches
 *     -> Financial reconciliation   confirmReconciliation()
 *     -> Final report               confirmFinalReport()
 *     -> Access review              decideAccess(), confirmAccessReview()
 *     -> Data disposition confirmed confirmDataDisposition(), which closes
 *     -> Closed
 *
 * Every step is a stage move, checked on the server against the stage the
 * engagement actually sits at, and every step is written once with who
 * confirmed it. The gates that matter:
 *
 *   begin() refuses while any batch in scope is still with the payer or
 *   waiting on an approval, or a follow-up is open. Closeout starts on
 *   resolved batches and nothing else.
 *
 *   confirmReconciliation() refuses while an overturned batch has no
 *   verified figure (zero counts, silence does not), while a fee is not on
 *   an invoice, or while an invoice is still a draft.
 *
 *   confirmAccessReview() and confirmDataDisposition() both refuse while
 *   anybody who can sign in at the practice has no decision recorded. That
 *   is "closeout cannot complete while required access remains open", and
 *   it is checked twice because the second check is the one that closes.
 *
 * Closing seals a Closeout and Data-Disposition Record into the vault as a
 * document of its own kind, hashed and reopenable like every agreement, and
 * moves the engagement to closed, which is terminal.
 */
final class CloseoutService
{
    public const TEMPLATE_CLOSEOUT = 'closeout_available';

    private Config $config;
    private Database $db;
    private Clock $clock;
    private CloseoutRepository $closeouts;
    private InvoiceRepository $invoices;
    private RecoveryScopeRepository $scopes;
    private ApprovalRequestRepository $approvals;
    private SubmissionEventRepository $events;
    private WorkBatchRepository $batches;
    private EngagementRepository $engagements;
    private DocumentRepository $documents;
    private ContactRepository $contacts;
    private UserRepository $users;
    private MembershipRepository $memberships;
    private InvitationRepository $invitations;
    private StatusEventRepository $timeline;
    private EngagementService $engagementService;
    private ReconciliationService $reconciliation;
    private DocumentService $documentService;
    private ActionRequestService $requests;
    private MailService $mail;
    private AuditService $audit;

    public function __construct(
        Config $config,
        Database $db,
        Clock $clock,
        CloseoutRepository $closeouts,
        InvoiceRepository $invoices,
        RecoveryScopeRepository $scopes,
        ApprovalRequestRepository $approvals,
        SubmissionEventRepository $events,
        WorkBatchRepository $batches,
        EngagementRepository $engagements,
        DocumentRepository $documents,
        ContactRepository $contacts,
        UserRepository $users,
        MembershipRepository $memberships,
        InvitationRepository $invitations,
        StatusEventRepository $timeline,
        EngagementService $engagementService,
        ReconciliationService $reconciliation,
        DocumentService $documentService,
        ActionRequestService $requests,
        MailService $mail,
        AuditService $audit
    ) {
        $this->config = $config;
        $this->db = $db;
        $this->clock = $clock;
        $this->closeouts = $closeouts;
        $this->invoices = $invoices;
        $this->scopes = $scopes;
        $this->approvals = $approvals;
        $this->events = $events;
        $this->batches = $batches;
        $this->engagements = $engagements;
        $this->documents = $documents;
        $this->contacts = $contacts;
        $this->users = $users;
        $this->memberships = $memberships;
        $this->invitations = $invitations;
        $this->timeline = $timeline;
        $this->engagementService = $engagementService;
        $this->reconciliation = $reconciliation;
        $this->documentService = $documentService;
        $this->requests = $requests;
        $this->mail = $mail;
        $this->audit = $audit;
    }

    // ------------------------------------------------------------------
    // Reading.
    // ------------------------------------------------------------------

    /**
     * Whether closeout can begin, and if not, the sentence that says why.
     *
     * @param array<string,mixed> $engagement
     * @return array{ok:bool,reason:?string}
     */
    public function canBegin(array $engagement): array
    {
        $engagementId = (string) $engagement['id'];
        $stage = $this->currentStage($engagementId);
        if ($stage !== Stage::RECOVERY_ACTIVE) {
            return ['ok' => false, 'reason' => 'Closeout begins at "Recovery active". This engagement is at "' . Stage::staffLabel($stage) . '".'];
        }
        $scope = $this->scopes->forEngagement($engagementId);
        $inScope = $scope === null ? [] : $this->scopes->batchIds((string) $scope['id']);
        foreach ($this->batches->forEngagement($engagementId) as $batch) {
            if (!in_array((string) $batch['id'], $inScope, true)) {
                continue;
            }
            $batchStage = (string) $batch['stage'];
            if (in_array($batchStage, [BatchStage::APPROVAL_PENDING, BatchStage::SUBMITTED, BatchStage::PAYER_REVIEW], true)) {
                return ['ok' => false, 'reason' => 'Batch ' . (string) $batch['public_ref'] . ' is "' . BatchStage::staffLabel($batchStage) . '". Every batch in scope is resolved before closeout begins.'];
            }
            if ($batchStage === BatchStage::RECOMMENDED) {
                return ['ok' => false, 'reason' => 'Batch ' . (string) $batch['public_ref'] . ' is in scope and was never put to the payer. Submit it, or close it on the Assessments screen, before closeout begins.'];
            }
        }
        if ($this->approvals->pendingForEngagement($engagementId) !== []) {
            return ['ok' => false, 'reason' => 'An approval request is still with the practice.'];
        }
        foreach ($this->events->forEngagement($engagementId) as $event) {
            if ($event['follow_up_due_at'] !== null && $event['follow_up_done_at'] === null) {
                return ['ok' => false, 'reason' => 'A payer follow-up is still open on batch ' . (string) $event['batch_ref'] . '. Close it first.'];
            }
        }
        return ['ok' => true, 'reason' => null];
    }

    /**
     * Whether the open step can be confirmed right now, and why not.
     *
     * @param array<string,mixed> $engagement
     * @return array{step:?string,ok:bool,reason:?string}
     */
    public function stepCheck(array $engagement): array
    {
        $engagementId = (string) $engagement['id'];
        $stage = $this->currentStage($engagementId);
        $step = CloseoutStep::forStage($stage);
        if ($step === null) {
            return ['step' => null, 'ok' => false, 'reason' => null];
        }
        $reason = match ($step) {
            CloseoutStep::RECONCILIATION   => $this->reconciliationBlocker($engagement),
            CloseoutStep::ACCESS_REVIEW,
            CloseoutStep::DATA_DISPOSITION => $this->accessBlocker($engagement),
            default                        => null,
        };
        return ['step' => $step, 'ok' => $reason === null, 'reason' => $reason];
    }

    /**
     * Everything section 15.10 shows, for the Desk and the room alike.
     *
     * @param array<string,mixed> $engagement
     * @return array<string,mixed>
     */
    public function summary(array $engagement): array
    {
        $engagementId = (string) $engagement['id'];
        $closeout = $this->closeouts->forEngagement($engagementId);
        $steps = [];
        $access = [];
        if ($closeout !== null) {
            $this->refreshAccessRows($engagement, $closeout);
            foreach ($this->closeouts->steps((string) $closeout['id']) as $step) {
                $step['label'] = CloseoutStep::label((string) $step['step_key']);
                $step['client_label'] = CloseoutStep::clientLabel((string) $step['step_key']);
                $step['confirmed_by_email'] = $this->userEmail($step['confirmed_by']);
                $steps[] = $step;
            }
            $access = $this->closeouts->accessRows((string) $closeout['id']);
        }

        $final = [];
        foreach ($this->documents->forEngagement($engagementId) as $document) {
            if ((string) $document['status'] === DocumentStatus::EXECUTED) {
                $final[] = $document;
            }
        }

        $batchTotals = ['count' => 0, 'overturned' => 0, 'upheld' => 0, 'closed' => 0];
        foreach ($this->batches->forEngagement($engagementId) as $batch) {
            $batchTotals['count']++;
            $batchTotals['overturned'] += (int) $batch['overturned_count'];
            $batchTotals['upheld'] += (int) $batch['upheld_count'];
            $batchTotals['closed'] += (int) $batch['closed_count'];
        }

        $record = $closeout === null || $closeout['record_document_id'] === null
            ? null
            : $this->documents->find((string) $closeout['record_document_id']);

        return [
            'closeout'      => $closeout,
            'steps'         => $steps,
            'access'        => $access,
            'undecided'     => $closeout === null ? 0 : $this->closeouts->undecidedAccessCount((string) $closeout['id']),
            'money'         => $this->reconciliation->summary($engagement),
            'invoices'      => $this->invoices->forEngagement($engagementId),
            'final'         => $final,
            'record'        => $record,
            'batches'       => $batchTotals,
            'events'        => $this->events->totals($engagementId),
            'disposition'   => $closeout === null || $closeout['data_disposition'] === null
                ? null
                : CloseoutStep::dispositionLabel((string) $closeout['data_disposition']),
            'access_outcome' => $closeout === null ? null : $closeout['access_outcome'],
            'closed_at'     => $closeout === null ? null : $closeout['closed_at'],
            'closed_by_email' => $closeout === null ? null : $this->userEmail($closeout['closed_by']),
            'started_by_email' => $closeout === null ? null : $this->userEmail($closeout['started_by']),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function inCloseout(): array
    {
        return $this->closeouts->engagementsInCloseout();
    }

    // ------------------------------------------------------------------
    // The steps.
    // ------------------------------------------------------------------

    /**
     * Begin. "Recovery active" to "financial reconciliation". Section 17.1:
     * the access and data-disposition checklist is created here.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @return array<string,mixed> the closeout row
     */
    public function begin(array $engagement, ?string $userId = null): array
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];

        $check = $this->canBegin($engagement);
        if (!$check['ok']) {
            $this->audit->record('closeout.begin', 'denied', 'engagement', $engagementId, [
                'reason' => mb_substr((string) $check['reason'], 0, 200),
            ], $organizationId);
            throw new \RuntimeException((string) $check['reason']);
        }

        return $this->db->transaction(function () use ($engagement, $engagementId, $organizationId, $userId): array {
            $closeout = $this->closeouts->open($engagementId, $organizationId, $userId);
            $this->refreshAccessRows($engagement, $closeout);
            $this->engagementService->move(
                $engagementId,
                Stage::RECONCILIATION,
                'We began closing this engagement out',
                'closeout.started',
                $userId
            );
            $this->audit->record('closeout.begin', 'success', 'closeout', (string) $closeout['id'], [], $organizationId);
            return $closeout;
        });
    }

    /**
     * Close an engagement that ran its recovery and recovered nothing.
     * "Recovery active" to "closed without recovery", terminal. Refused the
     * moment a cent has been verified: that engagement closes through the
     * money.
     *
     * @param array<string,mixed> $engagement joined with its organization
     */
    public function closeWithoutRecovery(array $engagement, string $reason, ?string $userId = null): void
    {
        $engagementId = (string) $engagement['id'];
        $check = $this->canBegin($engagement);
        if (!$check['ok']) {
            throw new \RuntimeException((string) $check['reason']);
        }
        $money = $this->reconciliation->summary($engagement);
        if ($money['verified_cents'] > 0) {
            throw new \RuntimeException(
                'A reimbursement has been verified on this engagement, so it closes through '
                . 'financial reconciliation, not as "no recovery".'
            );
        }
        $reason = SafeText::require($reason, 500, 'the reason');
        if (mb_strlen($reason) < 10) {
            throw new \RuntimeException('Say why nothing was recovered, in a sentence. It goes on the record.');
        }
        $this->db->transaction(function () use ($engagement, $engagementId, $reason, $userId): void {
            $this->engagementService->move(
                $engagementId,
                Stage::CLOSED_NO_RECOVERY,
                'This engagement was closed with no recovery',
                'closeout.closed_without_recovery',
                $userId,
                ['reason' => $reason]
            );
            $this->audit->record('closeout.closed_without_recovery', 'success', 'engagement', $engagementId, [
                'reason' => mb_substr($reason, 0, 200),
            ], (string) $engagement['organization_id']);
        });
    }

    /**
     * Step one. The money is final.
     *
     * @param array<string,mixed> $engagement joined with its organization
     */
    public function confirmReconciliation(array $engagement, ?string $note, ?string $userId = null): void
    {
        $this->confirmStep($engagement, CloseoutStep::RECONCILIATION, $note, $userId, function () use ($engagement): void {
            $blocker = $this->reconciliationBlocker($engagement);
            if ($blocker !== null) {
                throw new \RuntimeException($blocker);
            }
        });
    }

    /**
     * Step two. The final report the practice reads. Aggregate only,
     * screened, and it goes on the closeout row as well as the step.
     *
     * @param array<string,mixed> $engagement joined with its organization
     */
    public function confirmFinalReport(array $engagement, string $summary, ?string $userId = null): void
    {
        $clean = SafeText::require($summary, 2000, 'the final report');
        if (mb_strlen($clean) < 40) {
            throw new \RuntimeException('Not saved: the final report is what the practice keeps. Write at least a few sentences.');
        }
        $this->confirmStep($engagement, CloseoutStep::FINAL_REPORT, $clean, $userId, function () use ($engagement, $clean): void {
            $closeout = $this->closeouts->forEngagement((string) $engagement['id']);
            if ($closeout === null) {
                throw new \RuntimeException('Closeout has not begun on this engagement.');
            }
            if (!$this->closeouts->patch((string) $closeout['id'], ['final_summary' => $clean], (int) $closeout['row_version'])) {
                throw new \RuntimeException('This closeout changed while you were looking at it. Reload and try again.');
            }
        });
    }

    /**
     * One person's access, decided. Removing it revokes every client role
     * they hold at this practice, cancels any live invitation to their
     * address, and deactivates the sign-in if it holds nothing anywhere
     * else. Their session ends on its next request, because context() reads
     * the roles off the database every time.
     *
     * @param array<string,mixed> $engagement joined with its organization
     */
    public function decideAccess(array $engagement, string $rowId, string $decision, ?string $userId = null): void
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];
        $stage = $this->currentStage($engagementId);
        if ($stage !== Stage::ACCESS_REVIEW) {
            $this->refuse('closeout.access', $engagementId, 'decide access', $stage, Stage::ACCESS_REVIEW);
        }
        $closeout = $this->closeouts->forEngagement($engagementId);
        if ($closeout === null) {
            throw new \RuntimeException('Closeout has not begun on this engagement.');
        }
        $row = $this->closeouts->accessRow((string) $closeout['id'], $rowId);
        if ($row === null) {
            throw new \RuntimeException('That person is not on this access review.');
        }
        if (!CloseoutStep::isValidAccessDecision($decision)) {
            throw new \RuntimeException('Access is removed or retained. Nothing else.');
        }

        $this->db->transaction(function () use ($engagement, $engagementId, $organizationId, $closeout, $row, $decision, $userId): void {
            if (!$this->closeouts->decideAccess((string) $row['id'], $decision, $userId)) {
                throw new \RuntimeException('That person was already decided. Nothing was recorded twice.');
            }
            if ($decision === CloseoutStep::ACCESS_REMOVED) {
                $this->removeAccess((string) $row['user_id'], (string) $row['email'], $organizationId);
            }
            $this->audit->record('closeout.access', 'success', 'access_review', (string) $row['id'], [
                'decision' => $decision,
            ], $organizationId);
        });
    }

    /**
     * Step three. Refused while anybody is undecided.
     *
     * @param array<string,mixed> $engagement joined with its organization
     */
    public function confirmAccessReview(array $engagement, ?string $note, ?string $userId = null): void
    {
        $this->confirmStep($engagement, CloseoutStep::ACCESS_REVIEW, $note, $userId, function () use ($engagement): void {
            $blocker = $this->accessBlocker($engagement);
            if ($blocker !== null) {
                throw new \RuntimeException($blocker);
            }
            $closeout = $this->closeouts->forEngagement((string) $engagement['id']);
            if ($closeout === null) {
                throw new \RuntimeException('Closeout has not begun on this engagement.');
            }
            $removed = 0;
            $retained = 0;
            foreach ($this->closeouts->accessRows((string) $closeout['id']) as $row) {
                if ((string) $row['decision'] === CloseoutStep::ACCESS_REMOVED) {
                    $removed++;
                } else {
                    $retained++;
                }
            }
            $outcome = $removed > 0 && $retained > 0 ? 'mixed' : ($retained > 0 ? 'retained' : 'removed');
            if (!$this->closeouts->patch((string) $closeout['id'], ['access_outcome' => $outcome], (int) $closeout['row_version'])) {
                throw new \RuntimeException('This closeout changed while you were looking at it. Reload and try again.');
            }
        });
    }

    /**
     * Step four, and the close. Records the disposition, seals the closeout
     * record into the vault, moves the engagement to closed, tells the
     * practice. Refused while any access is undecided, again, because this
     * is the step that ends the engagement.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @param array<string,mixed> $input disposition, note
     * @return array<string,mixed> the sealed record document
     */
    public function confirmDataDisposition(array $engagement, array $input, ?string $userId = null): array
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];

        $disposition = trim((string) ($input['disposition'] ?? ''));
        if (!CloseoutStep::isValidDisposition($disposition)) {
            throw new \RuntimeException('Say what happened to the practice\'s material: returned, destroyed, or retained under the agreement.');
        }
        $note = trim((string) ($input['note'] ?? '')) === ''
            ? null
            : SafeText::require((string) $input['note'], 500, 'the note');

        $sealed = null;
        $this->confirmStep($engagement, CloseoutStep::DATA_DISPOSITION, $note, $userId, function () use ($engagement, $engagementId, $organizationId, $disposition, $note, $userId, &$sealed): void {
            $blocker = $this->accessBlocker($engagement);
            if ($blocker !== null) {
                throw new \RuntimeException($blocker);
            }
            $closeout = $this->closeouts->forEngagement($engagementId);
            if ($closeout === null) {
                throw new \RuntimeException('Closeout has not begun on this engagement.');
            }
            $now = $this->clock->nowUtc();
            if (!$this->closeouts->patch((string) $closeout['id'], [
                'data_disposition' => $disposition,
                'disposition_note' => $note,
            ], (int) $closeout['row_version'])) {
                throw new \RuntimeException('This closeout changed while you were looking at it. Reload and try again.');
            }

            // The record, sealed. Its context is the closeout as it stands
            // this second, which is why it is rendered inside the step and
            // not before it.
            $fresh = $this->closeouts->forEngagement($engagementId);
            $sealed = $this->documentService->seal(
                $engagement,
                DocumentKind::CLOSEOUT,
                $this->recordContext($engagement, $fresh ?? $closeout, $now, $userId),
                $userId
            );

            $again = $this->closeouts->forEngagement($engagementId);
            if ($again === null || !$this->closeouts->patch((string) $again['id'], [
                'record_document_id' => (string) $sealed['id'],
                'closed_at'          => $now,
                'closed_by'          => $userId,
            ], (int) $again['row_version'])) {
                throw new \RuntimeException('The closeout could not be stamped closed.');
            }

            $this->requests->closeKind($engagement, ActionRequestKind::REVIEW_CLOSEOUT, $userId);
            $this->notifyClosed($engagement, $sealed);
        });

        if ($sealed === null) {
            throw new \RuntimeException('The closeout record was not sealed.');
        }
        return $sealed;
    }

    // ------------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------------

    /**
     * The shape every step shares: the stage is checked, the work runs, the
     * step is written once, the stage moves, all in one transaction.
     */
    private function confirmStep(array $engagement, string $step, ?string $note, ?string $userId, callable $work): void
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];
        $expected = CloseoutStep::stage($step);
        $stage = $this->currentStage($engagementId);
        if ($stage !== $expected) {
            $this->refuse('closeout.' . $step, $engagementId, 'confirm ' . CloseoutStep::label($step), $stage, $expected);
        }
        $closeout = $this->closeouts->forEngagement($engagementId);
        if ($closeout === null) {
            throw new \RuntimeException('Closeout has not begun on this engagement.');
        }

        $this->db->transaction(function () use ($engagementId, $organizationId, $closeout, $step, $note, $userId, $work): void {
            $work();
            if (!$this->closeouts->confirmStep((string) $closeout['id'], $step, $userId, $note)) {
                throw new \RuntimeException(CloseoutStep::label($step) . ' was already confirmed. Nothing was recorded twice.');
            }
            $to = CloseoutStep::stageAfter($step);
            $this->engagementService->move(
                $engagementId,
                $to,
                $to === Stage::CLOSED ? 'This engagement is closed' : CloseoutStep::clientLabel($step),
                'closeout.' . $step,
                $userId
            );
            $this->audit->record('closeout.' . $step, 'success', 'closeout', (string) $closeout['id'], [
                'to_stage' => $to,
            ], $organizationId);
        });
    }

    /** Why reconciliation cannot be confirmed yet, or null. */
    private function reconciliationBlocker(array $engagement): ?string
    {
        $engagementId = (string) $engagement['id'];
        foreach ($this->reconciliation->verifiable($engagement) as $row) {
            if ($row['in_scope'] && !$row['has_verified']) {
                return 'Batch ' . (string) $row['batch']['public_ref'] . ' was overturned and has no verified figure. '
                    . 'Verify what arrived, even if that is $0.00, before the money is called reconciled.';
            }
        }
        $money = $this->reconciliation->summary($engagement);
        if ($money['uninvoiced_count'] > 0 && $money['uninvoiced_cents'] !== 0) {
            return Money::format($money['uninvoiced_cents']) . ' in fees is not on an invoice yet. Create the invoice first.';
        }
        if ($money['draft_count'] > 0) {
            return 'An invoice is still a draft. Issue it or void it first.';
        }
        return null;
    }

    /** Why access cannot be called reviewed yet, or null. */
    private function accessBlocker(array $engagement): ?string
    {
        $closeout = $this->closeouts->forEngagement((string) $engagement['id']);
        if ($closeout === null) {
            return 'Closeout has not begun on this engagement.';
        }
        $this->refreshAccessRows($engagement, $closeout);
        $undecided = $this->closeouts->undecidedAccessCount((string) $closeout['id']);
        if ($undecided > 0) {
            return $undecided . ' ' . ($undecided === 1 ? 'person' : 'people') . ' who can sign in at this practice '
                . ($undecided === 1 ? 'has' : 'have') . ' no access decision yet. Closeout cannot complete while access is open.';
        }
        return null;
    }

    /**
     * Everybody holding a client role at the practice goes on the review.
     * Run on every read, so a role granted after closeout began still has to
     * be decided on.
     *
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $closeout
     */
    private function refreshAccessRows(array $engagement, array $closeout): void
    {
        if ($closeout['closed_at'] !== null) {
            return;
        }
        $organizationId = (string) $engagement['organization_id'];
        foreach ($this->memberships->usersForOrganization($organizationId) as $person) {
            $user = $this->users->find((string) $person['user_id']);
            if ($user === null) {
                continue;
            }
            $contact = $user['contact_id'] === null ? null : $this->contacts->find((string) $user['contact_id']);
            $this->closeouts->addAccessRow(
                (string) $closeout['id'],
                (string) $user['id'],
                (string) $user['email'],
                $contact === null ? null : (string) $contact['name'],
                $person['roles']
            );
        }
    }

    private function removeAccess(string $userId, string $email, string $organizationId): void
    {
        foreach (Role::clientRoles() as $role) {
            $this->memberships->revoke($userId, $role, $organizationId);
        }
        $this->invitations->revokeAllForEmail($email);
        if ($this->memberships->rolesAnywhere($userId) === []) {
            $this->users->deactivate($userId);
        }
        $this->audit->record('closeout.access_removed', 'success', 'user', $userId, [], $organizationId);
    }

    /**
     * Everything the sealed record prints, resolved to strings.
     *
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $closeout
     * @return array<string,string>
     */
    private function recordContext(array $engagement, array $closeout, string $closedAt, ?string $closedBy): array
    {
        $summary = $this->summary($engagement);
        $money = $summary['money'];

        $steps = [];
        foreach ($summary['steps'] as $step) {
            if ((string) $step['step_key'] === CloseoutStep::DATA_DISPOSITION) {
                continue;
            }
            $who = $step['confirmed_by_email'] ?? 'Soft Appeals';
            $when = $step['confirmed_at'] === null ? 'now' : $this->clock->displayDate((string) $step['confirmed_at']);
            $steps[] = '  ' . (string) $step['label'] . ': confirmed by ' . $who . ' on ' . $when;
        }
        // The data disposition step is confirmed by the same request that
        // seals this record, so its stamp is not written yet.
        $steps[] = '  ' . CloseoutStep::label(CloseoutStep::DATA_DISPOSITION) . ': confirmed by '
            . ($this->userEmail($closedBy) ?? 'Soft Appeals') . ' on ' . $this->clock->displayDate($closedAt);

        $access = [];
        foreach ($summary['access'] as $row) {
            $access[] = '  ' . (string) $row['email']
                . ($row['contact_name'] === null ? '' : ' (' . (string) $row['contact_name'] . ')')
                . ', ' . str_replace(',', ', ', (string) $row['roles'])
                . ': ' . ($row['decision'] === null ? 'undecided' : CloseoutStep::accessLabel((string) $row['decision']));
        }

        $invoices = [];
        foreach ($summary['invoices'] as $invoice) {
            if ((string) $invoice['status'] === InvoiceStatus::DRAFT) {
                continue;
            }
            $invoices[] = '  ' . (string) $invoice['public_ref'] . ': ' . Money::format((int) $invoice['total_cents'])
                . ', ' . InvoiceStatus::staffLabel((string) $invoice['status']);
        }

        $final = [];
        foreach ($summary['final'] as $document) {
            $final[] = '  ' . DocumentKind::label((string) $document['kind']) . ' ' . (string) $document['public_ref']
                . ' v' . (int) $document['version'] . ', executed ' . $this->clock->displayDate((string) $document['executed_at']);
        }

        return [
            'closeout_started'     => $this->clock->displayDate((string) $closeout['started_at']),
            'closeout_closed'      => $this->clock->displayDate($closedAt),
            'closeout_steps'       => implode("\n", $steps),
            'closeout_summary'     => (string) ($closeout['final_summary'] ?? 'No final summary was written.'),
            'closeout_batches'     => (string) $summary['batches']['count'] . ' batch(es): '
                . (string) $summary['events']['submitted_count'] . ' claims submitted, '
                . (string) $summary['events']['overturned_count'] . ' overturned, '
                . (string) $summary['events']['upheld_count'] . ' upheld',
            'closeout_verified'    => (string) $money['verified'] . ' verified, ' . (string) $money['taken_back']
                . ' taken back, ' . (string) $money['net'] . ' net',
            'closeout_fee'         => (string) $money['fee_net'] . ' at ' . (string) $money['rate']
                . ($money['agreement_ref'] === null ? '' : ', under ' . (string) $money['agreement_ref']),
            'closeout_invoices'    => $invoices === [] ? '  No invoice was issued.' : implode("\n", $invoices),
            'closeout_access'      => $access === [] ? '  Nobody held access.' : implode("\n", $access),
            'closeout_disposition' => CloseoutStep::dispositionLabel((string) $closeout['data_disposition'])
                . ($closeout['disposition_note'] === null ? '' : '. ' . (string) $closeout['disposition_note']),
            'closeout_documents'   => $final === [] ? '  None.' : implode("\n", $final),
        ];
    }

    /**
     * Section 16.2, template 16. The practice is told the engagement is
     * closed and the record is in the room.
     *
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $record
     */
    private function notifyClosed(array $engagement, array $record): void
    {
        $signer = $this->requests->signerContact((string) $engagement['id']);
        if ($signer === null) {
            return;
        }
        $room = rtrim($this->config->string('SA_APP_URL'), '/') . '/soft-appeals-room?section=closeout';
        $organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');
        $lines = [];
        $lines[] = 'Hello ' . self::firstName((string) $signer['name']) . ',';
        $lines[] = '';
        $lines[] = wordwrap(
            'The Soft Appeals engagement for ' . $organization . ' is closed. Your closeout '
            . 'record, your final report and your signed agreements are in your Recovery '
            . 'Room, under Closeout, and they stay there.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = 'Open the room: ' . $room;
        $lines[] = '';
        $lines[] = wordwrap(
            'Nothing is attached to this email on purpose. Do not reply with patient, '
            . 'member, claim, clinical, or other protected health information.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = 'Thank you for working with us.';
        $lines[] = '';
        $lines[] = 'Nana Frimpongmaa';
        $lines[] = 'Soft Appeals';

        $this->mail->send(
            (string) $signer['work_email'],
            'Your Soft Appeals engagement is closed',
            implode("\n", $lines) . "\n",
            self::TEMPLATE_CLOSEOUT,
            (string) $engagement['id'],
            (string) $engagement['organization_id'],
            hash('sha256', (string) $record['id'] . '|' . self::TEMPLATE_CLOSEOUT)
        );
    }

    private function userEmail(?string $userId): ?string
    {
        if ($userId === null || $userId === '') {
            return null;
        }
        $user = $this->users->find($userId);
        return $user === null ? null : (string) $user['email'];
    }

    private function currentStage(string $engagementId): string
    {
        $row = $this->engagements->find($engagementId);
        if ($row === null) {
            throw new \RuntimeException('No such engagement.');
        }
        return (string) $row['stage'];
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

    private static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        return $parts === [] || $parts[0] === '' ? 'there' : $parts[0];
    }
}
