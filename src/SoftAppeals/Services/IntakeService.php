<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Database;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\FitDecision;
use SoftAppeals\Domain\IntakeForms;
use SoftAppeals\Repositories\EngagementRepository;
use SoftAppeals\Repositories\IntakeRepository;
use SoftAppeals\Support\Clock;

/**
 * Inquiries: taking one in, and deciding what happens to it.
 *
 * Taking one in is idempotent. The hash of the original submission is the key,
 * so a double click, a browser retry, or a second run of the importer over the
 * same archive file all land on the record that is already there. One
 * submission, one intake, however many times the request arrives.
 *
 * Deciding is the drawer in section 12.5. Four outcomes, and the server accepts
 * exactly those four. Accepting is the only one that creates anything: it opens
 * an engagement and leaves it at "terms ready", which is a preview waiting to
 * be approved and not an email that has already gone. Nothing in this class
 * sends anything to anybody.
 */
final class IntakeService
{
    private Database $db;
    private Clock $clock;
    private IntakeRepository $intakes;
    private EngagementRepository $engagements;
    private EngagementService $engagementService;
    private AuditService $audit;

    public function __construct(
        Database $db,
        Clock $clock,
        IntakeRepository $intakes,
        EngagementRepository $engagements,
        EngagementService $engagementService,
        AuditService $audit
    ) {
        $this->db = $db;
        $this->clock = $clock;
        $this->intakes = $intakes;
        $this->engagements = $engagements;
        $this->engagementService = $engagementService;
        $this->audit = $audit;
    }

    /**
     * Take in one submission.
     *
     * $originalPayload is whatever the caller considers the original bytes: the
     * posted body for a live submission, the archive file for an imported one.
     * It is hashed and never stored, and the hash is what makes the write
     * idempotent.
     *
     * @param array<string,string> $answers already filtered to the form's own
     *        allowlist of fields, exactly as sa-lead.php filters them
     * @return array{id:string,created:bool}
     */
    public function record(
        string $source,
        array $answers,
        string $originalPayload,
        ?string $submittedAtUtc = null,
        ?string $legacyRecordPath = null
    ): array {
        if (!IntakeForms::isKnown($source) && $source !== IntakeForms::SOURCE_LEGACY_LOG) {
            throw new \RuntimeException('Unknown intake source: ' . $source);
        }

        $result = $this->intakes->record(
            $source,
            $answers,
            hash('sha256', $originalPayload),
            $submittedAtUtc ?? $this->clock->nowUtc(),
            $legacyRecordPath
        );

        $this->audit->record(
            'intake.record',
            'success',
            'intake',
            $result['id'],
            ['source' => $source, 'reason' => $result['created'] ? 'stored' : 'already stored']
        );

        return $result;
    }

    /**
     * The fit review. One decision, one note, and the terms she would put on an
     * engagement if she is accepting.
     *
     * @return array{status:string,engagement_id:?string,engagement_ref:?string}
     */
    public function review(
        string $intakeId,
        string $decision,
        ?string $note,
        ?string $userId,
        string $feeBasis = EngagementTerms::FEE_NOT_SET,
        ?string $secureChannel = null,
        ?string $assessmentWindow = null
    ): array {
        if (!FitDecision::isValid($decision)) {
            throw new \RuntimeException('Unknown fit decision: ' . $decision);
        }

        $intake = $this->intakes->find($intakeId);
        if ($intake === null) {
            throw new \RuntimeException('No such inquiry.');
        }

        $status = FitDecision::resultingStatus($decision);

        return $this->db->transaction(function () use (
            $intake,
            $intakeId,
            $decision,
            $status,
            $note,
            $userId,
            $feeBasis,
            $secureChannel,
            $assessmentWindow
        ): array {
            $this->intakes->review($intakeId, $status, $decision, $note, $userId);

            $engagementId = null;
            $engagementRef = null;
            $existing = $this->engagements->findByIntake($intakeId);

            if ($decision === FitDecision::ACCEPT) {
                if ($existing !== null) {
                    // Reviewed twice. The engagement is already open, so the
                    // terms are updated rather than a second engagement being
                    // opened for the same practice and the same enquiry.
                    $engagementId = (string) $existing['id'];
                    $this->engagements->setTerms(
                        $engagementId,
                        $feeBasis,
                        $secureChannel,
                        $assessmentWindow
                    );
                } else {
                    $opened = $this->engagementService->openFromIntake(
                        $intake,
                        $feeBasis,
                        $secureChannel,
                        $assessmentWindow,
                        $userId
                    );
                    $engagementId = $opened['engagement_id'];
                }
            } elseif ($decision === FitDecision::DECLINE && $existing !== null) {
                // An engagement was opened and is now being turned down. It
                // closes; it is not deleted, and the reason stays on it.
                $engagementId = (string) $existing['id'];
                $this->engagementService->decline($engagementId, $note, $userId);
            }

            if ($engagementId !== null) {
                $row = $this->engagements->find($engagementId);
                $engagementRef = $row === null ? null : (string) $row['public_ref'];
            }

            // `field` rather than `to_stage`: this is an intake status, and the
            // stage keys in the audit allowlist mean an engagement stage. Two
            // different vocabularies under one key is how a trail stops being
            // readable a year later.
            $this->audit->record('intake.review', 'success', 'intake', $intakeId, [
                'reason' => $decision,
                'field'  => $status,
            ], $intake['organization_id'] === null ? null : (string) $intake['organization_id']);

            return [
                'status'         => $status,
                'engagement_id'  => $engagementId,
                'engagement_ref' => $engagementRef,
            ];
        });
    }
}
