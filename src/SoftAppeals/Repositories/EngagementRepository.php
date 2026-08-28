<?php
declare(strict_types=1);

namespace SoftAppeals\Repositories;

use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Support\Uuid;

/**
 * Engagements. One per accepted inquiry, and the thing every later phase hangs
 * off: documents, work batches, recoveries, the Recovery Room.
 *
 * Two rules live in this class rather than in whatever page happens to call it.
 *
 * The stage only ever moves along an edge Domain\Stage allows. A browser
 * calling a later endpoint directly cannot skip the BAA and land on "secure
 * intake ready", because the move is looked up here before it is written.
 *
 * The move is guarded by row_version. Two tabs open on the same practice, both
 * showing the state as it was a minute ago, must not both advance it. The
 * second one finds the version has moved and is refused, rather than quietly
 * writing over the first.
 */
final class EngagementRepository extends Repository
{
    protected function table(): string
    {
        return 'sa_engagements';
    }

    public function create(
        string $organizationId,
        ?string $intakeId,
        string $stage = Stage::INQUIRY_RECEIVED,
        string $feeBasis = EngagementTerms::FEE_NOT_SET,
        ?string $secureChannel = null,
        ?string $assessmentWindow = null
    ): string {
        if (!Stage::isValid($stage)) {
            throw new \RuntimeException('Unknown stage: ' . $stage);
        }
        if (!EngagementTerms::isValidFee($feeBasis)) {
            throw new \RuntimeException('Unknown fee basis: ' . $feeBasis);
        }
        if ($secureChannel !== null && !EngagementTerms::isValidChannel($secureChannel)) {
            throw new \RuntimeException('Unknown secure channel: ' . $secureChannel);
        }

        $id = Uuid::v4();
        $this->db->insert('sa_engagements', [
            'id'                     => $id,
            'organization_id'        => $organizationId,
            'intake_id'              => $intakeId,
            'public_ref'             => $this->uniquePublicRef(),
            'stage'                  => $stage,
            'fee_basis'              => $feeBasis,
            'fee_rate_bps'           => EngagementTerms::feeRateBps($feeBasis),
            'secure_channel_type'    => $secureChannel,
            'communication_cadence'  => null,
            'assessment_window'      => $assessmentWindow,
            'client_decision_due_at' => null,
            'opened_at'              => $this->clock->nowUtc(),
            'closed_at'              => null,
            'row_version'            => 1,
        ]);
        return $id;
    }

    /**
     * Move one engagement along one edge of the state machine.
     *
     * $expectedVersion is the row_version the caller last read. Pass null to
     * skip the concurrency check, which is correct only inside a transition
     * this same request already made.
     *
     * @throws \RuntimeException when the move is not an allowed edge
     * @return bool false when somebody else moved the record first
     */
    public function transition(string $engagementId, string $to, ?int $expectedVersion = null): bool
    {
        $row = $this->find($engagementId);
        if ($row === null) {
            throw new \RuntimeException('No such engagement.');
        }
        $from = (string) $row['stage'];

        if (!Stage::canMove($from, $to)) {
            throw new \RuntimeException(
                'An engagement cannot move from ' . $from . ' to ' . $to . '.'
            );
        }
        if ($expectedVersion !== null && (int) $row['row_version'] !== $expectedVersion) {
            return false;
        }

        $current = (int) $row['row_version'];

        // The version goes in the WHERE clause as well as the SET, so two
        // requests that both read the same version cannot both succeed even if
        // they arrive in the same millisecond. The second one updates no rows
        // and is told so, rather than quietly writing over the first.
        $sql = 'UPDATE sa_engagements SET stage = :stage, row_version = :new_version';
        $params = [
            'stage'       => $to,
            'new_version' => $current + 1,
            'id'          => $engagementId,
            'old_version' => $current,
        ];
        if (Stage::isTerminal($to)) {
            $sql .= ', closed_at = :closed_at';
            $params['closed_at'] = $this->clock->nowUtc();
        }
        $sql .= ' WHERE id = :id AND row_version = :old_version';

        return $this->db->run($sql, $params)->rowCount() === 1;
    }

