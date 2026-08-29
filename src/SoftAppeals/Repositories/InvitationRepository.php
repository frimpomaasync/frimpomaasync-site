<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Support\Uuid;

/**
 * One-time links, stored as digests.
 *
 * Section 10.3, and every line of it is enforced here rather than described:
 * 32 random bytes, the digest stored and never the token, purpose-bound,
 * organization-bound, one-time, an explicit expiry, and server-side revocation.
 *
 * The token itself exists in exactly one place, the email, and is returned from
 * mint() once. It is never written to this table, never logged, and never put
 * in an audit row. A copy of this table therefore cannot be replayed against a
 * practice, which is the whole reason for the shape.
 *
 * Resend rotates. The old invitation is revoked in the same transaction the new
 * one is minted, so there is never a window with two live links to the same
 * step, and a forwarded old email stops working the moment she resends.
 */
final class InvitationRepository extends Repository
{
    public const PURPOSE_PREFERENCES = 'preferences';
    public const PURPOSE_SIGN        = 'sign';
    public const PURPOSE_INVITE      = 'invite';
    public const PURPOSE_LOGIN       = 'passwordless_login';

    /** How long a preferences link lives. Long enough to reach a compliance
     *  officer, short enough that a forwarded email goes stale. */
    public const PREFERENCES_TTL_SECONDS = 14 * 24 * 60 * 60;

    protected function table(): string
    {
        return 'sa_invitations';
    }

    /** @return list<string> */
    public static function purposes(): array
    {
        return [self::PURPOSE_PREFERENCES, self::PURPOSE_SIGN, self::PURPOSE_INVITE, self::PURPOSE_LOGIN];
    }

    /**
     * Mint one invitation and revoke any live one for the same purpose.
     *
     * The plaintext token is returned here and nowhere else. Whatever calls
     * this must put it straight into the email and let it go.
     *
     * @return array{id:string,token:string,expires_at:string,revoked:int}
     */
    public function mint(
        string $organizationId,
        ?string $engagementId,
        string $contactEmail,
        string $purpose,
        int $ttlSeconds,
        ?string $createdBy = null,
        ?string $contactId = null
    ): array {
        if (!in_array($purpose, self::purposes(), true)) {
            throw new \RuntimeException('Unknown invitation purpose: ' . $purpose);
        }

        return $this->db->transaction(function () use (
            $organizationId,
            $engagementId,
            $contactEmail,
            $purpose,
            $ttlSeconds,
            $createdBy,
            $contactId
        ): array {
            $revoked = $this->revokeLive($organizationId, $purpose);

            // 32 bytes, as section 10.3 requires. Hex so it survives an email
            // client that mangles anything else.
            $token = bin2hex(random_bytes(32));
            $id = Uuid::v4();
            $expiresAt = $this->clock->utcPlusSeconds($ttlSeconds);

            $this->db->insert('sa_invitations', [
                'id'              => $id,
                'organization_id' => $organizationId,
                'engagement_id'   => $engagementId,
                'contact_id'      => $contactId,
                'contact_email'   => strtolower(trim($contactEmail)),
                'purpose'         => $purpose,
                'token_digest'    => self::digest($token),
                'expires_at'      => $expiresAt,
                'used_at'         => null,
                'revoked_at'      => null,
                'created_by'      => $createdBy,
                'created_at'      => $this->clock->nowUtc(),
            ]);

            return [
                'id'         => $id,
                'token'      => $token,
                'expires_at' => $expiresAt,
                'revoked'    => $revoked,
            ];
        });
    }

    /**
     * Revoke every invitation for this organization and purpose that has not
     * been used and has not already been revoked.
     *
     * @return int how many were revoked
     */
    public function revokeLive(string $organizationId, string $purpose): int
    {
        return $this->db->run(
            'UPDATE sa_invitations SET revoked_at = :now'
            . ' WHERE organization_id = :o AND purpose = :p'
            . ' AND used_at IS NULL AND revoked_at IS NULL',
            ['now' => $this->clock->nowUtc(), 'o' => $organizationId, 'p' => $purpose]
        )->rowCount();
    }

    /** Every invitation for a contact, revoked at once. Section 10.3. */
    public function revokeAllForEmail(string $contactEmail): int
    {
        return $this->db->run(
            'UPDATE sa_invitations SET revoked_at = :now'
            . ' WHERE contact_email = :e AND used_at IS NULL AND revoked_at IS NULL',
            ['now' => $this->clock->nowUtc(), 'e' => strtolower(trim($contactEmail))]
        )->rowCount();
    }

    /**
     * Section 17.2: "expire unused invitations". A link past its date that
     * was never used is revoked, so the table says plainly that it is dead
     * rather than leaving that to a comparison on every read.
     *
     * @return int how many were closed
     */
    public function expireLapsed(): int
    {
        $now = $this->clock->nowUtc();
        return $this->db->run(
            'UPDATE sa_invitations SET revoked_at = :now'
            . ' WHERE used_at IS NULL AND revoked_at IS NULL AND expires_at <= :cutoff',
            ['now' => $now, 'cutoff' => $now]
        )->rowCount();
    }

    /**
     * Look one up by the token a person presented. Returns null for a token
     * that is unknown, used, revoked or expired, all four for the same reason:
     * a caller must not be able to tell those apart.
     *
     * @return array<string,mixed>|null
     */
    public function redeemable(string $token, string $purpose): ?array
    {
        $row = $this->db->one(
            'SELECT * FROM sa_invitations WHERE token_digest = :d AND purpose = :p',
            ['d' => self::digest($token), 'p' => $purpose]
        );
        if ($row === null) {
            return null;
        }
        if ($row['used_at'] !== null || $row['revoked_at'] !== null) {
            return null;
        }
        if ($this->clock->hasPassed((string) $row['expires_at'])) {
            return null;
        }
        return $row;
    }

    /** One-time use. Returns false when somebody redeemed it first. */
    public function markUsed(string $invitationId): bool
    {
        return $this->db->run(
            'UPDATE sa_invitations SET used_at = :now WHERE id = :id AND used_at IS NULL',
            ['now' => $this->clock->nowUtc(), 'id' => $invitationId]
        )->rowCount() === 1;
    }

    /** @return array<string,mixed>|null the live one, if there is one */
    public function live(string $organizationId, string $purpose): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_invitations'
            . ' WHERE organization_id = :o AND purpose = :p'
            . ' AND used_at IS NULL AND revoked_at IS NULL AND expires_at > :now'
            . ' ORDER BY created_at DESC',
            ['o' => $organizationId, 'p' => $purpose, 'now' => $this->clock->nowUtc()]
        );
    }

    /**
     * The digest that is stored. SHA-256 of the token, which is what section
     * 10.3 permits. There is no salt because the token is 32 random bytes: a
     * rainbow table over that space does not exist and cannot be built.
     */
    public static function digest(string $token): string
    {
        return hash('sha256', $token);
    }
}
