<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Config;
use SoftAppeals\Database;
use SoftAppeals\Domain\ActionRequestKind;
use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Domain\ClientDecision;
use SoftAppeals\Domain\SafeText;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\ActionRequestRepository;
use SoftAppeals\Repositories\AssessmentRepository;
use SoftAppeals\Repositories\ContactRepository;
use SoftAppeals\Repositories\EngagementRepository;
use SoftAppeals\Repositories\PreferenceRepository;
use SoftAppeals\Repositories\StatusEventRepository;
use SoftAppeals\Repositories\WorkBatchRepository;
use SoftAppeals\Support\Clock;
use SoftAppeals\Support\Money;

/**
 * The assessment, section 7.2, from the secure route opening to the practice's
 * decision. Phase 5.
 *
 * Every milestone is one method, every method reads the stage off the
 * database before it moves anything, and every move goes through
 * EngagementService so the practice's timeline and the audit trail are
 * written together. Section 22's Phase 5 acceptance, "milestone changes
 * create status and audit events", is that shape rather than a promise.
 *
 * The data boundary holds throughout. The counts are aggregates, the money is
 * integer cents, and the summary the practice reads is screened before it is
 * stored. There is no method here that takes a claim.
 */
final class AssessmentService
{
    public const TEMPLATE_AVAILABLE = 'assessment_available';
    public const TEMPLATE_DECISION  = 'client_decision_recorded';

    private Config $config;
    private Database $db;
    private Clock $clock;
    private AssessmentRepository $assessments;
    private ActionRequestRepository $requestRows;
    private EngagementRepository $engagements;
    private WorkBatchRepository $batches;
    private ContactRepository $contacts;
    private PreferenceRepository $preferences;
    private StatusEventRepository $timeline;
    private EngagementService $engagementService;
    private WorkBatchService $batchService;
    private ChecklistService $checklist;
    private ActionRequestService $requests;
    private MailService $mail;
    private AuditService $audit;

    public function __construct(
        Config $config,
        Database $db,
        Clock $clock,
        AssessmentRepository $assessments,
        ActionRequestRepository $requestRows,
        EngagementRepository $engagements,
        WorkBatchRepository $batches,
        ContactRepository $contacts,
        PreferenceRepository $preferences,
        StatusEventRepository $timeline,
        EngagementService $engagementService,
        WorkBatchService $batchService,
        ChecklistService $checklist,
        ActionRequestService $requests,
        MailService $mail,
        AuditService $audit
    ) {
        $this->config = $config;
        $this->db = $db;
        $this->clock = $clock;
        $this->assessments = $assessments;
        $this->requestRows = $requestRows;
        $this->engagements = $engagements;
        $this->batches = $batches;
        $this->contacts = $contacts;
        $this->preferences = $preferences;
        $this->timeline = $timeline;
        $this->engagementService = $engagementService;
        $this->batchService = $batchService;
        $this->checklist = $checklist;
        $this->requests = $requests;
        $this->mail = $mail;
        $this->audit = $audit;
    }

    // ------------------------------------------------------------------
    // Reading.
    // ------------------------------------------------------------------

    /**
     * The assessment row for an engagement past the gate, or null before it.
     *
     * @param array<string,mixed> $engagement
     * @return array<string,mixed>|null
     */
    public function forEngagement(array $engagement): ?array
    {
        $stage = $this->currentStage((string) $engagement['id']);
        if (!Stage::phiGatePassed($stage)) {
            return null;
        }
        return $this->assessments->ensure((string) $engagement['id'], (string) $engagement['organization_id']);
    }

