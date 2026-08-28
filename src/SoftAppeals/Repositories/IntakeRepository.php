<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Domain\IntakeForms;
use SoftAppeals\Domain\IntakeStatus;
use SoftAppeals\Support\Uuid;

/**
 * The inquiries. One row per submission of a public Soft Appeals form.
 *
 * The whole submission is kept as JSON in `payload_json` rather than spread
 * across a column per question. The forms change wording as she learns what
 * actually gets answered, and a schema migration per question is a trade nobody
 * wins. The handful of things the Desk sorts and filters on are promoted to
 * real columns by IntakeForms::summarize, and everything else stays in the
 * payload and is shown in the drawer exactly as it arrived.
 *
 * `payload_sha256` is the idempotency key and it carries a unique constraint.
 * The same submission arriving twice, from a double click, a browser retry, or
 * a second run of the importer over the same archive file, produces the same
 * hash and is recognised rather than stored again.
 */
final class IntakeRepository extends Repository
{
    protected function table(): string
    {
        return 'sa_intakes';
    }

    /**
     * Store one submission, or hand back the one already stored.
     *
     * @param array<string,string> $answers the filtered answers, keyed by field
     * @param string $payloadHash sha256 of whatever the caller considers the
     *        original: the posted body for a live submission, the archive file
     *        for an imported one
     * @return array{id:string,created:bool}
     */
    public function record(
        string $source,
        array $answers,
        string $payloadHash,
        string $submittedAtUtc,
        ?string $legacyRecordPath = null
    ): array {
        $existing = $this->findByPayloadHash($payloadHash);
        if ($existing !== null) {
            return ['id' => (string) $existing['id'], 'created' => false];
        }

        $summary = IntakeForms::summarize($source, $answers);
        $id = Uuid::v4();

        $this->db->insert('sa_intakes', [
            'id'                 => $id,
            'public_ref'         => $this->uniquePublicRef(),
            'organization_id'    => null,
            'source'             => $source,
            'organization_name'  => $summary['organization_name'],
            'contact_name'       => $summary['contact_name'],
            'contact_email'      => $summary['contact_email'],
            'contact_role'       => $summary['contact_role'],
            'state'              => $summary['state'],
            'organization_type'  => $summary['organization_type'],
            'denial_volume_band' => $summary['denial_volume_band'],
            'denied_value_band'  => $summary['denied_value_band'],
            'time_sensitive'     => $summary['time_sensitive'] ? 1 : 0,
            'payload_json'       => $this->encode($answers),
            'payload_sha256'     => $payloadHash,
            'status'             => IntakeStatus::RECEIVED,
            'fit_decision'       => null,
            'fit_note'           => null,
            'reviewed_at'        => null,
            'reviewed_by'        => null,
            'legacy_record_path' => $legacyRecordPath,
            'submitted_at'       => $submittedAtUtc,
            'created_at'         => $this->clock->nowUtc(),
        ]);

        return ['id' => $id, 'created' => true];
    }

    /** @return array<string,mixed>|null */
    public function findByPayloadHash(string $hash): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_intakes WHERE payload_sha256 = :h',
            ['h' => $hash]
        );
    }

    /**
     * Record the fit review. The status is derived from the decision by
     * FitDecision, never taken from the request, so the two can never disagree.
     */
    public function review(string $intakeId, string $status, string $decision, ?string $note, ?string $userId): void
    {
        $this->db->update('sa_intakes', [
            'status'       => $status,
            'fit_decision' => $decision,
            'fit_note'     => $note,
            'reviewed_at'  => $this->clock->nowUtc(),
            'reviewed_by'  => $userId,
        ], ['id' => $intakeId]);
    }

    public function linkOrganization(string $intakeId, string $organizationId): void
    {
        $this->db->update(
            'sa_intakes',
            ['organization_id' => $organizationId],
            ['id' => $intakeId]
        );
    }

    /**
     * Newest first. What the Recent inquiries table shows.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db->all(
            'SELECT * FROM sa_intakes ORDER BY submitted_at DESC, created_at DESC LIMIT ' . $limit
        );
    }

    /**
     * Everything still waiting on a decision, oldest first, because the oldest
     * one waiting is the one that has been waiting longest.
     *
     * @return list<array<string,mixed>>
     */
    public function unresolved(): array
    {
        return $this->db->all(
            "SELECT * FROM sa_intakes WHERE status IN"
            . " ('received', 'in_review', 'clarification', 'hold')"
            . ' ORDER BY time_sensitive DESC, submitted_at ASC'
        );
    }

    /**
     * The ones that have not been looked at at all. The Needs you card.
     *
     * @return list<array<string,mixed>>
     */
    public function awaitingReview(): array
    {
        return $this->db->all(
            "SELECT * FROM sa_intakes WHERE status IN ('received', 'in_review')"
            . ' ORDER BY time_sensitive DESC, submitted_at ASC'
        );
    }

    /**
     * Open inquiries whose contact address is one particular mailbox.
     *
     * Used for exactly one thing: her own test submissions. Every Soft Appeals
     * form was exercised end to end before it went live, and each of those runs
     * wrote a real lead file with her own address on it. They are
     * indistinguishable from a genuine enquiry in every way except the address,
     * so the address is what the rule matches on. It is a fact, not a guess:
     * either the form was filled in with her own inbox or it was not.
     *
     * @return list<array<string,mixed>>
     */
    public function unresolvedForEmail(string $email): array
    {
        return $this->db->all(
            "SELECT * FROM sa_intakes WHERE contact_email = :e AND status IN"
            . " ('received', 'in_review', 'clarification', 'hold')"
            . ' ORDER BY submitted_at ASC',
            ['e' => strtolower(trim($email))]
        );
    }

    /** @return array<string,int> status => count, every status present */
    public function countsByStatus(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM sa_intakes GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }
        return $out;
    }

    /** How many came in through the importer rather than the live form. */
    public function importedCount(): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM sa_intakes WHERE legacy_record_path IS NOT NULL'
        );
    }

    /**
     * The answers, decoded, in the order the form asks them. A key the form no
     * longer has is still returned, at the end, so an old lead never loses an
     * answer just because the wording moved on.
     *
     * @param array<string,mixed> $intake
     * @return array<string,string> label => answer
     */
    public function answers(array $intake): array
    {
        $source = (string) ($intake['source'] ?? '');
        $raw = json_decode((string) ($intake['payload_json'] ?? ''), true);
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach (IntakeForms::labels($source) as $key => $label) {
            if (isset($raw[$key]) && (string) $raw[$key] !== '') {
                $out[$label] = (string) $raw[$key];
                unset($raw[$key]);
            }
        }
        foreach ($raw as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $out[(string) $key] = (string) $value;
            }
        }
        return $out;
    }

    /** @param array<string,string> $answers */
    private function encode(array $answers): ?string
    {
        $json = json_encode($answers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }

    private function uniquePublicRef(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $ref = Uuid::publicRef('INQ');
            if (!$this->db->exists('SELECT id FROM sa_intakes WHERE public_ref = :r', ['r' => $ref])) {
                return $ref;
            }
        }
        throw new \RuntimeException('Could not mint a unique inquiry reference.');
    }
}
