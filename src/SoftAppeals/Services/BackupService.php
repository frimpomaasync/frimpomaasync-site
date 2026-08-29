<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Config;
use SoftAppeals\Database;
use SoftAppeals\Support\Clock;

/**
 * The daily backup, and the proof that it can be put back. Section 18.1
 * control 18: "daily backup plus tested restoration procedure".
 *
 * There is no shell on this host and no mysqldump anyone can run, so the
 * backup is the application's own: every sa_ table, every row, written as
 * one gzipped JSON document into the private storage folder with a SHA-256
 * beside it. That is a logical backup, not a file copy, and it is the right
 * shape here for three reasons. It needs nothing but PHP. It restores onto
 * either engine the schema supports, so a MySQL backup can be proved against
 * SQLite on CI, which is where the restore test runs. And it is readable by
 * a person, which a binary dump is not.
 *
 * Two rules the restore holds itself to:
 *
 *   1. It only writes into EMPTY tables. A backup landing on top of live
 *      rows would be the worst possible outcome of a recovery, so the
 *      target has to be a database that has been migrated and holds nothing.
 *   2. It never touches the configured live database from the cron entry
 *      point. Restoring is a deliberate act with a named target. See the
 *      runbook.
 *
 * The documents themselves (agreements, executed records, signature payloads,
 * invoices) live in the vault as files and are NOT inside this backup: every
 * one carries its hash on its database row, so a restored database says
 * exactly which files it expects, and the files are what the host's own
 * file backup covers. Section 25 step 1 backs up both.
 */
final class BackupService
{
    /** The file format version. Bumped if the shape changes. */
    public const FORMAT = 1;

    /** A backup older than this counts as missing. Section 17.2 says daily. */
    public const MAX_AGE_HOURS = 36;

    /** How many days of backups to keep, and the floor under the count. */
    public const KEEP_DAYS = 14;
    public const KEEP_AT_LEAST = 7;

    /**
     * The order tables are written and restored in: parents before children,
     * the same order the migrations create them. A table not listed here is
     * appended after these, so a new migration cannot be left out by mistake.
     */
    private const ORDER = [
        'sa_migrations',
        'sa_organizations', 'sa_contacts', 'sa_users', 'sa_memberships',
        'sa_audit_events', 'sa_rate_limits', 'sa_idempotency_keys',
        'sa_intakes', 'sa_engagements', 'sa_invitations', 'sa_communications', 'sa_status_events',
        'sa_engagement_preferences', 'sa_login_codes',
        'sa_documents', 'sa_signatures',
        'sa_settings', 'sa_assessments', 'sa_work_batches', 'sa_checklist_items', 'sa_action_requests',
        'sa_recovery_scopes', 'sa_recovery_scope_batches', 'sa_approval_requests', 'sa_submission_events',
        'sa_invoices', 'sa_recoveries', 'sa_closeouts', 'sa_closeout_steps', 'sa_access_reviews',
        'sa_job_locks', 'sa_job_runs', 'sa_attention_items',
    ];

    private Config $config;
    private Database $db;
    private Clock $clock;
    private AuditService $audit;

    public function __construct(Config $config, Database $db, Clock $clock, AuditService $audit)
    {
        $this->config = $config;
        $this->db = $db;
        $this->clock = $clock;
        $this->audit = $audit;
    }

    /** Where the backups live. Deny-all on the server, gitignored in the repo. */
    public function directory(): string
    {
        return $this->config->privateStoragePath('backups');
    }

    /**
     * Write one backup of the live database.
     *
     * @return array{path:string,sha256:string,bytes:int,tables:int,rows:int,created_at:string}
     */
    public function create(): array
    {
        $directory = $this->directory();
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('The backup folder cannot be written.');
        }

        $now = $this->clock->nowUtc();
        $tables = [];
        $rows = 0;
        foreach ($this->tables() as $table) {
            $data = $this->db->all('SELECT * FROM ' . $this->db->quoteIdentifier($table));
            $columns = $data === [] ? $this->columnsOf($table) : array_keys($data[0]);
            $tables[$table] = [
                'columns' => $columns,
                'rows'    => array_map(static fn (array $row): array => array_values($row), $data),
            ];
            $rows += count($data);
        }

