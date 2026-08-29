<?php
declare(strict_types=1);

/**
 * The scheduled jobs. Section 17.2 and section 22, Phase 8.
 *
 * CLI only. The folder carries a deny-all .htaccess and this file refuses any
 * request that is not a command line, so there is no URL that runs a job.
 * SA_CRON_SECRET is reserved for a web trigger that does not exist; the host
 * runs PHP from its own cron screen, and that is the one door.
 *
 *   /usr/bin/php /home/<account>/public_html/cron/soft-appeals-jobs.php run
 *
 * runs every job in order, once. Daily is enough; every job is safe to run
 * again and a second run in the same day mostly finds its work done.
 *
 *   php cron/soft-appeals-jobs.php run                every job
 *   php cron/soft-appeals-jobs.php run <job>          one job by key
 *   php cron/soft-appeals-jobs.php status             each job's last run
 *   php cron/soft-appeals-jobs.php backup             write a backup now
 *   php cron/soft-appeals-jobs.php verify             verify the newest backup
 *   php cron/soft-appeals-jobs.php restore <file> --dsn=<target> [--user=..] [--password=..]
 *                                                     put a backup into an EMPTY, migrated database
 *   php cron/soft-appeals-jobs.php digest             print today's digest, send nothing
 *
 * The schedule is gated by SA_DEADLINE_CRON_ENABLED (section 25 says enable
 * cron last). While it is off, `run` prints why and exits 3, and the Desk's
 * own "Run the jobs now" button is the way to run them. `backup`, `verify`,
 * `restore` and `digest` are not scheduled work and are not gated.
 *
 * Exit codes: 0 every job ran clean · 1 a job failed · 2 bad usage ·
 * 3 the schedule is switched off · 4 the schema is behind.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not here.');
}

require_once __DIR__ . '/../src/SoftAppeals/Bootstrap.php';

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Repositories\JobRepository;

$argv = $_SERVER['argv'] ?? [];
$command = (string) ($argv[1] ?? 'status');
$rest = array_slice($argv, 2);
$positional = array_values(array_filter($rest, static fn (string $a): bool => !str_starts_with($a, '--')));
$option = static function (string $name) use ($rest): ?string {
    foreach ($rest as $flag) {
        if (str_starts_with($flag, '--' . $name . '=')) {
            return substr($flag, strlen($name) + 3);
        }
    }
    return null;
};

$say = static function (string $line = ''): void {
    fwrite(STDOUT, $line . "\n");
};
$complain = static function (string $line): void {
    fwrite(STDERR, $line . "\n");
};

// No error handler: on the command line an exception should print as text
// and set the exit code, not render an HTML page into a cron email.
$app = Bootstrap::boot(null, false);

try {
    if (!$app->config()->isConfigured()) {
        $complain('Soft Appeals is not configured on this installation. The private config file is missing or incomplete.');
        exit(2);
    }
    $app->requireSecrets();

    switch ($command) {
        case 'run':
            // The schema is brought up the way a page brings it up, which
            // is off on production. A behind schema on production is a
            // decision for the Desk, not a cron job.
            $app->prepareDatabase();
            if ($app->schema()->hasPending()) {
                $complain('The schema is behind the migration files. Open the Desk once to bring it up, then run again.');
                exit(4);
            }

            $jobs = $app->jobService();
            if (!$app->config()->cronEnabled()) {
                $complain('The scheduled jobs are switched off here: SA_DEADLINE_CRON_ENABLED. Section 25 enables cron last.');
                $complain('Run them from the Desk (Automation, "Run the jobs now") until then.');
                exit(3);
            }

            $only = $positional[0] ?? null;
            $results = $only === null
                ? $jobs->runAll(JobRepository::TRIGGER_CRON)
                : [$jobs->run($only, JobRepository::TRIGGER_CRON)];

            $failed = 0;
            foreach ($results as $result) {
                $say(sprintf('  %-8s %-20s %3d  %s', $result['outcome'], $result['job'], $result['items'], $result['summary']));
                if ($result['outcome'] === JobRepository::OUTCOME_FAILED) {
                    $failed++;
                }
            }
            exit($failed === 0 ? 0 : 1);

        case 'status':
            $app->prepareDatabase();
            $health = $app->jobService()->health();
            $say('');
            $say('  Soft Appeals jobs  ·  ' . $app->config()->string('SA_APP_ENV')
                . '  ·  schedule ' . ($app->config()->cronEnabled() ? 'ON' : 'OFF'));
            $say('  ' . str_repeat('-', 66));
            foreach ($health['jobs'] as $key => $job) {
                $last = $job['last'];
                $say(sprintf(
                    '  %-20s %-8s %s',
                    $key,
                    $last === null ? 'never' : (string) $last['outcome'],
                    $last === null ? '' : (string) $last['finished_at'] . '  ' . (string) ($last['summary'] ?? '')
                ));
            }
            $say('  ' . str_repeat('-', 66));
            $say('  ' . $health['failures_7d'] . ' failure(s) in the last 7 days.');
            $say('  cron line: ' . $app->jobService()->cronCommand());
            $say('');
            exit(0);

        case 'backup':
            $app->prepareDatabase();
            $made = $app->backupService()->create();
            $say('  wrote ' . basename($made['path']) . ': ' . $made['rows'] . ' rows, ' . $made['tables'] . ' tables, ' . $made['bytes'] . ' bytes');
            $say('  sha256 ' . $made['sha256']);
            exit(0);

        case 'verify':
            $check = $app->backupService()->verify($positional[0] ?? null);
            $say('  ' . ($check['ok'] ? 'ok' : 'FAILED') . '  ' . $check['reason']
                . ($check['path'] === null ? '' : '  ' . basename($check['path']))
                . ($check['age_hours'] === null ? '' : '  ' . $check['age_hours'] . 'h old')
                . ($check['rows'] === null ? '' : '  ' . $check['rows'] . ' rows'));
            exit($check['ok'] ? 0 : 1);

        case 'restore':
            $file = $positional[0] ?? '';
            $dsn = $option('dsn');
            if ($file === '' || $dsn === null) {
                $complain('Use: restore <backup-file> --dsn=<target dsn> [--user=..] [--password=..]');
                $complain('The target must be a migrated, EMPTY database. The live database is never the default.');
                exit(2);
            }
            $target = Database::connect($dsn, $option('user') ?? '', $option('password') ?? '');
            $done = $app->backupService()->restore($target, $file);
            $say('  restored ' . $done['rows'] . ' rows into ' . $done['tables'] . ' tables');
            exit(0);

        case 'digest':
            $app->prepareDatabase();
            $say($app->digestService()->text());
            exit(0);

        default:
            $complain('Unknown command: ' . $command);
            $complain('Use: run [job] | status | backup | verify [file] | restore <file> --dsn=.. | digest');
            exit(2);
    }
} catch (\Throwable $e) {
    $complain('');
    $complain('  FAILED  ' . $e->getMessage());
    $complain('          ' . basename($e->getFile()) . ':' . $e->getLine());
    $complain('');
    exit(1);
}
