<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Support\Uuid;

/**
 * Documents, one row per version.
 *
 * Every method here either inserts a new version or moves a status. Not one of
 * them writes to title, template_version, content_sha256 or private_path after
 * the insert, and that is deliberate: the columns that say what the document
 * IS are written once, and the columns that say where it has GOT TO are the
 * only ones that ever change. "A signed document cannot be edited" is that
 * split, held in one class rather than trusted to every caller.
 *
 * Version numbers are per engagement and per kind, so a practice on its second
 * BAA is on version 2 of a BAA and still on version 1 of everything else.
 */
final class DocumentRepository extends Repository
{
    protected function table(): string
    {
        return 'sa_documents';
    }

    /**
     * Pick the id, reference and version a new document is about to take.
     *
     * Reserving and inserting are two steps because the document body has to
     * carry its own reference and version on its face, and the body has to be
     * written and hashed before the row that records the hash exists. Section
     * 14.2 asks for both on the document, so the chicken and the egg are real
     * and this is where they are separated.
     *
     * Nothing is written here, so two callers reserving at once can both come
     * back with version 2. The unique constraint on (engagement_id, kind,
     * version) is what refuses the second insert, and insert() below turns that
     * refusal into a sentence a person can act on.
     *
     * @return array{id:string,public_ref:string,version:int}
     */
    public function reserve(string $engagementId, string $kind): array
    {
        return [
            'id'         => Uuid::v4(),
            'public_ref' => $this->uniquePublicRef(),
            'version'    => $this->nextVersion($engagementId, $kind),
        ];
    }

    /**
     * Insert the version that was reserved, now that its body exists.
     *
     * @param array{id:string,public_ref:string,version:int} $reserved
     * @param array<string,mixed> $fields
     * @return array{id:string,public_ref:string,version:int}
     */
    public function insertReserved(
        array $reserved,
        string $engagementId,
        string $organizationId,
        string $kind,
        array $fields
    ): array {
        $now = $this->clock->nowUtc();

        try {
            $this->db->insert('sa_documents', [
                'id'                => $reserved['id'],
                'public_ref'        => $reserved['public_ref'],
                'engagement_id'     => $engagementId,
                'organization_id'   => $organizationId,
                'kind'              => $kind,
                'version'           => $reserved['version'],
                'status'            => DocumentStatus::DRAFT,
                'title'             => (string) $fields['title'],
                'template_version'  => (string) $fields['template_version'],
                'consent_version'   => (string) $fields['consent_version'],
                'content_sha256'    => (string) $fields['content_sha256'],
                'executed_sha256'   => null,
                'private_path'      => (string) $fields['private_path'],
                'executed_path'     => null,
                'signer_contact_id' => $fields['signer_contact_id'] ?? null,
                'fee_basis'         => $fields['fee_basis'] ?? null,
                'void_reason'       => null,
                'superseded_by'     => null,
                'created_by'        => $fields['created_by'] ?? null,
                'sent_at'           => null,
                'client_signed_at'  => null,
                'countersigned_at'  => null,
                'executed_at'       => null,
                'voided_at'         => null,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        } catch (\PDOException $e) {
            if ($this->looksLikeDuplicate($e)) {
                throw new \RuntimeException(
                    'Another version of this document was created while this one was '
                    . 'being written. Nothing was saved twice. Reload and generate again.'
                );
            }
            throw $e;
        }

        return $reserved;
    }

    private function looksLikeDuplicate(\PDOException $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || $e->getCode() === '23000';
    }

    public function nextVersion(string $engagementId, string $kind): int
    {
        $highest = $this->db->value(
            'SELECT MAX(version) FROM sa_documents WHERE engagement_id = :e AND kind = :k',
            ['e' => $engagementId, 'k' => $kind]
        );
        return $highest === null ? 1 : ((int) $highest) + 1;
    }

    /**
     * The version of this kind that counts right now: the highest one that has
     * not been voided.
     *
     * @return array<string,mixed>|null
     */
    public function current(string $engagementId, string $kind): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_documents WHERE engagement_id = :e AND kind = :k'
            . ' AND status <> :void ORDER BY version DESC LIMIT 1',
            ['e' => $engagementId, 'k' => $kind, 'void' => DocumentStatus::VOID]
        );
    }

