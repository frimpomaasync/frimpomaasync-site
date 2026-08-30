<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Config;
use SoftAppeals\Repositories\IntakeRepository;

/**
 * The same-day fit reply. Drafted by the machine, sent by her, in one tap.
 *
 * A practice manager who forwards a denial letter at nine hears something
 * human before lunch or concludes nobody is home. This class writes the
 * something: three ready drafts per new inquiry, built from what the inquiry
 * actually says. Accept, decline, or one question. She reads one, edits it
 * or not, and presses Send. Nothing here sends on its own, and sending a
 * reply decides nothing: the fit review in section 12.5 is still the only
 * place a decision is made, and the inquiry stays on the board until she
 * makes it there.
 *
 * The drafts follow the same lines the confirmation emails already hold:
 * no promised outcome, no dressed-up answer, and the PHI rule stated before
 * a reply can arrive carrying a denial letter.
 */
final class FitReplyService
{
    public const KIND_ACCEPT   = 'accept';
    public const KIND_DECLINE  = 'decline';
    public const KIND_QUESTION = 'question';

    private Config $config;
    private IntakeRepository $intakes;
    private MailService $mail;
    private AuditService $audit;

    public function __construct(
        Config $config,
        IntakeRepository $intakes,
        MailService $mail,
        AuditService $audit
    ) {
        $this->config = $config;
        $this->intakes = $intakes;
        $this->mail = $mail;
        $this->audit = $audit;
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::KIND_ACCEPT, self::KIND_DECLINE, self::KIND_QUESTION];
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_ACCEPT   => 'It fits, terms follow',
            self::KIND_DECLINE  => 'Not a fit, said kindly',
            self::KIND_QUESTION => 'One question first',
            default             => $kind,
        };
    }

    /**
     * The three drafts for one inquiry row, built from its own answers and
     * nothing else. A fact the form did not ask for is not invented; the
     * question draft exists precisely to go get the missing one.
     *
     * @param array<string,mixed> $intake a row from sa_intakes
     * @return array<string,array{subject:string,body:string}>
     */
    public function drafts(array $intake): array
    {
        $organization = trim((string) ($intake['organization_name'] ?? ''));
        $named = $organization !== '' && strcasecmp($organization, 'Not given') !== 0;
        $org = $named ? $organization : 'your practice';

        $first = trim(explode(' ', trim((string) ($intake['contact_name'] ?? '')))[0] ?? '');
        $hello = $first === '' ? 'Hello,' : 'Hello ' . $first . ',';

        $volume = trim((string) ($intake['denial_volume_band'] ?? ''));
        $value = trim((string) ($intake['denied_value_band'] ?? ''));
        $state = trim((string) ($intake['state'] ?? ''));

        $signature = "Nana Frimpongmaa\nfrimpomaasync.com/soft-appeals";

        $volumeLine = '';
        if ($volume !== '') {
            $volumeLine = 'You listed ' . $volume . ' unresolved denials'
                . ($value !== '' ? ', worth ' . $value : '') . ".\n\n";
        }

        $accept = $hello . "\n\n"
            . 'I read your inquiry. ' . ($named ? $org : 'This') . " looks like work I take on.\n\n"
            . $volumeLine
            . "The next email from me lays out the assessment terms: what I look at, what it costs, and what happens after. Nothing starts and nothing is owed until you have read them and said yes.\n\n"
            . "One rule starts now: keep patient information out of email, including a reply to this one. No records, no denial letters, no explanation of benefits. The secure way to send claim information is its own step, after the paperwork that has to come first.\n\n"
            . "If you have a question before the terms arrive, reply here.\n\n"
            . $signature;

        $decline = $hello . "\n\n"
            . 'Thank you for writing about ' . $org . ". I read your inquiry carefully, and this is not work I can take on right now.\n\n"
            . "That is about my capacity and scope. It says nothing about whether your denials can be recovered; many can. Your state medical society and your billing company both know appeal-support services worth a call.\n\n"
            . "If your situation changes shape, write again. This inbox stays open.\n\n"
            . $signature;

        // The one missing fact that most changes the fit answer, in order:
        // how much is actually sitting there, then where, then with whom.
        if ($volume === '') {
            $question = 'Roughly how many denied claims are sitting unresolved right now, and how old are the oldest? Counts and rough ages are enough.';
        } elseif ($state === '') {
            $question = 'Which state does ' . $org . ' bill in? Appeal deadlines and external review rules differ by state, and the answer changes what is still recoverable.';
        } else {
            $question = 'How are denials handled today: someone in-house, your billing company, or nobody\'s desk in particular?';
        }

        $ask = $hello . "\n\n"
            . 'Your inquiry about ' . $org . " is in front of me. Before I can tell you honestly whether this is a fit, I need one thing:\n\n"
            . $question . "\n\n"
            . "Reply with that and you get a straight answer the same day I read it.\n\n"
            . "Keep patient information out of email. No records, no claim numbers, no denial letters.\n\n"
            . $signature;

        $subjectOrg = $named ? $org : 'your denied claims';
        return [
            self::KIND_ACCEPT => [
                'subject' => $subjectOrg . ': this looks like a fit',
                'body'    => $accept,
            ],
            self::KIND_DECLINE => [
                'subject' => 'Your inquiry about ' . $subjectOrg,
                'body'    => $decline,
            ],
            self::KIND_QUESTION => [
                'subject' => 'One question before I can answer about ' . $subjectOrg,
                'body'    => $ask,
            ],
        ];
    }

    /**
     * Send the reply she chose, as she left it.
     *
     * Idempotent on the exact text: the double click that sends the same
     * words twice finds the first row and sends nothing, while an edited
     * body is a new message and goes. The inquiry's status does not move.
     * A reply is a courtesy; the decision is still the fit review.
     *
     * @return array{state:string,communication_id:string,sent:bool,reason:string}
     */
    public function send(string $intakeId, string $kind, string $subject, string $body, ?string $userId): array
    {
        if (!in_array($kind, self::kinds(), true)) {
            throw new \RuntimeException('Unknown reply kind: ' . $kind);
        }
        $subject = mb_substr(trim(preg_replace('/[\r\n\x00-\x1F\x7F]/', ' ', $subject) ?? ''), 0, 200);
        $body = mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $body) ?? ''), 0, 8000);
        if ($subject === '' || $body === '') {
            throw new \RuntimeException('A reply needs both a subject and a body.');
        }

        $intake = $this->intakes->find($intakeId);
        if ($intake === null) {
            throw new \RuntimeException('No such inquiry.');
        }
        $to = trim((string) $intake['contact_email']);
        if ($to === '') {
            throw new \RuntimeException('That inquiry has no email address to answer.');
        }

        $result = $this->mail->send(
            $to,
            $subject,
            $body,
            'fit_reply_' . $kind,
            null,
            $intake['organization_id'] === null ? null : (string) $intake['organization_id'],
            'fit-reply:' . $intakeId . ':' . hash('sha256', $kind . "\n" . $subject . "\n" . $body)
        );

        $this->audit->record(
            'intake.reply',
            $result['sent'] ? 'success' : 'failure',
            'intake',
            $intakeId,
            ['reason' => $kind . ': ' . $result['reason']],
            $intake['organization_id'] === null ? null : (string) $intake['organization_id']
        );

        return $result;
    }
}
