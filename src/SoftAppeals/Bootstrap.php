<?php
declare(strict_types=1);

namespace SoftAppeals;

use SoftAppeals\Auth\AuthorizationService;
use SoftAppeals\Auth\AuthService;
use SoftAppeals\Auth\SessionManager;
use SoftAppeals\Repositories\ActionRequestRepository;
use SoftAppeals\Repositories\ApprovalRequestRepository;
use SoftAppeals\Repositories\AssessmentRepository;
use SoftAppeals\Repositories\AttentionRepository;
use SoftAppeals\Repositories\ChecklistRepository;
use SoftAppeals\Repositories\CloseoutRepository;
use SoftAppeals\Repositories\CommunicationRepository;
use SoftAppeals\Repositories\ContactRepository;
use SoftAppeals\Repositories\DocumentRepository;
use SoftAppeals\Repositories\EngagementRepository;
use SoftAppeals\Repositories\IntakeRepository;
use SoftAppeals\Repositories\JobRepository;
use SoftAppeals\Repositories\InvitationRepository;
use SoftAppeals\Repositories\InvoiceRepository;
use SoftAppeals\Repositories\LoginCodeRepository;
use SoftAppeals\Repositories\MembershipRepository;
use SoftAppeals\Repositories\OrganizationRepository;
use SoftAppeals\Repositories\PreferenceRepository;
use SoftAppeals\Repositories\RecoveryRepository;
use SoftAppeals\Repositories\RecoveryScopeRepository;
use SoftAppeals\Repositories\SettingsRepository;
use SoftAppeals\Repositories\SignatureRepository;
use SoftAppeals\Repositories\StatusEventRepository;
use SoftAppeals\Repositories\SubmissionEventRepository;
use SoftAppeals\Repositories\UserRepository;
use SoftAppeals\Repositories\WorkBatchRepository;
use SoftAppeals\Security\Csrf;
use SoftAppeals\Security\ErrorHandler;
use SoftAppeals\Security\Hmac;
use SoftAppeals\Security\RateLimiter;
use SoftAppeals\Services\ActionRequestService;
use SoftAppeals\Services\AssessmentService;
use SoftAppeals\Services\AuditService;
use SoftAppeals\Services\BackupService;
use SoftAppeals\Services\ChecklistService;
use SoftAppeals\Services\ClientAccessService;
use SoftAppeals\Services\CloseoutService;
use SoftAppeals\Services\DocumentService;
use SoftAppeals\Services\DigestService;
use SoftAppeals\Services\DocumentVault;
use SoftAppeals\Services\EngagementService;
use SoftAppeals\Services\FitReplyService;
use SoftAppeals\Services\IntakeService;
use SoftAppeals\Services\JobService;
use SoftAppeals\Services\MailboxService;
use SoftAppeals\Services\LegacyLeadImporter;
use SoftAppeals\Services\MailService;
use SoftAppeals\Services\PreferencesService;
use SoftAppeals\Services\ReconciliationService;
use SoftAppeals\Services\ReminderService;
use SoftAppeals\Services\RecoveryService;
use SoftAppeals\Services\SchemaService;
use SoftAppeals\Services\SeedService;
use SoftAppeals\Services\SigningService;
use SoftAppeals\Services\TermsService;
use SoftAppeals\Services\WorkBatchService;
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

    private function __construct(Config $config, ErrorHandler $errors)
    {
        $this->config = $config;
        $this->errors = $errors;
        $errors->withConfig($config);
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

        // Timestamps are stored in UTC regardless of what the host is set to.
        date_default_timezone_set('UTC');

        // The handler goes in BEFORE the configuration is read, because reading
        // the configuration is the first thing that can fail. A config file with
        // a stray character throws while loading, and with the handler installed
        // afterwards that threw past every catch: empty 500, nothing in the body,
        // nothing in any log. Measured on staging 2026-08-27.
        $errors = new ErrorHandler();
        if ($withErrorHandler) {
            $errors->register();
        }

        $config = Config::load($configFile);

        return new self($config, $errors);
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

    public function schema(): SchemaService
    {
        return $this->make(SchemaService::class, fn (): SchemaService => new SchemaService(
            $this->database(),
            $this->clock(),
            dirname(__DIR__, 2) . '/database/migrations',
            $this->config->privateStoragePath('config', '.migrate.lock')
        ));
    }

    public function seeds(): SeedService
    {
        return $this->make(SeedService::class, fn (): SeedService => new SeedService(
            $this->database(),
            $this->clock(),
            $this->organizations(),
            $this->users(),
            $this->memberships()
        ));
    }

    /**
     * Make the database ready to serve, without a command line.
     *
     * There is no SSH on this account and no PHP on the machine this was
     * written on, so `php database/migrate.php up` cannot be run by anyone.
     * Every page that needs a table calls this instead.
     *
     * Off in production unless SA_AUTO_MIGRATE says otherwise: staging keeps
     * itself current, the live database changes when she is watching.
     *
     * @return array{migrated:list<string>,seeded:int}
     */
    public function prepareDatabase(): array
    {
        $this->requireDatabase();

        $migrated = [];
        if ($this->config->autoMigrate()) {
            // Recovery from a half-applied migration is offered off production
            // only. See SchemaService::migrate.
            $migrated = $this->schema()->migrate($this->audit(), !$this->config->isProduction());
        } else {
            // Even with auto-migration off, the ledger must exist so the Desk
            // can say what is outstanding rather than crash on a missing table.
            $this->schema()->ensureLedger();
        }

        $seeded = 0;
        if ($this->config->autoSeed() && !$this->schema()->hasPending()) {
            $seeded = $this->seeds()->seedIfEmpty($this->audit());
        }

        return ['migrated' => $migrated, 'seeded' => $seeded];
    }

    public function clock(): Clock
    {
        return $this->make(Clock::class, fn (): Clock => new Clock(
            $this->config->string('SA_BUSINESS_TIMEZONE')
        ));
    }

    /**
     * Pin "now". Tests only.
     *
     * A reminder that fires once per cadence period cannot be proved without
     * moving the calendar, and nothing here sleeps for a fortnight. Every
     * service and repository already built is dropped, because each one holds
     * the clock it was handed, and a frozen clock that half the application
     * cannot see would prove the wrong thing.
     */
    public function useClock(Clock $clock): void
    {
        $this->made = [];
        $this->made[Clock::class] = $clock;
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

    public function intakes(): IntakeRepository
    {
        return $this->make(IntakeRepository::class, fn (): IntakeRepository => new IntakeRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function engagements(): EngagementRepository
    {
        return $this->make(EngagementRepository::class, fn (): EngagementRepository => new EngagementRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function invitations(): InvitationRepository
    {
        return $this->make(InvitationRepository::class, fn (): InvitationRepository => new InvitationRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function contacts(): ContactRepository
    {
        return $this->make(ContactRepository::class, fn (): ContactRepository => new ContactRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function preferences(): PreferenceRepository
    {
        return $this->make(PreferenceRepository::class, fn (): PreferenceRepository => new PreferenceRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function documents(): DocumentRepository
    {
        return $this->make(DocumentRepository::class, fn (): DocumentRepository => new DocumentRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function signatures(): SignatureRepository
    {
        return $this->make(SignatureRepository::class, fn (): SignatureRepository => new SignatureRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function loginCodes(): LoginCodeRepository
    {
        return $this->make(LoginCodeRepository::class, fn (): LoginCodeRepository => new LoginCodeRepository(
            $this->database(),
            $this->clock(),
            $this->hmac()
        ));
    }

    public function communications(): CommunicationRepository
    {
        return $this->make(CommunicationRepository::class, fn (): CommunicationRepository => new CommunicationRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function timeline(): StatusEventRepository
    {
        return $this->make(StatusEventRepository::class, fn (): StatusEventRepository => new StatusEventRepository(
            $this->database(),
            $this->clock()
        ));
    }

    /**
     * The mailer.
     *
     * $transport exists for the tests, which must never open a socket. Passing
     * one rebuilds the service, so a test that swaps the transport is not
     * handed a cached one that would still send for real.
     *
     * @param (callable(string,string,string,string):bool)|null $transport
     */
    public function mail(?callable $transport = null): MailService
    {
        if ($transport !== null) {
            return $this->made[MailService::class] = new MailService(
                $this->config,
                $this->communications(),
                $this->audit(),
                $transport
            );
        }
        return $this->make(MailService::class, fn (): MailService => new MailService(
            $this->config,
            $this->communications(),
            $this->audit()
        ));
    }

    public function engagementService(): EngagementService
    {
        return $this->make(EngagementService::class, fn (): EngagementService => new EngagementService(
            $this->database(),
            $this->organizations(),
            $this->engagements(),
            $this->intakes(),
            $this->timeline(),
            $this->audit()
        ));
    }

    public function intakeService(): IntakeService
    {
        return $this->make(IntakeService::class, fn (): IntakeService => new IntakeService(
            $this->database(),
            $this->clock(),
            $this->intakes(),
            $this->engagements(),
            $this->engagementService(),
            $this->audit()
        ));
    }

    /**
     * The same-day fit reply: three drafts per new inquiry, sent by her hand.
     */
    public function fitReplyService(): FitReplyService
    {
        return $this->make(FitReplyService::class, fn (): FitReplyService => new FitReplyService(
            $this->config,
            $this->intakes(),
            $this->mail(),
            $this->audit()
        ));
    }

    public function termsService(): TermsService
    {
        return $this->make(TermsService::class, fn (): TermsService => new TermsService(
            $this->config,
            $this->clock(),
            $this->engagements(),
            $this->intakes(),
            $this->invitations(),
            $this->communications(),
            $this->engagementService(),
            $this->mail(),
            $this->audit()
        ));
    }

    /**
     * The two client-side services, Phase 3.
     *
     * Both are built the same way every other service is, and both are given
     * the mailer through mail() so a test that swaps the transport swaps it for
     * these as well. A sign-in code that reached a real address from a test run
     * would be the one failure nobody could take back.
     */
    public function clientAccess(): ClientAccessService
    {
        return $this->make(ClientAccessService::class, fn (): ClientAccessService => new ClientAccessService(
            $this->database(),
            $this->clock(),
            $this->session(),
            $this->csrf(),
            $this->invitations(),
            $this->loginCodes(),
            $this->contacts(),
            $this->users(),
            $this->memberships(),
            $this->engagements(),
            $this->rateLimiter(),
            $this->mail(),
            $this->audit(),
            $this->hmac(),
            $this->config
        ));
    }

    public function preferencesService(): PreferencesService
    {
        return $this->make(PreferencesService::class, fn (): PreferencesService => new PreferencesService(
            $this->database(),
            $this->preferences(),
            $this->contacts(),
            $this->users(),
            $this->memberships(),
            $this->engagements(),
            $this->engagementService(),
            $this->timeline(),
            $this->mail(),
            $this->audit()
        ));
    }

    /**
     * The three Phase 4 pieces: the vault, her side of signing, and theirs.
     *
     * The vault takes only the config, because the one thing it must not do is
     * reach for anything that could tell it where to write other than the
     * configured private path.
     */
    public function vault(): DocumentVault
    {
        return $this->make(DocumentVault::class, fn (): DocumentVault => new DocumentVault(
            $this->config
        ));
    }

    public function documentService(): DocumentService
    {
        return $this->make(DocumentService::class, fn (): DocumentService => new DocumentService(
            $this->config,
            $this->database(),
            $this->clock(),
            $this->documents(),
            $this->engagements(),
            $this->signatures(),
            $this->preferences(),
            $this->contacts(),
            $this->invitations(),
            $this->timeline(),
            $this->engagementService(),
            $this->vault(),
            $this->mail(),
            $this->audit(),
            $this->hmac(),
            $this->settings(),
            $this->recoveryScopes()
        ));
    }

    public function signingService(): SigningService
    {
        return $this->make(SigningService::class, fn (): SigningService => new SigningService(
            $this->config,
            $this->database(),
            $this->clock(),
            $this->documents(),
            $this->signatures(),
            $this->contacts(),
            $this->timeline(),
            $this->vault(),
            $this->authorization(),
            $this->audit(),
            $this->hmac(),
            $this->documentService()
        ));
    }

    // ------------------------------------------------------------------
    // Phase 6. The recovery agreement, approvals and submissions.
    // ------------------------------------------------------------------

    public function recoveryScopes(): RecoveryScopeRepository
    {
        return $this->make(RecoveryScopeRepository::class, fn (): RecoveryScopeRepository => new RecoveryScopeRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function approvalRequests(): ApprovalRequestRepository
    {
        return $this->make(ApprovalRequestRepository::class, fn (): ApprovalRequestRepository => new ApprovalRequestRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function submissionEvents(): SubmissionEventRepository
    {
        return $this->make(SubmissionEventRepository::class, fn (): SubmissionEventRepository => new SubmissionEventRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function recoveryService(): RecoveryService
    {
        return $this->make(RecoveryService::class, fn (): RecoveryService => new RecoveryService(
            $this->config,
            $this->database(),
            $this->clock(),
            $this->recoveryScopes(),
            $this->approvalRequests(),
            $this->submissionEvents(),
            $this->workBatches(),
            $this->engagements(),
            $this->documents(),
            $this->contacts(),
            $this->users(),
            $this->memberships(),
            $this->preferences(),
            $this->timeline(),
            $this->engagementService(),
            $this->workBatchService(),
            $this->checklistService(),
            $this->actionRequestService(),
            $this->authorization(),
            $this->mail(),
            $this->audit(),
            $this->recoveries(),
            $this->invoices()
        ));
    }

    // ------------------------------------------------------------------
    // Phase 7. Reconciliation and closeout. The money.
    // ------------------------------------------------------------------

    public function recoveries(): RecoveryRepository
    {
        return $this->make(RecoveryRepository::class, fn (): RecoveryRepository => new RecoveryRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function invoices(): InvoiceRepository
    {
        return $this->make(InvoiceRepository::class, fn (): InvoiceRepository => new InvoiceRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function closeouts(): CloseoutRepository
    {
        return $this->make(CloseoutRepository::class, fn (): CloseoutRepository => new CloseoutRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function reconciliationService(): ReconciliationService
    {
        return $this->make(ReconciliationService::class, fn (): ReconciliationService => new ReconciliationService(
            $this->config,
            $this->database(),
            $this->clock(),
            $this->recoveries(),
            $this->invoices(),
            $this->recoveryScopes(),
            $this->submissionEvents(),
            $this->workBatches(),
            $this->engagements(),
            $this->documents(),
            $this->contacts(),
            $this->preferences(),
            $this->timeline(),
            $this->settings(),
            $this->vault(),
            $this->actionRequestService(),
            $this->mail(),
            $this->audit()
        ));
    }

    public function closeoutService(): CloseoutService
    {
        return $this->make(CloseoutService::class, fn (): CloseoutService => new CloseoutService(
            $this->config,
            $this->database(),
            $this->clock(),
            $this->closeouts(),
            $this->invoices(),
            $this->recoveryScopes(),
            $this->approvalRequests(),
            $this->submissionEvents(),
            $this->workBatches(),
            $this->engagements(),
            $this->documents(),
            $this->contacts(),
            $this->users(),
            $this->memberships(),
            $this->invitations(),
            $this->timeline(),
            $this->engagementService(),
            $this->reconciliationService(),
            $this->documentService(),
            $this->actionRequestService(),
            $this->mail(),
            $this->audit()
        ));
    }

    // ------------------------------------------------------------------
    // Phase 5. The assessment and the Recovery Room proper.
    // ------------------------------------------------------------------

    public function settings(): SettingsRepository
    {
        return $this->make(SettingsRepository::class, fn (): SettingsRepository => new SettingsRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function assessments(): AssessmentRepository
    {
        return $this->make(AssessmentRepository::class, fn (): AssessmentRepository => new AssessmentRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function workBatches(): WorkBatchRepository
    {
        return $this->make(WorkBatchRepository::class, fn (): WorkBatchRepository => new WorkBatchRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function checklistItems(): ChecklistRepository
    {
        return $this->make(ChecklistRepository::class, fn (): ChecklistRepository => new ChecklistRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function actionRequests(): ActionRequestRepository
    {
        return $this->make(ActionRequestRepository::class, fn (): ActionRequestRepository => new ActionRequestRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function checklistService(): ChecklistService
    {
        return $this->make(ChecklistService::class, fn (): ChecklistService => new ChecklistService(
            $this->checklistItems(),
            $this->timeline()
        ));
    }

    public function actionRequestService(): ActionRequestService
    {
        return $this->make(ActionRequestService::class, fn (): ActionRequestService => new ActionRequestService(
            $this->config,
            $this->clock(),
            $this->actionRequests(),
            $this->contacts(),
            $this->preferences(),
            $this->mail(),
            $this->audit()
        ));
    }

    public function workBatchService(): WorkBatchService
    {
        return $this->make(WorkBatchService::class, fn (): WorkBatchService => new WorkBatchService(
            $this->clock(),
            $this->workBatches(),
            $this->engagements(),
            $this->audit()
        ));
    }

    public function assessmentService(): AssessmentService
    {
        return $this->make(AssessmentService::class, fn (): AssessmentService => new AssessmentService(
            $this->config,
            $this->database(),
            $this->clock(),
            $this->assessments(),
            $this->actionRequests(),
            $this->engagements(),
            $this->workBatches(),
            $this->contacts(),
            $this->preferences(),
            $this->timeline(),
            $this->engagementService(),
            $this->workBatchService(),
            $this->checklistService(),
            $this->actionRequestService(),
            $this->mail(),
            $this->audit()
        ));
    }

    // ------------------------------------------------------------------
    // Phase 8. Automation: the jobs, what they surface, the reminders, the
    // digest and the backup.
    // ------------------------------------------------------------------

    public function jobs(): JobRepository
    {
        return $this->make(JobRepository::class, fn (): JobRepository => new JobRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function attention(): AttentionRepository
    {
        return $this->make(AttentionRepository::class, fn (): AttentionRepository => new AttentionRepository(
            $this->database(),
            $this->clock()
        ));
    }

    public function backupService(): BackupService
    {
        return $this->make(BackupService::class, fn (): BackupService => new BackupService(
            $this->config,
            $this->database(),
            $this->clock(),
            $this->audit()
        ));
    }

    public function reminderService(): ReminderService
    {
        return $this->make(ReminderService::class, fn (): ReminderService => new ReminderService(
            $this->config,
            $this->clock(),
            $this->actionRequests(),
            $this->approvalRequests(),
            $this->documents(),
            $this->contacts(),
            $this->preferences(),
            $this->engagements(),
            $this->mail(),
            $this->audit()
        ));
    }

    public function digestService(): DigestService
    {
        return $this->make(DigestService::class, fn (): DigestService => new DigestService(
            $this->config,
            $this->clock(),
            $this->intakes(),
            $this->engagements(),
            $this->documents(),
            $this->actionRequests(),
            $this->approvalRequests(),
            $this->submissionEvents(),
            $this->workBatches(),
            $this->recoveries(),
            $this->invoices(),
            $this->closeouts(),
            $this->attention(),
            $this->jobs(),
            $this->mail()
        ));
    }

    /**
     * The intake mailbox reader.
     *
     * $session exists for the tests, which must never open a socket. Passing
     * one rebuilds the service AND drops a cached JobService, which would
     * otherwise still hold the mailbox built earlier and quietly ignore the
     * fake. Same shape as mail().
     *
     * @param (callable():array{unseen:callable(int):list<array{uid:string,raw:string}>,seen:callable(string):void,close:callable():void})|null $session
     */
    public function mailbox(?callable $session = null): MailboxService
    {
        if ($session !== null) {
            unset($this->made[JobService::class]);
            return $this->made[MailboxService::class] = new MailboxService($this->config, $session);
        }
        return $this->make(MailboxService::class, fn (): MailboxService => new MailboxService(
            $this->config
        ));
    }

    public function jobService(): JobService
    {
        return $this->make(JobService::class, fn (): JobService => new JobService(
            $this->config,
            $this->database(),
            $this->clock(),
            $this->jobs(),
            $this->attention(),
            $this->invitations(),
            $this->rateLimiter(),
            $this->reminderService(),
            $this->digestService(),
            $this->backupService(),
            $this->workBatches(),
            $this->recoveries(),
            $this->closeouts(),
            $this->documents(),
            $this->approvalRequests(),
            $this->submissionEvents(),
            $this->actionRequests(),
            $this->invoices(),
            $this->audit(),
            $this->mailbox(),
            $this->intakeService(),
            $this->mail()
        ));
    }

    /**
     * The legacy lead importer.
     *
     * $metricsPath is only ever passed by the tests, which point it at a
     * fixture directory. In every other case it reads the real fs-metrics
     * folder, and it only ever reads it.
     */
    public function importer(?string $metricsPath = null): LegacyLeadImporter
    {
        if ($metricsPath !== null) {
            return $this->made[LegacyLeadImporter::class] = new LegacyLeadImporter(
                $this->intakes(),
                $this->audit(),
                $metricsPath
            );
        }
        return $this->make(LegacyLeadImporter::class, fn (): LegacyLeadImporter => new LegacyLeadImporter(
            $this->intakes(),
            $this->audit()
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
