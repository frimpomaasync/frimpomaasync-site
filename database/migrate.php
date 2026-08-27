<?php
declare(strict_types=1);

/**
 * The migration runner.
 *
 * CLI only. It refuses to run over the web, because a schema change reachable
 * by a GET request is a way for a stranger to drop her tables.
 *
 *   php database/migrate.php status
 *   php database/migrate.php up
 *   php database/migrate.php down          rolls back the last batch
 *   php database/migrate.php down --all    rolls everything back
 *   php database/migrate.php fresh         down --all, then up
 *
 * Options:
 *   --dsn=...   --user=...  --password=...   override the configured database
 *
 * The --dsn override is what lets the whole cycle be proved against a local
 * SQLite file before staging exists, which is the only reason this Mac can
 * verify anything at all: it has sqlite3 but no PHP, no MySQL, and no Docker.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not here.');
}

require_once __DIR__ . '/../src/SoftAppeals/Bootstrap.php';

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Support\Uuid;

$app = Bootstrap::boot(null, false);

$argv = $_SERVER['argv'] ?? [];
$command = $argv[1] ?? 'status';
$flags = array_slice($argv, 2);

$option = static function (string $name) use ($flags): ?string {
    foreach ($flags as $flag) {
        if (str_starts_with($flag, '--' . $name . '=')) {
            return substr($flag, strlen($name) + 3);
        }
    }
    return null;
};
$hasFlag = static fn (string $name): bool => in_array('--' . $name, $flags, true);

$dsn = $option('dsn');
$db = $dsn !== null
    ? Database::connect($dsn, $option('user') ?? '', $option('password') ?? '')
    : Database::fromConfig($app->config());

$runner = new SoftAppealsMigrationRunner($db, __DIR__ . '/migrations');

try {
    switch ($command) {
        case 'status':
            $runner->status();
            break;

        case 'up':
            $runner->up();
            break;

        case 'down':
            $runner->down($hasFlag('all'));
            break;

        case 'fresh':
            $runner->down(true);
            $runner->up();
            break;

        default:
            fwrite(STDERR, "Unknown command: {$command}\n");
            fwrite(STDERR, "Use: status | up | down [--all] | fresh\n");
            exit(2);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "\n  FAILED  " . $e->getMessage() . "\n");
    fwrite(STDERR, '          ' . basename($e->getFile()) . ':' . $e->getLine() . "\n\n");
    exit(1);
}

exit(0);


/**
 * Applies and rolls back migrations, tracking what has run in sa_migrations.
 *
 * Migrations run in a transaction where the driver supports a transactional
 * DDL. SQLite does; MySQL does not, and silently commits on a CREATE TABLE.
 * That difference is stated rather than hidden, because a half-applied MySQL
 * migration has to be finished or reversed by hand and pretending otherwise
 * would be the more dangerous choice.
 */
final class SoftAppealsMigrationRunner
{
    private Database $db;
    private string $directory;

    public function __construct(Database $db, string $directory)
    {
        $this->db = $db;
        $this->directory = $directory;
        $this->ensureLedger();
    }

    private function ensureLedger(): void
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

    /** @return list<array{name:string,file:string,up:callable,down:callable}> */
    private function available(): array
    {
        $files = glob($this->directory . '/*.php');
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
                throw new RuntimeException(
                    basename($file) . ' must return an array with name, up and down.'
                );
            }
            $out[] = [
                'name' => (string) $definition['name'],
                'file' => $file,
                'up'   => $definition['up'],
                'down' => $definition['down'],
            ];
        }
        return $out;
    }

    /** @return list<string> */
    private function applied(): array
    {
        $rows = $this->db->all('SELECT name FROM sa_migrations ORDER BY batch ASC, name ASC');
        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    public function status(): void
    {
        $applied = $this->applied();
        $this->line('');
        $this->line('  Soft Appeals migrations  ·  ' . $this->db->driver());
        $this->line('  ' . str_repeat('-', 52));
        foreach ($this->available() as $migration) {
            $mark = in_array($migration['name'], $applied, true) ? 'applied' : 'pending';
            $this->line(sprintf('  %-8s  %s', $mark, $migration['name']));
        }
        $pending = count($this->available()) - count($applied);
        $this->line('  ' . str_repeat('-', 52));
        $this->line(sprintf('  %d applied, %d pending', count($applied), max(0, $pending)));
        $this->line('');
    }

    public function up(): void
    {
        $applied = $this->applied();
        $batch = (int) ($this->db->value('SELECT MAX(batch) FROM sa_migrations') ?? 0) + 1;
        $ran = 0;

        foreach ($this->available() as $migration) {
            if (in_array($migration['name'], $applied, true)) {
                continue;
            }
            $this->line('  up    ' . $migration['name']);
            $this->db->transaction(function () use ($migration, $batch): void {
                ($migration['up'])($this->db);
                $this->db->insert('sa_migrations', [
                    'id'         => Uuid::v4(),
                    'name'       => $migration['name'],
                    'batch'      => $batch,
                    'applied_at' => gmdate('Y-m-d H:i:s'),
                ]);
            });
            $ran++;
        }

        $this->line($ran === 0 ? '  Nothing to do.' : sprintf('  %d migration(s) applied.', $ran));
    }

    public function down(bool $all = false): void
    {
        $available = [];
        foreach ($this->available() as $migration) {
            $available[$migration['name']] = $migration;
        }

        $rows = $all
            ? $this->db->all('SELECT * FROM sa_migrations ORDER BY batch DESC, name DESC')
            : $this->db->all(
                'SELECT * FROM sa_migrations WHERE batch = (SELECT MAX(batch) FROM sa_migrations)'
                . ' ORDER BY name DESC'
            );

        if ($rows === []) {
            $this->line('  Nothing to roll back.');
            return;
        }

        foreach ($rows as $row) {
            $name = (string) $row['name'];
            if (!isset($available[$name])) {
                throw new RuntimeException(
                    'Migration "' . $name . '" is recorded as applied but its file is gone. '
                    . 'Restore the file before rolling back.'
                );
            }
            $this->line('  down  ' . $name);
            $this->db->transaction(function () use ($available, $name): void {
                ($available[$name]['down'])($this->db);
                $this->db->run('DELETE FROM sa_migrations WHERE name = :n', ['n' => $name]);
            });
        }

        $this->line(sprintf('  %d migration(s) rolled back.', count($rows)));
    }

    private function line(string $text): void
    {
        fwrite(STDOUT, $text . "\n");
    }
}
