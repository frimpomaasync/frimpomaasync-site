<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Domain\Role;

/**
 * Which roles a user holds, and where.
 *
 * A staff membership has organization_id NULL and applies everywhere. A client
 * membership names one organization and applies only there. A user may hold
 * several roles, which is why this returns a list rather than a single value.
 */
final class MembershipRepository extends Repository
{
    /** Stands in for "no organization" so the unique index can be NOT NULL. */
    public const GLOBAL_SCOPE = 'GLOBAL';


    protected function table(): string
    {
        return 'sa_memberships';
    }

    /**
     * @param string|null $organizationId null means the global, staff-side rows
     * @return list<string>
     */
    public function rolesFor(string $userId, ?string $organizationId = null): array
    {
        if ($userId === '') {
            return [];
        }
        $rows = $organizationId === null
            ? $this->db->all(
                'SELECT role FROM sa_memberships WHERE user_id = :u AND organization_id IS NULL',
                ['u' => $userId]
            )
            : $this->db->all(
                'SELECT role FROM sa_memberships WHERE user_id = :u AND organization_id = :o',
                ['u' => $userId, 'o' => $organizationId]
            );

        $out = [];
        foreach ($rows as $row) {
            $role = (string) $row['role'];
            // An unrecognised role grants nothing. A row left behind by a
            // removed role must not silently keep working.
            if (Role::isValid($role)) {
                $out[] = $role;
            }
        }
        return $out;
    }

    public function hasAnyStaffRole(string $userId): bool
    {
        foreach ($this->rolesFor($userId, null) as $role) {
            if (Role::isStaff($role)) {
                return true;
            }
        }
        return false;
    }

    public function grant(string $userId, string $role, ?string $organizationId = null): void
    {
        if (!Role::isValid($role)) {
            throw new \RuntimeException('Unknown role: ' . $role);
        }
        if (Role::isStaff($role) && $organizationId !== null) {
            throw new \RuntimeException('A staff role is global and must not name an organization.');
        }
        if (!Role::isStaff($role) && $organizationId === null) {
            throw new \RuntimeException('A client role must name an organization.');
        }
        if ($this->has($userId, $role, $organizationId)) {
            return;
        }
        $this->db->insert('sa_memberships', [
            'user_id'            => $userId,
            'organization_id'    => $organizationId,
            // The uniqueness key. NULL never equals NULL, so a nullable column
            // cannot carry a unique constraint that means anything; this one is
            // NOT NULL and holds the sentinel for a staff row. A UUIDv4 always
            // carries hyphens in fixed positions, so GLOBAL can never collide.
            'organization_scope' => $organizationId ?? self::GLOBAL_SCOPE,
            'role'               => $role,
            'created_at'         => $this->clock->nowUtc(),
        ]);
    }

    public function revoke(string $userId, string $role, ?string $organizationId = null): void
    {
        $sql = $organizationId === null
            ? 'DELETE FROM sa_memberships WHERE user_id = :u AND role = :r AND organization_id IS NULL'
            : 'DELETE FROM sa_memberships WHERE user_id = :u AND role = :r AND organization_id = :o';
        $params = ['u' => $userId, 'r' => $role];
        if ($organizationId !== null) {
            $params['o'] = $organizationId;
        }
        $this->db->run($sql, $params);
    }

    /** Every role for a user, in every organization. For a closeout access review. */
    public function revokeAllForUser(string $userId): int
    {
        return $this->db->run('DELETE FROM sa_memberships WHERE user_id = :u', ['u' => $userId])->rowCount();
    }

    public function has(string $userId, string $role, ?string $organizationId = null): bool
    {
        return in_array($role, $this->rolesFor($userId, $organizationId), true);
    }
}