    /** Set the terms. Every value is checked before it is stored. */
    public function setTerms(
        string $engagementId,
        string $feeBasis,
        ?string $secureChannel,
        ?string $assessmentWindow,
        ?string $cadence = null
    ): void {
        if (!EngagementTerms::isValidFee($feeBasis)) {
            throw new \RuntimeException('Unknown fee basis: ' . $feeBasis);
        }
        if ($secureChannel !== null && !EngagementTerms::isValidChannel($secureChannel)) {
            throw new \RuntimeException('Unknown secure channel: ' . $secureChannel);
        }
        if ($cadence !== null && !EngagementTerms::isValidCadence($cadence)) {
            throw new \RuntimeException('Unknown cadence: ' . $cadence);
        }

        $this->db->update('sa_engagements', [
            'fee_basis'             => $feeBasis,
            'fee_rate_bps'          => EngagementTerms::feeRateBps($feeBasis),
            'secure_channel_type'   => $secureChannel,
            'assessment_window'     => $assessmentWindow,
            'communication_cadence' => $cadence,
        ], ['id' => $engagementId]);
    }

    /**
     * One engagement with its organization joined on.
     *
     * find() is the generic one every repository inherits and it stays generic.
     * The terms preview needs the organization's name in the same row, so the
     * join lives here rather than being pushed into a page.
     *
     * @return array<string,mixed>|null
     */
    public function findWithOrganization(string $engagementId): ?array
    {
        return $this->db->one(
            'SELECT e.*, o.legal_name, o.display_name, o.public_ref AS organization_ref,'
            . ' o.state AS organization_state, o.status AS organization_status'
            . ' FROM sa_engagements e'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' WHERE e.id = :id',
            ['id' => $engagementId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByIntake(string $intakeId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_engagements WHERE intake_id = :i ORDER BY opened_at DESC',
            ['i' => $intakeId]
        );
    }

    /**
     * Every engagement, with the organization joined on, ordered so the ones
     * waiting on her sit above the ones waiting on somebody else.
     *
     * @return list<array<string,mixed>>
     */
    public function withOrganizations(bool $openOnly = true): array
    {
        $where = $openOnly ? ' WHERE e.closed_at IS NULL' : '';
        return $this->db->all(
            'SELECT e.*, o.legal_name, o.display_name, o.public_ref AS organization_ref,'
            . ' o.state AS organization_state, o.status AS organization_status'
            . ' FROM sa_engagements e'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . $where
            . ' ORDER BY e.opened_at DESC'
        );
    }

    /**
     * @return list<array<string,mixed>> the ones sitting at one stage, with the
     *         organization joined on
     */
    public function atStage(string $stage): array
    {
        return $this->db->all(
            'SELECT e.*, o.legal_name, o.display_name, o.public_ref AS organization_ref'
            . ' FROM sa_engagements e'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' WHERE e.stage = :s ORDER BY e.opened_at ASC',
            ['s' => $stage]
        );
    }

    /** @return array<string,int> stage => count */
    public function countsByStage(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT stage, COUNT(*) AS n FROM sa_engagements GROUP BY stage') as $row) {
            $out[(string) $row['stage']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * The pipeline, in the eight columns section 12.4 asks for. Every bucket is
     * present even when it is empty, because a missing column reads as a
     * rendering fault and a zero reads as the truth.
     *
     * @return array<string,int>
     */
    public function pipeline(): array
    {
        $counts = array_fill_keys(array_keys(Stage::deskBuckets()), 0);
        foreach ($this->countsByStage() as $stage => $n) {
            $counts[Stage::deskBucket($stage)] += $n;
        }
        return $counts;
    }

    /**
     * Everything with a client decision date on it, soonest first. The only
     * dated thing Phase 2 has: payer and appeal deadlines arrive with work
     * batches, and until then the Desk says so rather than showing an empty
     * board that looks broken.
     *
     * @return list<array<string,mixed>>
     */
    public function withDecisionDates(): array
    {
        return $this->db->all(
            'SELECT e.*, o.legal_name, o.display_name, o.public_ref AS organization_ref'
            . ' FROM sa_engagements e'
            . ' JOIN sa_organizations o ON o.id = e.organization_id'
            . ' WHERE e.client_decision_due_at IS NOT NULL AND e.closed_at IS NULL'
            . ' ORDER BY e.client_decision_due_at ASC'
        );
    }

    private function uniquePublicRef(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $ref = Uuid::publicRef('ENG');
            if (!$this->db->exists('SELECT id FROM sa_engagements WHERE public_ref = :r', ['r' => $ref])) {
                return $ref;
            }
        }
        throw new \RuntimeException('Could not mint a unique engagement reference.');
    }
}
