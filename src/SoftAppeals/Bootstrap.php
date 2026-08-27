<?php
declare(strict_types=1);

namespace SoftAppeals;

use SoftAppeals\Auth\AuthorizationService;
use SoftAppeals\Auth\AuthService;
use SoftAppeals\Auth\SessionManager;
use SoftAppeals\Repositories\MembershipRepository;
use SoftAppeals\Repositories\OrganizationRepository;
use SoftAppeals\Repositories\UserRepository;
use SoftAppeals\Security\Csrf;
use SoftAppeals\Security\ErrorHandler;
use SoftAppeals\Security\Hmac;
use SoftAppeals\Security\RateLimiter;
use SoftAppeals\Services\AuditService;
use SoftAppeals\Support\Clock;

/**
 * One place that builds everything, so no page controller has to.
 *
 * There is no framework and no Composer here, which is deliberate: this site
 * has never had either, the host is shared, and a dependency tree is a supply
 * chain she would have to keep watching. A hand-written autoloader over one
 * namespace is about twenty lines and has no update cadence.
 *
 * Services are built lazily and cached, so a page that only renders a login
 * form never opens a database connection.
 *
 * Usage from a page in public_html:
 *
 *     $app = require __DIR__ . '/src/SoftAppeals/boot.php';
 *     $app->requireDatabase();
 *
 * boot.php is the entry point and this file is only the class, so that the
 * autoloader loading Bootstrap can never have the side effect of booting.
 */
final class Bootstrap
{
    /** The one instance per request, handed out by instance(). */
    private static ?self $instance = null;

    private Config $config;
    private ErrorHandler $errors;
    private ?Database $db = null;
    /** @var array<string,object> */
    private array $made = [];

    private function __construct(Config $config)
    {
        $this->config = $config;
        $this->errors = new ErrorHandler($config);
    }

    /**
     * Build the application.
     *
     * $withErrorHandler is false in tests and in the migration runner, where a
     * thrown exception should surface with its stack rather than be turned into
     * a polite page.
     */
    public static function boot(?string $configFile = null, bool $withErrorHandler = true): self
    {
        self::registerAutoloader();

        $config = Config::load($configFile);
        $app = new self($config);

        if ($withErrorHandler) {
            $app->errors->register();
        }

        // Timestamps are stored in UTC regardless of what the host is set to.
        date_default_timezone_set('UTC');

        return $app;
    }

    /**
     * The one instance for this request.
     *
     * A second Bootstrap would mean a second database connection and a second
     * correlation reference for one request, so boot.php goes through here.
     */
    public static function instance(?string $configFile = null): self
    {
        return self::$instance ??= self::boot($configFile);
    }

    /** Only the tests call this, between cases. */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * PSR-4 for the SoftAppeals namespace, and nothing else.
     *
     * The realpath check is what stops a crafted class name from reaching
     * outside src/SoftAppeals. Class names come from source in normal use, but
     * a loader that resolves paths by string concatenation is worth pinning
     * shut regardless.
     */
    public static function registerAutoloader(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        $base = __DIR__;
        spl_autoload_register(static function (string $class) use ($base): void {
            $prefix = 'SoftAppeals\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative)) {
                return;
            }
            $path = $base . '/' . str_replace('\\', '/', $relative) . '.php';
            $real = realpath($path);
            if ($real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                return;
            }
            require $real;
        });
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function errors(): ErrorHandler
    {
        return $this->errors;
    }

    /**
     * Refuse to serve unless the secrets are present.
     * Called by every page that does anything beyond render static markup.
     */
    public function requireSecrets(): void
    {
        $this->config->assertSecretsPresent();
    }

    public function database(): Database
    {
        return $this->db ??= Database::fromConfig($this->config);
    }

    /** Point the application at an already-open connection. Used by the tests. */
    public function useDatabase(Database $db): void
    {
        $this->db = $db;
        $this->made = [];
    }

    public function requireDatabase(): Database
    {
        $this->requireSecrets();
        return $this->database();
    }

    public function clock(): Clock
    {
        return $this->make(Clock::class, fn (): Clock => new Clock(
            $this->config->string('SA_BUSINESS_TIMEZONE')
        ));
    }

    public function hmac(): Hmac
    {
        return $this->make(Hmac::class, fn (): Hmac => new Hmac(
            $this->config->string('SA_IP_HMAC_SECRET')
        ));
    }

    public function session(): SessionManager
    {
        return $this->make(SessionManager::class, fn (): SessionManager => new SessionManager($this->config));
    }

    public function csrf(): Csrf
    {
        return $this->make(Csrf::class, fn (): Csrf => new Csrf($this->session()));
    }

    public function audit(): AuditService
    {
        return $this->make(AuditService::class, fn (): AuditService => new AuditService(
            $this->database(),
            $this->clock(),
            $this->hmac(),
            $this->session(),
            substr($this->errors->correlationReference(), 7)
        ));
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->make(RateLimiter::class, fn (): RateLimiter => new RateLimiter(
            $this->database(),
            $this->clock(),
            $this->hmac()
        ));
    }

    public function users(): UserRepository
    {
        return $this->make(UserRepository::class, fn (): UserRepository => new UserRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function memberships(): MembershipRepository
    {
        return $this->make(MembershipRepository::class, fn (): MembershipRepository => new MembershipRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function organizations(): OrganizationRepository
    {
        return $this->make(OrganizationRepository::class, fn (): OrganizationRepository => new OrganizationRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function auth(): AuthService
    {
        return $this->make(AuthService::class, fn (): AuthService => new AuthService(
            $this->users(),
            $this->memberships(),
            $this->session(),
            $this->csrf(),
            $this->rateLimiter(),
            $this->audit(),
            $this->clock(),
            $this->hmac()
        ));
    }

    public function authorization(): AuthorizationService
    {
        return $this->make(AuthorizationService::class, fn (): AuthorizationService => new AuthorizationService(
            $this->session(),
            $this->memberships(),
            $this->audit()
        ));
    }

    /**
     * @template T of object
     * @param class-string<T> $key
     * @param callable():T $factory
     * @return T
     */
    private function make(string $key, callable $factory): object
    {
        /** @var T */
        return $this->made[$key] ??= $factory();
    }
}
