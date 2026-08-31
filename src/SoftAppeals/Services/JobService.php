<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Config;
use SoftAppeals\Database;
use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Repositories\ActionRequestRepository;
use SoftAppeals\Repositories\ApprovalRequestRepository;
use SoftAppeals\Repositories\AttentionRepository;
use SoftAppeals\Repositories\CloseoutRepository;
use SoftAppeals\Repositories\DocumentRepository;
use SoftAppeals\Repositories\InvitationRepository;
use SoftAppeals\Repositories\InvoiceRepository;
use SoftAppeals\Repositories\JobRepository;
use SoftAppeals\Repositories\RecoveryRepository;
use SoftAppeals\Repositories\SubmissionEventRepository;
use SoftAppeals\Repositories\WorkBatchRepository;
use SoftAppeals\Security\RateLimiter;
use SoftAppeals\Support\Clock;

/**
 * The scheduled jobs. Section 17.2, the plan's JobService.
 *
 * One entry point, cron/soft-appeals-jobs.php, runs runAll() once a day
 * from the host's cron. The Desk's "Run the jobs now" button calls the same
 * method with a different trigger. Every job:
 *
 *   takes a database lock first, and skips if another run holds it
 *   opens a run row, does its work, and closes the row with an outcome
 *   is safe to run again a minute later, because what it surfaces is keyed
 *   writes one PHI-free line to the health log
 *   on failure, records the failure, surfaces it on the Desk, releases the
 *   lock, and lets the next job run
 *
 * The jobs do not move an engagement. Every state transition in this
 * application is a person's act with an audit row behind it (section 17.1's
 * "human gate" column), and a job that could move one would be a job that
 * could move one wrongly at three in the morning. A job looks, counts,
 * reminds, backs up, and writes down what it saw.
 */
final class JobService
{
    /** How long one job may hold its lock before a later cron may take it. */
    public const LOCK_SECONDS = 600;

    /** The deadline thresholds of section 17.2, tightest last. */
    public const DEADLINE_THRESHOLDS = [30, 14, 7, 3, 1];

    /** Days a countersignature may wait before it is an overdue internal task. */
    public const COUNTERSIGN_DAYS = 2;

    /** Days an approved batch may wait for her submission before it is overdue. */
    public const SUBMISSION_DAYS = 3;

    private Config $config;
    private Database $db;
    private Clock $clock;
    private JobRepository $jobs;
    private AttentionRepository $attention;
    private InvitationRepository $invitations;
    private RateLimiter $rateLimiter;
    private ReminderService $reminders;
    private DigestService $digest;
    private BackupService $backups;
    private WorkBatchRepository $batches;
    private RecoveryRepository $recoveries;
    private CloseoutRepository $closeouts;
    private DocumentRepository $documents;
    private ApprovalRequestRepository $approvals;
    private SubmissionEventRepository $events;
    private ActionRequestRepository $requests;
    private InvoiceRepository $invoices;
    private AuditService $audit;
    private MailboxService $mailbox;
    private IntakeService $intakeService;
    private MailService $mail;

    /** @var array<string,array{label:string,what:string,work:callable():array{summary:string,items:int}}> */
    private array $extra = [];

    public function __construct(
        Config $config,
        Database $db,
        Clock $clock,
        JobRepository $jobs,
        AttentionRepository $attention,
        InvitationRepository $invitations,
        RateLimiter $rateLimiter,
        ReminderService $reminders,
        DigestService $digest,
        BackupService $backups,
        WorkBatchRepository $batches,
        RecoveryRepository $recoveries,
        CloseoutRepository $closeouts,
        DocumentRepository $documents,
        ApprovalRequestRepository $approvals,
        SubmissionEventRepository $events,
        ActionRequestRepository $requests,
        InvoiceRepository $invoices,
        AuditService $audit,
        MailboxService $mailbox,
        IntakeService $intakeService,
        MailService $mail
    ) {
        $this->config = $config;
        $this->db = $db;
        $this->clock = $clock;
        $this->jobs = $jobs;
        $this->attention = $attention;
        $this->invitations = $invitations;
        $this->rateLimiter = $rateLimiter;
        $this->reminders = $reminders;
        $this->digest = $digest;
        $this->backups = $backups;
        $this->batches = $batches;
        $this->recoveries = $recoveries;
        $this->closeouts = $closeouts;
        $this->documents = $documents;
        $this->approvals = $approvals;
        $this->events = $events;
        $this->requests = $requests;
        $this->invoices = $invoices;
        $this->audit = $audit;
        $this->mailbox = $mailbox;
        $this->intakeService = $intakeService;
        $this->mail = $mail;
    }

