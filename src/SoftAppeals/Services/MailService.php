<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Config;
use SoftAppeals\Repositories\CommunicationRepository;

/**
 * Sending mail, and recording that it was sent.
 *
 * The transport is the one the site has used since the free shelf went up:
 * fs-mail.php, a hand-written SMTP client that authenticates as her own
 * notify@ address. It is not re-implemented here. This class adds the three
 * things a command centre needs on top of a socket.
 *
 * First, the allowlist. Staging must never be able to email a real practice.
 * SA_MAIL_ALLOWLIST names the only addresses this environment may reach, and a
 * recipient outside it is refused before the socket is opened, not merely
 * discouraged. In production the list is empty and means no restriction.
 *
 * Second, the record. Every attempt writes a row in sa_communications, in the
 * state it actually reached: accepted, failed, or refused. Nothing is ever
 * recorded as delivered, because nothing here can know that.
 *
 * Third, idempotency. A double click that produces the same key finds the row
 * already written and sends nothing, which is the difference between a slow
 * page and a practice receiving the same terms email twice.
 */
final class MailService
{
    /** Bumped when the wording of a template changes. Stored on every row. */
    public const TEMPLATE_VERSION = '2026-08-27';

    private Config $config;
    private CommunicationRepository $communications;
    private AuditService $audit;

    /** @var callable(string,string,string,string):bool */
    private $transport;

    /**
     * @param (callable(string,string,string,string):bool)|null $transport
     *        to, subject, body, replyTo => accepted by the mail server.
     *        Null uses the site's own SMTP sender. The tests pass their own.
     */
    public function __construct(
        Config $config,
        CommunicationRepository $communications,
        AuditService $audit,
        ?callable $transport = null
    ) {
        $this->config = $config;
        $this->communications = $communications;
        $this->audit = $audit;
        $this->transport = $transport ?? self::smtpTransport($config);
    }

    /**
     * True when this environment is allowed to write to this address.
     * An empty allowlist means unrestricted, which is only correct in production.
     */
    public function isAllowedRecipient(string $email): bool
    {
        $allowlist = $this->config->mailAllowlist();
        if ($allowlist === []) {
            return true;
        }
        return in_array(strtolower(trim($email)), $allowlist, true);
    }

