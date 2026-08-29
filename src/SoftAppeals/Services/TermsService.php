<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Config;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\CommunicationRepository;
use SoftAppeals\Repositories\EngagementRepository;
use SoftAppeals\Repositories\IntakeRepository;
use SoftAppeals\Repositories\InvitationRepository;
use SoftAppeals\Support\Clock;

/**
 * The assessment onboarding terms: the preview she reads, and the send she
 * approves.
 *
 * Two methods, and the split between them is the whole point. preview() builds
 * every word of the email and writes nothing, mints nothing and sends nothing.
 * send() is a separate, deliberate act with its own button and its own CSRF
 * token. Section 12.6 requires exactly that, and it is the difference between
 * a command centre and a machine that emails practices when a page loads.
 *
 * Resend rotates. Every send mints a new one-time link and revokes the previous
 * unused one in the same transaction, so a forwarded old email stops working
 * the moment she sends a new one, and a second communication row records that
 * it went twice rather than losing the first.
 *
 * Nothing in the email is protected health information, and nothing in it can
 * become protected health information later: the body is assembled here from
 * the organization's name, the contact's first name, a fee sentence chosen from
 * a fixed list, and a link. No field a practice typed is echoed back into it.
 */
final class TermsService
{
    public const TEMPLATE_KEY = 'assessment_terms';
    /** The subject carries the practice's own name, so it reads as theirs in a full inbox. */
    public const SUBJECT      = 'Your free denial review: eight questions, then it starts';

    public static function subjectFor(string $organization): string
    {
        $organization = trim($organization);
        return $organization === '' ? self::SUBJECT : $organization . ': your free denial review starts with eight questions';
    }

    private Config $config;
    private Clock $clock;
    private EngagementRepository $engagements;
    private IntakeRepository $intakes;
    private InvitationRepository $invitations;
    private CommunicationRepository $communications;
    private EngagementService $engagementService;
    private MailService $mail;
    private AuditService $audit;

    public function __construct(
        Config $config,
        Clock $clock,
        EngagementRepository $engagements,
        IntakeRepository $intakes,
        InvitationRepository $invitations,
        CommunicationRepository $communications,
        EngagementService $engagementService,
        MailService $mail,
        AuditService $audit
    ) {
        $this->config = $config;
        $this->clock = $clock;
        $this->engagements = $engagements;
        $this->intakes = $intakes;
        $this->invitations = $invitations;
        $this->communications = $communications;
        $this->engagementService = $engagementService;
        $this->mail = $mail;
        $this->audit = $audit;
    }

    /**
     * Everything section 12.6 asks the preview to show, and not one write.
     *
     * @param array<string,mixed> $engagement a row joined with its organization
     * @return array<string,mixed>
     */
    public function preview(array $engagement): array
    {
        $engagementId = (string) $engagement['id'];
        $intake = $engagement['intake_id'] === null
            ? null
            : $this->intakes->find((string) $engagement['intake_id']);

        $recipientName = $intake === null ? '' : (string) $intake['contact_name'];
        $recipientEmail = $intake === null ? '' : (string) $intake['contact_email'];
        $organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');

        $feeBasis = (string) $engagement['fee_basis'];
        $window = $engagement['assessment_window'] === null
            ? null
            : (string) $engagement['assessment_window'];

        $expiresAt = $this->clock->utcPlusSeconds(InvitationRepository::PREFERENCES_TTL_SECONDS);
        $sentBefore = $this->communications->countFor($engagementId, self::TEMPLATE_KEY);

        return [
            'engagement_id'   => $engagementId,
            'engagement_ref'  => (string) $engagement['public_ref'],
            'organization'    => $organization,
            'recipient_name'  => $recipientName,
            'recipient_email' => $recipientEmail,
            'subject'         => self::subjectFor($organization),

            'scope' => [
                'A review of 20 recent denied claims, at business level.',
                'Each reviewed denial sorted by recommended action, financial value, '
                    . 'priority, known time sensitivity, missing information, and who '
                    . 'owns the next step.',
                'A written assessment they keep, whatever they decide afterwards.',
            ],
            'not_included' => [
                'Twenty completed appeals. This is an assessment, and it says so in the email.',
                'Any work on a claim before the Business Associate Agreement is executed.',
                'Any patient-level material. None moves until the paperwork is done.',
                'Any promise about how a payer will decide, or how much comes back.',
            ],

            'fee_sentence'   => EngagementTerms::feeSentence($feeBasis),
            'fee_basis'      => $feeBasis,
            'fee_label'      => EngagementTerms::feeLabel($feeBasis),
            'no_payment'     => 'No payment is due for the assessment, and nothing is '
                . 'charged at signing. They keep the assessment whether or not they '
                . 'continue with recovery work.',

            'window'         => $window,
            'cadence'        => $engagement['communication_cadence'] === null
                ? null
                : (string) $engagement['communication_cadence'],
            'cadence_choices' => EngagementTerms::cadences(),

            'expires_at'         => $expiresAt,
            'expires_at_display' => $this->clock->displayDateTime($expiresAt),

            'sent_before'    => $sentBefore,
            'is_resend'      => $sentBefore > 0,
            'stage'          => (string) $engagement['stage'],
            'row_version'    => (int) $engagement['row_version'],
            'send_sequence'  => $sentBefore,

            'recipient_allowed' => $recipientEmail !== '' && $this->mail->isAllowedRecipient($recipientEmail),

            // The exact email, with the link shown as what it is: something
            // that does not exist until she presses send.
            'body' => $this->body(
                $recipientName,
                $organization,
                $feeBasis,
                $window,
                '[the one-time link, created when you send this]',
                $this->clock->displayDateTime($expiresAt)
            ),
        ];
    }