    /**
     * The jobs, in the order runAll() runs them. The digest is last so it
     * reads what the others just surfaced.
     *
     * @return array<string,array{label:string,what:string}>
     */
    public function definitions(): array
    {
        $out = [
            // First, so everything downstream of it this run, the digest
            // included, already sees whatever the inbox brought.
            'intake.mailbox' => [
                'label' => 'Forwarded-email intake',
                'what'  => 'Unread messages in the intake mailbox become inquiry rows on the board. Nothing is deleted.',
            ],
            'invitations.expire' => [
                'label' => 'Expire unused links',
                'what'  => 'A one-time link past its date that was never used is marked dead.',
            ],
            'tasks.internal' => [
                'label' => 'Overdue internal tasks',
                'what'  => 'Countersignatures, submissions, follow-ups, questions and invoices that have waited too long.',
            ],
            'deadlines.batches' => [
                'label' => 'Deadline groups',
                'what'  => 'Every batch deadline crossing 30, 14, 7, 3 or 1 day. Unconfirmed dates are labelled as such.',
            ],
            'payments.pending' => [
                'label' => 'Favorable, awaiting payment',
                'what'  => 'Overturned batches with no verified reimbursement yet. No fee exists until one is.',
            ],
            'closeout.access' => [
                'label' => 'Open access at closeout',
                'what'  => 'People still undecided on a closeout access review.',
            ],
            'reminders.client' => [
                'label' => 'Client reminders',
                'what'  => 'One reminder per cadence period for each item waiting on a practice. Never twice in a period.',
            ],
            'backup.daily' => [
                'label' => 'Daily backup',
                'what'  => 'Every table, written to the private backup folder with its hash. Old ones pruned.',
            ],
            'backup.verify' => [
                'label' => 'Verify the newest backup',
                'what'  => 'Exists, is under 36 hours old, matches its hash, and decodes.',
            ],
            'backup.offsite' => [
                'label' => 'Off-site backup copy',
                'what'  => 'The newest backup file, emailed to you once per day. The copy that survives the server.',
            ],
            'housekeeping' => [
                'label' => 'Housekeeping',
                'what'  => 'Rate-limit rows, old run rows and resolved items past 90 days are dropped.',
            ],
            'digest.morning' => [
                'label' => 'Morning digest',
                'what'  => 'The counts, emailed to you once a day after the digest hour.',
            ],
        ];
        foreach ($this->extra as $key => $definition) {
            $out[$key] = ['label' => $definition['label'], 'what' => $definition['what']];
        }
        return $out;
    }

    /**
     * Add a job. Tests use this to plant one that fails, so the failure path
     * is proved rather than assumed. Not called by application code.
     *
     * @param callable():array{summary:string,items:int} $work
     */
    public function withJob(string $key, string $label, string $what, callable $work): void
    {
        $this->extra[$key] = ['label' => $label, 'what' => $what, 'work' => $work];
    }

    public function isKnown(string $key): bool
    {
        return array_key_exists($key, $this->definitions());
    }

    /**
     * Run every job, in order. A failure in one is recorded and the rest
     * still run.
     *
     * @return list<array{job:string,outcome:string,items:int,summary:string,run_id:?string}>
     */
    public function runAll(string $trigger): array
    {
        $this->guardTrigger($trigger);
        $results = [];
        foreach (array_keys($this->definitions()) as $key) {
            $results[] = $this->run($key, $trigger, false);
        }
        return $results;
    }

