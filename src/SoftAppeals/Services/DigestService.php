<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Config;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\ActionRequestRepository;
use SoftAppeals\Repositories\ApprovalRequestRepository;
use SoftAppeals\Repositories\AttentionRepository;
use SoftAppeals\Repositories\CloseoutRepository;
use SoftAppeals\Repositories\DocumentRepository;
use SoftAppeals\Repositories\EngagementRepository;
use SoftAppeals\Repositories\IntakeRepository;
use SoftAppeals\Repositories\InvoiceRepository;
use SoftAppeals\Repositories\JobRepository;
use SoftAppeals\Repositories\RecoveryRepository;
use SoftAppeals\Repositories\SubmissionEventRepository;
use SoftAppeals\Repositories\WorkBatchRepository;
use SoftAppeals\Support\Clock;

/**
 * The morning digest. Section 17.3.
 *
 * "Generated from real tasks, not fictional AI agents." Every line below is
 * a count read from the same tables the Desk reads, in the same words, and
 * the email is the Desk's "needs you" list folded into a paragraph. Nothing
 * in it names a person, a patient, a claim or a dollar figure on a claim:
 * a practice appears as a count, and the Desk is where the names are.
 *
 * It goes once per day. The idempotency key is the business date, so a
 * second run the same morning finds the row and sends nothing, and a run
 * before the digest hour does nothing at all.
 */
final class DigestService
{
    public const TEMPLATE = 'morning_digest';

    private Config $config;
    private Clock $clock;
    private IntakeRepository $intakes;
    private EngagementRepository $engagements;
    private DocumentRepository $documents;
    private ActionRequestRepository $requests;
    private ApprovalRequestRepository $approvals;
    private SubmissionEventRepository $events;
    private WorkBatchRepository $batches;
    private RecoveryRepository $recoveries;
    private InvoiceRepository $invoices;
    private CloseoutRepository $closeouts;
    private AttentionRepository $attention;
    private JobRepository $jobs;
    private MailService $mail;

    public function __construct(
        Config $config,
        Clock $clock,
        IntakeRepository $intakes,
        EngagementRepository $engagements,
        DocumentRepository $documents,
        ActionRequestRepository $requests,
        ApprovalRequestRepository $approvals,
        SubmissionEventRepository $events,
        WorkBatchRepository $batches,
        RecoveryRepository $recoveries,
        InvoiceRepository $invoices,
        CloseoutRepository $closeouts,
        AttentionRepository $attention,
        JobRepository $jobs,
        MailService $mail
    ) {
        $this->config = $config;
        $this->clock = $clock;
        $this->intakes = $intakes;
        $this->engagements = $engagements;
        $this->documents = $documents;
        $this->requests = $requests;
        $this->approvals = $approvals;
        $this->events = $events;
        $this->batches = $batches;
        $this->recoveries = $recoveries;
        $this->invoices = $invoices;
        $this->closeouts = $closeouts;
        $this->attention = $attention;
        $this->jobs = $jobs;
        $this->mail = $mail;
    }

    /**
     * The counts, and the lines a person reads.
     *
     * @return array{date:string,counts:array<string,int>,lines:list<string>,quiet:bool}
     */
    public function build(): array
    {
        $counts = [
            'fit_review'          => count($this->intakes->awaitingReview()),
            'terms_ready'         => count($this->engagements->atStage(Stage::TERMS_READY)),
            'countersign'         => count($this->documents->awaitingCountersignature()),
            'client_actions_due'  => $this->clientActionsDue(),
            'deadline_groups_14'  => $this->deadlineGroupsUnder(14),
            'awaiting_submission' => count($this->approvals->approvedAwaitingSubmission()),
            'follow_ups_due'      => $this->followUpsDue(),
            'awaiting_verification' => count($this->recoveries->awaitingVerification()),
            'invoice_ready'       => count($this->recoveries->invoiceReadyEverywhere()),
            'invoices_overdue'    => $this->invoicesOverdue(),
            'closeouts_open'      => $this->closeoutsOpen(),
            'questions_for_her'   => count($this->requests->openForSoftAppeals()),
            'attention_urgent'    => count($this->attention->openOfKind(AttentionRepository::KIND_BACKUP))
                + count($this->attention->openOfKind(AttentionRepository::KIND_JOB_FAILED)),
            'jobs_failed_24h'     => $this->jobs->failuresSince(1),
        ];

        $lines = [];
        $add = static function (int $n, string $singular, string $plural) use (&$lines): void {
            if ($n <= 0) {
                return;
            }
            $lines[] = $n . ' ' . ($n === 1 ? $singular : $plural);
        };

        $add($counts['fit_review'], 'inquiry needs fit review', 'inquiries need fit review');
        $add($counts['terms_ready'], 'set of assessment terms is ready to send', 'sets of assessment terms are ready to send');
        $add($counts['countersign'], 'agreement needs your countersignature', 'agreements need your countersignature');
        $add($counts['questions_for_her'], 'question from a practice is waiting for your answer', 'questions from practices are waiting for your answer');
        $add($counts['client_actions_due'], 'client action is due', 'client actions are due');
        $add($counts['deadline_groups_14'], 'deadline group is under 14 days', 'deadline groups are under 14 days');
        $add($counts['awaiting_submission'], 'approved batch is waiting on your submission', 'approved batches are waiting on your submission');
        $add($counts['follow_ups_due'], 'payer follow-up is due', 'payer follow-ups are due');
        $add($counts['awaiting_verification'], 'recovery is waiting for payment verification', 'recoveries are waiting for payment verification');
        $add($counts['invoice_ready'], 'practice has fees not yet on an invoice', 'practices have fees not yet on an invoice');
        $add($counts['invoices_overdue'], 'invoice is past its due date', 'invoices are past their due date');
        $add($counts['closeouts_open'], 'closeout is in progress', 'closeouts are in progress');
        $add($counts['attention_urgent'], 'system item needs a look (backup or a failed job)', 'system items need a look (backup or a failed job)');

        return [
            'date'   => $this->businessDate(),
            'counts' => $counts,
            'lines'  => $lines,
            'quiet'  => $lines === [],
        ];
    }

