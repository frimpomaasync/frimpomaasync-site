<?php
declare(strict_types=1);

namespace SoftAppeals;

use RuntimeException;

/**
 * Configuration for the Soft Appeals Recovery Command Center.
 *
 * Values are read from, in order of precedence:
 *
 *   1. the process environment, which is how the cron jobs and the migration
 *      runner are configured
 *   2. storage-private/soft-appeals/config/config.php, which returns an array
 *      and never enters this repository
 *   3. the defaults below, which cover everything that is not a secret
 *
 * Nothing in this file is a secret. The four secrets (database password,
 * session secret, token secret, IP HMAC secret) have no defaults on purpose: a
 * missing one is a hard failure at boot rather than a silent fallback to a
 * predictable value.
 *
 * The same file shape works on staging and in production. The difference is the
 * values, and SA_APP_ENV, which gates the demo banner and the mail allowlist.
 */
final class Config
{
    /** Keys that must never be printed, logged, or rendered. */
    private const SECRET_KEYS = [
        'SA_DB_PASSWORD',
        'SA_SESSION_SECRET',
        'SA_TOKEN_SECRET',
        'SA_IP_HMAC_SECRET',
        'SA_CRON_SECRET',
    ];

    /** Secrets that must be present before the application will serve a request. */
    private const REQUIRED_SECRETS = [
        'SA_SESSION_SECRET',
        'SA_TOKEN_SECRET',
        'SA_IP_HMAC_SECRET',
    ];

    private const MIN_SECRET_LENGTH = 32;

