<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Domain\CloseoutStep;
use SoftAppeals\Support\Uuid;

/**
 * The closeout record, its four steps, and the access review. Section 7.4
 * and section 15.10.
 *
 * One closeout per engagement. The steps are seeded when it opens and each
 * is written exactly once, with who confirmed it. The access review rows are
 * a snapshot of who could sign in at the practice when closeout began, with
 * the decision taken on each.
 */
final class CloseoutRepository extends Repository
{
    protected function table(): string
    {
        return 'sa_closeouts';
    }

    /** @return array<string,mixed>|null */
    public function forEngagement(string $engagementId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_closeouts WHERE engagement_id = :e',
            ['e' => $engagementId]
        );
    }

    /** @return array<string,mixed> the row as inserted, with its four steps seeded */
    public function open(string $engagementId, string $organizationId, ?string $userId = null): array
    {
        $existing = $this->forEngagement($engagementId);
        if ($existing !== null) {
            return $existing;
        }
        $id = Uuid::v4();
        $now = $this->clock->nowUtc();
        $this->db->insert('sa_closeouts', [
            'id'                 => $id,
            'engagement_id'      => $engagementId,
            'organization_id'    => $organizationId,
            'started_at'         => $now,
            'started_by'         => $userId,
            'final_summary'      => null,
            'access_outcome'     => null,
            'data_disposition'   => null,
            'disposition_note'   => null,
            'record_document_id' => null,
            'closed_at'          => null,
            'closed_by'          => null,
            'created_at'         => $now,
            'updated_at'         => $now,
            'row_version'        => 1,
        ]);
        foreach (CloseoutStep::all() as $order => $step) {
            $this->db->insert('sa_closeout_steps', [
                'closeout_id'   => $id,
                'step_key'      => $step,
                'display_order' => $order + 1,
                'confirmed_at'  => null,
                'confirmed_by'  => null,
                'note'          => null,
                'created_at'    => $now,
            ]);
        }
        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('The closeout was written and could not be read back.');
        }
        return $row;
    }

    /**
     * Write the closeout's own columns, version-guarded.
     *
     * @param array<string,mixed> $changes
     */
    public function patch(string $closeoutId, array $changes, int $expectedVersion): bool
    {
        $changes['updated_at'] = $this->clock->nowUtc();
        $changes['row_version'] = $expectedVersion + 1;
        return $this->db->update(
            'sa_closeouts',
            $changes,
            ['id' => $closeoutId, 'row_version' => $expectedVersion]
        ) === 1;
    }

    /** @return list<array<string,mixed>> in display order */
    public function steps(string $closeoutId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_closeout_steps WHERE closeout_id = :c ORDER BY display_order ASC',
            ['c' => $closeoutId]
        );
    }

    /** @return array<string,mixed>|null */
    public function step(string $closeoutId, string $step): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_closeout_steps WHERE closeout_id = :c AND step_key = :k',
            ['c' => $closeoutId, 'k' => $step]
        );
    }

    /**
     * Confirm one step. Exactly once: the WHERE names an unconfirmed row.
     */
    public function confirmStep(string $closeoutId, string $step, ?string $userId, ?string $note): bool
    {
        if (!CloseoutStep::isValid($step)) {
            throw new \RuntimeException('Unknown closeout step: ' . $step);
        }
        return $this->db->run(
            'UPDATE sa_closeout_steps SET confirmed_at = :now, confirmed_by = :by, note = :note'
            . ' WHERE closeout_id = :c AND step_key = :k AND confirmed_at IS NULL',
            [
                'now'  => $this->clock->nowUtc(),
                'by'   => $userId,
                'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 2000),
                'c'    => $closeoutId,
                'k'    => $step,
            ]
        )->rowCount() === 1;
    }

    // ------------------------------------------------------------------
    // The access review.
    // ------------------------------------------------------------------

    /**
     * Add a person to the review if they are not on it. Idempotent, so the
     * review can be brought up to date on every read: somebody granted a
     * role after closeout began still has to be decided on.
     *
     * @param list<string> $roles
     */
    public function addAccessRow(string $closeoutId, string $userId, string $email, ?string $contactName, array $roles): void
    {
        if ($this->db->exists(
            'SELECT id FROM sa_access_reviews WHERE closeout_id = :c AND user_id = :u',
            ['c' => $closeoutId, 'u' => $userId]
        )) {
            return;
        }
        $this->db->insert('sa_access_reviews', [
            'id'           => Uuid::v4(),
            'closeout_id'  => $closeoutId,
            'user_id'      => $userId,
            'email'        => mb_substr(strtolower(trim($email)), 0, 200),
            'contact_name' => $contactName === null || trim($contactName) === '' ? null : mb_substr(trim($contactName), 0, 160),
            'roles'        => mb_substr(implode(',', $roles), 0, 200),
            'decision'     => null,
            'decided_at'   => null,
            'decided_by'   => null,
            'created_at'   => $this->clock->nowUtc(),
        ]);
    }

    /** @return list<array<string,mixed>> oldest first */
    public function accessRows(string $closeoutId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_access_reviews WHERE closeout_id = :c ORDER BY created_at ASC, email ASC',
            ['c' => $closeoutId]
        );
    }

    /** @return array<string,mixed>|null found through the closeout, never alone */
    public function accessRow(string $closeoutId, string $rowId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_access_reviews WHERE closeout_id = :c AND id = :i',
            ['c' => $closeoutId, 'i' => $rowId]
        );
    }

    /** Decide one row. Exactly once. */
    public function decideAccess(string $rowId, string $decision, ?string $userId): bool
    {
        if (!CloseoutStep::isValidAccessDecision($decision)) {
            throw new \RuntimeException('Unknown access decision: ' . $decision);
        }
        return $this->db->run(
            'UPDATE sa_access_reviews SET decision = :d, decided_at = :now, decided_by = :by'
            . ' WHERE id = :i AND decision IS NULL',
            ['d' => $decision, 'now' => $this->clock->nowUtc(), 'by' => $userId, 'i' => $rowId]
        )->rowCount() === 1;
    }

    public function undecidedAccessCount(string $closeoutId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM sa_access_reviews WHERE closeout_id = :c AND decision IS NULL',
            ['c' => $closeoutId]
        );
    }

    /**
     * Every engagement in a closeout stage, with its organization. The Desk
     * list and the rail count.
     *
     * @return list<array<string,mixed>>
     */
    public function engagementsInCloseout(): array
    {
        return $this->db->all(
            'SELECT e.*, o.legal_name, o.display_name, c.started_at, c.closed_at AS closeout_closed_at'
            . ' FROM sa_closeouts c'
            . ' JOIN sa_engagements e ON e.id = c.engagement_id'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' ORDER BY c.closed_at IS NULL DESC, c.started_at ASC'
        );
    }
}
