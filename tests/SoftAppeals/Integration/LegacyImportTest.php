<?php
declare(strict_types=1);

/**
 * The lead importer. Section 21.2, as tests.
 *
 *   a read-only importer for legacy leads
 *   an original-payload hash on every record
 *   a dry run showing counts, duplicates and invalid records
 *   import only after the dry run
 *   reconcile imported count to source count
 *   no migration deletes the original lead files
 *
 * The fixture is a real directory with real files in the exact shape
 * sa-lead.php writes: the same header lines, the same "Label: value" body, the
 * same tab-separated log, the same filename stamp. A fixture that is merely
 * plausible would prove nothing, because the whole risk here is a parser that
 * agrees with an imagined format.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\IntakeForms;
use SoftAppeals\Domain\IntakeStatus;
use SoftAppeals\Services\LegacyLeadImporter;

/** The archive filename sa-lead.php builds: Ymd-His then eight hex of sha256(email . source). */
$stamp = static function (string $whenUtc, string $source, string $email): string {
    $when = new DateTimeImmutable($whenUtc, new DateTimeZone('UTC'));
    return $when->format('Ymd-His') . '-' . substr(hash('sha256', $email . $source), 0, 8);
};

/**
 * Build a throwaway fs-metrics directory and return its path.
 *
 * @return string
 */
$fixture = static function () use ($stamp): string {
    $root = sys_get_temp_dir() . '/sa-leads-fixture-' . bin2hex(random_bytes(4));
    mkdir($root . '/sa-leads', 0755, true);

    // A full submission of the long form, with a pasted paragraph in it so the
    // continuation-line handling is exercised rather than assumed.
    $one = "Form:  Denial review request\nWhen:  2026-08-15 14:32 UTC\n\n"
        . "Organization: Fictional Behavioral Health LLC\n"
        . "Your name: A Person\n"
        . "Work email: a.person@example.org\n"
        . "Your role: Administrator or practice manager\n"
        . "Organization type: Behavioral health\n"
        . "State: Maryland\n"
        . "Denied claims unresolved: 51 to 100\n"
        . "Value involved: \$25,001 to \$50,000\n"
        . "Time-sensitive: Yes, some have approaching deadlines\n"
        . "Anything else: We changed billing companies in March.\n"
        . "\n"
        . "Half of these are from before the change.\n";
    file_put_contents(
        $root . '/sa-leads/' . $stamp('2026-08-15 14:32:01', 'soft-appeals-start', 'a.person@example.org') . '.txt',
        $one
    );

    // A Maryland form, which asks a different set of questions.
    $two = "Form:  Maryland denial review request\nWhen:  2026-08-16 09:05 UTC\n\n"
        . "Practice or organization: Fictional Dental Partners\n"
        . "Your name: Another Person\n"
        . "Work email: another@example.org\n"
        . "Your role: Practice owner or provider\n"
        . "State: Maryland\n"
        . "Practice type: Dental\n"
        . "Clinicians: 2 to 5\n"
        . "Carelon interest check: No\n";
    file_put_contents(
        $root . '/sa-leads/' . $stamp('2026-08-16 09:05:44', 'soft-appeals-maryland', 'another@example.org') . '.txt',
        $two
    );

    // A file that is not a lead at all.
    file_put_contents($root . '/sa-leads/20260101-000000-deadbeef.txt', "Some other note.\n");

    // The log. Three lines: two matching the archive files above, one whose
    // archive file was pruned years ago, and one malformed line.
    $log = "2026-08-15 14:32\tsoft-appeals-start\tA Person\ta.person@example.org\tFictional Behavioral Health LLC\n"
        . "2026-08-16 09:05\tsoft-appeals-maryland\tAnother Person\tanother@example.org\tFictional Dental Partners\n"
        . "2025-11-02 18:10\tsoft-appeals-contact\tAn Older Person\tolder@example.org\tFictional Therapy Group\n"
        . "this line is not five columns\n";
    file_put_contents($root . '/sa-leads.log', $log);

    return $root;
};

$remove = static function (string $root): void {
    foreach ((array) glob($root . '/sa-leads/*') as $file) {
        @unlink((string) $file);
    }
    @rmdir($root . '/sa-leads');
    @unlink($root . '/sa-leads.log');
    @rmdir($root);
};