    private const DEFAULTS = [
        'SA_APP_ENV'              => 'production',
        'SA_APP_URL'              => 'https://frimpomaasync.com',
        'SA_BUSINESS_TIMEZONE'    => 'America/New_York',
        'SA_DB_DSN'               => '',
        'SA_DB_USER'              => '',
        'SA_DB_PASSWORD'          => '',
        'SA_MAIL_FROM'            => 'notify@frimpomaasync.com',
        'SA_MAIL_REPLY_TO'        => 'hello@frimpomaasync.com',
        'SA_OWNER_EMAIL'          => 'nanafrimpgskc@gmail.com',
        'SA_PRIVATE_STORAGE_PATH' => '',

        // Every capability past the foundation ships switched off. Phase 1
        // turns none of these on. Section 20 of the plan.
        // Bring the schema up on boot. On staging that means she never runs a
        // command line, which she does not have. In production it stays off:
        // the live database changes when she is watching, not when a visitor
        // happens to load a page.
        'SA_AUTO_MIGRATE' => null,

        // Create the fictional practices on a staging database that has none.
        // Never in production, and never once real rows exist.
        'SA_AUTO_SEED' => null,

        'SA_PORTAL_ENABLED'           => false,
        'SA_CLIENT_LOGIN_ENABLED'     => false,
        'SA_E_SIGN_ENABLED'           => false,
        'SA_RECOVERY_FINANCE_ENABLED' => false,
        'SA_DEADLINE_CRON_ENABLED'    => false,
        'SA_DEMO_MODE'                => true,

        // Staging must never be able to email a real practice. Any recipient
        // outside this list is refused by the mail layer, not merely discouraged.
        // Empty means no restriction, which is only correct in production.
        'SA_MAIL_ALLOWLIST' => '',
    ];

    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * Build the configuration. $configFile is the private config path; when it
     * is null the standard location is used.
     */
    public static function load(?string $configFile = null): self
    {
        $root = dirname(__DIR__, 2);
        $configFile ??= $root . '/storage-private/soft-appeals/config/config.php';

        $fromFile = [];
        if (is_file($configFile)) {
            /** @psalm-suppress UnresolvableInclude */
            $loaded = require $configFile;
            if (!is_array($loaded)) {
                throw new RuntimeException('The Soft Appeals config file must return an array.');
            }
            $fromFile = $loaded;
        }

        $values = self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists($key, $fromFile)) {
                $values[$key] = $fromFile[$key];
            }
            $env = getenv($key);
            if ($env !== false && $env !== '') {
                $values[$key] = is_bool($default) ? self::toBool($env) : $env;
            }
        }

        // The private storage path defaults to the in-repo deny-all directory.
        // ADR-003: the FTPS deploy cannot reach above public_html, and the
        // deny-all pattern is proven to return 403 on this host.
        if ($values['SA_PRIVATE_STORAGE_PATH'] === '') {
            $values['SA_PRIVATE_STORAGE_PATH'] = $root . '/storage-private/soft-appeals';
        }

        return new self($values);
    }

    /**
     * Fail loudly and early if a secret is missing or too short to be one.
     * Called from Bootstrap before any request is served.
     */
    public function assertSecretsPresent(): void
    {
        foreach (self::REQUIRED_SECRETS as $key) {
            $value = (string) ($this->values[$key] ?? '');
            if ($value === '') {
                throw new RuntimeException(
                    'Soft Appeals is not configured: ' . $key . ' is missing. '
                    . 'Write it into the private config file on the server.'
                );
            }
            if (strlen($value) < self::MIN_SECRET_LENGTH) {
                throw new RuntimeException(
                    'Soft Appeals is not configured: ' . $key . ' is shorter than '
                    . self::MIN_SECRET_LENGTH . ' characters.'
                );
            }
        }
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        return $this->values[$key] ?? $fallback;
    }

    public function string(string $key): string
    {
        return (string) ($this->values[$key] ?? '');
    }

    public function bool(string $key): bool
    {
        return self::toBool($this->values[$key] ?? false);
    }

    public function isProduction(): bool
    {
        return $this->string('SA_APP_ENV') === 'production';
    }

    public function isStaging(): bool
    {
        return $this->string('SA_APP_ENV') === 'staging';
    }

    /**
     * Whether the application may bring its own schema up.
     *
     * Unset means "anywhere but production", which is the behaviour that keeps
     * staging current with no ceremony and leaves the live database alone. An
     * explicit true or false in the config wins either way.
     */
    public function autoMigrate(): bool
    {
        $value = $this->values['SA_AUTO_MIGRATE'] ?? null;
        if ($value === null || $value === '') {
            return !$this->isProduction();
        }
        return self::toBool($value);
    }

    /** Same rule, for the fictional practices. */
    public function autoSeed(): bool
    {
        $value = $this->values['SA_AUTO_SEED'] ?? null;
        if ($value === null || $value === '') {
            return !$this->isProduction() && $this->bool('SA_DEMO_MODE');
        }
        return self::toBool($value);
    }

    /**
     * True when the private config file is present and complete enough to serve.
     *
     * A freshly deployed site has no config: the file is written on the server
     * and never committed. That is an ordinary state, not an error, and the
     * pages say so plainly rather than answering with a 500 that looks like
     * something broke.
     */
    public function isConfigured(): bool
    {
        try {
            $this->assertSecretsPresent();
        } catch (RuntimeException) {
            return false;
        }
        return $this->hasDatabase();
    }

    /** True when a database has actually been configured. */
    public function hasDatabase(): bool
    {
        return $this->string('SA_DB_DSN') !== '';
    }

    public function privateStoragePath(string ...$parts): string
    {
        $base = rtrim($this->string('SA_PRIVATE_STORAGE_PATH'), '/');
        return $parts === [] ? $base : $base . '/' . implode('/', $parts);
    }

    /**
     * The recipients this environment is allowed to email.
     *
     * @return list<string> lowercase addresses, empty when unrestricted
     */
    public function mailAllowlist(): array
    {
        $raw = trim($this->string('SA_MAIL_ALLOWLIST'));
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $one) {
            $one = strtolower(trim($one));
            if ($one !== '') {
                $out[] = $one;
            }
        }
        return $out;
    }

    /**
     * A view of the configuration safe to render on a diagnostics screen.
     * Every secret is replaced with its presence, never its value.
     *
     * @return array<string,scalar>
     */
    public function redacted(): array
    {
        $out = [];
        foreach ($this->values as $key => $value) {
            if (in_array($key, self::SECRET_KEYS, true)) {
                $out[$key] = ((string) $value === '') ? 'not set' : 'set';
                continue;
            }
            if ($key === 'SA_DB_DSN' && (string) $value !== '') {
                // A DSN carries the host and database name but never a password.
                // It is still not something to print in full on a web page.
                $out[$key] = 'set';
                continue;
            }
            $out[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }
        ksort($out);
        return $out;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
