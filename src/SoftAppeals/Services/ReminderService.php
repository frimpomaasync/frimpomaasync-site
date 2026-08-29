<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Config;
use SoftAppeals\Domain\ActionRequestKind;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Repositories\ActionRequestRepository;
use SoftAppeals\Repositories\ApprovalRequestRepository;
use SoftAppeals\Repositories\ContactRepository;
use SoftAppeals\Repositories\DocumentRepository;
use SoftAppeals\Repositories\EngagementRepository;
use SoftAppeals\Repositories\PreferenceRepository;
use SoftAppeals\Support\Clock;

/**
 * Reminders to a practice. Section 17.2: "send one reminder for due client
 * actions according to configured cadence". Section 16.2 templates 9
 * (recovery decision reminder) and 12 (approval reminder), and the generic
 * pattern for everything else.
 *
 * Three things are waiting on a practice at any moment: an action request
 * she opened for them, an approval request on a batch, and a document out
 * for signature. Each becomes eligible for a reminder three days before its
 * date, or seven days after it was asked when it carries no date. From then
 * on it is reminded ONCE PER CADENCE PERIOD, the cadence being the answer
 * the practice gave to question 1 of section 13.2: weekly, every two weeks,
 * monthly, or only at milestones, which means once and never again.
 *
 * "Reminders do not send twice" is a Phase 8 acceptance line and it rests
 * on one thing: the idempotency key. The key is the item, the kind, and the
 * period number, where the period number is how many whole cadence periods
 * have passed since the item became eligible. Two runs in the same period
 * compute the same key, the mail layer finds the row, and nothing goes out.
 * A run a period later computes the next key. There is no "last reminded"
 * column to forget to write.
 *
 * A reminder never carries a link that could be replayed. The signing link
 * and the room link were sent when the item was opened; a reminder says
 * where the room is and how to get back in. Nothing about the item itself,
 * and nothing about a patient, goes in the email.
 */
final class ReminderService
{
    public const TEMPLATE_DECISION  = 'recovery_decision_reminder';
    public const TEMPLATE_APPROVAL  = 'approval_reminder';
    public const TEMPLATE_REQUEST   = 'action_request_reminder';
    public const TEMPLATE_SIGNATURE = 'document_sign_reminder';

    /** Days before a dated item that the first reminder may go. */
    public const LEAD_DAYS = 3;

    /** Days after an undated item was asked that the first reminder may go. */
    public const UNDATED_AFTER_DAYS = 7;

    private Config $config;
    private Clock $clock;
    private ActionRequestRepository $requests;
    private ApprovalRequestRepository $approvals;
    private DocumentRepository $documents;
    private ContactRepository $contacts;
    private PreferenceRepository $preferences;
    private EngagementRepository $engagements;
    private MailService $mail;
    private AuditService $audit;

    public function __construct(
        Config $config,
        Clock $clock,
        ActionRequestRepository $requests,
        ApprovalRequestRepository $approvals,
        DocumentRepository $documents,
        ContactRepository $contacts,
        PreferenceRepository $preferences,
        EngagementRepository $engagements,
        MailService $mail,
        AuditService $audit
    ) {
        $this->config = $config;
        $this->clock = $clock;
        $this->requests = $requests;
        $this->approvals = $approvals;
        $this->documents = $documents;
        $this->contacts = $contacts;
        $this->preferences = $preferences;
        $this->engagements = $engagements;
        $this->mail = $mail;
        $this->audit = $audit;
    }

    /**
     * The period length, in days, for one cadence. Null means once only.
     */
    public static function periodDays(?string $cadence): ?int
    {
        return match ($cadence) {
            EngagementTerms::CADENCE_WEEKLY   => 7,
            EngagementTerms::CADENCE_BIWEEKLY => 14,
            EngagementTerms::CADENCE_MONTHLY  => 30,
            default                           => null,
        };
    }

