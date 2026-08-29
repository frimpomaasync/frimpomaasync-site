<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Support\Uuid;

/**
 * A named person at a practice.
 *
 * Everything on this table is business identity: a name, a work email, a job
 * title, a phone number. No patient ever becomes a contact, and there is no
 * column here that could hold one.
 *
 * The address is the identity. `upsert` finds an existing contact by address
 * within the organization and updates it rather than creating a second row, so
 * a practice that names the same person as both signer and approver ends up
 * with one person holding two roles, which is what section 8.2 says happens.
 *
 * Deactivating a contact is not a delete. Section 10.3 requires every live
 * invitation for that address to be revoked at the same moment, and that is the
 * caller's job because this class owns one table.
 */
final class ContactRepository extends Repository
{
    protected function table(): string
    {
        return 'sa_contacts';
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $organizationId, string $email): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_contacts WHERE organization_id = :o AND work_email = :e',
            ['o' => $organizationId, 'e' => self::normalizeEmail($email)]
        );
    }

    /**
     * Create the contact, or update the one that is already there.
     *
     * @return array{id:string,created:bool}
     */
    public function upsert(
        string $organizationId,
        string $name,
        string $email,
        ?string $roleTitle = null,
        ?string $phone = null
    ): array {
        $email = self::normalizeEmail($email);
        $existing = $this->findByEmail($organizationId, $email);

        if ($existing !== null) {
            $this->db->update('sa_contacts', [
                'name'       => $name === '' ? (string) $existing['name'] : $name,
                'role_title' => $roleTitle ?? ($existing['role_title'] === null ? null : (string) $existing['role_title']),
                'phone'      => $phone ?? ($existing['phone'] === null ? null : (string) $existing['phone']),
                'active'     => 1,
            ], ['id' => (string) $existing['id']]);
            return ['id' => (string) $existing['id'], 'created' => false];
        }

        $id = Uuid::v4();
        $this->db->insert('sa_contacts', [
            'id'              => $id,
            'organization_id' => $organizationId,
            'name'            => $name,
            'work_email'      => $email,
            'role_title'      => $roleTitle,
            'phone'           => $phone,
            'active'          => 1,
            'created_at'      => $this->clock->nowUtc(),
        ]);
        return ['id' => $id, 'created' => true];
    }

    /** @return list<array<string,mixed>> */
    public function forOrganization(string $organizationId, bool $activeOnly = true): array
    {
        $where = $activeOnly ? ' AND active = 1' : '';
        return $this->db->all(
            'SELECT * FROM sa_contacts WHERE organization_id = :o' . $where . ' ORDER BY name',
            ['o' => $organizationId]
        );
    }

    /** A soft removal. The record stays; the person loses their standing. */
    public function deactivate(string $contactId): void
    {
        $this->db->update('sa_contacts', ['active' => 0], ['id' => $contactId]);
    }
}