    /**
     * Everything the overview cards need, section 15.4, in one array. Both
     * the Desk and the Recovery Room read this, so the two never disagree.
     *
     * @param array<string,mixed> $engagement
     * @return array<string,mixed>
     */
    public function overview(array $engagement): array
    {
        $engagementId = (string) $engagement['id'];
        $stage = $this->currentStage($engagementId);
        $assessment = $this->forEngagement($engagement);
        $totals = $this->batches->totals($engagementId);
        $items = $this->checklist->sync($engagementId);
        $openClient = $this->requestsFor($engagementId, ActionRequestKind::OWNER_CLIENT, true);

        $progress = match ($stage) {
            Stage::SECURE_INTAKE_READY    => 'Waiting for the denials',
            Stage::RECEIPT_CONFIRMED      => 'Denials received, not started',
            Stage::ASSESSMENT_IN_PROGRESS => 'In progress',
            Stage::ASSESSMENT_QA          => 'In quality review',
            Stage::ASSESSMENT_DELIVERED,
            Stage::CLIENT_DECISION_PENDING => 'Delivered',
            default => Stage::phiGatePassed($stage) ? 'Delivered' : 'Not started',
        };

        return [
            'stage'            => $stage,
            'assessment'       => $assessment,
            'received'         => $assessment === null || $assessment['received_count'] === null
                ? null
                : (int) $assessment['received_count'],
            'expected'         => $assessment === null || $assessment['expected_count'] === null
                ? AssessmentRepository::DEFAULT_EXPECTED
                : (int) $assessment['expected_count'],
            'client_confirmed' => $assessment !== null && $assessment['client_confirmed_at'] !== null,
            'progress'         => $progress,
            'delivered'        => $assessment !== null && $assessment['delivered_at'] !== null,
            'recommended'      => $assessment === null || $assessment['recommended_count'] === null
                ? null
                : (int) $assessment['recommended_count'],
            'recommended_amount' => $assessment === null || $assessment['recommended_amount_cents'] === null
                ? null
                : Money::format((int) $assessment['recommended_amount_cents']),
            'decision'         => $assessment === null || $assessment['decision'] === null
                ? null
                : (string) $assessment['decision'],
            'totals'           => $totals,
            'checklist'        => $items,
            'checklist_progress' => ChecklistService::progress($items),
            'client_requests_open' => count($openClient),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function requestsFor(string $engagementId, ?string $owner = null, bool $openOnly = false): array
    {
        $repository = $this->requestsRepository();
        return $openOnly
            ? $repository->openForEngagement($engagementId, $owner)
            : $repository->forEngagement($engagementId, $owner);
    }

    // ------------------------------------------------------------------
    // Her milestones.
    // ------------------------------------------------------------------

    /**
     * Aggregate receipt confirmation, section 7.2 and the automation table in
     * section 17: the secure route delivered a set, she records how many.
     *
     * Opens the first batch from the same numbers, so the practice's card and
     * her count can never disagree, and asks the practice to confirm the
     * count. Nothing here is a claim.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @param array<string,mixed> $batchFields from WorkBatchService::fieldsFromInput
     * @return array<string,mixed> the assessment row
     */
    public function confirmReceipt(
        array $engagement,
        int $receivedCount,
        ?int $expectedCount,
        array $batchFields,
        ?string $userId = null
    ): array {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];

        $this->requireStage($engagementId, Stage::SECURE_INTAKE_READY, 'confirm receipt');
        if ($receivedCount < 0 || $receivedCount > 999999) {
            throw new \RuntimeException('The received count has to be a whole number.');
        }

        return $this->db->transaction(function () use (
            $engagement,
            $engagementId,
            $organizationId,
            $receivedCount,
            $expectedCount,
            $batchFields,
            $userId
        ): array {
            $assessment = $this->assessments->ensure($engagementId, $organizationId);
            $now = $this->clock->nowUtc();

            $this->assessments->patch((string) $assessment['id'], [
                'received_count'       => $receivedCount,
                'expected_count'       => $expectedCount ?? (int) ($assessment['expected_count'] ?? AssessmentRepository::DEFAULT_EXPECTED),
                'receipt_confirmed_at' => $now,
                'receipt_confirmed_by' => $userId,
            ]);

            // The stage moves first, because the batch service refuses a batch
            // on an engagement that has not passed the gate, and this move is
            // what takes it from "ready" to "received".
            $this->engagementService->move(
                $engagementId,
                Stage::RECEIPT_CONFIRMED,
                'Initial denial set received',
                'assessment.receipt_confirmed',
                $userId,
                ['count' => (string) $receivedCount]
            );

            $batchFields['label'] = $batchFields['label'] ?? 'Initial set';
            $batchFields['claim_count'] = $batchFields['claim_count'] ?? $receivedCount;
            $batchFields['received_count'] = $receivedCount;
            $batchFields['stage'] = BatchStage::RECEIVED;
            $this->batchService->open($engagement, $batchFields, $userId);

            $this->requests->open(
                $engagement,
                ActionRequestKind::CONFIRM_RECEIPT_COUNT,
                'We recorded ' . $receivedCount . ' denials received. Confirm that matches what you sent.',
                null,
                $userId
            );

            $this->audit->record('assessment.receipt_confirmed', 'success', 'assessment', (string) $assessment['id'], [
                'count' => (string) $receivedCount,
            ], $organizationId);

            return $this->assessments->ensure($engagementId, $organizationId);
        });
    }

    /**
     * Start the assessment. Every received batch goes into review.
     *
     * @param array<string,mixed> $engagement
     */
    public function start(array $engagement, ?string $userId = null): void
    {
        $engagementId = (string) $engagement['id'];
        $this->requireStage($engagementId, Stage::RECEIPT_CONFIRMED, 'start the assessment');

        $this->db->transaction(function () use ($engagement, $engagementId, $userId): void {
            $assessment = $this->assessments->ensure($engagementId, (string) $engagement['organization_id']);
            $this->assessments->patch((string) $assessment['id'], ['started_at' => $this->clock->nowUtc()]);
            $this->engagementService->move(
                $engagementId,
                Stage::ASSESSMENT_IN_PROGRESS,
                'Assessment started',
                'assessment.started',
                $userId
            );
            $this->batchService->moveAll($engagement, BatchStage::RECEIVED, BatchStage::IN_REVIEW, $userId);
        });
    }

    /** @param array<string,mixed> $engagement */
    public function sendToQualityReview(array $engagement, ?string $userId = null): void
    {
        $engagementId = (string) $engagement['id'];
        $this->requireStage($engagementId, Stage::ASSESSMENT_IN_PROGRESS, 'send it to quality review');

        $this->db->transaction(function () use ($engagement, $engagementId, $userId): void {
            $assessment = $this->assessments->ensure($engagementId, (string) $engagement['organization_id']);
            $this->assessments->patch((string) $assessment['id'], ['quality_review_at' => $this->clock->nowUtc()]);
            $this->engagementService->move(
                $engagementId,
                Stage::ASSESSMENT_QA,
                'Assessment in our quality check',
                'assessment.quality_review',
                $userId
            );
        });
    }

    /** @param array<string,mixed> $engagement */
    public function returnToWork(array $engagement, ?string $userId = null): void
    {
        $engagementId = (string) $engagement['id'];
        $this->requireStage($engagementId, Stage::ASSESSMENT_QA, 'return it to work');

        $this->engagementService->move(
            $engagementId,
            Stage::ASSESSMENT_IN_PROGRESS,
            'Assessment back in review after the quality check',
            'assessment.returned',
            $userId
        );
    }

    /**
     * Deliver the assessment. Section 17: "Assessment delivered: create
     * client decision request".
     *
     * The summary is what the practice reads. It is business text, screened.
     * The recommended count and amount are aggregates. The decision date is
     * hers to set, is stored on the engagement as the plan asks, and is shown
     * as unconfirmed by the client until they open the assessment.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @param array{summary:string,recommended_count:?int,recommended_amount_cents:?int,decision_due:?string} $input
     * @return array<string,mixed> the assessment row
     */
    public function deliver(array $engagement, array $input, ?string $userId = null): array
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];
        $this->requireStage($engagementId, Stage::ASSESSMENT_QA, 'deliver it');