    /**
     * Run one job under its lock.
     *
     * @return array{job:string,outcome:string,items:int,summary:string,run_id:?string}
     */
    public function run(string $key, string $trigger, bool $guard = true): array
    {
        if ($guard) {
            $this->guardTrigger($trigger);
        }
        if (!$this->isKnown($key)) {
            throw new \RuntimeException('There is no job called "' . $key . '".');
        }

        $token = $this->jobs->acquireLock($key, self::LOCK_SECONDS);
        if ($token === null) {
            $result = ['job' => $key, 'outcome' => JobRepository::OUTCOME_SKIPPED, 'items' => 0, 'summary' => 'another run holds the lock', 'run_id' => null];
            $this->logLine($result, $trigger);
            return $result;
        }

        $runId = $this->jobs->startRun($key, $trigger);
        try {
            $outcome = $this->work($key);
            $this->jobs->finishRun($runId, JobRepository::OUTCOME_OK, $outcome['items'], $outcome['summary']);
            $this->attention->resolve('job_failed:' . $key);
            $this->audit->record('job.run', 'success', 'job', $runId, [
                'job'     => $key,
                'trigger' => $trigger,
                'count'   => $outcome['items'],
                'reason'  => $outcome['summary'],
            ]);
            $result = ['job' => $key, 'outcome' => JobRepository::OUTCOME_OK, 'items' => $outcome['items'], 'summary' => $outcome['summary'], 'run_id' => $runId];
        } catch (\Throwable $e) {
            // The class and a short message. Never a payload, never a value
            // from a row: application exceptions are written by the
            // application, and a driver message is recorded by class alone.
            $reason = str_starts_with($e::class, 'SoftAppeals\\') || $e instanceof \RuntimeException
                ? mb_substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', $e->getMessage()) ?? '', 0, 200)
                : $e::class;
            $this->jobs->finishRun($runId, JobRepository::OUTCOME_FAILED, 0, $reason);
            $this->attention->see('job_failed:' . $key, [
                'kind'     => AttentionRepository::KIND_JOB_FAILED,
                'severity' => AttentionRepository::SEVERITY_URGENT,
                'label'    => 'The job "' . ($this->definitions()[$key]['label'] ?? $key) . '" failed',
                'detail'   => $reason,
                'link'     => '/sa-desk.php?view=jobs',
            ]);
            $this->audit->record('job.run', 'error', 'job', $runId, [
                'job'     => $key,
                'trigger' => $trigger,
                'reason'  => $reason,
            ]);
            $result = ['job' => $key, 'outcome' => JobRepository::OUTCOME_FAILED, 'items' => 0, 'summary' => $reason, 'run_id' => $runId];
        } finally {
            $this->jobs->releaseLock($key, $token);
        }

        $this->logLine($result, $trigger);
        return $result;
    }

    /**
     * What the Desk shows: every job with its last run, whether it is stale,
     * and the count of recent failures.
     *
     * @return array{jobs:array<string,array{label:string,what:string,last:?array<string,mixed>,last_ok:?array<string,mixed>,stale:bool,locked:bool}>,failures_7d:int,last_any:?array<string,mixed>,stale_any:bool}
     */
    public function health(): array
    {
        $jobs = [];
        $staleAny = false;
        foreach ($this->definitions() as $key => $definition) {
            $lastOk = $this->jobs->lastSuccess($key);
            $stale = $lastOk === null
                || ($this->clock->now()->getTimestamp() - ($this->clock->parseUtc((string) $lastOk['finished_at'])?->getTimestamp() ?? 0)) > 26 * 3600;
            $staleAny = $staleAny || $stale;
            $jobs[$key] = [
                'label'   => $definition['label'],
                'what'    => $definition['what'],
                'last'    => $this->jobs->lastRun($key),
                'last_ok' => $lastOk,
                'stale'   => $stale,
                'locked'  => $this->jobs->isLocked($key),
            ];
        }
        return [
            'jobs'        => $jobs,
            'failures_7d' => $this->jobs->failuresSince(7),
            'last_any'    => $this->jobs->lastRunAnywhere(),
            'stale_any'   => $staleAny,
        ];
    }

    /** The exact line for the host's cron screen. */
    public function cronCommand(): string
    {
        return '/usr/bin/php ' . dirname(__DIR__, 3) . '/cron/soft-appeals-jobs.php run';
    }

    /** Where the health log is. */
    public function logPath(): string
    {
        return $this->config->privateStoragePath('audit-exports', 'jobs.log');
    }

    /** The tail of the health log, newest last. */
    public function logTail(int $lines = 40): array
    {
        $path = $this->logPath();
        if (!is_file($path)) {
            return [];
        }
        $all = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_slice($all, -max(1, $lines));
    }

    // ------------------------------------------------------------------
    // The jobs themselves. Each returns a PHI-free summary and a count.
    // ------------------------------------------------------------------

