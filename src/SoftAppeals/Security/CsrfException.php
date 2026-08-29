<?php
declare(strict_types=1);

namespace SoftAppeals\Security;

use RuntimeException;

/**
 * A write arrived without a valid CSRF token.
 *
 * Its own class so the error handler can answer it with a plain refusal rather
 * than a correlation reference: there is nothing for her to look up, the
 * request simply did not carry proof it came from her own page.
 */
final class CsrfException extends RuntimeException
{
}
