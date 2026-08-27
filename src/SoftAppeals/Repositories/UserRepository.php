<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Support\Uuid;

/**
 * Users. In Version 1 that means her, plus whatever staff accounts she adds.
 *
 * Client contacts get a user row only when client login is switched on in a
 * later phase, which is why password_hash is nullable: a client will never have
 * one, because their access is passwordless by design.
 */
final class UserRepository extends Repository
{
    protected function table(): string
    {
        return 'sa_users';
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_users WHERE email = :e',
            ['e' => strtolower(trim($email))]
        );
    }

    /** Returns the new user id. */
    public function create(
        string $email,
        ?string $passwordHash = null,
        ?string $contactId = null,
        bool $active = true
    ): string {
        $id = Uuid::v4();
        $this->db->insert('sa_users', [
            'id'            => $id,
            'contact_id'    => $contactId,
            'email'         => strtolower(trim($email)),
            'password_hash' => $passwordHash,
            'active'        => $active ? 1 : 0,
            'last_login_at' => null,
            'created_at'    => $this->clock->nowUtc(),
        ]);
        return $id;
    }

    public function updatePasswordHash(string $userId, string $hash): void
    {
        $this->db->update('sa_users', ['password_hash' => $hash], ['id' => $userId]);
    }

    public function markLoggedIn(string $userId, string $whenUtc): void
    {
        $this->db->update('sa_users', ['last_login_at' => $whenUtc], ['id' => $userId]);
    }

    public function deactivate(string $userId): void
    {
        $this->db->update('sa_users', ['active' => 0], ['id' => $userId]);
    }

    /** @return list<array<string,mixed>> */
    public function allActive(): array
    {
        return $this->db->all('SELECT * FROM sa_users WHERE active = 1 ORDER BY email');
    }
}