        $summary = SafeText::require((string) $input['summary'], 2000, 'the summary');
        if (mb_strlen($summary) < 20) {
            throw new \RuntimeException('Not delivered: the summary needs at least a sentence.');
        }

        $count = $input['recommended_count'] ?? null;
        if ($count !== null && ($count < 0 || $count > 999999)) {
            throw new \RuntimeException('Not delivered: the recommended count has to be a whole number.');
        }
        $cents = $input['recommended_amount_cents'] ?? null;
        if ($cents !== null && $cents < 0) {
            throw new \RuntimeException('Not delivered: the recommended amount cannot be negative.');
        }

        $dueUtc = null;
        $due = trim((string) ($input['decision_due'] ?? ''));
        if ($due !== '') {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $due, $m) !== 1
                || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ) {
                throw new \RuntimeException('Not delivered: the decision date has to be a date, like 2026-09-30.');
            }
            $dueUtc = $due . ' 12:00:00';
        }

        return $this->db->transaction(function () use (
            $engagement,
            $engagementId,
            $organizationId,
            $summary,
            $count,
            $cents,
            $dueUtc,
            $userId
        ): array {
            $assessment = $this->assessments->ensure($engagementId, $organizationId);
            $now = $this->clock->nowUtc();

            $this->assessments->patch((string) $assessment['id'], [
                'summary'                  => $summary,
                'recommended_count'        => $count,
                'recommended_amount_cents' => $cents,
                'decision_due_at'          => $dueUtc,
                'delivered_at'             => $now,
                'delivered_by'             => $userId,
            ]);

            if ($dueUtc !== null) {
                $this->db->update('sa_engagements', ['client_decision_due_at' => $dueUtc], ['id' => $engagementId]);
            }

            $this->engagementService->move(
                $engagementId,
                Stage::ASSESSMENT_DELIVERED,
                'Assessment delivered',
                'assessment.delivered',
                $userId,
                $count === null ? [] : ['count' => (string) $count]
            );

            // Batches she marked recommended stay so; anything still "in
            // review" was reviewed and not recommended, and the card says so.
            $this->batchService->moveAll($engagement, BatchStage::IN_REVIEW, BatchStage::NOT_RECOMMENDED, $userId);

            $this->requests->closeKind($engagement, ActionRequestKind::CONFIRM_RECEIPT_COUNT, $userId);
            $this->requests->open(
                $engagement,
                ActionRequestKind::REVIEW_ASSESSMENT,
                null,
                $dueUtc,
                $userId,
                false
            );

            $this->notifyDelivered($engagement);

            $this->audit->record('assessment.delivered', 'success', 'assessment', (string) $assessment['id'], [
                'count'        => $count === null ? null : (string) $count,
                'amount_cents' => $cents === null ? null : (string) $cents,
            ], $organizationId);

            return $this->assessments->ensure($engagementId, $organizationId);
        });
    }

    /**
     * Her answer to a practice that asked for more information. Closes the
     * request with the answer on it and re-opens the decision.
     *
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $request
     */
    public function answer(array $engagement, array $request, string $response, ?string $userId = null): void
    {
        if ((string) $request['kind'] !== ActionRequestKind::ANSWER_QUESTION) {
            throw new \RuntimeException('That request is not a question.');
        }
        $this->db->transaction(function () use ($engagement, $request, $response, $userId): void {
            $this->requests->complete($engagement, $request, $userId, $response);
            $this->timeline->record(
                (string) $engagement['id'],
                'assessment.answered',
                'We answered your question',
                null,
                null,
                StatusEventRepository::ACTOR_STAFF,
                $userId
            );
            if ($this->requestsRepository()->openOfKind((string) $engagement['id'], ActionRequestKind::CHOOSE_SCOPE) === null) {
                $this->requests->open($engagement, ActionRequestKind::CHOOSE_SCOPE, null, null, $userId);
            }
        });
    }

    // ------------------------------------------------------------------
    // The practice's side.
    // ------------------------------------------------------------------

    /**
     * The practice confirms the aggregate count. No stage moves; the
     * confirmation is stamped, the request closes, and the timeline says so
     * in their voice.
     *
     * @param array<string,mixed> $engagement
     */
    public function clientConfirmsReceipt(array $engagement, ?string $contactId, ?string $userId = null): void
    {
        $engagementId = (string) $engagement['id'];
        $assessment = $this->forEngagement($engagement);
        if ($assessment === null || $assessment['receipt_confirmed_at'] === null) {
            throw new \RuntimeException('There is no receipt to confirm yet.');
        }
        if ($assessment['client_confirmed_at'] !== null) {
            return;
        }

        $this->db->transaction(function () use ($engagement, $engagementId, $assessment, $contactId, $userId): void {
            $this->assessments->patch((string) $assessment['id'], [
                'client_confirmed_at'      => $this->clock->nowUtc(),
                'client_confirmed_contact' => $contactId,
            ]);
            $this->timeline->record(
                $engagementId,
                'assessment.receipt_client_confirmed',
                'You confirmed the number of denials received',
                null,
                null,
                StatusEventRepository::ACTOR_CLIENT,
                $userId,
                ['count' => (string) $assessment['received_count']]
            );
            $this->requests->closeKind($engagement, ActionRequestKind::CONFIRM_RECEIPT_COUNT, $userId);
            $this->audit->record('assessment.receipt_client_confirmed', 'success', 'assessment', (string) $assessment['id'], [], (string) $engagement['organization_id']);
        });
    }

    /**
     * The practice opened the assessment. The first time, that is the move
     * from "delivered" to "decision pending": Stage::nextOwner says the
     * delivered stage waits on the client to read it, and this is the read.
     *
     * @param array<string,mixed> $engagement
     * @return bool true when this call moved the stage
     */
    public function markRead(array $engagement, ?string $userId = null): bool
    {
        $engagementId = (string) $engagement['id'];
        if ($this->currentStage($engagementId) !== Stage::ASSESSMENT_DELIVERED) {
            return false;
        }

        return $this->db->transaction(function () use ($engagement, $engagementId, $userId): bool {
            $assessment = $this->assessments->ensure($engagementId, (string) $engagement['organization_id']);
            if ($assessment['read_at'] === null) {
                $this->assessments->patch((string) $assessment['id'], ['read_at' => $this->clock->nowUtc()]);
            }
            $this->engagementService->move(
                $engagementId,
                Stage::CLIENT_DECISION_PENDING,
                'You opened your assessment',
                'assessment.read',
                $userId,
                [],
                null,
                StatusEventRepository::ACTOR_CLIENT
            );
            $this->requests->closeKind($engagement, ActionRequestKind::REVIEW_ASSESSMENT, $userId);
            $this->requests->open(
                $engagement,
                ActionRequestKind::CHOOSE_SCOPE,
                null,
                $assessment['decision_due_at'] === null ? null : (string) $assessment['decision_due_at'],
                $userId,
                false
            );
            return true;
        });
    }

    /**
     * The decision, section 22 Phase 5 acceptance. One of four, recorded
     * once, and only from "client decision pending".
     *
     * Recovery moves the engagement to "recovery scope selected" and stops.
     * The recovery agreement is the next gate; Domain\Stage has no edge from
     * here to "recovery active" that does not pass through an executed one.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @return array<string,mixed> the assessment row
     */
    public function decide(
        array $engagement,
        string $decision,
        ?string $note,
        ?string $contactId,
        ?string $userId = null
    ): array {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];

        if (!ClientDecision::isValid($decision)) {
            throw new \RuntimeException('That is not one of the four choices.');
        }
        $this->requireStage($engagementId, Stage::CLIENT_DECISION_PENDING, 'record a decision');

        $cleanNote = $note === null || trim($note) === ''
            ? null
            : SafeText::require($note, 500, 'your note');
        if ($decision === ClientDecision::MORE_INFORMATION && $cleanNote === null) {
            throw new \RuntimeException('Say what you need to know, so we can answer it.');
        }

        return $this->db->transaction(function () use (
            $engagement,
            $engagementId,
            $organizationId,
            $decision,
            $cleanNote,
            $contactId,
            $userId
        ): array {
            $assessment = $this->assessments->ensure($engagementId, $organizationId);
            $now = $this->clock->nowUtc();

            // A question is not a decision. It is recorded on the request it
            // opens, and the decision fields stay empty until they choose.
            if ($decision !== ClientDecision::MORE_INFORMATION) {
                $this->assessments->patch((string) $assessment['id'], [
                    'decision'            => $decision,
                    'decision_at'         => $now,
                    'decision_contact_id' => $contactId,
                    'decision_note'       => $cleanNote,
                ]);
            }

            $this->requests->closeKind($engagement, ActionRequestKind::CHOOSE_SCOPE, $userId);

            $to = ClientDecision::stageAfter($decision);
            if ($to === null) {
                $this->timeline->record(
                    $engagementId,
                    'assessment.question',
                    ClientDecision::timelineLabel($decision),
                    null,
                    null,
                    StatusEventRepository::ACTOR_CLIENT,
                    $userId,
                    ['decision' => $decision]
                );
                $this->requests->open($engagement, ActionRequestKind::ANSWER_QUESTION, $cleanNote, null, $userId);
            } else {
                if (ClientDecision::closes($decision)) {
                    $this->timeline->record(
                        $engagementId,
                        'assessment.decision',
                        ClientDecision::timelineLabel($decision),
                        null,
                        null,
                        StatusEventRepository::ACTOR_CLIENT,
                        $userId,
                        ['decision' => $decision]
                    );
                }
                $this->engagementService->move(
                    $engagementId,
                    $to,
                    ClientDecision::closes($decision) ? 'Engagement closed' : ClientDecision::timelineLabel($decision),
                    'assessment.decision',
                    $userId,
                    ['decision' => $decision],
                    null,
                    StatusEventRepository::ACTOR_CLIENT
                );
            }

            $this->audit->record('assessment.decision', 'success', 'assessment', (string) $assessment['id'], [
                'decision' => $decision,
                'to_stage' => $to,
            ], $organizationId);

            $this->notifyDecision($engagement, $decision, $cleanNote);

            // The checklist reads the timeline, and the recovery list appears
            // on this very event, so sync it now rather than on the next read.
            $this->checklist->sync($engagementId);

            return $this->assessments->ensure($engagementId, $organizationId);
        });
    }

    // ------------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------------

    /**
     * Section 16.2, template 8. The practice is told the assessment is in
     * the room. The email never carries the assessment.
     *
     * @param array<string,mixed> $engagement
     */
    private function notifyDelivered(array $engagement): void
    {
        $signer = $this->requests->signerContact((string) $engagement['id']);
        if ($signer === null) {
            return;
        }
        $room = rtrim($this->config->string('SA_APP_URL'), '/') . '/soft-appeals-room';
        $organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');

        $lines = [];
        $lines[] = 'Hello ' . self::firstName((string) $signer['name']) . ',';
        $lines[] = '';
        $lines[] = wordwrap(
            'The complimentary assessment for ' . $organization . ' is ready to read in '
            . 'your Soft Appeals Recovery Room. It is written at aggregate level, and it '
            . 'asks you for one decision at the end.',
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
            'Your assessment is ready',
            implode("\n", $lines) . "\n",
            self::TEMPLATE_AVAILABLE,
            (string) $engagement['id'],
            (string) $engagement['organization_id'],
            hash('sha256', (string) $engagement['id'] . '|' . self::TEMPLATE_AVAILABLE)
        );
    }

    /**
     * She is told what they chose, at her own address. The note travels only
     * after the screen, and only to her.
     *
     * @param array<string,mixed> $engagement
     */
    private function notifyDecision(array $engagement, string $decision, ?string $note): void
    {
        $organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');
        $desk = rtrim($this->config->string('SA_APP_URL'), '/')
            . '/sa-desk.php?view=assessments&e=' . rawurlencode((string) $engagement['public_ref']);

        $lines = [];
        $lines[] = $organization . ' answered the assessment: ' . ClientDecision::staffLabel($decision) . '.';
        $lines[] = '';
        if ($note !== null) {
            $lines[] = 'They wrote:';
            $lines[] = '';
            $lines[] = wordwrap($note, 72, "\n", false);
            $lines[] = '';
        }
        $lines[] = 'Open it on the Desk: ' . $desk;
        $lines[] = '';
        $lines[] = 'Soft Appeals';

        $this->mail->send(
            $this->config->string('SA_OWNER_EMAIL'),
            'Decision from ' . $organization,
            implode("\n", $lines) . "\n",
            self::TEMPLATE_DECISION,
            (string) $engagement['id'],
            (string) $engagement['organization_id'],
            hash('sha256', (string) $engagement['id'] . '|' . self::TEMPLATE_DECISION . '|' . $decision . '|' . $this->clock->nowUtc())
        );
    }

    private function requestsRepository(): ActionRequestRepository
    {
        return $this->requestRows;
    }

    private function currentStage(string $engagementId): string
    {
        $row = $this->engagements->find($engagementId);
        if ($row === null) {
            throw new \RuntimeException('No such engagement.');
        }
        return (string) $row['stage'];
    }

    private function requireStage(string $engagementId, string $expected, string $verb): void
    {
        $stage = $this->currentStage($engagementId);
        if ($stage !== $expected) {
            $this->audit->record('assessment.refused', 'denied', 'engagement', $engagementId, [
                'reason'     => 'cannot ' . $verb,
                'from_stage' => $stage,
                'to_stage'   => $expected,
            ]);
            throw new \RuntimeException(
                'You cannot ' . $verb . ' at "' . Stage::staffLabel($stage) . '". It needs to be at "'
                . Stage::staffLabel($expected) . '".'
            );
        }
    }

    private static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        return $parts === [] || $parts[0] === '' ? 'there' : $parts[0];
    }
}
