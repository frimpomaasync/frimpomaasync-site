<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * Roles and the permissions each one carries.
 *
 * Version 1 launches with the owner admin only. The other roles are defined
 * here from the start so that adding one later is a row in `memberships` and
 * not a schema change, which is what section 8.1 of the plan requires.
 *
 * Permissions are checked on the server for every action. Hiding a button is
 * presentation, never protection.
 */
final class Role
{
    // Soft Appeals side.
    public const OWNER_ADMIN      = 'owner_admin';
    public const OPERATIONS_ADMIN = 'operations_admin';
    public const AUDITOR          = 'auditor';

    // Client side.
    public const ORG_ADMIN            = 'org_admin';
    public const AUTHORIZED_SIGNER    = 'authorized_signer';
    public const SUBMISSION_APPROVER  = 'submission_approver';
    public const BILLING              = 'billing';
    public const COMPLIANCE           = 'compliance';
    public const VIEWER               = 'viewer';

    /** @return list<string> */
    public static function staffRoles(): array
    {
        return [self::OWNER_ADMIN, self::OPERATIONS_ADMIN, self::AUDITOR];
    }

    /** @return list<string> */
    public static function clientRoles(): array
    {
        return [
            self::ORG_ADMIN,
            self::AUTHORIZED_SIGNER,
            self::SUBMISSION_APPROVER,
            self::BILLING,
            self::COMPLIANCE,
            self::VIEWER,
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [...self::staffRoles(), ...self::clientRoles()];
    }

    public static function isValid(string $role): bool
    {
        return in_array($role, self::all(), true);
    }

    public static function isStaff(string $role): bool
    {
        return in_array($role, self::staffRoles(), true);
    }

    public static function label(string $role): string
    {
        return match ($role) {
            self::OWNER_ADMIN         => 'Owner admin',
            self::OPERATIONS_ADMIN    => 'Operations admin',
            self::AUDITOR             => 'Read-only auditor',
            self::ORG_ADMIN           => 'Organization admin',
            self::AUTHORIZED_SIGNER   => 'Authorized signer',
            self::SUBMISSION_APPROVER => 'Submission approver',
            self::BILLING             => 'Billing or finance',
            self::COMPLIANCE          => 'Compliance or IT',
            self::VIEWER              => 'Read-only viewer',
            default                   => $role,
        };
    }
}