    /**
     * Every version of every kind on this engagement, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function forEngagement(string $engagementId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_documents WHERE engagement_id = :e'
            . ' ORDER BY created_at DESC, kind ASC, version DESC',
            ['e' => $engagementId]
        );
    }

    /**
     * What a practice is shown in its own portal: everything except drafts.
     *
     * A draft is a document she is still preparing. Section 15 gives the client
     * a document portal, not a window into the drafting.
     *
     * @return list<array<string,mixed>>
     */
    public function forClient(string $engagementId): array
    {
        return $this->db->all(
            'SELECT * FROM sa_documents WHERE engagement_id = :e AND status <> :draft'
            . ' ORDER BY created_at DESC',
            ['e' => $engagementId, 'draft' => DocumentStatus::DRAFT]
        );
    }

    /**
     * Everything waiting on her countersignature, across every practice.
     *
     * @return list<array<string,mixed>>
     */
    public function awaitingCountersignature(): array
    {
        return $this->db->all(
            'SELECT d.*, o.legal_name, o.display_name, e.public_ref AS engagement_ref'
            . ' FROM sa_documents d'
            . ' JOIN sa_organizations o ON o.id = d.organization_id'
            . ' JOIN sa_engagements e ON e.id = d.engagement_id'
            . ' WHERE d.status = :s ORDER BY d.client_signed_at ASC',
            ['s' => DocumentStatus::CLIENT_SIGNED]
        );
    }

    /**
     * Everything out for signature, across every practice.
     *
     * @return list<array<string,mixed>>
     */
    public function outForSignature(): array
    {
        return $this->db->all(
            'SELECT d.*, o.legal_name, o.display_name, e.public_ref AS engagement_ref'
            . ' FROM sa_documents d'
            . ' JOIN sa_organizations o ON o.id = d.organization_id'
            . ' JOIN sa_engagements e ON e.id = d.engagement_id'
            . ' WHERE d.status = :s ORDER BY d.sent_at ASC',
            ['s' => DocumentStatus::SENT]
        );
    }

    /**
     * Move a status, and only if the move is one the state machine allows.
     *
     * The WHERE clause names the status it expects to find, so two requests
     * racing to sign the same document cannot both win: the second one updates
     * zero rows and is told the document moved underneath it.
     *
     * @param array<string,mixed> $extra columns to write alongside the status
     */
    public function moveStatus(string $documentId, string $from, string $to, array $extra = []): bool
    {
        if (!DocumentStatus::canMove($from, $to)) {
            throw new \RuntimeException(
                'A document cannot go from ' . $from . ' to ' . $to . '.'
            );
        }

        $changes = $extra + [
            'status'     => $to,
            'updated_at' => $this->clock->nowUtc(),
        ];

        return $this->db->update(
            'sa_documents',
            $changes,
            ['id' => $documentId, 'status' => $from]
        ) === 1;
    }

    /**
     * Point a voided version at the version that replaced it.
     *
     * Written after the replacement exists, because the foreign key needs the
     * new row to be there first. It is the only column on a voided document
     * that is ever written after the void.
     */
    public function markSuperseded(string $documentId, string $replacementId): void
    {
        $this->db->update(
            'sa_documents',
            ['superseded_by' => $replacementId, 'updated_at' => $this->clock->nowUtc()],
            ['id' => $documentId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findForOrganization(string $documentId, string $organizationId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_documents WHERE id = :d AND organization_id = :o',
            ['d' => $documentId, 'o' => $organizationId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByRefForOrganization(string $ref, string $organizationId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_documents WHERE public_ref = :r AND organization_id = :o',
            ['r' => $ref, 'o' => $organizationId]
        );
    }

    /** @return array<string,int> status => how many */
    public function countsByStatus(): array
    {
        $out = [];
        foreach (DocumentStatus::all() as $status) {
            $out[$status] = 0;
        }
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM sa_documents GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }
        return $out;
    }

    private function uniquePublicRef(): string
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $ref = Uuid::publicRef('DOC');
            if (!$this->db->exists('SELECT id FROM sa_documents WHERE public_ref = :r', ['r' => $ref])) {
                return $ref;
            }
        }
        throw new \RuntimeException('Could not allocate a document reference.');
    }
}
