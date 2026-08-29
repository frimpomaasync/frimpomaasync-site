<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Database;
use SoftAppeals\Security\Hmac;
use SoftAppeals\Support\Clock;
use SoftAppeals\Support\Uuid;

/**
 * Six-digit codes, for coming back after the first visit.
 *
 * Section 10.2: the code expires in ten minutes, is stored only as a digest,
 * is marked used on success, and cannot be replayed.
 *
 * The digest is keyed with the application secret and bound to the address, not
 * a bare hash of six digits. A bare hash of a million possible values is a
 * lookup table somebody builds once, and a stolen database would then be a list
 * of live codes. Keyed and address-bound, the same table is worth nothing
 * without the secret, which lives outside the repository and outside the web
 * root.
 *
 * Two counters guard the verify, and they count different things. The rate
 * limiter counts by caller, which stops one machine working through codes for
 * many addresses. `attempts` counts by code, which burns a single code after
 * five wrong guesses however many machines are guessing at it.
 */
final class LoginCodeRepository extends Repository
{
    public const PURPOSE_CLIENT_LOGIN = 'client_login';

    /** Section 10.2. Ten minutes, and not a minute of leeway. */
    public const TTL_SECONDS = 600;

    /** Wrong guesses at one code before it is dead. */
    public const MAX_ATTEMPTS = 5;

    private Hmac $hmac;

    public function __construct(Database $db, Clock $clock, Hmac $hmac)
    {
        parent::__construct($db, $clock);
        $this->hmac = $hmac;
    }

    protected function table(): string
    {
        return 'sa_login_codes';
    }

    /**
     * Mint one code and revoke every live one for the same address.
     *
     * The plaintext code is returned here and nowhere else. Whatever calls this
     * puts it straight into the email and lets it go.
     *
     * @return array{id:string,code:string,expires_at:string,revoked:int}
     */
    public function mint(string $organizationId, ?string $userId, string $email): array
    {
        $email = strtolower(trim($email));

        return $this->db->transaction(function () use ($organizationId, $userId, $email): array {
            $revoked = $this->revokeLive($email);

            $code = Hmac::newNumericCode(6);
            $id = Uuid::v4();
            $expiresAt = $this->clock->utcPlusSeconds(self::TTL_SECONDS);

            $this->db->insert('sa_login_codes', [
                'id'              => $id,
                'organization_id' => $organizationId,
                'user_id'         => $userId,
                'email'           => $email,
                'code_digest'     => $this->digest($email, $code),
                'purpose'         => self::PURPOSE_CLIENT_LOGIN,
                'expires_at'      => $expiresAt,
                'used_at'         => null,
                'revoked_at'      => null,
                'attempts'        => 0,
                'created_at'      => $this->clock->nowUtc(),
            ]);

            return [
                'id'         => $id,
                'code'       => $code,
                'expires_at' => $expiresAt,
                'revoked'    => $revoked,
            ];
        });
    }

    /** Every unused, unrevoked, unexpired code for this address, killed. */
    public function revokeLive(string $email): int
    {
        return $this->db->run(
            'UPDATE sa_login_codes SET revoked_at = :now'
            . ' WHERE email = :e AND used_at IS NULL AND revoked_at IS NULL',
            ['now' => $this->clock->nowUtc(), 'e' => strtolower(trim($email))]
        )->rowCount();
    }

    /**
     * Check a code against an address.
     *
     * Returns the row on success and null on every kind of failure, because a
     * caller must not be able to tell an unknown address from a wrong code from
     * an expired one. A wrong guess is counted against the live code before the
     * null comes back.
     *
     * @return array<string,mixed>|null
     */
    public function verify(string $email, string $code): ?array
    {
        $email = strtolower(trim($email));
        $code = preg_replace('/\D/', '', $code) ?? '';
        if ($code === '') {
            $this->countWrongGuess($email);
            return null;
        }

        $row = $this->db->one(
            'SELECT * FROM sa_login_codes'
            . ' WHERE email = :e AND code_digest = :d AND purpose = :p'
            . ' ORDER BY created_at DESC',
            [
                'e' => $email,
                'd' => $this->digest($email, $code),
                'p' => self::PURPOSE_CLIENT_LOGIN,
            ]
        );

        if ($row === null) {
            $this->countWrongGuess($email);
            return null;
        }
        if ($row['used_at'] !== null || $row['revoked_at'] !== null) {
            return null;
        }
        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return null;
        }
        if ($this->clock->hasPassed((string) $row['expires_at'])) {
            return null;
        }

        // One-time. A second request carrying the same correct code updates no
        // rows and is refused, so two tabs cannot both establish a session.
        $used = $this->db->run(
            'UPDATE sa_login_codes SET used_at = :now WHERE id = :id AND used_at IS NULL',
            ['now' => $this->clock->nowUtc(), 'id' => (string) $row['id']]
        )->rowCount();

        return $used === 1 ? $row : null;
    }

    /** Drop everything that expired more than a day ago. The cleanup job. */
    public function prune(): int
    {
        return $this->db->run(
            'DELETE FROM sa_login_codes WHERE expires_at < :c',
            ['c' => $this->clock->utcPlusSeconds(-86400)]
        )->rowCount();
    }

    /** @return array<string,mixed>|null the live code for this address, if any */
    public function live(string $email): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_login_codes'
            . ' WHERE email = :e AND used_at IS NULL AND revoked_at IS NULL AND expires_at > :now'
            . ' ORDER BY created_at DESC',
            ['e' => strtolower(trim($email)), 'now' => $this->clock->nowUtc()]
        );
    }

    /**
     * A wrong guess counts against whatever code that address currently holds.
     * Five of them and the code is dead, whether or not the guesser ever names
     * the right digits afterwards.
     */
    private function countWrongGuess(string $email): void
    {
        $this->db->run(
            'UPDATE sa_login_codes SET attempts = attempts + 1'
            . ' WHERE email = :e AND used_at IS NULL AND revoked_at IS NULL',
            ['e' => $email]
        );
    }

    private function digest(string $email, string $code): string
    {
        return $this->hmac->digest('login_code', strtolower(trim($email)) . '|' . $code);
    }
}
