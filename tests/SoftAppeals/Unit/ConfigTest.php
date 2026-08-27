<?php
declare(strict_types=1);

/**
 * The configuration loader.
 *
 * The first case here is the one that matters. The loader used to walk its own
 * DEFAULTS table and copy only the keys it found there, which silently threw
 * away every value that has no default. The three secrets have no default on
 * purpose, so that a missing one fails loudly rather than falling back to
 * something predictable, and they were exactly the set being dropped.
 *
 * The symptom was a config file that was found, a database setting that
 * arrived, and secrets that vanished between the file and the application. It
 * cost an afternoon on staging, and nothing in the suite would have caught it,
 * because nothing tested a real config file end to end.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Config;

/** Write a temporary config file and return its path. */
$writeConfig = static function (array $values): string {
    $path = sys_get_temp_dir() . '/sa-cfg-' . bin2hex(random_bytes(5)) . '.php';
    file_put_contents($path, '<?php return ' . var_export($values, true) . ";\n");
    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });
    return $path;
};

$goodSecrets = [
    'SA_SESSION_SECRET' => str_repeat('session-', 8),
    'SA_TOKEN_SECRET'   => str_repeat('token-', 10),
    'SA_IP_HMAC_SECRET' => str_repeat('iphmac-', 9),
];

return [

    'REGRESSION the secrets in a config file actually reach the application' =>
        static function (Bootstrap $app) use ($writeConfig, $goodSecrets): void {
            $config = Config::load($writeConfig($goodSecrets + [
                'SA_APP_ENV' => 'staging',
                'SA_DB_DSN'  => 'sqlite::memory:',
            ]));

            // The bug: these were dropped because they have no default.
            foreach (array_keys($goodSecrets) as $key) {
                Expect::same(
                    $goodSecrets[$key],
                    $config->string($key),
                    $key . ' must survive the load'
                );
            }
            $config->assertSecretsPresent();
            Expect::true($config->isConfigured(), 'a complete config file must read as configured');
        },

    'readiness names exactly what is missing' =>
        static function (Bootstrap $app) use ($writeConfig, $goodSecrets): void {
            // No file at all.
            $none = Config::load(sys_get_temp_dir() . '/sa-does-not-exist-' . bin2hex(random_bytes(4)) . '.php');
            $r = $none->readiness();
            Expect::false($r['file'], 'a missing file is reported as missing');
            Expect::false($r['ready'], 'and is not ready');

            // File present, database missing.
            $noDb = Config::load($writeConfig($goodSecrets + ['SA_APP_ENV' => 'staging']));
            $r = $noDb->readiness();
            Expect::true($r['file'], 'the file is found');
            Expect::true($r['secrets'], 'the secrets are found');
            Expect::false($r['database'], 'the database setting is missing');
            Expect::false($r['ready'], 'so it is not ready');

            // File present, one secret too short.
            $short = Config::load($writeConfig([
                'SA_APP_ENV'        => 'staging',
                'SA_DB_DSN'         => 'sqlite::memory:',
                'SA_SESSION_SECRET' => str_repeat('session-', 8),
                'SA_TOKEN_SECRET'   => 'too-short',
                'SA_IP_HMAC_SECRET' => str_repeat('iphmac-', 9),
            ]));
            $r = $short->readiness();
            Expect::false($r['secrets'], 'a short secret counts as missing');
            Expect::true(
                in_array('SA_TOKEN_SECRET', $r['missing'], true),
                'and the short one is named'
            );
        },

    'the redacted view never prints a secret value' =>
        static function (Bootstrap $app) use ($writeConfig, $goodSecrets): void {
            $config = Config::load($writeConfig($goodSecrets + [
                'SA_APP_ENV'     => 'staging',
                'SA_DB_DSN'      => 'sqlite::memory:',
                'SA_DB_PASSWORD' => 'a-real-looking-password',
            ]));
            $printed = json_encode($config->redacted()) ?: '';

            foreach ($goodSecrets as $value) {
                Expect::false(
                    str_contains($printed, $value),
                    'a secret value must never appear in the redacted view'
                );
            }
            Expect::false(
                str_contains($printed, 'a-real-looking-password'),
                'nor a database password'
            );
            Expect::false(
                str_contains($printed, 'sqlite::memory:'),
                'nor the DSN, which carries the host and database name'
            );
        },

    'a key outside the SA prefix is ignored' =>
        static function (Bootstrap $app) use ($writeConfig, $goodSecrets): void {
            $config = Config::load($writeConfig($goodSecrets + [
                'SA_APP_ENV'   => 'staging',
                'SA_DB_DSN'    => 'sqlite::memory:',
                'evil'         => 'nope',
                'sa_lowercase' => 'nope',
            ]));
            Expect::null($config->get('evil'), 'an unprefixed key must not be taken');
            Expect::null($config->get('sa_lowercase'), 'nor a lowercase one');
        },

    'auto-migrate is on off production and off on it' =>
        static function (Bootstrap $app) use ($writeConfig, $goodSecrets): void {
            $staging = Config::load($writeConfig($goodSecrets + [
                'SA_APP_ENV' => 'staging',
                'SA_DB_DSN'  => 'sqlite::memory:',
            ]));
            Expect::true($staging->autoMigrate(), 'staging brings its own schema up');

            $production = Config::load($writeConfig($goodSecrets + [
                'SA_APP_ENV' => 'production',
                'SA_DB_DSN'  => 'sqlite::memory:',
            ]));
            Expect::false($production->autoMigrate(), 'production does not, unless told');

            $told = Config::load($writeConfig($goodSecrets + [
                'SA_APP_ENV'      => 'production',
                'SA_DB_DSN'       => 'sqlite::memory:',
                'SA_AUTO_MIGRATE' => true,
            ]));
            Expect::true($told->autoMigrate(), 'an explicit true wins');
        },
];
