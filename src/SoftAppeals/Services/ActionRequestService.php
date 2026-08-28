<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Config;
use SoftAppeals\Domain\ActionRequestKind;
use SoftAppeals\Domain\SafeText;
use SoftAppeals\Repositories\ActionRequestRepository;
use SoftAppeals\Repositories\ContactRepository;
use SoftAppeals\Repositories\PreferenceRepository;
use SoftAppeals\Support\Clock;

/**
 * Action requests, section 15.8: opening, closing, and telling the person.
 *
 * A request opened for the client is emailed as the generic notification of
 * section 16.2, template 11: there is a new item, here is the portal, do not
 * reply with patient information. The email never carries the request itself.
 *
 * Every free-text field is screened. The standing instructions come from
 * Domain\ActionRequestKind and cannot carry a patient; the per-request note
 * and the practice's question go through SafeText before they are stored.
 */
final class ActionRequestService
{
    public const TEMPLATE_AVAILABLE = 'action_request_available';

    private Config $config;
    private Clock $clock;
    private ActionRequestRepository $requests;
    private ContactRepository $contacts;
    private PreferenceRepository $preferences;
    private MailService $mail;
    private AuditService $audit;

    public function __construct(
        Config $config,
        Clock $clock,
        ActionRequestRepository $requests,
        ContactRepository $contacts,
        PreferenceRepository $preferences,
        MailService $mail,
        AuditService $audit
    ) {
        $this->config = $config;
        $this->clock = $clock;
        $this->requests = $requests;
        $this->contacts = $contacts;
        $this->preferences = $preferences;
        $this->mail = $mail;
        $this->audit = $audit;
    }

    /**
     * Open one request. A second open request of the same kind on the same
     * engagement is refused, because two cards asking for the same thing read
     * as a fault.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @return array<string,mixed> the request row
     */
    public function open(
        array $engagement,
        string $kind,
        ?string $note = null,
        ?string $dueAtUtc = null,
        ?string $userId = null,
        bool $notify = true
    ): array {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];

        if (!ActionRequestKind::isValid($kind)) {
            throw new \RuntimeException('There is no such action request.');
        }
        if ($this->requests->openOfKind($engagementId, $kind) !== null) {
            throw new \RuntimeException(
                '"' . ActionRequestKind::title($kind) . '" is already open on this engagement.'
            );
        }

        $cleanNote = $note === null ? null : SafeText::require($note, 1000, 'the note');

        $signer = $this->signerContact($engagementId);
        $requestedFrom = ActionRequestKind::owner($kind) === ActionRequestKind::OWNER_CLIENT && $signer !== null
            ? (string) $signer['id']
            : null;

        $row = $this->requests->open(
            $engagementId,
            $organizationId,
            $kind,
            $requestedFrom,
            $cleanNote,
            $dueAtUtc,
            $userId
        );

        $this->audit->record('action_request.open', 'success', 'action_request', (string) $row['id'], [
            'reason' => $kind,
        ], $organizationId);

        if ($notify && ActionRequestKind::owner($kind) === ActionRequestKind::OWNER_CLIENT && $signer !== null) {
            $this->notify($row, $engagement, $signer);
        }

        return $row;
    }

    /**
     * Close a request as done. $response is her answer when the request was
     * a question; it is screened and shown to the practice.
     *
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $request
     */
    public function complete(array $engagement, array $request, ?string $userId = null, ?string $response = null): void
    {
        $this->close($engagement, $request, ActionRequestKind::STATUS_DONE, $userId, $response);
    }

    /**
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $request
     */
    public function cancel(array $engagement, array $request, ?string $userId = null): void
    {
        $this->close($engagement, $request, ActionRequestKind::STATUS_CANCELLED, $userId, null);
    }

    /**
     * Close every open request of one kind. Used when the thing it asked for
     * has happened by another route, so the card does not outlive its reason.
     *
     * @param array<string,mixed> $engagement
     */
    public function closeKind(array $engagement, string $kind, ?string $userId = null): int
    {
        $closed = 0;
        $open = $this->requests->openOfKind((string) $engagement['id'], $kind);
        while ($open !== null) {
            $this->close($engagement, $open, ActionRequestKind::STATUS_DONE, $userId, null);
            $closed++;
            $open = $this->requests->openOfKind((string) $engagement['id'], $kind);
        }
        return $closed;
    }

    /**
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $request
     */
    private function close(array $engagement, array $request, string $status, ?string $userId, ?string $response): void
    {
        if ((string) $request['engagement_id'] !== (string) $engagement['id']) {
            throw new \RuntimeException('That request is not on this engagement.');
        }
        $cleanResponse = $response === null ? null : SafeText::require($response, 1000, 'the answer');

        if (!$this->requests->close((string) $request['id'], $status, $userId, $cleanResponse)) {
            throw new \RuntimeException('That request is not open.');
        }

        $this->audit->record('action_request.close', 'success', 'action_request', (string) $request['id'], [
            'reason' => $status,
        ], (string) $engagement['organization_id']);
    }

    /** @return array<string,mixed>|null the open request of one kind, if any */
    public function openOfKind(string $engagementId, string $kind): ?array
    {
        return $this->requests->openOfKind($engagementId, $kind);
    }

    /** @return array<string,mixed>|null the named authorized signer */
    public function signerContact(string $engagementId): ?array
    {
        $preferences = $this->preferences->forEngagement($engagementId);
        if ($preferences === null || $preferences['signer_contact_id'] === null) {
            return null;
        }
        return $this->contacts->find((string) $preferences['signer_contact_id']);
    }

    /**
     * Section 16.2, template 11. The generic pattern: a new item, the
     * portal, the warning. Nothing about the item itself.
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $contact
     */
    private function notify(array $request, array $engagement, array $contact): void
    {
        $room = rtrim($this->config->string('SA_APP_URL'), '/') . '/soft-appeals-room';
        $organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');

        $lines = [];
        $lines[] = 'Hello ' . self::firstName((string) $contact['name']) . ',';
        $lines[] = '';
        $lines[] = wordwrap(
            'There is a new item for ' . $organization . ' in your Soft Appeals Recovery Room: '
            . ActionRequestKind::title((string) $request['kind']) . '.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = 'Open the room: ' . $room;
        if ($request['due_at'] !== null) {
            $lines[] = '';
            $lines[] = 'It is asked for by ' . $this->clock->displayDate((string) $request['due_at']) . '.';
        }
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

        $this->mail->send(
            (string) $contact['work_email'],
            'A Soft Appeals portal update is ready',
            implode("\n", $lines) . "\n",
            self::TEMPLATE_AVAILABLE,
            (string) $engagement['id'],
            (string) $engagement['organization_id'],
            hash('sha256', (string) $request['id'] . '|' . self::TEMPLATE_AVAILABLE)
        );
    }

    private static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        return $parts === [] || $parts[0] === '' ? 'there' : $parts[0];
    }
}
