<?php
declare(strict_types=1);

/**
 * The Soft Appeals test runner.
 *
 * No PHPUnit and no Composer, for the same reason there is no framework: this
 * site has never carried a dependency tree and a shared host is a poor place to
 * start one. What is here is about eighty lines and needs no updates.
 *
 * CLI only.
 *
 *   php tests/SoftAppeals/run.php
 *   php tests/SoftAppeals/run.php --dsn=sqlite:/tmp/sa-test.sqlite
 *   php tests/SoftAppeals/run.php --filter=Csrf
 *
 * Every test gets a fresh database: the runner migrates up before each file and
 * all the way down after it, so no test can pass because of a row another test
 * left behind.
 *
 * This runner cannot execute on the machine the code was written on, because
 * that machine has no PHP. It runs on staging. Two checks that CAN run locally
 * live beside it and are the reason Phase 1 is not shipping unverified:
 *
 *   python3 tests/SoftAppeals/schema_check.py   the migrations, on real SQLite
 *   python3 tests/SoftAppeals/static_check.py   PSR-4, secrets, deny-all
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not here.');
}

require_once __DIR__ . '/../../src/SoftAppeals/Bootstrap.php';

use SoftAppeals\Bootstrap;
use SoftAppeals\Database;

final class TestFailure extends RuntimeException
{
}

/**
 * The assertions. Deliberately few: a test that needs a richer vocabulary than
 * this is usually a test that is doing too much.
 */
final class Expect
{
    public static function true(bool $value, string $what): void
    {
        if ($value !== true) {
            throw new TestFailure($what . ' (expected true, got false)');
        }
    }

    public static function false(bool $value, string $what): void
    {
        if ($value !== false) {
            throw new TestFailure($what . ' (expected false, got true)');
        }
    }

    public static function same(mixed $expected, mixed $actual, string $what): void
    {
        if ($expected !== $actual) {
            throw new TestFailure(sprintf(
                '%s (expected %s, got %s)',
                $what,
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    public static function notNull(mixed $value, string $what): void
    {
        if ($value === null) {
            throw new TestFailure($what . ' (expected a value, got null)');
        }
    }

    public static function null(mixed $value, string $what): void
    {
        if ($value !== null) {
            throw new TestFailure($what . ' (expected null, got ' . var_export($value, true) . ')');
        }
    }

    /** @param class-string<\Throwable> $class */
    public static function throws(string $class, callable $work, string $what): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return;
            }
            throw new TestFailure($what . ' (expected ' . $class . ', got ' . $e::class . ')');
        }
        throw new TestFailure($what . ' (expected ' . $class . ', nothing was thrown)');
    }
}

$flags = array_slice($_SERVER['argv'] ?? [], 1);
$option = static function (string $name) use ($flags): ?string {
    foreach ($flags as $flag) {
        if (str_starts_with($flag, '--' . $name . '=')) {
            return substr($flag, strlen($name) + 3);
        }
    }
    return null;
};

$filter = $option('filter');

// A file database rather than :memory:, because the migration runner opens its
// own handle and an in-memory database would not be the same one.
$dsn = $option('dsn') ?? 'sqlite:' . sys_get_temp_dir() . '/sa-test-' . bin2hex(random_bytes(4)) . '.sqlite';
$temporary = $option('dsn') === null;
$path = str_starts_with($dsn, 'sqlite:') ? substr($dsn, 7) : null;

$files = [];
foreach (['Unit', 'Integration', 'Security'] as $group) {
    $found = glob(__DIR__ . '/' . $group . '/*Test.php');
    if ($found !== false) {
        sort($found);
        $files = array_merge($files, $found);
    }
}

if ($filter !== null) {
    $files = array_values(array_filter(
        $files,
        static fn (string $f): bool => stripos(basename($f), $filter) !== false
    ));
}

echo "\n  Soft Appeals tests\n";
echo '  ' . str_repeat('-', 58) . "\n";

$passed = 0;
$failed = 0;
$failures = [];

foreach ($files as $file) {
    /** @var array<string,callable> $tests */
    $tests = require $file;
    if (!is_array($tests)) {
        echo '  SKIP   ' . basename($file) . " (did not return an array of tests)\n";
        continue;
    }

    $group = basename(dirname($file));
    echo "\n  " . $group . ' / ' . basename($file, '.php') . "\n";

    foreach ($tests as $name => $test) {
        // Fresh schema for every single test.
        $db = Database::connect($dsn);
        migrateDown($db);
        migrateUp($db);

        $app = Bootstrap::boot(testConfigFile(), false);
        $app->useDatabase($db);

        try {
            $test($app, $db);
            $passed++;
            echo '    ok   ' . $name . "\n";
        } catch (\Throwable $e) {
            $failed++;
            $label = $group . ' / ' . basename($file, '.php') . ' / ' . $name;
            $failures[] = $label . "\n           " . $e->getMessage()
                . "\n           " . basename($e->getFile()) . ':' . $e->getLine();
            echo '    FAIL ' . $name . "\n";
        }

        Bootstrap::resetInstance();
    }
}

// Leave nothing behind.
$finalDb = Database::connect($dsn);
migrateDown($finalDb);
unset($finalDb);
if ($temporary && $path !== null && is_file($path)) {
    @unlink($path);
    @unlink($path . '-wal');
    @unlink($path . '-shm');
}

echo "\n  " . str_repeat('-', 58) . "\n";
if ($failures !== []) {
    foreach ($failures as $failure) {
        echo '  FAIL   ' . $failure . "\n";
    }
    echo '  ' . str_repeat('-', 58) . "\n";
}
echo sprintf("  %d passed, %d failed\n\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);


/**
 * A config file the tests can boot against, holding fake secrets that are long
 * enough to satisfy the length check and are obviously not real.
 */
function testConfigFile(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $path = sys_get_temp_dir() . '/sa-test-config-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($path, "<?php return " . var_export([
        'SA_APP_ENV'           => 'testing',
        'SA_APP_URL'           => 'https://staging.frimpomaasync.com',
        'SA_BUSINESS_TIMEZONE' => 'America/New_York',
        'SA_SESSION_SECRET'    => str_repeat('test-session-secret-', 3),
        'SA_TOKEN_SECRET'      => str_repeat('test-token-secret-', 3),
        'SA_IP_HMAC_SECRET'    => str_repeat('test-ip-hmac-secret-', 3),
        'SA_DEMO_MODE'         => true,
        'SA_MAIL_ALLOWLIST'    => 'nanafrimpgskc@gmail.com',
    ], true) . ";\n");
    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });
    return $path;
}

function migrationDefinitions(): array
{
    static $definitions = null;
    if ($definitions !== null) {
        return $definitions;
    }
    $files = glob(__DIR__ . '/../../database/migrations/*.php') ?: [];
    sort($files, SORT_STRING);
    $definitions = array_map(static fn (string $f): array => require $f, $files);
    return $definitions;
}

function migrateUp(Database $db): void
{
    foreach (migrationDefinitions() as $migration) {
        ($migration['up'])($db);
    }
}

function migrateDown(Database $db): void
{
    foreach (array_reverse(migrationDefinitions()) as $migration) {
        ($migration['down'])($db);
    }
}
