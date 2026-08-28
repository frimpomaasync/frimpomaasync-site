<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Database;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\EngagementRepository;
use SoftAppeals\Repositories\IntakeRepository;
use SoftAppeals\Repositories\OrganizationRepository;
use SoftAppeals\Repositories\StatusEventRepository;

/**
 * Turning an accepted inquiry into an engagement, and moving one along.
 *
 * The state machine is walked, never jumped. An accepted inquiry does not
 * appear at "terms ready" out of nowhere: the engagement is opened at
 * "inquiry received", moved to "fit review", then to "terms ready", each move
 * checked against Domain\Stage and each one leaving a line on the timeline.
 * That is three writes where one would do, and it is worth it, because the
 * timeline is what a practice is shown and a timeline with a hole in it is
 * worse than no timeline.
 *
 * The whole thing runs in one transaction. A half-created engagement, with an
 * organization but no stage history, is not a state anybody should have to
 * recover from by hand on a shared host with no SQL console.
 */
final class EngagementService
{
    private Database $db;
    private OrganizationRepository $organizations;
    private EngagementRepository $engagements;
    private IntakeRepository $intakes;
    private StatusEventRepository $timeline;
    private AuditService $audit;

    public function __construct(
        Database $db,
        OrganizationRepository $organizations,
        EngagementRepository $engagements,
        IntakeRepository $intakes,
        StatusEventRepository $timeline,
        AuditService $audit
    ) {
        $this->db = $db;
        $this->organizations = $organizations;
        $this->engagements = $engagements;
        $this->intakes = $intakes;
        $this->timeline = $timeline;
        $this->audit = $audit;
    }

    /**
     * Open an engagement for an accepted inquiry, ready for the terms preview.
     *
     * The organization is created from the inquiry if the inquiry is not
     * already linked to one, as a prospect. It becomes active later, when there
     * is an executed agreement behind it, not when she decides she likes them.
     *
     * @param array<string,mixed> $intake
     * @return array{engagement_id:string,organization_id:string}
     */
    public function openFromIntake(
        array $intake,
        string $feeBasis = EngagementTerms::FEE_NOT_SET,
        ?string $secureChannel = null,
        ?string $assessmentWindow = null,
        ?string $actorId = null
    ): array {
        $intakeId = (string) $intake['id'];

        return $this->db->transaction(function () use (
            $intake,
            $intakeId,
            $feeBasis,
            $secureChannel,
            $assessmentWindow,
            $actorId
        ): array {
            $organizationId = $intake['organization_id'] !== null
                ? (string) $intake['organization_id']
                : $this->organizations->create(
                    (string) $intake['organization_name'],
                    (string) $intake['organization_name'],
                    $intake['organization_type'] === null ? null : (string) $intake['organization_type'],
                    $intake['state'] === null ? null : (string) $intake['state'],
                    OrganizationRepository::STATUS_PROSPECT
                );

            if ($intake['organization_id'] === null) {
                $this->intakes->linkOrganization($intakeId, $organizationId);
            }

            $engagementId = $this->engagements->create(
                $organizationId,
                $intakeId,
                Stage::INQUIRY_RECEIVED,
                $feeBasis,
                $secureChannel,
                $assessmentWindow
            );

            $this->timeline->record(
                $engagementId,
                'engagement.opened',
                'Your enquiry was received and opened for review.',
                null,
                Stage::INQUIRY_RECEIVED,
                StatusEventRepository::ACTOR_STAFF,
                $actorId,
                ['source' => (string) $intake['source']]
            );

            // Walk the machine rather than jumping it. Each move is checked
            // against the transition table before it is written.
            $this->move(
                $engagementId,
                Stage::FIT_REVIEW,
                'Your enquiry was reviewed for fit.',
                'engagement.fit_review',
                $actorId
            );
            $this->move(
                $engagementId,
                Stage::TERMS_READY,
                'Your assessment terms are being prepared.',
                'engagement.terms_ready',
                $actorId,
                [
                    'fee_basis'         => $feeBasis,
                    'assessment_window' => $assessmentWindow,
                    'secure_channel'    => $secureChannel,
                ]
            );

            $this->audit->record('engagement.open', 'success', 'engagement', $engagementId, [
                'to_stage'  => Stage::TERMS_READY,
                'fee_rate_bps' => EngagementTerms::feeRateBps($feeBasis),
            ], $organizationId);

            return ['engagement_id' => $engagementId, 'organization_id' => $organizationId];
        });
    }

    /**
     * Move one engagement one step, and write the line a client will read.
     *
     * $actorType is who moved it. It defaults to staff because that is who
     * moves most things, and the preferences page passes 'client' because a
     * timeline that credits her with a decision the practice made is a timeline
     * that misdescribes the only history the practice is ever shown.
     *
     * @param array<string,scalar|null> $metadata
     * @throws \RuntimeException when the move is not an allowed edge, or when
     *         somebody else moved the record first
     */
    public function move(
        string $engagementId,
        string $to,
        string $publicLabel,
        string $eventType,
        ?string $actorId = null,
        array $metadata = [],
        ?int $expectedVersion = null,
        string $actorType = StatusEventRepository::ACTOR_STAFF
    ): void {
        $before = $this->engagements->find($engagementId);
        if ($before === null) {
            throw new \RuntimeException('No such engagement.');
        }
        $from = (string) $before['stage'];

        if (!$this->engagements->transition($engagementId, $to, $expectedVersion)) {
            $this->audit->record('engagement.transition', 'failure', 'engagement', $engagementId, [
                'from_stage' => $from,
                'to_stage'   => $to,
                'reason'     => 'somebody else moved this record first',
            ], (string) $before['organization_id']);
            throw new \RuntimeException(
                'This engagement moved while you were looking at it. Reload and try again.'
            );
        }

        $this->timeline->record(
            $engagementId,
            $eventType,
            $publicLabel,
            $from,
            $to,
            $actorType,
            $actorId,
            $metadata
        );

        $this->audit->record('engagement.transition', 'success', 'engagement', $engagementId, [
            'from_stage' => $from,
            'to_stage'   => $to,
        ], (string) $before['organization_id']);
    }

    /**
     * Close an engagement that was opened and then turned down. Terminal, and
     * deliberately so: a declined inquiry cannot be quietly revived, it needs a
     * new record and a new trail.
     */
    public function decline(string $engagementId, ?string $reason, ?string $actorId = null): void
    {
        $this->move(
            $engagementId,
            Stage::DECLINED,
            'This enquiry was closed.',
            'engagement.declined',
            $actorId,
            ['reason' => $reason]
        );
    }
}
