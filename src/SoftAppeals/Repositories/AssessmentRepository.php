<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Support\Uuid;

/**
 * One assessment per engagement. Section 7.2 as a row.
 *
 * The row is created the first time anything asks for it after the secure
 * route is open, and every milestone is a timestamp on it. Nothing here is a
 * claim: the counts are aggregates and the summary is business text.
 */
final class AssessmentRepository extends Repository
{
    /** The initial set size the assessment terms promise. Section 15.6. */
    public const DEFAULT_EXPECTED = 20;

    protected function table(): string
    {
        return 'sa_assessments';
    }

    /** @return array<string,mixed>|null */
    public function forEngagement(string $engagementId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_assessments WHERE engagement_id = :e',
            ['e' => $engagementId]
        );
    }

    /**
     * The row, created if it is not there. The unique constraint on
     * engagement_id makes a race between two requests harmless: the second
     * insert fails and the read below returns the first.
     *
     * @return array<string,mixed>
     */
    public function ensure(string $engagementId, string $organizationId): array
    {
        $existing = $this->forEngagement($engagementId);
        if ($existing !== null) {
            return $existing;
        }
        $now = $this->clock->nowUtc();
        try {
            $this->db->insert('sa_assessments', [
                'id'              => Uuid::v4(),
                'engagement_id'   => $engagementId,
                'organization_id' => $organizationId,
                'expected_count'  => self::DEFAULT_EXPECTED,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        } catch (\PDOException) {
            // Somebody else got there first. Fall through to the read.
        }
        $row = $this->forEngagement($engagementId);
        if ($row === null) {
            throw new \RuntimeException('The assessment row could not be created.');
        }
        return $row;
    }

    /** @param array<string,mixed> $changes */
    public function patch(string $assessmentId, array $changes): void
    {
        $changes['updated_at'] = $this->clock->nowUtc();
        $this->db->update('sa_assessments', $changes, ['id' => $assessmentId]);
    }

    /**
     * Every assessment with its engagement and organization, for the Desk
     * list. Open engagements only.
     *
     * @return list<array<string,mixed>>
     */
    public function withEngagements(): array
    {
        return $this->db->all(
            'SELECT a.*, e.public_ref AS engagement_ref, e.stage, e.client_decision_due_at,'
            . ' o.legal_name, o.display_name'
            . ' FROM sa_assessments a'
            . ' JOIN sa_engagements e ON e.id = a.engagement_id'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' WHERE e.closed_at IS NULL'
            . ' ORDER BY a.updated_at DESC'
        );
    }
}