return [

    'the dry run counts everything and writes nothing' =>
        static function (Bootstrap $app, Database $db) use ($fixture, $remove): void {
            $root = $fixture();
            try {
                $report = $app->importer($root)->inspect();

                Expect::same(3, $report['archive_files'], 'three files sit in the archive');
                Expect::same(2, $report['archive_read'], 'two of them are leads');
                Expect::same(1, count($report['archive_unreadable']), 'the third is not, and is named');

                Expect::same(4, $report['log_lines'], 'four lines in the log');
                Expect::same(1, $report['log_malformed'], 'one of them is not five columns');
                Expect::same(
                    1,
                    $report['log_unmatched'],
                    'one lead has only a log line, its archive file long since pruned'
                );

                Expect::same(3, $report['new'], 'two full leads and one recovered from the log');
                Expect::same(0, $report['duplicates'], 'nothing has been imported yet');

                Expect::same(
                    0,
                    (int) $db->value('SELECT COUNT(*) FROM sa_intakes'),
                    'looking is not importing'
                );
            } finally {
                $remove($root);
            }
        },

    'the import lands every usable lead, and reconciles' =>
        static function (Bootstrap $app, Database $db) use ($fixture, $remove): void {
            $root = $fixture();
            try {
                $report = $app->importer($root)->import();

                Expect::same(3, $report['created'], 'three inquiries created');
                Expect::same([], $report['failed'], 'nothing failed');
                Expect::true($report['reconciled'], 'source and database agree');
                Expect::same(
                    3,
                    (int) $db->value('SELECT COUNT(*) FROM sa_intakes'),
                    'and the table says the same'
                );
                Expect::same(
                    3,
                    (int) $db->value('SELECT COUNT(*) FROM sa_intakes WHERE legacy_record_path IS NOT NULL'),
                    'every one of them names the file it came from'
                );
                Expect::same(
                    3,
                    (int) $db->value("SELECT COUNT(*) FROM sa_intakes WHERE status = 'received'"),
                    'they all arrive waiting for a fit review'
                );
            } finally {
                $remove($root);
            }
        },

    'the long form is read back with its paragraph intact' =>
        static function (Bootstrap $app, Database $db) use ($fixture, $remove): void {
            $root = $fixture();
            try {
                $app->importer($root)->import();

                $row = $db->one(
                    "SELECT * FROM sa_intakes WHERE contact_email = 'a.person@example.org'"
                );
                Expect::notNull($row, 'the long form landed');
                Expect::same('soft-appeals-start', (string) $row['source'], 'matched back to its own form');
                Expect::same('MD', (string) $row['state'], 'Maryland as a code');
                Expect::same('51 to 100', (string) $row['denial_volume_band'], 'the band it actually gave');
                Expect::same(1, (int) $row['time_sensitive'], 'the deadline flag survived');
                Expect::same(
                    '2026-08-15 14:32:00',
                    (string) $row['submitted_at'],
                    'the date it was actually sent, not the date it was imported'
                );

                $answers = $app->intakes()->answers($row);
                Expect::true(
                    str_contains($answers['Anything else'] ?? '', "\n"),
                    'the pasted paragraph kept its line break instead of being flattened'
                );
                Expect::true(
                    str_contains($answers['Anything else'] ?? '', 'before the change'),
                    'and its second half is still there'
                );
            } finally {
                $remove($root);
            }
        },

    'the Maryland form is read back with its own questions' =>
        static function (Bootstrap $app, Database $db) use ($fixture, $remove): void {
            $root = $fixture();
            try {
                $app->importer($root)->import();

                $row = $db->one("SELECT * FROM sa_intakes WHERE contact_email = 'another@example.org'");
                Expect::same('soft-appeals-maryland', (string) $row['source'], 'the Maryland form');
                Expect::same('Dental', (string) $row['organization_type'], 'practice type is the type');
                Expect::null(
                    $row['denial_volume_band'],
                    'that form never asks a denial volume, so the column stays empty'
                );

                $answers = $app->intakes()->answers($row);
                Expect::same('2 to 5', $answers['Clinicians'] ?? null, 'the clinician count is kept as an answer');
            } finally {
                $remove($root);
            }
        },

    'a lead that survives only as a log line says so' =>
        static function (Bootstrap $app, Database $db) use ($fixture, $remove): void {
            $root = $fixture();
            try {
                $app->importer($root)->import();

                $row = $db->one("SELECT * FROM sa_intakes WHERE contact_email = 'older@example.org'");
                Expect::notNull($row, 'the older lead is not lost');
                Expect::same(
                    IntakeForms::SOURCE_LEGACY_LOG,
                    (string) $row['source'],
                    'it is marked as what it is, not as the form it once came through'
                );
                Expect::true(
                    str_contains((string) $row['legacy_record_path'], 'sa-leads.log#'),
                    'and it names the line it came from'
                );
                Expect::same(
                    'Fictional Therapy Group',
                    (string) $row['organization_name'],
                    'the three things the line does hold are kept'
                );
            } finally {
                $remove($root);
            }
        },

    'running the import twice imports nothing twice' =>
        static function (Bootstrap $app, Database $db) use ($fixture, $remove): void {
            $root = $fixture();
            try {
                $app->importer($root)->import();
                $second = $app->importer($root)->import();

                Expect::same(0, $second['created'], 'the second run creates nothing');
                Expect::same(3, $second['duplicates'], 'it recognises all three by their hash');
                Expect::same(
                    3,
                    (int) $db->value('SELECT COUNT(*) FROM sa_intakes'),
                    'still three rows'
                );
            } finally {
                $remove($root);
            }
        },

    'the original files are never touched' =>
        static function (Bootstrap $app, Database $db) use ($fixture, $remove): void {
            $root = $fixture();
            try {
                $before = [];
                foreach ((array) glob($root . '/sa-leads/*.txt') as $file) {
                    $before[(string) $file] = hash_file('sha256', (string) $file);
                }
                $before[$root . '/sa-leads.log'] = hash_file('sha256', $root . '/sa-leads.log');

                $app->importer($root)->import();

                foreach ($before as $file => $digest) {
                    Expect::true(is_file((string) $file), 'the importer must not remove ' . basename((string) $file));
                    Expect::same(
                        $digest,
                        hash_file('sha256', (string) $file),
                        'the importer must not rewrite ' . basename((string) $file)
                    );
                }
            } finally {
                $remove($root);
            }
        },

    'the two lead folders are named, and a request cannot name a third' =>
        static function (Bootstrap $app, Database $db): void {
            $root = sys_get_temp_dir() . '/sa-roots-' . bin2hex(random_bytes(4));
            // A site with its own lead folder, sitting inside a parent that has
            // one too. That is exactly the shape staging has: Hostinger put it
            // inside the live site, so the live site's leads are one level up.
            mkdir($root . '/live/staging/fs-metrics', 0755, true);
            mkdir($root . '/live/fs-metrics', 0755, true);

            try {
                $sources = LegacyLeadImporter::sources($root . '/live/staging');
                Expect::same(2, count($sources), 'both folders are offered');
                Expect::same(
                    $root . '/live/staging/fs-metrics',
                    $sources['self']['path'],
                    'its own'
                );
                Expect::same(
                    $root . '/live/fs-metrics',
                    $sources['parent']['path'],
                    'and the live site one directory up'
                );

                Expect::null(
                    LegacyLeadImporter::pathForSource('../../etc', $root . '/live/staging'),
                    'a key nobody offered resolves to nothing, so a POST cannot pick a folder'
                );
                Expect::null(
                    LegacyLeadImporter::pathForSource('/var/log', $root . '/live/staging'),
                    'and neither can an absolute path'
                );

                // The live site has no fs-metrics above it, so the choice
                // disappears on its own rather than offering a folder that is
                // not there.
                $onLive = LegacyLeadImporter::sources($root . '/live');
                Expect::same(1, count($onLive), 'production sees only its own folder');
            } finally {
                foreach ([
                    $root . '/live/staging/fs-metrics',
                    $root . '/live/staging',
                    $root . '/live/fs-metrics',
                    $root . '/live',
                    $root,
                ] as $directory) {
                    @rmdir($directory);
                }
            }
        },

    'a missing fs-metrics folder is an empty report, not a crash' =>
        static function (Bootstrap $app, Database $db): void {
            $report = $app->importer(sys_get_temp_dir() . '/definitely-not-there-' . bin2hex(random_bytes(4)))
                ->inspect();

            Expect::false($report['available'], 'it says the folder is not there');
            Expect::same(0, $report['new'], 'and nothing is waiting');
            Expect::false($report['notes'] === [], 'and it explains why rather than showing a blank screen');
        },

    'an imported lead can be reviewed like any other' =>
        static function (Bootstrap $app, Database $db) use ($fixture, $remove): void {
            $root = $fixture();
            try {
                $app->importer($root)->import();
                $row = $db->one("SELECT * FROM sa_intakes WHERE contact_email = 'a.person@example.org'");

                $result = $app->intakeService()->review(
                    (string) $row['id'],
                    \SoftAppeals\Domain\FitDecision::ACCEPT,
                    'Imported, still a fit.',
                    null
                );

                Expect::same(IntakeStatus::ACCEPTED, $result['status'], 'an old lead accepts like a new one');
                Expect::notNull($result['engagement_id'], 'and opens an engagement');
            } finally {
                $remove($root);
            }
        },
];