    /** @return array{summary:string,items:int} */
    private function work(string $key): array
    {
        if (isset($this->extra[$key])) {
            return ($this->extra[$key]['work'])();
        }
        return match ($key) {
            'intake.mailbox'     => $this->emailIntake(),
            'invitations.expire' => $this->expireInvitations(),
            'tasks.internal'     => $this->internalTasks(),
            'deadlines.batches'  => $this->deadlines(),
            'payments.pending'   => $this->paymentsPending(),
            'closeout.access'    => $this->closeoutAccess(),
            'reminders.client'   => $this->clientReminders(),
            'backup.daily'       => $this->backupDaily(),
            'backup.verify'      => $this->backupVerify(),
            'backup.offsite'     => $this->backupOffsite(),
            'housekeeping'       => $this->housekeeping(),
            'digest.morning'     => $this->morningDigest(),
            default              => throw new \RuntimeException('There is no job called "' . $key . '".'),
        };
    }

    /**
     * The no-form intake. Every unread message in the intake mailbox becomes
     * an ordinary inquiry row, reviewed on the Desk like any submitted form.
     *
     * The raw email is stored whole in private storage before anything is
     * parsed out of it, so a message the parser half-understood is never the
     * only record. A message is marked read only after its row is stored; a
     * crash between the two leaves it unread for the next run, and the
     * payload hash makes that rerun land on the row this one made.
     */
    private function emailIntake(): array
    {
        if (!$this->config->intakeMailboxEnabled()) {
            return ['summary' => 'switched off here (SA_INTAKE_MAILBOX_ENABLED)', 'items' => 0];
        }
        if (!$this->mailbox->configured()) {
            return ['summary' => 'no mailbox credentials here', 'items' => 0];
        }

        return $this->mailbox->withMailbox(function (callable $unseen, callable $seen): array {
            $read = 0;
            $created = 0;
            foreach ($unseen(MailboxService::BATCH) as $message) {
                $read++;
                $mail = MailboxService::parse((string) $message['raw']);

                // No sender address means no way to answer and no way to
                // review fit. It stays unread, visible in the mailbox itself,
                // rather than becoming a row nobody can act on.
                if ($mail['from_email'] === '') {
                    continue;
                }

                // The original, whole, before any interpretation of it.
                $key = $mail['message_id'] !== '' ? $mail['message_id'] : (string) $message['raw'];
                $hash = hash('sha256', $key);
                $dir = $this->config->privateStoragePath('intake-mail');
                if (!is_dir($dir)) {
                    @mkdir($dir, 0750, true);
                }
                @file_put_contents($dir . '/' . $hash . '.eml', (string) $message['raw'], LOCK_EX);

                $clean = static fn (string $s, int $max): string => mb_substr(
                    trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? ''),
                    0,
                    $max
                );
                $answers = array_filter([
                    'name'        => $clean($mail['from_name'], 120),
                    'email'       => $clean($mail['from_email'], 120),
                    'subject'     => $clean($mail['subject'], 200),
                    'message'     => $clean($mail['body'], 4000),
                    'attachments' => $clean(implode(', ', $mail['attachments']), 500),
                ], static fn (string $v): bool => $v !== '');

                $result = $this->intakeService->record(
                    \SoftAppeals\Domain\IntakeForms::SOURCE_EMAIL,
                    $answers,
                    $key,
                    $mail['date']
                );
                if ($result['created']) {
                    $created++;
                    // The instant acknowledgment: the person who forwarded a
                    // letter at nine hears something human before the fit
                    // review, or concludes nobody is home. Keyed on the
                    // inquiry, so the same message reappearing sends nothing
                    // twice, and the allowlist rules it like every send.
                    $this->mail->send(
                        $mail['from_email'],
                        'Your email reached Soft Appeals',
                        $this->intakeAckBody($mail['from_name']),
                        'intake_email_ack',
                        null,
                        null,
                        'intake-ack:' . $result['id']
                    );
                }
                $seen((string) $message['uid']);
            }
            return [
                'summary' => $read . ' read, ' . $created . ' new '
                    . ($created === 1 ? 'inquiry' : 'inquiries') . ' on the board',
                'items'   => $created,
            ];
        });
    }

    /** The words a forwarded email is answered with, before any review. */
    private function intakeAckBody(string $name): string
    {
        $first = trim(explode(' ', trim($name))[0] ?? '');
        return ($first === '' ? 'Hello,' : 'Hello ' . $first . ',') . "\n\n"
            . "Your email came through at Soft Appeals. I read these myself, and you hear back within one business day with a straight answer: it fits, it does not, or one question first.\n\n"
            . "Nothing starts and nothing is owed from sending this. If we work together, paperwork comes first and a secure route for records comes after it. From here on, please keep patient details out of regular email.\n\n"
            . "Nana Frimpongmaa\nfrimpomaasync.com/soft-appeals";
    }

    /**
     * The off-site copy. Every backup lives on the same server as the site,
     * and a dead server would take them with it. The newest backup file is
     * emailed to the owner once per day, keyed on the date, so the inbox
     * becomes the copy that survives. The backup holds business rows only;
     * nothing patient-level exists in this database by design.
     */
    private function backupOffsite(): array
    {
        $latest = $this->backups->latest();
        if ($latest === null) {
            return ['summary' => 'no backup to send yet', 'items' => 0];
        }
        if ($latest['bytes'] > 8_000_000) {
            return ['summary' => 'newest backup is ' . $latest['bytes'] . ' bytes, past the email cap; download a copy by hand', 'items' => 0];
        }

        $bytes = (string) @file_get_contents((string) $latest['path']);
        if ($bytes === '') {
            return ['summary' => 'newest backup could not be read', 'items' => 0];
        }

        $result = $this->mail->send(
            $this->config->string('SA_OWNER_EMAIL'),
            'Soft Appeals off-site backup: ' . (string) $latest['name'],
            "The newest database backup is attached.\n\n"
                . 'File: ' . (string) $latest['name'] . "\n"
                . 'Size: ' . (int) $latest['bytes'] . " bytes\n"
                . 'SHA-256: ' . hash('sha256', $bytes) . "\n\n"
                . "Keep this email. It is the copy that survives the server, and restoring from it is in the runbook.\n\n"
                . "Soft Appeals",
            'backup_offsite',
            null,
            null,
            'backup-offsite:' . substr($this->clock->nowUtc(), 0, 10),
            [['name' => (string) $latest['name'], 'bytes' => $bytes]]
        );

        if ($result['reason'] === 'already sent') {
            return ['summary' => 'already sent today', 'items' => 0];
        }
        return [
            'summary' => $result['sent']
                ? 'sent ' . (string) $latest['name'] . ' (' . (int) $latest['bytes'] . ' bytes)'
                : 'not taken: ' . $result['reason'],
            'items'   => $result['sent'] ? 1 : 0,
        ];
    }

    private function expireInvitations(): array
    {
        $n = $this->invitations->expireLapsed();
        return ['summary' => $n . ' lapsed link' . ($n === 1 ? '' : 's') . ' closed', 'items' => $n];
    }

    private function internalTasks(): array
    {
        $seen = [
            AttentionRepository::KIND_INTERNAL_TASK   => [],
            AttentionRepository::KIND_COUNTERSIGN     => [],
            AttentionRepository::KIND_SUBMISSION      => [],
            AttentionRepository::KIND_FOLLOW_UP       => [],
            AttentionRepository::KIND_INVOICE_OVERDUE => [],
        ];

        foreach ($this->requests->overdueForSoftAppeals() as $row) {
            $key = 'task:' . (string) $row['id'];
            $seen[AttentionRepository::KIND_INTERNAL_TASK][] = $key;
            $days = abs($this->clock->daysUntil((string) $row['due_at']) ?? 0);
            $this->attention->see($key, [
                'kind'            => AttentionRepository::KIND_INTERNAL_TASK,
                'severity'        => AttentionRepository::SEVERITY_URGENT,
                'engagement_id'   => (string) $row['engagement_id'],
                'organization_id' => (string) $row['organization_id'],
                'label'           => self::org($row) . ' · ' . \SoftAppeals\Domain\ActionRequestKind::title((string) $row['kind']),
                'detail'          => 'Overdue by ' . $days . ' day' . ($days === 1 ? '' : 's') . '.',
                'link'            => '/sa-desk.php?view=assessments&e=' . rawurlencode((string) $row['engagement_ref']) . '#desk-as-requests',
            ]);
        }

        foreach ($this->documents->awaitingCountersignature() as $row) {
            $waited = -($this->clock->daysUntil((string) $row['client_signed_at']) ?? 0);
            if ($waited < self::COUNTERSIGN_DAYS) {
                continue;
            }
            $key = 'countersign:' . (string) $row['id'];
            $seen[AttentionRepository::KIND_COUNTERSIGN][] = $key;
            $this->attention->see($key, [
                'kind'            => AttentionRepository::KIND_COUNTERSIGN,
                'severity'        => AttentionRepository::SEVERITY_URGENT,
                'engagement_id'   => (string) $row['engagement_id'],
                'organization_id' => (string) $row['organization_id'],
                'label'           => self::org($row) . ' · ' . DocumentKind::label((string) $row['kind']) . ' signed by the practice',
                'detail'          => 'Waiting ' . $waited . ' day' . ($waited === 1 ? '' : 's') . ' for your countersignature.',
                'link'            => '/sa-desk.php?view=documents&e=' . rawurlencode((string) $row['engagement_ref']),
            ]);
        }

        foreach ($this->approvals->approvedAwaitingSubmission() as $row) {
            $waited = -($this->clock->daysUntil((string) $row['decision_at']) ?? 0);
            if ($waited < self::SUBMISSION_DAYS) {
                continue;
            }
            $key = 'submission:' . (string) $row['id'];
            $seen[AttentionRepository::KIND_SUBMISSION][] = $key;
            $this->attention->see($key, [
                'kind'            => AttentionRepository::KIND_SUBMISSION,
                'severity'        => AttentionRepository::SEVERITY_URGENT,
                'engagement_id'   => (string) $row['engagement_id'],
                'organization_id' => (string) $row['organization_id'],
                'label'           => self::org($row) . ' · batch ' . (string) $row['batch_label'] . ' approved',
                'detail'          => 'Approved ' . $waited . ' day' . ($waited === 1 ? '' : 's') . ' ago and not yet submitted.',
                'link'            => '/sa-desk.php?view=recovery&e=' . rawurlencode((string) $row['engagement_ref']) . '#desk-rc-board',
            ]);
        }

        foreach ($this->events->openFollowUps() as $row) {
            $days = $this->clock->daysUntil((string) $row['follow_up_due_at']);
            if ($days === null || $days > 0) {
                continue;
            }
            $key = 'followup:' . (string) $row['id'];
            $seen[AttentionRepository::KIND_FOLLOW_UP][] = $key;
            $this->attention->see($key, [
                'kind'            => AttentionRepository::KIND_FOLLOW_UP,
                'severity'        => AttentionRepository::SEVERITY_URGENT,
                'engagement_id'   => (string) $row['engagement_id'],
                'organization_id' => (string) $row['organization_id'],
                'label'           => self::org($row) . ' · batch ' . (string) $row['batch_label'] . ' follow-up',
                'detail'          => $days === 0 ? 'Due today.' : 'Overdue by ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . '.',
                'link'            => '/sa-desk.php?view=recovery&e=' . rawurlencode((string) $row['engagement_ref']) . '#desk-rc-events',
            ]);
        }

        foreach ($this->invoices->outstandingEverywhere() as $row) {
            if ($row['due_at'] === null) {
                continue;
            }
            $days = $this->clock->daysUntil((string) $row['due_at']);
            if ($days === null || $days >= 0) {
                continue;
            }
            $key = 'invoice:' . (string) $row['id'];
            $seen[AttentionRepository::KIND_INVOICE_OVERDUE][] = $key;
            $this->attention->see($key, [
                'kind'            => AttentionRepository::KIND_INVOICE_OVERDUE,
                'severity'        => AttentionRepository::SEVERITY_ACTION,
                'engagement_id'   => (string) $row['engagement_id'],
                'organization_id' => (string) $row['organization_id'],
                'label'           => self::org($row) . ' · invoice ' . (string) $row['public_ref'],
                'detail'          => 'Past due by ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . '.',
                'link'            => '/sa-desk.php?view=money&e=' . rawurlencode((string) $row['engagement_ref']) . '#desk-mo-invoices',
            ]);
        }

        $total = 0;
        $resolved = 0;
        foreach ($seen as $kind => $keys) {
            $total += count($keys);
            $resolved += $this->attention->resolveUnseen($kind, $keys);
        }
        return ['summary' => $total . ' open, ' . $resolved . ' resolved', 'items' => $total];
    }

    /**
     * Section 17.2: "surface aggregate deadline groups at 30, 14, 7, 3, and
     * 1 day". Section 33.5 rule 3: an unconfirmed date may be labelled and
     * may not be called controlling.
     */
    private function deadlines(): array
    {
        $seen = [];
        foreach ($this->batches->withDeadlines() as $row) {
            $days = $this->clock->daysUntil((string) $row['earliest_deadline_at']);
            if ($days === null) {
                continue;
            }
            $threshold = null;
            foreach (array_reverse(self::DEADLINE_THRESHOLDS) as $t) {
                if ($days <= $t) {
                    $threshold = $t;
                    break;
                }
            }
            if ($threshold === null) {
                continue;
            }
            $confirmed = (int) $row['deadline_confirmed'] === 1;
            $key = 'deadline:' . (string) $row['id'] . ':' . $threshold;
            $seen[] = $key;

            $when = $days < 0
                ? abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' ago'
                : ($days === 0 ? 'today' : 'in ' . $days . ' day' . ($days === 1 ? '' : 's'));
            $this->attention->see($key, [
                'kind'            => AttentionRepository::KIND_DEADLINE,
                'severity'        => $threshold <= 7 ? AttentionRepository::SEVERITY_URGENT
                    : ($threshold === 14 ? AttentionRepository::SEVERITY_ACTION : AttentionRepository::SEVERITY_NOTE),
                'engagement_id'   => (string) $row['engagement_id'],
                'organization_id' => (string) $row['organization_id'],
                'label'           => ($confirmed ? '' : 'Unconfirmed date: ') . self::org($row) . ' · batch ' . (string) $row['label']
                    . ' · ' . (string) $row['claim_count'] . ' claim' . ((int) $row['claim_count'] === 1 ? '' : 's') . ' due ' . $when,
                'detail'          => ($confirmed
                    ? 'Confirmed deadline, under ' . $threshold . ' day' . ($threshold === 1 ? '' : 's') . '. '
                    : 'This date has not been confirmed and is not shown as controlling. Confirm it or correct it on the batch. ')
                    . 'Stage: ' . BatchStage::staffLabel((string) $row['stage']) . '.',
                'link'            => '/sa-desk.php?view=assessments&e=' . rawurlencode((string) $row['engagement_ref']),
            ]);
        }
        $resolved = $this->attention->resolveUnseen(AttentionRepository::KIND_DEADLINE, $seen);
        return ['summary' => count($seen) . ' under a threshold, ' . $resolved . ' resolved', 'items' => count($seen)];
    }

    private function paymentsPending(): array
    {
        $seen = [];
        foreach ($this->recoveries->awaitingVerification() as $row) {
            $key = 'payment:' . (string) $row['id'];
            $seen[] = $key;
            $this->attention->see($key, [
                'kind'            => AttentionRepository::KIND_PAYMENT_PENDING,
                'severity'        => AttentionRepository::SEVERITY_ACTION,
                'engagement_id'   => (string) $row['engagement_id'],
                'organization_id' => (string) $row['organization_id'],
                'label'           => self::org($row) . ' · batch ' . (string) $row['label'] . ' overturned',
                'detail'          => 'No reimbursement verified yet. No fee exists until one is.',
                'link'            => '/sa-desk.php?view=money&e=' . rawurlencode((string) $row['engagement_ref']) . '#desk-mo-verify',
            ]);
        }
        $resolved = $this->attention->resolveUnseen(AttentionRepository::KIND_PAYMENT_PENDING, $seen);
        return ['summary' => count($seen) . ' awaiting verification, ' . $resolved . ' resolved', 'items' => count($seen)];
    }

    private function closeoutAccess(): array
    {
        $seen = [];
        foreach ($this->closeouts->engagementsInCloseout() as $row) {
            if ($row['closeout_closed_at'] !== null) {
                continue;
            }
            $closeout = $this->closeouts->forEngagement((string) $row['id']);
            if ($closeout === null) {
                continue;
            }
            $undecided = $this->closeouts->undecidedAccessCount((string) $closeout['id']);
            if ($undecided === 0) {
                continue;
            }
            $key = 'closeout_access:' . (string) $closeout['id'];
            $seen[] = $key;
            $this->attention->see($key, [
                'kind'            => AttentionRepository::KIND_CLOSEOUT_ACCESS,
                'severity'        => AttentionRepository::SEVERITY_ACTION,
                'engagement_id'   => (string) $row['id'],
                'organization_id' => (string) $row['organization_id'],
                'label'           => self::org($row) . ' · ' . $undecided . ' person' . ($undecided === 1 ? '' : 's') . ' still undecided at closeout',
                'detail'          => 'Closeout began ' . $this->clock->displayDate((string) $row['started_at']) . '. Access stays open until every row is decided.',
                'link'            => '/sa-desk.php?view=closeout&e=' . rawurlencode((string) $row['public_ref']),
            ]);
        }
        $resolved = $this->attention->resolveUnseen(AttentionRepository::KIND_CLOSEOUT_ACCESS, $seen);
        return ['summary' => count($seen) . ' with open access, ' . $resolved . ' resolved', 'items' => count($seen)];
    }

    private function clientReminders(): array
    {
        $r = $this->reminders->send();
        return [
            'summary' => $r['considered'] . ' due, ' . $r['sent'] . ' sent, ' . $r['already'] . ' already sent this period, '
                . $r['refused'] . ' not taken, ' . $r['skipped'] . ' with nobody to remind',
            'items'   => $r['sent'],
        ];
    }

    private function backupDaily(): array
    {
        $made = $this->backups->create();
        $pruned = $this->backups->prune();
        return [
            'summary' => $made['rows'] . ' rows in ' . $made['tables'] . ' tables, ' . $made['bytes'] . ' bytes, ' . $pruned . ' old file' . ($pruned === 1 ? '' : 's') . ' pruned',
            'items'   => 1,
        ];
    }

    private function backupVerify(): array
    {
        $check = $this->backups->verify();
        if ($check['ok']) {
            $this->attention->resolve('backup:stale');
            return ['summary' => 'newest backup ' . $check['reason'] . ' (' . $check['age_hours'] . 'h old)', 'items' => 1];
        }
        $this->attention->see('backup:stale', [
            'kind'     => AttentionRepository::KIND_BACKUP,
            'severity' => AttentionRepository::SEVERITY_URGENT,
            'label'    => 'The newest backup did not verify',
            'detail'   => ucfirst($check['reason']) . '.',
            'link'     => '/sa-desk.php?view=jobs',
        ]);
        return ['summary' => 'FAILED: ' . $check['reason'], 'items' => 0];
    }

    private function housekeeping(): array
    {
        $limits = $this->rateLimiter->prune();
        $runs = $this->jobs->pruneRuns(90);
        $items = $this->attention->pruneResolved(90);
        return [
            'summary' => $limits . ' rate-limit rows, ' . $runs . ' old run rows, ' . $items . ' resolved items dropped',
            'items'   => $limits + $runs + $items,
        ];
    }

    private function morningDigest(): array
    {
        $r = $this->digest->send();
        if ($r['state'] === 'skipped') {
            return ['summary' => 'not yet: ' . $r['reason'] . ' (' . $this->config->digestHour() . ':00)', 'items' => 0];
        }
        if ($r['reason'] === 'already sent') {
            return ['summary' => 'already sent for ' . $r['date'], 'items' => 0];
        }
        return [
            'summary' => ($r['sent'] ? 'sent' : 'not taken: ' . $r['reason']) . ' for ' . $r['date'],
            'items'   => $r['sent'] ? 1 : 0,
        ];
    }

    // ------------------------------------------------------------------
    // Support.
    // ------------------------------------------------------------------

    private function guardTrigger(string $trigger): void
    {
        if (!in_array($trigger, JobRepository::triggers(), true)) {
            throw new \RuntimeException('Unknown job trigger: ' . $trigger);
        }
        // Only the schedule reads the flag. A run she starts from the Desk is
        // her act; the CLI is hers too, from the host's cron screen, which is
        // the one place the flag is meant to gate.
        if (in_array($trigger, [JobRepository::TRIGGER_CRON, JobRepository::TRIGGER_CLI], true)
            && !$this->config->cronEnabled()
        ) {
            throw new \RuntimeException(
                'The scheduled jobs are switched off here (SA_DEADLINE_CRON_ENABLED). '
                . 'Section 25 enables cron last. Run them from the Desk instead.'
            );
        }
    }

    /**
     * One tab-separated line: time, trigger, job, outcome, count, summary.
     * The summary is counts and reasons, never a name. Capped like every
     * other log on this site.
     */
    private function logLine(array $result, string $trigger): void
    {
        $path = $this->logPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = implode("\t", [
            $this->clock->nowUtc(),
            $trigger,
            (string) $result['job'],
            (string) $result['outcome'],
            (string) $result['items'],
            preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $result['summary']) ?? '',
        ]) . "\n";
        if (!is_file($path) || filesize($path) < 2_000_000) {
            @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        }
    }

    /** @param array<string,mixed> $row */
    private static function org(array $row): string
    {
        return (string) ($row['display_name'] ?? $row['legal_name'] ?? 'A practice');
    }
}
