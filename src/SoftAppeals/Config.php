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
        'SA_INTAKE_MAILBOX_PASS',
    ];

    /** Secrets that must be present before the application will serve a request. */
    private const REQUIRED_SECRETS = [
        'SA_SESSION_SECRET',
        'SA_TOKEN_SECRET',
        'SA_IP_HMAC_SECRET',
    ];

    private const MIN_SECRET_LENGTH = 32;

    /**
     * The six things section 14.5 says have to be true before production
     * signing may be switched on. While this list has anything in it,
     * eSignEnabled() answers false on production whatever the config file says.
     *
     * Written out rather than kept as a boolean, so the screen that says
     * signing is off can also say what is standing in the way.
     */
    private const PRODUCTION_SIGNING_BLOCKERS = [
        'the BAA text has not been approved',
        'the review-authorization text has not been approved',
        'the recovery-agreement and approved-scope text has not been approved',
        'the legal entity and trade-name language are not confirmed',
        'the signature consent and evidence requirements have not been reviewed',
        'the retention requirements have not been confirmed',
    ];

    private const DEFAULTS = [
        'SA_APP_ENV'              => 'production',
        'SA_APP_URL'              => 'https://frimpomaasync.com',
        'SA_BUSINESS_TIMEZONE'    => 'America/New_York',
        'SA_DB_DSN'               => '',
        'SA_DB_USER'              => '',
        'SA_DB_PASSWORD'          => '',
        'SA_MAIL_FROM'            => 'notify@frimpomaasync.com',
        // The name on the From line, the way a person's mail shows a person.
        'SA_MAIL_FROM_NAME'       => 'Nana Frimpongmaa from Soft Appeals',
        // Soft Appeals has its own inbox. The client pages tell a practice to
        // write to it, so replies to the terms email, the confirmation and the
        // sign-in code have to land in the same place. A page pointing one way
        // and a Reply-To pointing another is how a practice's question goes to
        // an address nobody is watching for it.
        'SA_MAIL_REPLY_TO'        => 'softappeals@frimpomaasync.com',
        'SA_OWNER_EMAIL'          => 'nanafrimpgskc@gmail.com',
        'SA_PRIVATE_STORAGE_PATH' => '',

        // The two parties named on the face of every generated agreement.
        //
        // The legal entity is deliberately blank. Section 14.5 lists "legal
        // entity and trade-name language are confirmed" as one of the six
        // blockers on production signing, and a default here would be an
        // invented company name sitting inside a document somebody could sign.
        // Blank means the generator refuses on production and names the field.
        // Off production it falls back to a placeholder that says out loud what
        // it is, so the workflow can be walked without inventing anything.
        'SA_LEGAL_ENTITY' => '',
        'SA_TRADE_NAME'   => 'Soft Appeals',

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

        // The client side of the application: the preferences page, the
        // passwordless sign-in, and the Recovery Room. Unset means "anywhere
        // but production", the same rule the two above use, so staging can be
        // walked end to end while the live site stays shut until she says
        // otherwise. Section 20 asks for false in production and that is what
        // an unset value produces there.
        'SA_PORTAL_ENABLED'           => null,
        'SA_CLIENT_LOGIN_ENABLED'     => null,

        // Signing follows the same unset rule, and then production is clamped
        // shut on top of it whatever the file says. See eSignEnabled().
        'SA_E_SIGN_ENABLED'           => null,

        // Recovery finance follows the same unset rule as the portal flags:
        // on anywhere but production, so Phase 7 can be walked and tested on
        // staging, and shut on production until section 25 step 10 is done
        // ("enable recovery finance after reconciliation tests") and she sets
        // it to true by hand. See recoveryFinanceEnabled().
        'SA_RECOVERY_FINANCE_ENABLED' => null,

        // The scheduled jobs: reminders, deadline surfacing, the digest, the
        // backup. Same unset rule again, so staging can run them and prove
        // them while production stays quiet until section 25 step 11,
        // "enable cron last". The Desk's own "Run the jobs now" button is
        // her explicit act and does not read this flag. See cronEnabled().
        'SA_DEADLINE_CRON_ENABLED'    => null,
        'SA_DEMO_MODE'                => true,

        // Staging must never be able to email a real practice. Any recipient
        // outside this list is refused by the mail layer, not merely discouraged.
        // Empty means no restriction, which is only correct in production.
        'SA_MAIL_ALLOWLIST' => '',

        // The hour of her day the morning digest goes out. Section 17.3.
        'SA_DIGEST_HOUR' => '6',

        // The forwarded-email intake. An address a practice manager can
        // forward a denial letter or a voice note to, read on the job
        // schedule by intake.mailbox, each message becoming an inquiry row.
        // The username and password are written into the private config on
        // the server once she creates the mailbox in hPanel; until both are
        // there the job reports "no mailbox credentials" and touches nothing.
        // The flag follows the portal rule: unset is on anywhere but
        // production, and production waits for an explicit true.
        'SA_INTAKE_MAILBOX_ENABLED' => null,
        // The public alias a real inquiry must actually name in its delivery
        // or visible-recipient headers. The mailbox itself is shared with the
        // sending account, so this is the boundary between intake and ordinary
        // account notices, replies and bounces.
        'SA_INTAKE_ADDRESS'         => 'start@frimpomaasync.com',
        'SA_INTAKE_MAILBOX_HOST'    => 'imap.hostinger.com',
        'SA_INTAKE_MAILBOX_PORT'    => '993',
        'SA_INTAKE_MAILBOX_USER'    => '',
    ];

    /** @var array<string,mixed> */
    private array $values;

    /** Whether the private config file was found on disk at boot. */
    private bool $configFileFound;

    /** The absolute path that was looked at. Shown on a non-production site. */
    private string $configFilePath;

    /** @param array<string,mixed> $values */
    private function __construct(array $values, bool $configFileFound = false, string $configFilePath = '')
    {
        $this->values = $values;
        $this->configFileFound = $configFileFound;
        $this->configFilePath = $configFilePath;
    }

    /**
     * Where the configuration was looked for.
     *
     * Returned only so a NON-PRODUCTION site can print it. A path that is right
     * in the repository and wrong on the server is invisible from outside, and
     * an afternoon was lost to exactly that: the file was saved, in a folder
     * that looked correct, and the application was reading a different one.
     */
    public function configFilePath(): string
    {
        return $this->configFilePath;
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
        $found = is_file($configFile);
        if ($found) {
            /** @psalm-suppress UnresolvableInclude */
            $loaded = require $configFile;
            if (!is_array($loaded)) {
                throw new RuntimeException('The Soft Appeals config file must return an array.');
            }
            $fromFile = $loaded;
        }

        // Start from the defaults, then overlay everything the file supplied.
        //
        // This used to walk DEFAULTS and copy only the keys it found there,
        // which quietly threw away every value that has no default. The three
        // secrets have no default ON PURPOSE, so a missing one fails loudly
        // instead of falling back to something predictable, and that is exactly
        // the set the loop was dropping. The config file was read, the database
        // setting arrived, and the secrets vanished between the file and the
        // application. Measured on staging 2026-08-27.
        //
        // Keys are restricted to the SA_ prefix so a stray entry in the file
        // cannot reach anything else.
        $values = self::DEFAULTS;
        foreach ($fromFile as $key => $value) {
            if (is_string($key) && preg_match('/^SA_[A-Z0-9_]+$/', $key) === 1) {
                $values[$key] = $value;
            }
        }

        // The environment wins over the file, which is how the cron jobs are
        // configured. Every key that could exist is considered, not just the
        // ones carrying a default.
        $known = array_unique(array_merge(
            array_keys(self::DEFAULTS),
            self::SECRET_KEYS,
            self::REQUIRED_SECRETS,
            array_keys($values)
        ));
        foreach ($known as $key) {
            $env = getenv($key);
            if ($env !== false && $env !== '') {
                $default = self::DEFAULTS[$key] ?? null;
                $values[$key] = is_bool($default) ? self::toBool($env) : $env;
            }
        }

        // The private storage path defaults to the in-repo deny-all directory.
        // ADR-003: the FTPS deploy cannot reach above public_html, and the
        // deny-all pattern is proven to return 403 on this host.
        if ($values['SA_PRIVATE_STORAGE_PATH'] === '') {
            $values['SA_PRIVATE_STORAGE_PATH'] = $root . '/storage-private/soft-appeals';
        }

        return new self($values, $found, $configFile);
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
     * Whether the Recovery Room and the preferences page answer at all.
     *
     * A practice that follows a link into a closed portal is told plainly that
     * it is not open yet, rather than being shown a 404 that reads as a broken
     * link in an email she sent.
     */
    public function portalEnabled(): bool
    {
        return $this->flagOrNotProduction('SA_PORTAL_ENABLED');
    }

    /** Whether the six-digit sign-in code door is open. */
    public function clientLoginEnabled(): bool
    {
        return $this->flagOrNotProduction('SA_CLIENT_LOGIN_ENABLED');
    }

    /**
     * Whether a reimbursement can be verified, a fee calculated and an
     * invoice issued. Section 20 lists the flag and section 25 says when it
     * goes on: after the reconciliation tests. Unset is on off production and
     * off on production, like the portal flags.
     */
    public function recoveryFinanceEnabled(): bool
    {
        return $this->flagOrNotProduction('SA_RECOVERY_FINANCE_ENABLED');
    }

    /**
     * Whether the scheduled jobs may run on their own.
     *
     * Read by the cron entry point and by nothing else: a run she starts from
     * the Desk is a click, not a schedule, and section 25 says the schedule
     * is the last thing to switch on. Unset is on off production and off on
     * production, like the portal flags.
     */
    public function cronEnabled(): bool
    {
        return $this->flagOrNotProduction('SA_DEADLINE_CRON_ENABLED');
    }

    /**
     * Whether the intake.mailbox job may read the forwarded-email inbox.
     * Same unset rule as the portal flags. Credentials are checked
     * separately, so an enabled job with no mailbox reports that plainly
     * rather than failing.
     */
    public function intakeMailboxEnabled(): bool
    {
        return $this->flagOrNotProduction('SA_INTAKE_MAILBOX_ENABLED');
    }

    /**
     * The hour, in her timezone, at which the morning digest is sent. A
     * job run earlier in the day does nothing; a run later sends it once.
     */
    public function digestHour(): int
    {
        $value = (int) $this->string('SA_DIGEST_HOUR');
        return $value >= 0 && $value <= 23 ? $value : 6;
    }

    /**
     * Whether the signing screen will actually apply a signature.
     *
     * Two gates, and the second one is not overridable from the config file.
     *
     * Off production, this behaves like the portal flags: unset means on, and
     * an explicit value in the config file wins. That is what lets the whole
     * agreement workflow be built, walked and tested against fictional
     * practices, which section 14.5 expressly allows.
     *
     * On production it is false, full stop, whatever the file says. Section
     * 14.5 lists six blockers and not one of them has been cleared: no
     * agreement text is approved, the legal entity and trade-name language are
     * not confirmed, and the consent and retention requirements have not been
     * reviewed. A config value is a thing that can be flipped by accident at
     * two in the morning; the clamp is a thing that has to be edited, reviewed
     * and deployed, which is the right weight for turning on real signatures.
     *
     * Clearing the blockers means editing PRODUCTION_SIGNING_BLOCKERS below
     * down to nothing, and approving the templates in Domain\DocumentTemplates.
     */
    public function eSignEnabled(): bool
    {
        if ($this->isProduction() && self::PRODUCTION_SIGNING_BLOCKERS !== []) {
            return false;
        }
        return $this->flagOrNotProduction('SA_E_SIGN_ENABLED');
    }

    /**
     * The section 14.5 blockers, still standing.
     *
     * @return list<string>
     */
    public static function productionSigningBlockers(): array
    {
        return self::PRODUCTION_SIGNING_BLOCKERS;
    }

    /**
     * The legal party name that appears on every generated agreement.
     *
     * Empty on production is a refusal, not a fallback. See the DEFAULTS entry.
     */
    public function legalEntity(): string
    {
        $value = trim($this->string('SA_LEGAL_ENTITY'));
        if ($value !== '') {
            return $value;
        }
        return $this->isProduction()
            ? ''
            : 'Legal entity name not confirmed yet';
    }

    public function tradeName(): string
    {
        $value = trim($this->string('SA_TRADE_NAME'));
        return $value === '' ? 'Soft Appeals' : $value;
    }

    /**
     * The pattern the three switchable capabilities share: unset means anywhere
     * but production, and an explicit value in the config file wins either way.
     */
    private function flagOrNotProduction(string $key): bool
    {
        $value = $this->values[$key] ?? null;
        if ($value === null || $value === '') {
            return !$this->isProduction();
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
        return $this->readiness()['ready'];
    }

    /**
     * What is present and what is not, in a form safe to show on screen.
     *
     * No value and no path is ever included, only whether each thing arrived.
     * That is enough to tell "the file is not there" apart from "the file is
     * there and a field is blank", which are the two mistakes worth telling
     * apart and which look identical from outside.
     *
     * @return array{path:string,ready:bool,file:bool,database:bool,secrets:bool,missing:list<string>}
     */
    public function readiness(): array
    {
        $missing = [];
        foreach (self::REQUIRED_SECRETS as $key) {
            $value = (string) ($this->values[$key] ?? '');
            if ($value === '' || strlen($value) < self::MIN_SECRET_LENGTH) {
                $missing[] = $key;
            }
        }

        $file = $this->configFileFound;
        $database = $this->hasDatabase();
        $secrets = $missing === [];

        return [
            'path'     => $this->configFilePath,
            'ready'    => $file && $database && $secrets,
            'file'     => $file,
            'database' => $database,
            'secrets'  => $secrets,
            'missing'  => $missing,
        ];
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
