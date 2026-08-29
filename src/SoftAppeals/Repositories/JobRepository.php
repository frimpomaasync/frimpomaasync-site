<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Support\Uuid;

/**
 * Job locks and job runs. Section 17.2, and Phase 8's "job failures surface
 * on The Desk".
 *
 * The lock is the one write that has to be right. Two crons can start the
 * same minute on a shared host, and section 17.2 says the same job must not
 * run twice at once. Acquiring is a single UPDATE guarded on the lock having
 * lapsed, so whichever of the two runs the statement first gets one affected
 * row and the other gets zero. No read-then-write, no window between them.
 *
 * A lock is never held forever. Every acquisition names how long it is good
 * for, and a job that died mid-run without releasing leaves a lock the next
 * cron can take once that time has passed. A held lock with a live process
 * behind it and a stale lock with a dead one look identical, and the expiry
 * is what tells them apart without a shell.
 */
final class JobRepository extends Repository
{
    public const TRIGGER_CRON = 'cron';
    public const TRIGGER_DESK = 'desk';
    public const TRIGGER_CLI  = 'cli';
    public const TRIGGER_TEST = 'test';

    public const OUTCOME_OK      = 'ok';
    public const OUTCOME_FAILED  = 'failed';
    public const OUTCOME_SKIPPED = 'skipped';

    protected function table(): string
    {
        return 'sa_job_runs';
    }

    /** @return list<string> */
    public static function triggers(): array
    {
        return [self::TRIGGER_CRON, self::TRIGGER_DESK, self::TRIGGER_CLI, self::TRIGGER_TEST];
    }

    // ------------------------------------------------------------------
    // Locks.
    // ------------------------------------------------------------------

    /**
     * Take the lock for one job, or find out somebody else holds it.
     *
     * Returns the token that now holds it, or null when a live lock exists.
     * The token is what release() needs, so a job cannot release a lock a
     * later run of the same job has since taken.
     */
    public function acquireLock(string $jobKey, int $ttlSeconds): ?string
    {
        $token = Uuid::v4();
        $now = $this->clock->nowUtc();
        $until = $this->clock->utcPlusSeconds(max(1, $ttlSeconds));

        // A job that has never run has no row. Insert one; a race on the
        // insert itself is settled by the primary key, and the loser falls
        // through to the guarded update below and finds the row taken.
        if (!$this->db->exists('SELECT job_key FROM sa_job_locks WHERE job_key = :k', ['k' => $jobKey])) {
            try {
                $this->db->insert('sa_job_locks', [
                    'job_key'      => $jobKey,
                    'token'        => $token,
                    'locked_until' => $until,
                    'updated_at'   => $now,
                ]);
                return $token;
            } catch (\Throwable) {
                // Somebody inserted first. Try the update.
            }
        }

        $taken = $this->db->run(
            'UPDATE sa_job_locks SET token = :t, locked_until = :u, updated_at = :n'
            . ' WHERE job_key = :k AND locked_until < :now',
            ['t' => $token, 'u' => $until, 'n' => $now, 'k' => $jobKey, 'now' => $now]
        )->rowCount();

        return $taken === 1 ? $token : null;
    }

    /** Let go. Only the holder can; a stale token releases nothing. */
    public function releaseLock(string $jobKey, string $token): bool
    {
        return $this->db->run(
            'UPDATE sa_job_locks SET locked_until = :past, updated_at = :n'
            . ' WHERE job_key = :k AND token = :t',
            [
                'past' => $this->clock->utcPlusSeconds(-1),
                'n'    => $this->clock->nowUtc(),
                'k'    => $jobKey,
                't'    => $token,
            ]
        )->rowCount() === 1;
    }

    /** @return array<string,mixed>|null the lock row, for the Desk to show who holds what */
    public function lock(string $jobKey): ?array
    {
        return $this->db->one('SELECT * FROM sa_job_locks WHERE job_key = :k', ['k' => $jobKey]);
    }

