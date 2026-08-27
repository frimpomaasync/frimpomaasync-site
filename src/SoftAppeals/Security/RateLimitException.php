<?php
declare(strict_types=1);

namespace SoftAppeals\Security;

use RuntimeException;

/**
 * Too many attempts at one action.
 *
 * $retryAfterSeconds is what the response tells the caller. It is a whole
 * number of seconds and it is never negative, so it can go straight into a
 * Retry-After header.
 */
final class RateLimitException extends RuntimeException
{
    public readonly string $action;
    public readonly int $retryAfterSeconds;

    public function __construct(string $action, int $retryAfterSeconds)
    {
        $this->action = $action;
        $this->retryAfterSeconds = max(0, $retryAfterSeconds);
        parent::__construct('Rate limit reached for ' . $action . '.');
    }
}