    /**
     * Approve and send. The only method here that changes anything.
     *
     * $sendSequence is the number of times this template had already gone when
     * the preview was rendered. It makes the idempotency key, so two submits of
     * the same preview send once, and a deliberate resend from a freshly loaded
     * page sends again.
     *
     * `link` is the one-time link that was just minted, and it is null on
     * production, always, with no setting that changes that. Off production it
     * is returned so the Desk can show it once, because staging refuses to
     * email a real practice and the link would otherwise exist only inside a
     * message the mail layer declined to send, which makes the client side
     * impossible to walk. The token is still never stored and never logged: it
     * is handed back on this one request and then it is gone.
     *
     * @param array<string,mixed> $engagement a row joined with its organization
     * @return array{state:string,sent:bool,reason:string,expires_at:?string,resent:bool,link:?string}
     */
    public function send(array $engagement, int $sendSequence, ?string $userId = null): array
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];
        $preview = $this->preview($engagement);

        $recipient = (string) $preview['recipient_email'];
        if ($recipient === '') {
            throw new \RuntimeException(
                'This engagement has no contact address on it, so there is nobody to send to.'
            );
        }

        $idempotencyKey = hash(
            'sha256',
            $engagementId . '|' . self::TEMPLATE_KEY . '|' . $sendSequence
        );

        // The guard goes BEFORE the invitation is minted, not after. Minting
        // first would revoke the live link and then discover the email had
        // already gone, which would leave the practice holding a dead link and
        // no new one. Checked first, a second submit changes nothing at all.
        $already = $this->communications->findByIdempotencyKey($idempotencyKey);
        if ($already !== null) {
            return [
                'state'      => (string) $already['state'],
                'sent'       => (string) $already['state'] === CommunicationRepository::ACCEPTED,
                'reason'     => 'this preview had already been sent',
                'expires_at' => null,
                'resent'     => false,
                // Nothing was minted on this path, so there is nothing to show.
                'link'       => null,
            ];
        }

        $invitation = $this->invitations->mint(
            $organizationId,
            $engagementId,
            $recipient,
            InvitationRepository::PURPOSE_PREFERENCES,
            InvitationRepository::PREFERENCES_TTL_SECONDS,
            $userId
        );

        $link = rtrim($this->config->string('SA_APP_URL'), '/')
            . '/soft-appeals-preferences.php?t=' . $invitation['token'];

        $body = $this->body(
            (string) $preview['recipient_name'],
            (string) $preview['organization'],
            (string) $engagement['fee_basis'],
            $engagement['assessment_window'] === null ? null : (string) $engagement['assessment_window'],
            $link,
            $this->clock->displayDateTime($invitation['expires_at'])
        );

        $result = $this->mail->send(
            $recipient,
            self::subjectFor((string) $preview['organization']),
            $body,
            self::TEMPLATE_KEY,
            $engagementId,
            $organizationId,
            $idempotencyKey
        );

        // The stage moves whether or not the mail server took it. The terms
        // were approved and issued; whether the message landed is a separate
        // fact, and it is recorded as its own state on the communication row.
        // Collapsing the two would mean a mail outage silently unwinding a
        // decision she actually made.
        $stage = (string) $engagement['stage'];
        if ($stage === Stage::TERMS_READY || $stage === Stage::TERMS_SENT) {
            $this->engagementService->move(
                $engagementId,
                Stage::TERMS_SENT,
                $sendSequence > 0
                    ? 'Your assessment terms were sent again, with a new link.'
                    : 'Your assessment terms were sent.',
                'terms.sent',
                $userId,
                ['template_key' => self::TEMPLATE_KEY, 'count' => $sendSequence + 1]
            );
        }

        $this->audit->record('terms.send', $result['sent'] ? 'success' : 'failure', 'engagement', $engagementId, [
            'communication_template' => self::TEMPLATE_KEY,
            'template_version'       => MailService::TEMPLATE_VERSION,
            'idempotency_key'        => $idempotencyKey,
            'count'                  => $invitation['revoked'],
            'reason'                 => $result['reason'],
        ], $organizationId);

        return [
            'state'      => $result['state'],
            'sent'       => $result['sent'],
            'reason'     => $result['reason'],
            'expires_at' => $invitation['expires_at'],
            'resent'     => $sendSequence > 0,

            // Production never sees this. The check is against the environment
            // itself rather than a feature flag, so there is no setting anybody
            // can switch that would start handing live tokens back to a page.
            'link'       => $this->config->isProduction() ? null : $link,
        ];
    }

    /**
     * The email itself, from section 13.1.
     *
     * Plain text, no HTML, nothing loaded from anywhere, which is the same
     * shape every other message this site sends. It carries no attachment, no
     * claim identifier, no secure-channel credential, and no answer the
     * practice typed into the form.
     */
    private function body(
        string $recipientName,
        string $organization,
        string $feeBasis,
        ?string $window,
        string $link,
        string $expiresDisplay
    ): string {
        $first = self::firstName($recipientName);
        $organization = $organization === '' ? 'your practice' : $organization;

        $lines = [];
        $room = rtrim($this->config->string('SA_APP_URL'), '/') . '/soft-appeals-room';
        $replyTo = $this->config->string('SA_MAIL_REPLY_TO');

        // Short lines are section labels; the HTML half sets them as
        // headlines. Paragraphs are one line each: the client wraps them,
        // and a hard wrap at 72 columns read as broken on a phone.
        $lines[] = 'Hello ' . ($first === '' ? 'there' : $first) . ',';
        $lines[] = '';
        $lines[] = 'Thank you for telling me about ' . $organization . "'s denials. "
            . 'The next step is a free review of 20 recent denied claims, and it starts once you answer eight short questions.';
        $lines[] = '';
        $lines[] = 'WHAT TO DO NOW';
        $lines[] = 'Open the link below and tap "Open the terms". Eight questions, about ten minutes: '
            . 'how often you want to hear from me, how you will send the denials, who signs for the practice, '
            . 'and who approves a submission later. Nothing about a patient.';
        $lines[] = '';
        $lines[] = $link;
        $lines[] = '';
        $lines[] = 'The link works once and stops working at ' . $expiresDisplay . '. '
            . 'After that, sign in at ' . $room . ' with this email address and a six-digit code.';
        $lines[] = '';
        $lines[] = 'WHAT HAPPENS AFTER THAT';
        $lines[] = '1. Two short agreements go to the person you name: a Business Associate Agreement and a review authorization. '
            . 'Nothing at patient level moves before both are signed.';
        $lines[] = '2. Your secure route opens and you send 20 recent denied claims through it.';
        $lines[] = '3. The review. It does not produce 20 finished appeals. It sorts every denial by what to do about it, '
            . 'what it is worth, which ones cannot wait, what information is missing, and who owns the next step.'
            . ($window !== null && trim($window) !== '' ? ' Timing: ' . trim($window) . '.' : '');
        $lines[] = '4. You decide. Keep the assessment for your own team, ask me questions, '
            . 'or ask Soft Appeals to pursue the claims worth pursuing.';
        $lines[] = '';
        $lines[] = 'WHAT IT COSTS';
        $lines[] = 'The assessment is free and yours to keep, whichever way you decide. '
            . EngagementTerms::feeSentence($feeBasis);
        $lines[] = '';
        $lines[] = 'Do not reply with claim or patient information. Replies go to ' . $replyTo . ', a mailbox I read myself.';
        $lines[] = '';
        $lines[] = 'Nana Frimpongmaa';
        $lines[] = 'Founder, Soft Appeals';

        return implode("\n", $lines) . "\n";
    }

    private static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        return (string) ($parts[0] ?? '');
    }
}
