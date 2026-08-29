<?php
declare(strict_types=1);

namespace SoftAppeals\Security;

use SoftAppeals\Database;
use SoftAppeals\Support\Clock;

/**
 * Rate limiting by action and by subject, with the defaults from section 18.4.
 *
 * The subject is never a raw identifier. An IP arrives already digested by
 * Hmac, and an email is digested here before it is stored, so the table cannot
 * be read as a list of who tried to log in.
 *
 * A fixed window is used rather than a sliding one. It is what `sa_rate_ok()`
 * already does on this site, it needs no background sweep, and at these limits
 * the boundary effect (up to twice the limit across a window edge) is not worth
 * the extra machinery.
 */
final class RateLimiter
{
    /** action => [max attempts, window in seconds] */
    private const LIMITS = [
        'intake.ip'            => [5, 3600],
        'intake.email'         => [2, 86400],
        'admin.login'          => [10, 900],
        'client.code.request'  => [5, 900],
        'client.code.verify'   => [8, 900],
        'invitation.resend'    => [3, 3600],
        'signature.submit'     => [3, 900],
    ];

    private Database $db;
    private Clock $clock;
    private Hmac $hmac;

    public function __construct(Database $db, Clock $clock, Hmac $hmac)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->hmac = $hmac;
    }

    /**
     * Count one attempt and throw if the limit is now exceeded.
     *
     * The attempt is counted before the check on purpose. A caller that keeps
     * trying keeps extending nothing: the window is fixed, so the counter rises
     * and the refusal continues until the window rolls over.
     */
    public function hit(string $action, string $subject): void
    {
        [$max, $window] = $this->limitFor($action);

        $key = $this->hmac->digest('ratelimit:' . $action, $subject);
        $windowStart = $this->windowStart($window);
        $now = $this->clock->nowUtc();

        $row = $this->db->one(
            'SELECT attempts FROM sa_rate_limits WHERE action = :a AND subject_digest = :s AND window_start = :w',
            ['a' => $action, 's' => $key, 'w' => $windowStart]
        );

        if ($row === null) {
            $this->db->insert('sa_rate_limits', [
                'action'         => $action,
                'subject_digest' => $key,
                'window_start'   => $windowStart,
                'attempts'       => 1,
                'updated_at'     => $now,
            ]);
            $attempts = 1;
        } else {
            $attempts = (int) $row['attempts'] + 1;
            $this->db->run(
                'UPDATE sa_rate_limits SET attempts = attempts + 1, updated_at = :n'
                . ' WHERE action = :a AND subject_digest = :s AND window_start = :w',
                ['n' => $now, 'a' => $action, 's' => $key, 'w' => $windowStart]
            );
        }

        if ($attempts > $max) {
            throw new RateLimitException($action, $this->secondsUntilWindowEnd($window));
        }
    }

    /** Check without counting. For showing a message before a form is submitted. */
    public function isExceeded(string $action, string $subject): bool
    {
        [$max, $window] = $this->limitFor($action);
        $row = $this->db->one(
            'SELECT attempts FROM sa_rate_limits WHERE action = :a AND subject_digest = :s AND window_start = :w',
            [
                'a' => $action,
                's' => $this->hmac->digest('ratelimit:' . $action, $subject),
                'w' => $this->windowStart($window),
            ]
        );
        return $row !== null && (int) $row['attempts'] >= $max;
    }

    /**
     * Forget the counter for one subject. Called after a successful login, so
     * a person who mistyped their password four times is not still throttled
     * once they get it right.
     */
    public function clear(string $action, string $subject): void
    {
        $this->db->run(
            'DELETE FROM sa_rate_limits WHERE action = :a AND subject_digest = :s',
            ['a' => $action, 's' => $this->hmac->digest('ratelimit:' . $action, $subject)]
        );
    }

    /** Drop rows older than a day. Called by the hourly cleanup job. */
    public function prune(): int
    {
        $cutoff = $this->clock->utcPlusSeconds(-86400 * 2);
        return $this->db->run(
            'DELETE FROM sa_rate_limits WHERE updated_at < :c',
            ['c' => $cutoff]
        )->rowCount();
    }

    /** @return array{0:int,1:int} */
    private function limitFor(string $action): array
    {
        if (!isset(self::LIMITS[$action])) {
            throw new \RuntimeException('No rate limit is defined for "' . $action . '".');
        }
        return self::LIMITS[$action];
    }

    private function windowStart(int $window): string
    {
        $now = $this->clock->now()->getTimestamp();
        return gmdate('Y-m-d H:i:s', intdiv($now, $window) * $window);
    }

    private function secondsUntilWindowEnd(int $window): int
    {
        $now = $this->clock->now()->getTimestamp();
        return ($window - ($now % $window));
    }
}