    /**
     * Every reminder that is due right now, whether or not it has already
     * been sent this period. Pure: reads and computes, sends nothing.
     *
     * @return list<array{type:string,id:string,engagement_id:string,organization_id:string,template:string,key:string,period:int,due_at:?string,title:string,contact_id:?string}>
     */
    public function due(): array
    {
        $out = [];

        foreach ($this->requests->openForClientsEverywhere() as $row) {
            // An approval has its own card below, keyed on the approval
            // request, and the practice must not get two reminders for one
            // batch. The action request opened alongside it is skipped here.
            if ((string) $row['kind'] === ActionRequestKind::APPROVE_SUBMISSION) {
                continue;
            }
            $candidate = $this->candidate(
                'request',
                (string) $row['id'],
                (string) $row['engagement_id'],
                (string) $row['organization_id'],
                $row['due_at'] === null ? null : (string) $row['due_at'],
                (string) $row['created_at'],
                in_array((string) $row['kind'], [ActionRequestKind::REVIEW_ASSESSMENT, ActionRequestKind::CHOOSE_SCOPE], true)
                    ? self::TEMPLATE_DECISION
                    : self::TEMPLATE_REQUEST,
                ActionRequestKind::title((string) $row['kind']),
                $row['requested_from'] === null ? null : (string) $row['requested_from']
            );
            if ($candidate !== null) {
                $out[] = $candidate;
            }
        }

        foreach ($this->approvals->pendingEverywhere() as $row) {
            $candidate = $this->candidate(
                'approval',
                (string) $row['id'],
                (string) $row['engagement_id'],
                (string) $row['organization_id'],
                $row['due_at'] === null ? null : (string) $row['due_at'],
                (string) $row['created_at'],
                self::TEMPLATE_APPROVAL,
                'A submission waiting for your approval',
                $row['requested_from'] === null ? null : (string) $row['requested_from']
            );
            if ($candidate !== null) {
                $out[] = $candidate;
            }
        }

        foreach ($this->documents->outForSignature() as $row) {
            $candidate = $this->candidate(
                'document',
                (string) $row['id'],
                (string) $row['engagement_id'],
                (string) $row['organization_id'],
                null,
                (string) ($row['sent_at'] ?? $row['created_at']),
                self::TEMPLATE_SIGNATURE,
                DocumentKind::label((string) $row['kind']) . ' waiting for your signature',
                null
            );
            if ($candidate !== null) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * Send what is due and not yet sent this period.
     *
     * @return array{considered:int,sent:int,already:int,refused:int,skipped:int}
     */
    public function send(): array
    {
        $considered = 0;
        $sent = 0;
        $already = 0;
        $refused = 0;
        $skipped = 0;

        foreach ($this->due() as $item) {
            $considered++;
            $engagement = $this->engagements->findWithOrganization($item['engagement_id']);
            if ($engagement === null) {
                $skipped++;
                continue;
            }
            $contact = $this->recipient($item['engagement_id'], $item['contact_id']);
            if ($contact === null) {
                // Nobody named yet. Nothing to remind, and nothing to invent.
                $skipped++;
                continue;
            }

            $result = $this->mail->send(
                (string) $contact['work_email'],
                'A reminder from Soft Appeals',
                $this->body($item, $engagement, $contact),
                $item['template'],
                $item['engagement_id'],
                $item['organization_id'],
                $item['key']
            );

            if ($result['reason'] === 'already sent') {
                $already++;
                continue;
            }
            if ($result['sent']) {
                $sent++;
            } else {
                $refused++;
            }
            $this->audit->record('reminder.send', $result['sent'] ? 'success' : 'failure', $item['type'], $item['id'], [
                'communication_template' => $item['template'],
                'idempotency_key'        => $item['key'],
                'reason'                 => $result['reason'],
            ], $item['organization_id']);
        }

        return [
            'considered' => $considered,
            'sent'       => $sent,
            'already'    => $already,
            'refused'    => $refused,
            'skipped'    => $skipped,
        ];
    }

    /**
     * One item, judged. Null when it is not yet eligible, or when the
     * practice asked for milestones only and its one reminder has passed.
     *
     * @return array{type:string,id:string,engagement_id:string,organization_id:string,template:string,key:string,period:int,due_at:?string,title:string,contact_id:?string}|null
     */
    private function candidate(
        string $type,
        string $id,
        string $engagementId,
        string $organizationId,
        ?string $dueAt,
        string $createdAt,
        string $template,
        string $title,
        ?string $contactId
    ): ?array {
        $eligibleAt = $dueAt !== null
            ? $this->clock->parseUtc($dueAt)?->modify('-' . self::LEAD_DAYS . ' days')
            : $this->clock->parseUtc($createdAt)?->modify('+' . self::UNDATED_AFTER_DAYS . ' days');
        if ($eligibleAt === null || $eligibleAt > $this->clock->now()) {
            return null;
        }

        $preferences = $this->preferences->forEngagement($engagementId);
        $cadence = $preferences === null ? null : (string) $preferences['communication_cadence'];
        $periodDays = self::periodDays($cadence);

        $elapsedDays = intdiv($this->clock->now()->getTimestamp() - $eligibleAt->getTimestamp(), 86400);
        $period = $periodDays === null ? 0 : intdiv(max(0, $elapsedDays), $periodDays);

        return [
            'type'            => $type,
            'id'              => $id,
            'engagement_id'   => $engagementId,
            'organization_id' => $organizationId,
            'template'        => $template,
            'key'             => hash('sha256', 'reminder|' . $type . '|' . $id . '|' . $template . '|' . $period),
            'period'          => $period,
            'due_at'          => $dueAt,
            'title'           => $title,
            'contact_id'      => $contactId,
        ];
    }

    /**
     * Who gets it: the person the item was asked of, else the named signer.
     *
     * @return array<string,mixed>|null
     */
    private function recipient(string $engagementId, ?string $contactId): ?array
    {
        if ($contactId !== null) {
            $contact = $this->contacts->find($contactId);
            if ($contact !== null && (int) $contact['active'] === 1) {
                return $contact;
            }
        }
        $preferences = $this->preferences->forEngagement($engagementId);
        if ($preferences === null || $preferences['signer_contact_id'] === null) {
            return null;
        }
        $signer = $this->contacts->find((string) $preferences['signer_contact_id']);
        return $signer !== null && (int) $signer['active'] === 1 ? $signer : null;
    }

    /**
     * The generic pattern of section 16.2, worded as a reminder. No link
     * that could be replayed, no item detail, no patient.
     *
     * @param array{title:string,due_at:?string,template:string} $item
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $contact
     */
    private function body(array $item, array $engagement, array $contact): string
    {
        $room = rtrim($this->config->string('SA_APP_URL'), '/') . '/soft-appeals-room';
        $organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');

        $lines = [];
        $lines[] = 'Hello ' . self::firstName((string) $contact['name']) . ',';
        $lines[] = '';
        $lines[] = wordwrap(
            'A reminder: there is an item waiting for ' . $organization
            . ' in your Soft Appeals Recovery Room: ' . $item['title'] . '.',
            72,
            "\n",
            false
        );
        if ($item['due_at'] !== null) {
            $lines[] = '';
            $days = $this->clock->daysUntil($item['due_at']);
            $when = $this->clock->displayDate($item['due_at']);
            $lines[] = $days !== null && $days < 0
                ? 'It was asked for by ' . $when . '.'
                : 'It is asked for by ' . $when . '.';
        }
        $lines[] = '';
        $lines[] = 'Open the room: ' . $room;
        $lines[] = wordwrap(
            'Sign in with your work email and the six-digit code it sends you. '
            . 'If you were sent a signing link, use that link; this reminder does not carry one.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = wordwrap(
            'Do not reply with patient, member, claim, clinical, or other protected '
            . 'health information.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = 'Nana Frimpongmaa';
        $lines[] = 'Soft Appeals';

        return implode("\n", $lines) . "\n";
    }

    private static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        return $parts === [] || $parts[0] === '' ? 'there' : $parts[0];
    }
}
