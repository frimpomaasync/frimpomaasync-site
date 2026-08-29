<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Domain\ActionRequestKind;
use SoftAppeals\Support\Uuid;

/**
 * Action requests, section 15.8.
 */
final class ActionRequestRepository extends Repository
{
    protected function table(): string
    {
        return 'sa_action_requests';
    }

    /**
     * @return array<string,mixed> the row as inserted
     */
    public function open(
        string $engagementId,
        string $organizationId,
        string $kind,
        ?string $requestedFrom = null,
        ?string $note = null,
        ?string $dueAtUtc = null,
        ?string $userId = null
    ): array {
        if (!ActionRequestKind::isValid($kind)) {
            throw new \RuntimeException('Unknown action request kind: ' . $kind);
        }
        $id = Uuid::v4();
        $now = $this->clock->nowUtc();
        $this->db->insert('sa_action_requests', [
            'id'              => $id,
            'public_ref'      => $this->uniquePublicRef(),
            'engagement_id'   => $engagementId,
            'organization_id' => $organizationId,
            'kind'            => $kind,
            'owner'           => ActionRequestKind::owner($kind),
            'requested_from'  => $requestedFrom,
            'note'            => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 1000),
            'response'        => null,
            'due_at'          => $dueAtUtc,
            'status'          => ActionRequestKind::STATUS_OPEN,
            'completed_at'    => null,
            'completed_by'    => null,
            'created_by'      => $userId,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('The request was written and could not be read back.');
        }
        return $row;
    }

    /**
     * Close one request. $status is done, cancelled or expired.
     *
     * @return bool false when it was not open
     */
    public function close(string $requestId, string $status, ?string $userId = null, ?string $response = null): bool
    {
        if (!ActionRequestKind::isValidStatus($status) || $status === ActionRequestKind::STATUS_OPEN) {
            throw new \RuntimeException('A request closes as done, cancelled or expired.');
        }
        $changes = [
            'status'       => $status,
            'completed_at' => $this->clock->nowUtc(),
            'completed_by' => $userId,
            'updated_at'   => $this->clock->nowUtc(),
        ];
        if ($response !== null && trim($response) !== '') {
            $changes['response'] = mb_substr(trim($response), 0, 1000);
        }
        $count = $this->db->run(
            'UPDATE sa_action_requests SET status = :status, completed_at = :completed_at,'
            . ' completed_by = :completed_by, updated_at = :updated_at'
            . (isset($changes['response']) ? ', response = :response' : '')
            . ' WHERE id = :id AND status = :open',
            $changes + ['id' => $requestId, 'open' => ActionRequestKind::STATUS_OPEN]
        )->rowCount();
        return $count === 1;
    }

    /** @return list<array<string,mixed>> open first, then newest */
    public function forEngagement(string $engagementId, ?string $owner = null): array
    {
        $sql = 'SELECT * FROM sa_action_requests WHERE engagement_id = :e';
        $params = ['e' => $engagementId];
        if ($owner !== null) {
            $sql .= ' AND owner = :o';
            $params['o'] = $owner;
        }
        $sql .= ' ORDER BY CASE WHEN status = \'open\' THEN 0 ELSE 1 END ASC, created_at DESC';
        return $this->db->all($sql, $params);
    }

    /** @return list<array<string,mixed>> */
    public function openForEngagement(string $engagementId, ?string $owner = null): array
    {
        return array_values(array_filter(
            $this->forEngagement($engagementId, $owner),
            static fn (array $row): bool => (string) $row['status'] === ActionRequestKind::STATUS_OPEN
        ));
    }

    /** @return array<string,mixed>|null the open request of one kind, if any */
    public function openOfKind(string $engagementId, string $kind): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_action_requests WHERE engagement_id = :e AND kind = :k AND status = :s'
            . ' ORDER BY created_at DESC',
            ['e' => $engagementId, 'k' => $kind, 's' => ActionRequestKind::STATUS_OPEN]
        );
    }

    /** @return array<string,mixed>|null found through the engagement, never alone */
    public function findForEngagement(string $ref, string $engagementId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_action_requests WHERE public_ref = :r AND engagement_id = :e',
            ['r' => $ref, 'e' => $engagementId]
        );
    }

    /**
     * Everything open that is waiting on her, across every practice, with the
     * organization joined on. The Desk's "needs you" source.
     *
     * @return list<array<string,mixed>>
     */
    public function openForSoftAppeals(): array
    {
        return $this->db->all(
            'SELECT r.*, e.public_ref AS engagement_ref, o.legal_name, o.display_name'
            . ' FROM sa_action_requests r'
            . ' JOIN sa_engagements e ON e.id = r.engagement_id'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' WHERE r.owner = :o AND r.status = :s'
            . ' ORDER BY r.created_at ASC',
            ['o' => ActionRequestKind::OWNER_SOFT_APPEALS, 's' => ActionRequestKind::STATUS_OPEN]
        );
    }

    /**
     * Everything open that is waiting on a PRACTICE, across every open
     * engagement, with the organization joined on. The reminder job's source.
     *
     * @return list<array<string,mixed>>
     */
    public function openForClientsEverywhere(): array
    {
        return $this->db->all(
            'SELECT r.*, e.public_ref AS engagement_ref, o.legal_name, o.display_name'
            . ' FROM sa_action_requests r'
            . ' JOIN sa_engagements e ON e.id = r.engagement_id'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' WHERE r.owner = :o AND r.status = :s AND e.closed_at IS NULL'
            . ' ORDER BY r.created_at ASC',
            ['o' => ActionRequestKind::OWNER_CLIENT, 's' => ActionRequestKind::STATUS_OPEN]
        );
    }

    /**
     * Open requests owned by Soft Appeals whose date has passed. Section
     * 17.2: "surface overdue internal tasks".
     *
     * @return list<array<string,mixed>>
     */
    public function overdueForSoftAppeals(): array
    {
        return $this->db->all(
            'SELECT r.*, e.public_ref AS engagement_ref, o.legal_name, o.display_name'
            . ' FROM sa_action_requests r'
            . ' JOIN sa_engagements e ON e.id = r.engagement_id'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' WHERE r.owner = :o AND r.status = :s AND r.due_at IS NOT NULL AND r.due_at < :now'
            . ' AND e.closed_at IS NULL'
            . ' ORDER BY r.due_at ASC',
            ['o' => ActionRequestKind::OWNER_SOFT_APPEALS, 's' => ActionRequestKind::STATUS_OPEN, 'now' => $this->clock->nowUtc()]
        );
    }

    private function uniquePublicRef(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $ref = Uuid::publicRef('REQ');
            if (!$this->db->exists('SELECT id FROM sa_action_requests WHERE public_ref = :r', ['r' => $ref])) {
                return $ref;
            }
        }
        throw new \RuntimeException('Could not mint a unique request reference.');
    }
}