    /**
     * Send one message and record it.
     *
     * @return array{state:string,communication_id:string,sent:bool,reason:string}
     */
    public function send(
        string $to,
        string $subject,
        string $body,
        string $templateKey,
        ?string $engagementId = null,
        ?string $organizationId = null,
        ?string $idempotencyKey = null
    ): array {
        $to = strtolower(trim($to));

        // Already sent under this key. Nothing goes out a second time.
        if ($idempotencyKey !== null) {
            $existing = $this->communications->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return [
                    'state'            => (string) $existing['state'],
                    'communication_id' => (string) $existing['id'],
                    'sent'             => (string) $existing['state'] === CommunicationRepository::ACCEPTED,
                    'reason'           => 'already sent',
                ];
            }
        }

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return $this->finish(
                CommunicationRepository::FAILED,
                'invalid_address',
                $to,
                $subject,
                $templateKey,
                $engagementId,
                $organizationId,
                $idempotencyKey,
                'that is not a usable email address'
            );
        }

        if (!$this->isAllowedRecipient($to)) {
            // Not an error. This environment is doing exactly what it was
            // configured to do, and the Desk shows it as its own state so a
            // refused staging send is never mistaken for a broken one.
            return $this->finish(
                CommunicationRepository::REFUSED,
                'outside_allowlist',
                $to,
                $subject,
                $templateKey,
                $engagementId,
                $organizationId,
                $idempotencyKey,
                'this environment may only email its allowlist'
            );
        }

        $replyTo = $this->config->string('SA_MAIL_REPLY_TO');
        $accepted = false;
        try {
            $accepted = ($this->transport)($to, $subject, $body, $replyTo);
        } catch (NoMailCredentials) {
            // Not a refusal by the mail server: this installation has no
            // credentials to offer it. Recorded as its own category so the
            // Desk can say "no credentials here" rather than "refused".
            return $this->finish(
                CommunicationRepository::FAILED,
                'no_mail_credentials',
                $to,
                $subject,
                $templateKey,
                $engagementId,
                $organizationId,
                $idempotencyKey,
                'this environment has no mail credentials'
            );
        } catch (\Throwable) {
            $accepted = false;
        }

        return $this->finish(
            $accepted ? CommunicationRepository::ACCEPTED : CommunicationRepository::FAILED,
            $accepted ? null : 'transport_refused',
            $to,
            $subject,
            $templateKey,
            $engagementId,
            $organizationId,
            $idempotencyKey,
            $accepted ? 'the mail server took it' : 'the mail server would not take it'
        );
    }

    /**
     * @return array{state:string,communication_id:string,sent:bool,reason:string}
     */
    private function finish(
        string $state,
        ?string $errorCategory,
        string $to,
        string $subject,
        string $templateKey,
        ?string $engagementId,
        ?string $organizationId,
        ?string $idempotencyKey,
        string $reason
    ): array {
        $row = $this->communications->record(
            $engagementId,
            $organizationId,
            $to,
            $templateKey,
            self::TEMPLATE_VERSION,
            $subject,
            $state,
            $idempotencyKey,
            $errorCategory
        );

        $this->audit->record(
            'communication.send',
            $state === CommunicationRepository::ACCEPTED ? 'success' : 'failure',
            'communication',
            $row['id'],
            [
                'communication_template' => $templateKey,
                'template_version'       => self::TEMPLATE_VERSION,
                'reason'                 => $reason,
                'idempotency_key'        => $idempotencyKey,
            ],
            $organizationId
        );

        return [
            'state'            => $state,
            'communication_id' => $row['id'],
            'sent'             => $state === CommunicationRepository::ACCEPTED,
            'reason'           => $reason,
        ];
    }

    /**
     * Where the SMTP credentials live, or null when this installation has
     * none it may use.
     *
     * The live site keeps them at fs-metrics/smtp.json beside fs-mail.php,
     * written on the server and never committed. Staging is a folder INSIDE
     * public_html with its own fs-metrics, which the deploy creates empty, so
     * the same relative path finds nothing there. That is the whole reason
     * every staging send came back "the mail server would not take it" from
     * 2026-08-27: there was no server, because there were no credentials to
     * open a socket with.
     *
     * The rule is the one LegacyLeadImporter already uses for the leads: look
     * in this installation's own folder first, and off production look one
     * level up, which on staging is the live site. On production the parent
     * of the document root is the account home and holds no fs-metrics, and
     * the fallback is not offered there anyway.
     *
     * $appRoot is the folder holding fs-mail.php; the tests pass a fixture.
     */
    public static function smtpConfigPath(bool $isProduction, ?string $appRoot = null): ?string
    {
        $appRoot = rtrim($appRoot ?? dirname(__DIR__, 3), '/');
        $own = $appRoot . '/fs-metrics/smtp.json';
        if (is_file($own)) {
            return $own;
        }
        if ($isProduction) {
            return null;
        }
        $up = dirname($appRoot) . '/fs-metrics/smtp.json';
        return is_file($up) ? $up : null;
    }

    /**
     * The site's own sender. Loaded lazily so nothing outside a real send ever
     * pulls the SMTP code in, and so a test that never sends never touches it.
     *
     * @return callable(string,string,string,string):bool
     */
    private static function smtpTransport(Config $config): callable
    {
        return static function (string $to, string $subject, string $body, string $replyTo) use ($config): bool {
            $mailer = dirname(__DIR__, 3) . '/fs-mail.php';
            if (!is_file($mailer)) {
                throw new NoMailCredentials('fs-mail.php is not here');
            }
            require_once $mailer;
            if (!function_exists('fs_smtp_send')) {
                throw new NoMailCredentials('fs-mail.php does not define fs_smtp_send');
            }

            $path = self::smtpConfigPath($config->isProduction());
            if ($path === null) {
                throw new NoMailCredentials('no smtp.json in reach');
            }
            $cfg = json_decode((string) file_get_contents($path), true);
            if (!is_array($cfg) || empty($cfg['user']) || empty($cfg['pass'])) {
                throw new NoMailCredentials('smtp.json is incomplete');
            }
            return (bool) fs_smtp_send($cfg, $to, $subject, $body, $replyTo);
        };
    }
}
