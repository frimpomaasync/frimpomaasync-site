<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Support\Uuid;

/**
 * Organizations, which in her world means practices.
 *
 * Everything on this table is business-level: a legal name, a type, a state, a
 * status. There is no column here that could hold a patient, a claim, or a date
 * of service, which is ADR-009 enforced by the schema rather than by a promise.
 */
final class OrganizationRepository extends Repository
{
    public const STATUS_PROSPECT = 'prospect';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_CLOSED   = 'closed';

    protected function table(): string
    {
        return 'sa_organizations';
    }

    public function create(
        string $legalName,
        ?string $displayName = null,
        ?string $organizationType = null,
        ?string $state = null,
        string $status = self::STATUS_PROSPECT
    ): string {
        $id = Uuid::v4();
        $now = $this->clock->nowUtc();
        $this->db->insert('sa_organizations', [
            'id'                => $id,
            'public_ref'        => $this->uniquePublicRef(),
            'legal_name'        => $legalName,
            'display_name'      => $displayName,
            'organization_type' => $organizationType,
            'state'             => $state === null ? null : strtoupper(substr($state, 0, 2)),
            'status'            => $status,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        return $id;
    }

    public function setStatus(string $organizationId, string $status): void
    {
        $this->db->update(
            'sa_organizations',
            ['status' => $status, 'updated_at' => $this->clock->nowUtc()],
            ['id' => $organizationId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function allByStatus(string $status): array
    {
        return $this->db->all(
            'SELECT * FROM sa_organizations WHERE status = :s ORDER BY legal_name',
            ['s' => $status]
        );
    }

    /**
     * A public reference nobody else holds. Six characters from a 30-symbol
     * alphabet is 729 million combinations, so a collision is vanishingly
     * unlikely, but the loop makes it impossible rather than unlikely.
     */
    private function uniquePublicRef(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $ref = Uuid::publicRef('ORG');
            if (!$this->db->exists('SELECT id FROM sa_organizations WHERE public_ref = :r', ['r' => $ref])) {
                return $ref;
            }
        }
        throw new \RuntimeException('Could not mint a unique organization reference.');
    }
}