    /** The email, as plain text. */
    public function text(?array $digest = null): string
    {
        $digest ??= $this->build();
        $out = [];
        $out[] = 'Good morning. Here is what needs attention today.';
        $out[] = '';
        if ($digest['quiet']) {
            $out[] = 'Nothing needs you today. Every inquiry is reviewed, nothing is waiting on a signature, and no deadline is close.';
        } else {
            foreach ($digest['lines'] as $line) {
                $out[] = $line;
            }
        }
        $out[] = '';
        $out[] = 'The Desk: ' . rtrim($this->config->string('SA_APP_URL'), '/') . '/sa-desk.php';
        $out[] = '';
        $out[] = 'This digest holds counts only. Names, batches and figures are on the Desk.';
        $out[] = '';
        $out[] = 'Soft Appeals';
        return implode("\n", $out) . "\n";
    }

    /**
     * Send today's digest to her, once. Before the digest hour, nothing.
     *
     * @return array{state:string,sent:bool,reason:string,date:string}
     */
    public function send(bool $ignoreHour = false): array
    {
        $date = $this->businessDate();
        if (!$ignoreHour && $this->localHour() < $this->config->digestHour()) {
            return ['state' => 'skipped', 'sent' => false, 'reason' => 'before the digest hour', 'date' => $date];
        }

        $digest = $this->build();
        $result = $this->mail->send(
            $this->config->string('SA_OWNER_EMAIL'),
            'Soft Appeals this morning: ' . ($digest['quiet'] ? 'nothing needs you' : count($digest['lines']) . ' thing' . (count($digest['lines']) === 1 ? '' : 's')),
            $this->text($digest),
            self::TEMPLATE,
            null,
            null,
            hash('sha256', 'digest|' . $date)
        );

        return [
            'state'  => $result['state'],
            'sent'   => $result['sent'],
            'reason' => $result['reason'],
            'date'   => $date,
        ];
    }

    /** "2026-08-29", in her timezone. */
    public function businessDate(): string
    {
        return $this->clock->now()
            ->setTimezone(new \DateTimeZone($this->config->string('SA_BUSINESS_TIMEZONE')))
            ->format('Y-m-d');
    }

    private function localHour(): int
    {
        return (int) $this->clock->now()
            ->setTimezone(new \DateTimeZone($this->config->string('SA_BUSINESS_TIMEZONE')))
            ->format('G');
    }

    /** Open client requests and pending approvals due within three days or past. */
    private function clientActionsDue(): int
    {
        $n = 0;
        foreach ($this->requests->openForClientsEverywhere() as $row) {
            if ($row['due_at'] !== null && ($this->clock->daysUntil((string) $row['due_at']) ?? 99) <= 3) {
                $n++;
            }
        }
        foreach ($this->approvals->pendingEverywhere() as $row) {
            if ($row['due_at'] !== null && ($this->clock->daysUntil((string) $row['due_at']) ?? 99) <= 3) {
                $n++;
            }
        }
        return $n;
    }

    private function deadlineGroupsUnder(int $days): int
    {
        $n = 0;
        foreach ($this->batches->withDeadlines() as $row) {
            $left = $this->clock->daysUntil((string) $row['earliest_deadline_at']);
            if ($left !== null && $left < $days) {
                $n++;
            }
        }
        return $n;
    }

    private function followUpsDue(): int
    {
        $n = 0;
        foreach ($this->events->openFollowUps() as $row) {
            if (($this->clock->daysUntil((string) $row['follow_up_due_at']) ?? 1) <= 0) {
                $n++;
            }
        }
        return $n;
    }

    private function invoicesOverdue(): int
    {
        $n = 0;
        foreach ($this->invoices->outstandingEverywhere() as $row) {
            if ($row['due_at'] !== null && ($this->clock->daysUntil((string) $row['due_at']) ?? 1) < 0) {
                $n++;
            }
        }
        return $n;
    }

    private function closeoutsOpen(): int
    {
        $n = 0;
        foreach ($this->closeouts->engagementsInCloseout() as $row) {
            if ($row['closeout_closed_at'] === null) {
                $n++;
            }
        }
        return $n;
    }
}