        $document = [
            'format'     => self::FORMAT,
            'created_at' => $now,
            'driver'     => $this->db->driver(),
            'app_env'    => $this->config->string('SA_APP_ENV'),
            'tables'     => $tables,
        ];
        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('The backup could not be encoded.');
        }
        $bytes = gzencode($json, 6);
        if ($bytes === false) {
            throw new \RuntimeException('The backup could not be compressed.');
        }

        // Named by the second it was written. Two in one second (the test
        // suite does this; a person cannot) get a counter so neither is
        // overwritten.
        $stamp = $this->clock->now()->format('Ymd-His');
        $name = 'sa-backup-' . $stamp . '.json.gz';
        for ($n = 2; is_file($directory . '/' . $name) && $n < 100; $n++) {
            $name = 'sa-backup-' . $stamp . '-' . $n . '.json.gz';
        }
        $path = $directory . '/' . $name;
        $sha = hash('sha256', $bytes);

        // Written whole, then moved into place, so a half-written file can
        // never be mistaken for a backup.
        $temporary = $path . '.part';
        if (@file_put_contents($temporary, $bytes, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('The backup file could not be written.');
        }
        @file_put_contents($path . '.sha256', $sha . "\n", LOCK_EX);
        @chmod($path, 0640);

        $this->audit->record('backup.create', 'success', 'backup', null, [
            'count'  => $rows,
            'source' => $name,
        ]);

        return [
            'path'       => $path,
            'sha256'     => $sha,
            'bytes'      => strlen($bytes),
            'tables'     => count($tables),
            'rows'       => $rows,
            'created_at' => $now,
        ];
    }

    /**
     * The newest backup on disk, by name, or null. The name carries the
     * stamp, so name order is time order.
     *
     * @return array{path:string,name:string,bytes:int,modified_at:string}|null
     */
    public function latest(): ?array
    {
        $files = glob($this->directory() . '/sa-backup-*.json.gz');
        if ($files === false || $files === []) {
            return null;
        }
        sort($files, SORT_STRING);
        $path = (string) end($files);
        return [
            'path'        => $path,
            'name'        => basename($path),
            'bytes'       => (int) (filesize($path) ?: 0),
            'modified_at' => gmdate('Y-m-d H:i:s', (int) (filemtime($path) ?: 0)),
        ];
    }

    /** @return list<array{path:string,name:string,bytes:int,modified_at:string}> newest first */
    public function all(): array
    {
        $files = glob($this->directory() . '/sa-backup-*.json.gz');
        if ($files === false) {
            return [];
        }
        rsort($files, SORT_STRING);
        $out = [];
        foreach ($files as $path) {
            $out[] = [
                'path'        => $path,
                'name'        => basename($path),
                'bytes'       => (int) (filesize($path) ?: 0),
                'modified_at' => gmdate('Y-m-d H:i:s', (int) (filemtime($path) ?: 0)),
            ];
        }
        return $out;
    }

    /**
     * Section 17.2: "verify the most recent backup exists". Exists, is
     * recent, matches its hash, decodes, and names the tables the live
     * schema has.
     *
     * @return array{ok:bool,reason:string,path:?string,age_hours:?float,rows:?int}
     */
    public function verify(?string $path = null): array
    {
        if ($path === null) {
            $latest = $this->latest();
            if ($latest === null) {
                return ['ok' => false, 'reason' => 'no backup has been written yet', 'path' => null, 'age_hours' => null, 'rows' => null];
            }
            $path = $latest['path'];
        }
        if (!is_file($path)) {
            return ['ok' => false, 'reason' => 'the backup file is missing', 'path' => $path, 'age_hours' => null, 'rows' => null];
        }

        $bytes = (string) file_get_contents($path);
        $sidecar = $path . '.sha256';
        $expected = is_file($sidecar) ? trim((string) file_get_contents($sidecar)) : '';
        if ($expected === '') {
            return ['ok' => false, 'reason' => 'the backup has no hash beside it', 'path' => $path, 'age_hours' => null, 'rows' => null];
        }
        if (!hash_equals($expected, hash('sha256', $bytes))) {
            return ['ok' => false, 'reason' => 'the backup does not match its hash', 'path' => $path, 'age_hours' => null, 'rows' => null];
        }

        $decoded = $this->decode($bytes);
        if ($decoded === null) {
            return ['ok' => false, 'reason' => 'the backup does not decode', 'path' => $path, 'age_hours' => null, 'rows' => null];
        }

        $createdAt = $this->clock->parseUtc((string) ($decoded['created_at'] ?? ''));
        $ageHours = $createdAt === null
            ? null
            : round(($this->clock->now()->getTimestamp() - $createdAt->getTimestamp()) / 3600, 1);
        $rows = 0;
        foreach ($decoded['tables'] as $table) {
            $rows += count($table['rows']);
        }

        $missing = [];
        foreach ($this->tables() as $table) {
            if (!isset($decoded['tables'][$table])) {
                $missing[] = $table;
            }
        }
        if ($missing !== []) {
            return ['ok' => false, 'reason' => 'the backup is missing ' . count($missing) . ' table(s) the schema has', 'path' => $path, 'age_hours' => $ageHours, 'rows' => $rows];
        }
        if ($ageHours === null || $ageHours > self::MAX_AGE_HOURS) {
            return ['ok' => false, 'reason' => 'the newest backup is older than ' . self::MAX_AGE_HOURS . ' hours', 'path' => $path, 'age_hours' => $ageHours, 'rows' => $rows];
        }

        return ['ok' => true, 'reason' => 'present, recent, and matches its hash', 'path' => $path, 'age_hours' => $ageHours, 'rows' => $rows];
    }

    /**
     * Put a backup into an EMPTY, migrated database.
     *
     * $target is deliberately a parameter and never defaulted to the live
     * connection. The tests restore into a fresh SQLite file; the runbook
     * restores into a fresh database on the host. Refuses if any sa_ table in
     * the target holds a row.
     *
     * @return array{tables:int,rows:int}
     */
    public function restore(Database $target, string $path): array
    {
        $check = $this->verifyFile($path);
        if (!$check['ok']) {
            throw new \RuntimeException('Refusing to restore: ' . $check['reason'] . '.');
        }
        $decoded = $this->decode((string) file_get_contents($path));
        if ($decoded === null) {
            throw new \RuntimeException('Refusing to restore: the backup does not decode.');
        }

        $targetTables = self::listTables($target);
        foreach ($targetTables as $table) {
            if ($table === 'sa_migrations') {
                continue;
            }
            $n = (int) $target->value('SELECT COUNT(*) FROM ' . $target->quoteIdentifier($table));
            if ($n > 0) {
                throw new \RuntimeException('Refusing to restore into a database that holds rows (' . $table . ').');
            }
        }

        // Rows are written parents-first, but a foreign key that a later
        // table's row points BACK at (an invoice named by a recovery row,
        // written after it) needs the checks off for the duration. On
        // SQLite the pragma has to be set outside a transaction.
        $sqlite = $target->isSqlite();
        if ($sqlite) {
            $target->run('PRAGMA foreign_keys = OFF');
        } else {
            $target->run('SET FOREIGN_KEY_CHECKS = 0');
        }

        $tables = 0;
        $rows = 0;
        try {
            $target->transaction(function () use ($target, $decoded, $targetTables, &$tables, &$rows): void {
                // The ledger is restored by replacement: the target's own
                // ledger says what it migrated, and the backup's says what
                // the source had. They must agree, or the rows will not fit.
                foreach ($this->orderedNames(array_keys($decoded['tables'])) as $table) {
                    if (!in_array($table, $targetTables, true)) {
                        throw new \RuntimeException('The target has no table ' . $table . '. Migrate it first.');
                    }
                    $spec = $decoded['tables'][$table];
                    if ($table === 'sa_migrations') {
                        $target->run('DELETE FROM sa_migrations');
                    }
                    $tables++;
                    foreach ($spec['rows'] as $values) {
                        $row = array_combine($spec['columns'], $values);
                        if ($row === false) {
                            throw new \RuntimeException('A row in ' . $table . ' does not match its columns.');
                        }
                        $target->insert($table, $row);
                        $rows++;
                    }
                }
            });
        } finally {
            if ($sqlite) {
                $target->run('PRAGMA foreign_keys = ON');
            } else {
                $target->run('SET FOREIGN_KEY_CHECKS = 1');
            }
        }

        $this->audit->record('backup.restore', 'success', 'backup', null, [
            'count'  => $rows,
            'source' => basename($path),
        ]);

        return ['tables' => $tables, 'rows' => $rows];
    }

    /**
     * Drop old backups. Keeps everything from the last KEEP_DAYS days and
     * never fewer than KEEP_AT_LEAST files, whatever their age.
     *
     * @return int how many files were removed
     */
    public function prune(): int
    {
        $all = $this->all();
        if (count($all) <= self::KEEP_AT_LEAST) {
            return 0;
        }
        $cutoff = $this->clock->now()->getTimestamp() - 86400 * self::KEEP_DAYS;
        $removed = 0;
        foreach (array_slice($all, self::KEEP_AT_LEAST) as $file) {
            $modified = (int) (filemtime($file['path']) ?: 0);
            if ($modified >= $cutoff) {
                continue;
            }
            if (@unlink($file['path'])) {
                @unlink($file['path'] . '.sha256');
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Read one backup's header without keeping the rows. For the Desk.
     *
     * @return array{created_at:string,driver:string,app_env:string,tables:int,rows:int}|null
     */
    public function describe(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $decoded = $this->decode((string) file_get_contents($path));
        if ($decoded === null) {
            return null;
        }
        $rows = 0;
        foreach ($decoded['tables'] as $table) {
            $rows += count($table['rows']);
        }
        return [
            'created_at' => (string) $decoded['created_at'],
            'driver'     => (string) $decoded['driver'],
            'app_env'    => (string) $decoded['app_env'],
            'tables'     => count($decoded['tables']),
            'rows'       => $rows,
        ];
    }

    /** @return list<string> every sa_ table in the live database, parents first */
    public function tables(): array
    {
        return $this->orderedNames(self::listTables($this->db));
    }

    /** @return list<string> */
    private static function listTables(Database $db): array
    {
        if ($db->isSqlite()) {
            $rows = $db->all("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'sa\\_%' ESCAPE '\\' ORDER BY name");
        } else {
            $rows = $db->all(
                "SELECT table_name AS name FROM information_schema.tables"
                . " WHERE table_schema = DATABASE() AND table_name LIKE 'sa\\_%' ORDER BY table_name"
            );
        }
        $out = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? $row['NAME'] ?? '');
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * The known order first, then anything else alphabetically.
     *
     * @param list<string> $names
     * @return list<string>
     */
    private function orderedNames(array $names): array
    {
        $set = array_flip($names);
        $out = [];
        foreach (self::ORDER as $table) {
            if (isset($set[$table])) {
                $out[] = $table;
                unset($set[$table]);
            }
        }
        $rest = array_keys($set);
        sort($rest, SORT_STRING);
        return array_merge($out, $rest);
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        if ($this->db->isSqlite()) {
            return array_map(
                static fn (array $r): string => (string) $r['name'],
                $this->db->all('PRAGMA table_info(' . $this->db->quoteIdentifier($table) . ')')
            );
        }
        return array_map(
            static fn (array $r): string => (string) ($r['column_name'] ?? $r['COLUMN_NAME']),
            $this->db->all(
                'SELECT column_name FROM information_schema.columns'
                . ' WHERE table_schema = DATABASE() AND table_name = :t ORDER BY ordinal_position',
                ['t' => $table]
            )
        );
    }

    /** Hash and decode only. Used by restore, which does not care about age. */
    private function verifyFile(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'reason' => 'the backup file is missing'];
        }
        $bytes = (string) file_get_contents($path);
        $sidecar = $path . '.sha256';
        $expected = is_file($sidecar) ? trim((string) file_get_contents($sidecar)) : '';
        if ($expected === '' || !hash_equals($expected, hash('sha256', $bytes))) {
            return ['ok' => false, 'reason' => 'the backup does not match its hash'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /** @return array{format:int,created_at:string,driver:string,app_env:string,tables:array<string,array{columns:list<string>,rows:list<list<mixed>>}>}|null */
    private function decode(string $bytes): ?array
    {
        $json = @gzdecode($bytes);
        if ($json === false) {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['tables']) || !is_array($decoded['tables'])) {
            return null;
        }
        if ((int) ($decoded['format'] ?? 0) !== self::FORMAT) {
            return null;
        }
        foreach ($decoded['tables'] as $name => $spec) {
            if (!is_string($name) || !is_array($spec) || !isset($spec['columns'], $spec['rows'])
                || !is_array($spec['columns']) || !is_array($spec['rows'])) {
                return null;
            }
        }
        /** @var array{format:int,created_at:string,driver:string,app_env:string,tables:array<string,array{columns:list<string>,rows:list<list<mixed>>}>} $decoded */
        return $decoded;
    }
}
