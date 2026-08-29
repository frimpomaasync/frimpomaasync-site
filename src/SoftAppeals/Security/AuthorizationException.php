<?php
declare(strict_types=1);

namespace SoftAppeals\Security;

use RuntimeException;

/**
 * The caller is not allowed to do this.
 *
 * Carries the permission that was refused so the audit trail can record it.
 * That string never reaches the browser: an unauthorized request is answered
 * with a 404, so an internal page cannot be discovered by watching status
 * codes.
 */
final class AuthorizationException extends RuntimeException
{
    public readonly string $permission;

    public function __construct(string $permission, string $message = '')
    {
        $this->permission = $permission;
        parent::__construct($message !== '' ? $message : 'Not permitted: ' . $permission);
    }
}