    public function isLocked(string $jobKey): bool
    {
        $row = $this->lock($jobKey);
        return $row !== null && !$this->clock->hasPassed((string) $row['locked_until']);
    }

    // ------------------------------------------------------------------
    // Runs.
    // ------------------------------------------------------------------

    /** Open a run. Returns its id. */
    public function startRun(string $jobKey, string $trigger): string
    {
        if (!in_array($trigger, self::triggers(), true)) {
            throw new \RuntimeException('Unknown job trigger: ' . $trigger);
        }
        $id = Uuid::v4();
        $now = $this->clock->nowUtc();
        $this->db->insert('sa_job_runs', [
            'id'          => $id,
            'job_key'     => $jobKey,
            'trigger_by'  => $trigger,
            'started_at'  => $now,
            'finished_at' => null,
            'outcome'     => null,
            'items'       => 0,
            'summary'     => null,
            'created_at'  => $now,
        ]);
        return $id;
    }

    /** Close a run. Exactly once: the WHERE names an open row. */
    public function finishRun(string $runId, string $outcome, int $items, ?string $summary): bool
    {
        if (!in_array($outcome, [self::OUTCOME_OK, self::OUTCOME_FAILED, self::OUTCOME_SKIPPED], true)) {
            throw new \RuntimeException('Unknown job outcome: ' . $outcome);
        }
        return $this->db->run(
            'UPDATE sa_job_runs SET finished_at = :f, outcome = :o, items = :i, summary = :s'
            . ' WHERE id = :id AND finished_at IS NULL',
            [
                'f'  => $this->clock->nowUtc(),
                'o'  => $outcome,
                'i'  => max(0, $items),
                's'  => $summary === null || trim($summary) === '' ? null : mb_substr(trim($summary), 0, 500),
                'id' => $runId,
            ]
        )->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> newest first */
    public function recentRuns(int $limit = 40): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->all(
            'SELECT * FROM sa_job_runs ORDER BY started_at DESC, id DESC LIMIT ' . $limit
        );
    }

    /** @return list<array<string,mixed>> newest first, one job */
    public function runsFor(string $jobKey, int $limit = 20): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->all(
            'SELECT * FROM sa_job_runs WHERE job_key = :k ORDER BY started_at DESC, id DESC LIMIT ' . $limit,
            ['k' => $jobKey]
        );
    }

    /** @return array<string,mixed>|null the newest finished run of one job */
    public function lastRun(string $jobKey): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_job_runs WHERE job_key = :k AND finished_at IS NOT NULL'
            . ' ORDER BY started_at DESC, id DESC',
            ['k' => $jobKey]
        );
    }

    /** @return array<string,mixed>|null the newest run of one job that worked */
    public function lastSuccess(string $jobKey): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_job_runs WHERE job_key = :k AND outcome = :o'
            . ' ORDER BY started_at DESC, id DESC',
            ['k' => $jobKey, 'o' => self::OUTCOME_OK]
        );
    }

    /** How many runs failed in the last $days days, across every job. */
    public function failuresSince(int $days): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM sa_job_runs WHERE outcome = :o AND started_at >= :since',
            ['o' => self::OUTCOME_FAILED, 'since' => $this->clock->utcPlusSeconds(-86400 * max(1, $days))]
        );
    }

    /** The newest finished run of any job, whatever it was. Null when nothing has ever run. */
    public function lastRunAnywhere(): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_job_runs WHERE finished_at IS NOT NULL ORDER BY started_at DESC, id DESC'
        );
    }

    /** Drop run rows older than $days days. The health log on disk keeps the long tail. */
    public function pruneRuns(int $days = 90): int
    {
        return $this->db->run(
            'DELETE FROM sa_job_runs WHERE started_at < :c AND finished_at IS NOT NULL',
            ['c' => $this->clock->utcPlusSeconds(-86400 * max(1, $days))]
        )->rowCount();
    }
}
