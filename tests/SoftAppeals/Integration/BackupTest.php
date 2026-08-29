<?php
declare(strict_types=1);

/**
 * Phase 8 acceptance: "restore test succeeds". Section 18.1 control 18:
 * "daily backup plus tested restoration procedure".
 *
 * A backup is written from a database holding a full walk, put into a fresh
 * migrated database, and compared row for row. Then the ways it must refuse:
 * a tampered file, a target that is not empty, a missing hash.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Services\BackupService;
use SoftAppeals\Support\Clock;

$walk = require __DIR__ . '/../Support/walk.php';
$boot = $walk['boot'];
$overturned = $walk['overturned'];

/** A fresh, migrated, empty SQLite database. */
$freshTarget = static function (): Database {
    $path = sys_get_temp_dir() . '/sa-restore-' . bin2hex(random_bytes(4)) . '.sqlite';
    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
        @unlink($path . '-wal');
        @unlink($path . '-shm');
    });
    $db = Database::connect('sqlite:' . $path);
    migrateUp($db);
    return $db;
};

/** Every sa_ table with its row count. */
$counts = static function (Database $db): array {
    $out = [];
    foreach ($db->all("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'sa%' ORDER BY name") as $row) {
        $name = (string) $row['name'];
        $out[$name] = (int) $db->value('SELECT COUNT(*) FROM ' . $db->quoteIdentifier($name));
    }
    return $out;
};

return [

    'a backup is written with its hash, verifies, and describes itself' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned): void {
            [$app, $sent] = $boot($db);
            $overturned($app, $sent);
            $backups = $app->backupService();

            Expect::false($backups->verify()['ok'], 'before any backup, verify says so');
            Expect::true(str_contains($backups->verify()['reason'], 'no backup'), 'and names the reason');

            $made = $backups->create();
            Expect::true(is_file($made['path']), 'the file exists');
            Expect::true(is_file($made['path'] . '.sha256'), 'with its hash beside it');
            Expect::same($made['sha256'], trim((string) file_get_contents($made['path'] . '.sha256')), 'and the hash matches');
            Expect::true($made['rows'] > 50, 'a full walk is more than fifty rows: ' . $made['rows']);
            Expect::true($made['tables'] >= 30, 'every table is in it: ' . $made['tables']);
            Expect::true(str_starts_with(basename($made['path']), 'sa-backup-'), 'named by stamp');

            $check = $backups->verify();
            Expect::true($check['ok'], 'the newest backup verifies: ' . $check['reason']);
            Expect::same($made['rows'], $check['rows'], 'and counts the same rows');

            $described = $backups->describe($made['path']);
            Expect::notNull($described, 'it describes itself');
            Expect::same('sqlite', $described['driver'], 'and names the engine it came from');
            Expect::same('testing', $described['app_env'], 'and the environment');
        },

    'a backup restores into a fresh database row for row' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $freshTarget, $counts): void {
            [$app, $sent] = $boot($db);
            $overturned($app, $sent);
            $made = $app->backupService()->create();

            $target = $freshTarget();
            $before = $counts($target);
            foreach ($before as $table => $n) {
                if ($table !== 'sa_migrations') {
                    Expect::same(0, $n, $table . ' should start empty');
                }
            }

            $done = $app->backupService()->restore($target, $made['path']);
            Expect::same($made['rows'], $done['rows'], 'every row went in');

            // Writing the backup and restoring it each record an audit row in
            // the SOURCE, after the rows were read, so the audit trail is the
            // one table that is legitimately two rows ahead of the copy.
            $source = $counts($db);
            $restored = $counts($target);
            foreach ($source as $table => $n) {
                if ($table === 'sa_audit_events') {
                    Expect::same($n - 2, $restored[$table] ?? -1, 'the audit trail is behind by exactly the create and the restore');
                    continue;
                }
                Expect::same($n, $restored[$table] ?? -1, $table . ' should hold the same number of rows');
            }

            // A row's content survives: the executed document hash, which the
            // Desk re-verifies on every read, is byte for byte the same.
            $hashes = static fn (Database $d): array => array_map(
                static fn (array $r): string => (string) $r['executed_sha256'],
                $d->all('SELECT executed_sha256 FROM sa_documents WHERE executed_sha256 IS NOT NULL ORDER BY public_ref')
            );
            Expect::true(count($hashes($db)) >= 3, 'the walk executed at least three documents');
            Expect::same($hashes($db), $hashes($target), 'the executed hashes are identical after the restore');

            // And the restored database works as a database: foreign keys hold.
            Expect::throws(
                Throwable::class,
                static fn () => $target->insert('sa_contacts', [
                    'id' => 'x', 'organization_id' => 'no-such-org', 'name' => 'n', 'work_email' => 'e@example.org',
                    'active' => 1, 'created_at' => '2026-01-01 00:00:00',
                ]),
                'foreign keys are back on after the restore'
            );
        },

    'restore refuses a target that holds rows, a tampered file, and a file with no hash' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $freshTarget): void {
            [$app, $sent] = $boot($db);
            $overturned($app, $sent);
            $made = $app->backupService()->create();

            Expect::throws(
                RuntimeException::class,
                static fn () => $app->backupService()->restore($db, $made['path']),
                'the live database holds rows and must be refused'
            );

            $tampered = $made['path'] . '.tampered.json.gz';
            copy($made['path'], $tampered);
            copy($made['path'] . '.sha256', $tampered . '.sha256');
            file_put_contents($tampered, 'x', FILE_APPEND);
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->backupService()->restore($freshTarget(), $tampered),
                'a byte changed is a refusal'
            );
            Expect::false($app->backupService()->verify($tampered)['ok'], 'and verify says the same');

            @unlink($tampered . '.sha256');
            Expect::throws(
                RuntimeException::class,
                static fn () => $app->backupService()->restore($freshTarget(), $tampered),
                'no hash beside it is a refusal'
            );
            @unlink($tampered);
        },

    'an old backup fails verification, and pruning keeps the newest and never fewer than the floor' =>
        static function (Bootstrap $app, Database $db) use ($boot): void {
            [$app] = $boot($db);
            $backups = $app->backupService();

            // Nine backups, "written" on nine different days by moving the clock.
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            for ($i = 20; $i >= 12; $i--) {
                $app->useClock(new Clock('America/New_York', $now->modify('-' . $i . ' days')));
                $made = $app->backupService()->create();
                touch($made['path'], $now->modify('-' . $i . ' days')->getTimestamp());
            }
            $app->useClock(new Clock('America/New_York', $now));
            $backups = $app->backupService();

            Expect::same(9, count($backups->all()), 'nine files');
            $check = $backups->verify();
            Expect::false($check['ok'], 'the newest is twelve days old and fails');
            Expect::true(str_contains($check['reason'], 'older than'), 'for its age');

            $removed = $backups->prune();
            Expect::same(2, $removed, 'all nine are past 14 days but the floor keeps seven');
            Expect::same(7, count($backups->all()), 'seven remain');
            $names = array_map(static fn (array $f): string => $f['name'], $backups->all());
            Expect::true(str_contains($names[0], $now->modify('-12 days')->format('Ymd')), 'the newest survived');

            $fresh = $backups->create();
            Expect::true($backups->verify()['ok'], 'a fresh one verifies');
            Expect::same(basename($fresh['path']), $backups->latest()['name'], 'and is the latest');
            Expect::same(BackupService::KEEP_AT_LEAST, 7, 'the floor is seven');
        },
];
