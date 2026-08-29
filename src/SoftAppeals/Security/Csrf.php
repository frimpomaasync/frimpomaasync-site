<?php
declare(strict_types=1);

namespace SoftAppeals\Security;

use SoftAppeals\Auth\SessionManager;

/**
 * CSRF protection for every state-changing request.
 *
 * One token per session, rotated when the session ID rotates, compared in
 * constant time. Tokens are also bound to an action name, so a token minted for
 * the login form cannot be replayed against the countersign button.
 *
 * The guard is deliberately not optional and not configurable. A write path
 * that forgets to call require() is a bug caught by the Security test, not a
 * setting somebody can switch off.
 */
final class Csrf
{
    private const SESSION_KEY = 'sa_csrf';

    private SessionManager $session;

    public function __construct(SessionManager $session)
    {
        $this->session = $session;
    }

    /** The per-session secret, created on first use. */
    private function seed(): string
    {
        $seed = $this->session->get(self::SESSION_KEY);
        if (!is_string($seed) || $seed === '') {
            $seed = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $seed);
        }
        return $seed;
    }

    /**
     * A token for one action. Put it in a hidden input named `_csrf`.
     * The action is usually the form's purpose: 'login', 'terms.send', 'sign'.
     */
    public function token(string $action): string
    {
        return hash_hmac('sha256', $action, $this->seed());
    }

    public function isValid(string $action, ?string $given): bool
    {
        if ($given === null || $given === '') {
            return false;
        }
        return hash_equals($this->token($action), $given);
    }

    /**
     * Read the token from the request. A form field first, then the header,
     * which is what a fetch() call from the Desk sends.
     */
    public function fromRequest(): ?string
    {
        $field = $_POST['_csrf'] ?? null;
        if (is_string($field) && $field !== '') {
            return $field;
        }
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        return is_string($header) && $header !== '' ? $header : null;
    }

    /**
     * Throw unless the request carries a valid token for this action.
     * Every write path calls this before it touches anything.
     */
    public function require(string $action): void
    {
        if (!$this->isValid($action, $this->fromRequest())) {
            throw new CsrfException('The security token for "' . $action . '" was missing or wrong.');
        }
    }

    /** Called by SessionManager after a session ID rotation. */
    public function rotate(): void
    {
        $this->session->set(self::SESSION_KEY, bin2hex(random_bytes(32)));
    }

    /** Ready-to-print hidden input, already escaped. */
    public function field(string $action): string
    {
        return '<input type="hidden" name="_csrf" value="'
            . htmlspecialchars($this->token($action), ENT_QUOTES, 'UTF-8')
            . '">';
    }
}
