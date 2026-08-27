<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * Every action the application can authorise, and which roles hold it.
 *
 * One table, read by AuthorizationService, checked on every server action.
 * A role that is not listed against a permission does not hold it: the map is
 * an allowlist, never a denylist, so a new permission defaults to nobody rather
 * than to everybody.
 */
final class Permission
{
    // Staff.
    public const DESK_VIEW              = 'desk.view';
    public const INTAKE_REVIEW          = 'intake.review';
    public const ENGAGEMENT_MANAGE      = 'engagement.manage';
    public const TERMS_SEND             = 'terms.send';
    public const DOCUMENT_GENERATE      = 'document.generate';
    public const DOCUMENT_COUNTERSIGN   = 'document.countersign';
    public const COMMUNICATION_SEND     = 'communication.send';
    public const WORK_BATCH_MANAGE      = 'work_batch.manage';
    public const RECOVERY_VERIFY        = 'recovery.verify';
    public const AUDIT_VIEW             = 'audit.view';
    public const CONFIG_MANAGE          = 'config.manage';
    public const USER_MANAGE            = 'user.manage';

    // Client.
    public const ROOM_VIEW              = 'room.view';
    public const PREFERENCES_CONFIRM    = 'preferences.confirm';
    public const DOCUMENT_SIGN          = 'document.sign';
    public const APPROVAL_DECIDE        = 'approval.decide';
    public const FINANCE_VIEW           = 'finance.view';
    public const COMPLIANCE_VIEW        = 'compliance.view';
    public const CLIENT_USER_MANAGE     = 'client_user.manage';

    /**
     * permission => roles that hold it.
     *
     * @return array<string,list<string>>
     */
    public static function map(): array
    {
        return [
            // Staff. The auditor sees everything and changes nothing, which is
            // the whole point of the role.
            self::DESK_VIEW            => [Role::OWNER_ADMIN, Role::OPERATIONS_ADMIN, Role::AUDITOR],
            self::AUDIT_VIEW           => [Role::OWNER_ADMIN, Role::AUDITOR],
            self::INTAKE_REVIEW        => [Role::OWNER_ADMIN, Role::OPERATIONS_ADMIN],
            self::ENGAGEMENT_MANAGE    => [Role::OWNER_ADMIN, Role::OPERATIONS_ADMIN],
            self::TERMS_SEND           => [Role::OWNER_ADMIN, Role::OPERATIONS_ADMIN],
            self::COMMUNICATION_SEND   => [Role::OWNER_ADMIN, Role::OPERATIONS_ADMIN],
            self::WORK_BATCH_MANAGE    => [Role::OWNER_ADMIN, Role::OPERATIONS_ADMIN],
            self::DOCUMENT_GENERATE    => [Role::OWNER_ADMIN, Role::OPERATIONS_ADMIN],

            // Countersigning, money, configuration and user management are the
            // owner's alone. An operations admin can move work forward but
            // cannot execute an agreement or decide what a recovery was worth.
            self::DOCUMENT_COUNTERSIGN => [Role::OWNER_ADMIN],
            self::RECOVERY_VERIFY      => [Role::OWNER_ADMIN],
            self::CONFIG_MANAGE        => [Role::OWNER_ADMIN],
            self::USER_MANAGE          => [Role::OWNER_ADMIN],

            // Client. Every one of these is additionally tenancy-checked
            // against the organization on the session.
            self::ROOM_VIEW            => Role::clientRoles(),
            self::PREFERENCES_CONFIRM  => [Role::ORG_ADMIN, Role::AUTHORIZED_SIGNER],
            self::DOCUMENT_SIGN        => [Role::AUTHORIZED_SIGNER],
            self::APPROVAL_DECIDE      => [Role::ORG_ADMIN, Role::SUBMISSION_APPROVER],
            self::FINANCE_VIEW         => [Role::ORG_ADMIN, Role::BILLING],
            self::COMPLIANCE_VIEW      => [Role::ORG_ADMIN, Role::COMPLIANCE],
            self::CLIENT_USER_MANAGE   => [Role::ORG_ADMIN],
        ];
    }

    /** @return list<string> */
    public static function forRole(string $role): array
    {
        $out = [];
        foreach (self::map() as $permission => $roles) {
            if (in_array($role, $roles, true)) {
                $out[] = $permission;
            }
        }
        return $out;
    }

    public static function isValid(string $permission): bool
    {
        return array_key_exists($permission, self::map());
    }
}
