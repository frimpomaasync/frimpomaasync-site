<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Database;
use SoftAppeals\Support\Clock;
use SoftAppeals\Support\Uuid;
use Throwable;

/**
 * Applies pending migrations without a command line.
 *
 * There is no SSH on this account, by her decision, and no PHP on the machine
 * this was written on. That left `php database/migrate.php up` unrunnable by
 * anyone, which would have made a schema change a manual errand forever.
 *
 * So the application brings its own schema up on boot. The same migration files
 * the CLI runner uses are applied here, in the same order, recorded in the same
 * ledger table. The CLI runner still exists and still works; this is a second
 * door to it, not a second implementation.
 *
 * Four things keep that from being reckless:
 *
 *   1. It is off in production unless SA_AUTO_MIGRATE is switched on. Staging
 *      keeps its schema current by itself; the live database changes when she
 *      is watching.
 *   2. Only one request can migrate at a time. A lock file with an exclusive
 *      flock means a second visitor arriving mid-migration waits or moves on
 *      rather than running the same CREATE TABLE twice.
 *   3. Each migration runs inside a transaction where the driver supports one,
 *      and its ledger row is written in the same transaction. A migration that
 *      fails halfway is not recorded as applied.
 *   4. Every outcome is audited, including the failures.
 */
final class SchemaService
{
    private Database $db;
    private Clock $clock;
    private string $migrationsPath;
    private string $lockPath;

    public function __construct(Database $db, Clock $clock, string $migrationsPath, string $lockPath)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->migrationsPath = $migrationsPath;
        $this->lockPath = $lockPath;
    }

    /** True when the schema is behind the migration files on disk. */
    public function hasPending(): bool
    {
        return $this->pending() !== [];
    }

    /** @return list<array{name:string,up:callable,down:callable}> */
    public function pending(): array
    {
        $applied = $this->appliedNames();
        $out = [];
        foreach ($this->available() as $migration) {
            if (!in_array($migration['name'], $applied, true)) {
                $out[] = $migration;
            }
        }
        return $out;
    }

    /**
     * Apply everything outstanding.
     *
     * Returns the names actually applied. An empty array means either there was
     * nothing to do, or another request held the lock and is doing it.
     *
     * $allowRecovery clears the tables left behind by a migration that failed
     * halfway. Never pass true on production.
     *
     * @return list<string>
     */
    public function migrate(?AuditService $audit = null, bool $allowRecovery = false): array
    {
        $pending = $this->pending();
        if ($pending === []) {
            return [];
        }

        $lock = $this->acquireLock();
        if ($lock === null) {
            // Somebody else is mid-migration. Not an error: the next request
            // will find the work already done.
            return [];
        }

        $applied = [];
        try {
            // Re-read inside the lock. Between the check above and the lock
            // being granted, the other request may have finished the lot.
            foreach ($this->pending() as $migration) {
                $batch = (int) ($this->db->value('SELECT MAX(batch) FROM sa_migrations') ?? 0) + 1;

                // MySQL commits DDL as it goes, so a migration that fails
                // halfway leaves tables behind and no ledger row. Retrying then
                // dies on "table already exists" and stays stuck forever, which
                // is not a state anyone can get out of without a SQL console,
                // and there is no SQL console on this account.
                //
                // Off production, clear first. This is only ever reached for a
                // migration the ledger says was never applied, so it can only
                // remove tables from an attempt that did not finish. Production
                // is left alone deliberately: there, a half-applied migration is
                // a decision to make, not a mess to sweep.
                if ($allowRecovery) {
                    try {
                        // The down step is DROP TABLE IF EXISTS throughout, so
                        // on a database where this migration never ran it is a
                        // no-op, and on one where it half ran it is the cleanup.
                        // Either way it is safe to call before the up.
                        ($migration['down'])($this->db);
                    } catch (Throwable) {
                        // Best effort. If the down cannot clear it, the up below
                        // fails with its own message, which is the more useful
                        // of the two.
                    }
                }

                $this->db->transaction(function () use ($migration, $batch): void {
                    ($migration['up'])($this->db);
                    $this->db->insert('sa_migrations', [
                        'id'         => Uuid::v4(),
                        'name'       => $migration['name'],
                        'batch'      => $batch,
                        'applied_at' => $this->clock->nowUtc(),
                    ]);
                });

                $applied[] = $migration['name'];
                $audit?->record('schema.migrate', 'success', 'migration', null, [
                    'migration' => $migration['name'],
                ]);
            }
        } catch (Throwable $e) {
            $audit?->record('schema.migrate', 'error', 'migration', null, [
                'migration' => $pending[0]['name'] ?? 'unknown',
                'reason'    => 'transaction failed',
            ]);
            throw $e;
        } finally {
            $this->releaseLock($lock);
        }

        return $applied;
    }

    /**
     * The ledger table, created outside the migration list because every
     * migration needs it to exist first.
     */
    public function ensureLedger(): void
    {
        if ($this->db->tableExists('sa_migrations')) {
            return;
        }
        $suffix = $this->db->isSqlite()
            ? ''
            : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->db->run(
            'CREATE TABLE sa_migrations (
                id         CHAR(36)     NOT NULL,
                name       VARCHAR(120) NOT NULL,
                batch      INTEGER      NOT NULL,
                applied_at DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_migration_name_unique UNIQUE (name)
            )' . $suffix
        );
    }

    /** @return list<string> */
    public function appliedNames(): array
    {
        $this->ensureLedger();
        $rows = $this->db->all('SELECT name FROM sa_migrations ORDER BY batch ASC, name ASC');
        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    /** @return list<array{name:string,up:callable,down:callable}> */
    private function available(): array
    {
        $files = glob(rtrim($this->migrationsPath, '/') . '/*.php');
        if ($files === false) {
            return [];
        }
        sort($files, SORT_STRING);

        $out = [];
        foreach ($files as $file) {
            /** @psalm-suppress UnresolvableInclude */
            $definition = require $file;
            if (!is_array($definition)
                || !isset($definition['name'], $definition['up'], $definition['down'])
                || !is_callable($definition['up'])
                || !is_callable($definition['down'])
            ) {
                throw new \RuntimeException(
                    basename($file) . ' must return an array with name, up and down.'
                );
            }
            $out[] = [
                'name' => (string) $definition['name'],
                'up'   => $definition['up'],
                'down' => $definition['down'],
            ];
        }
        return $out;
    }

    /**
     * Exclusive, non-blocking. A second request does not queue behind the
     * first: it gets null, skips migrating, and carries on. Whatever it needed
     * will be there on the next request, and a page that hangs waiting on a
     * lock is worse than one that renders a moment early.
     *
     * @return resource|null
     */
    private function acquireLock()
    {
        $directory = dirname($this->lockPath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }
        $handle = @fopen($this->lockPath, 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource $handle */
    private function releaseLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
