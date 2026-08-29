<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Support\Uuid;

/**
 * The eight answers, one row per engagement.
 *
 * The uniqueness is in the schema, not in an if statement here: engagement_id
 * carries a UNIQUE constraint, so a second set of preferences for the same
 * engagement is refused by the database whatever code tries to write it. This
 * class saves into that shape, which means a second submit updates rather than
 * inserts.
 *
 * confirmed_at is written once and never rewritten. It is the fact the stage
 * move hangs off, and the whole of "preferences update the engagement state
 * once" reduces to: the row can be edited, the confirmation cannot be re-taken.
 */
final class PreferenceRepository extends Repository
{
    protected function table(): string
    {
        return 'sa_engagement_preferences';
    }

    /** @return array<string,mixed>|null */
    public function forEngagement(string $engagementId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_engagement_preferences WHERE engagement_id = :e',
            ['e' => $engagementId]
        );
    }

    public function isConfirmed(string $engagementId): bool
    {
        $row = $this->forEngagement($engagementId);
        return $row !== null && $row['confirmed_at'] !== null;
    }

    /**
     * Write the answers.
     *
     * $answers holds only the columns this table owns; the caller has already
     * validated every one of them against Domain\PreferenceForm and turned the
     * three named people into contact ids.
     *
     * The confirmation stamp is set on the first save and left alone on any
     * later one, so a practice that goes back and changes its cadence does not
     * re-confirm and does not move the engagement a second time.
     *
     * @param array<string,mixed> $answers
     * @return array{id:string,created:bool,first_confirmation:bool}
     */
    public function save(
        string $engagementId,
        string $organizationId,
        array $answers,
        ?string $confirmedByContactId
    ): array {
        $now = $this->clock->nowUtc();
        $existing = $this->forEngagement($engagementId);

        $columns = [
            'communication_cadence',
            'secure_channel',
            'billing_partner',
            'signer_contact_id',
            'approver_contact_id',
            'billing_contact_id',
            'compliance_contact_id',
            'initial_payer_group',
            'procurement_notes',
        ];

        $values = [];
        foreach ($columns as $column) {
            $values[$column] = $answers[$column] ?? null;
        }

        if ($existing !== null) {
            $firstConfirmation = $existing['confirmed_at'] === null;
            $values['updated_at'] = $now;
            if ($firstConfirmation) {
                $values['confirmed_at'] = $now;
                $values['confirmed_by_contact_id'] = $confirmedByContactId;
            }
            $this->db->update('sa_engagement_preferences', $values, ['id' => (string) $existing['id']]);
            return [
                'id'                 => (string) $existing['id'],
                'created'            => false,
                'first_confirmation' => $firstConfirmation,
            ];
        }

        $id = Uuid::v4();
        $this->db->insert('sa_engagement_preferences', $values + [
            'id'                      => $id,
            'engagement_id'           => $engagementId,
            'organization_id'         => $organizationId,
            'confirmed_at'            => $now,
            'confirmed_by_contact_id' => $confirmedByContactId,
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);

        return ['id' => $id, 'created' => true, 'first_confirmation' => true];
    }
}
