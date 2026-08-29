<?php
declare(strict_types=1);

namespace SoftAppeals\Auth;

use SoftAppeals\Domain\Permission;
use SoftAppeals\Domain\Role;
use SoftAppeals\Repositories\MembershipRepository;
use SoftAppeals\Security\AuthorizationException;
use SoftAppeals\Services\AuditService;

/**
 * Who may do what, checked on the server, on every action.
 *
 * Two questions are always asked, never one:
 *
 *   1. does this user hold a role that carries this permission
 *   2. is this record inside the organization this session belongs to
 *
 * The second is the tenancy check. Without it a client user with a legitimate
 * session could read another practice's Recovery Room by changing an id, and
 * that is the failure that matters most in a system where the organizations are
 * competitors in the same state.
 *
 * A refusal is recorded and then answered with a 404. Section 10.1 permits the
 * generic 404, and it is the right choice: a 403 tells the caller the page is
 * real.
 */
final class AuthorizationService
{
    private SessionManager $session;
    private MembershipRepository $memberships;
    private AuditService $audit;

    public function __construct(
        SessionManager $session,
        MembershipRepository $memberships,
        AuditService $audit
    ) {
        $this->session = $session;
        $this->memberships = $memberships;
        $this->audit = $audit;
    }

    /**
     * @return list<string> the roles the signed-in user holds, optionally
     *         narrowed to one organization
     */
    public function roles(?string $organizationId = null): array
    {
        $userId = $this->session->userId();
        if ($userId === null) {
            return [];
        }
        return $this->memberships->rolesFor($userId, $organizationId);
    }

    public function can(string $permission, ?string $organizationId = null): bool
    {
        if (!Permission::isValid($permission)) {
            // An unknown permission is a programming mistake, and the safe
            // answer to a programming mistake is no.
            return false;
        }
        if (!$this->session->isAuthenticated()) {
            return false;
        }

        $holders = Permission::map()[$permission];

        // Staff roles are global: an owner admin has no organization on their
        // membership row and reaches every organization by design.
        foreach ($this->memberships->rolesFor($this->session->userId() ?? '', null) as $role) {
            if (Role::isStaff($role) && in_array($role, $holders, true)) {
                return true;
            }
        }

        if ($organizationId === null) {
            return false;
        }

        // Client roles are scoped. The session's organization must match the
        // record being reached, and the role must be held in that organization.
        if ($this->session->organizationId() !== $organizationId) {
            return false;
        }
        foreach ($this->memberships->rolesFor($this->session->userId() ?? '', $organizationId) as $role) {
            if (in_array($role, $holders, true)) {
                return true;
            }
        }

        return false;
    }

    /** Throw unless the caller holds the permission. */
    public function require(string $permission, ?string $organizationId = null): void
    {
        if ($this->can($permission, $organizationId)) {
            return;
        }
        $this->audit->record(
            'authz.denied',
            'failure',
            'permission',
            null,
            ['permission' => $permission],
            $organizationId
        );
        throw new AuthorizationException($permission);
    }

    /**
     * Tenancy on its own, for a read that has already passed a permission
     * check but must still be pinned to one organization.
     */
    public function requireSameOrganization(string $organizationId): void
    {
        $userId = $this->session->userId();
        if ($userId === null) {
            throw new AuthorizationException('tenancy');
        }
        // Staff reach every organization.
        foreach ($this->memberships->rolesFor($userId, null) as $role) {
            if (Role::isStaff($role)) {
                return;
            }
        }
        if ($this->session->organizationId() === $organizationId) {
            return;
        }
        $this->audit->record(
            'authz.tenancy_denied',
            'failure',
            'organization',
            $organizationId
        );
        throw new AuthorizationException('tenancy');
    }

    public function isStaff(): bool
    {
        $userId = $this->session->userId();
        if ($userId === null) {
            return false;
        }
        foreach ($this->memberships->rolesFor($userId, null) as $role) {
            if (Role::isStaff($role)) {
                return true;
            }
        }
        return false;
    }
}
