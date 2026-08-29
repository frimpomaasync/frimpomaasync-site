<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Config;
use SoftAppeals\Database;
use SoftAppeals\Domain\ActionRequestKind;
use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\InvoiceStatus;
use SoftAppeals\Domain\RecoveryRecord;
use SoftAppeals\Domain\SafeText;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Domain\SubmissionEventType;
use SoftAppeals\Repositories\ContactRepository;
use SoftAppeals\Repositories\DocumentRepository;
use SoftAppeals\Repositories\EngagementRepository;
use SoftAppeals\Repositories\InvoiceRepository;
use SoftAppeals\Repositories\PreferenceRepository;
use SoftAppeals\Repositories\RecoveryRepository;
use SoftAppeals\Repositories\RecoveryScopeRepository;
use SoftAppeals\Repositories\SettingsRepository;
use SoftAppeals\Repositories\StatusEventRepository;
use SoftAppeals\Repositories\SubmissionEventRepository;
use SoftAppeals\Repositories\WorkBatchRepository;
use SoftAppeals\Support\Clock;
use SoftAppeals\Support\Money;

/**
 * The money. Section 19, every rule, and the reconciliation half of section
 * 7.4. Phase 7.
 *
 * What this class writes:
 *
 *   A VERIFIED row, by her, only on money that has actually reached the
 *   practice and been checked, only against a batch the payer overturned,
 *   never for more than the payer said it overturned. The fee is calculated
 *   here and nowhere else: integer cents, the scope's basis points, half up,
 *   Money::feeCents. The row snapshots the rate and names the executed
 *   agreement that set it (rule 10), so a later agreement version cannot
 *   quietly change what an earlier row was worth (rule "fee rate change only
 *   through a new agreement version").
 *
 *   An ADJUSTMENT or a REVERSAL, as a new row naming the verified row it
 *   takes from. The original is not touched (rule 8). The fee credit is
 *   calculated at the ORIGINAL row's rate, because that is the rate the fee
 *   was charged at.
 *
 *   An INVOICE, as the sum of every row not yet on one. Issued, paid, void
 *   are three moves with three stamps. Void hands the rows back.
 *
 * What this class never does: read a submission event or a payer decision
 * as money (rules 3, 4, 5), accept a figure it cannot parse exactly, or
 * touch a float.
 */
final class ReconciliationService
{
    public const TEMPLATE_VERIFIED  = 'recovery_verified';
    public const TEMPLATE_INVOICE   = 'invoice_available';

    /** How long an issued invoice has before the Desk calls it overdue. */
    public const DEFAULT_TERMS_DAYS = 30;

    private Config $config;
    private Database $db;
    private Clock $clock;
    private RecoveryRepository $recoveries;
    private InvoiceRepository $invoices;
    private RecoveryScopeRepository $scopes;
    private SubmissionEventRepository $events;
    private WorkBatchRepository $batches;
    private EngagementRepository $engagements;
    private DocumentRepository $documents;
    private ContactRepository $contacts;
    private PreferenceRepository $preferences;
    private StatusEventRepository $timeline;
    private SettingsRepository $settings;
    private DocumentVault $vault;
    private ActionRequestService $requests;
    private MailService $mail;
    private AuditService $audit;

    public function __construct(
        Config $config,
        Database $db,
        Clock $clock,
        RecoveryRepository $recoveries,
        InvoiceRepository $invoices,
        RecoveryScopeRepository $scopes,
        SubmissionEventRepository $events,
        WorkBatchRepository $batches,
        EngagementRepository $engagements,
        DocumentRepository $documents,
        ContactRepository $contacts,
        PreferenceRepository $preferences,
        StatusEventRepository $timeline,
        SettingsRepository $settings,
        DocumentVault $vault,
        ActionRequestService $requests,
        MailService $mail,
        AuditService $audit
    ) {
        $this->config = $config;
        $this->db = $db;
        $this->clock = $clock;
        $this->recoveries = $recoveries;
        $this->invoices = $invoices;
        $this->scopes = $scopes;
        $this->events = $events;
        $this->batches = $batches;
        $this->engagements = $engagements;
        $this->documents = $documents;
        $this->contacts = $contacts;
        $this->preferences = $preferences;
        $this->timeline = $timeline;
        $this->settings = $settings;
        $this->vault = $vault;
        $this->requests = $requests;
        $this->mail = $mail;
        $this->audit = $audit;
    }

    // ------------------------------------------------------------------
    // Reading.
    // ------------------------------------------------------------------

    /**
     * Every recovery row on the engagement, with the batch and the invoice
     * joined on and the running remainder on each verified row.
     *
     * @param array<string,mixed> $engagement
     * @return list<array<string,mixed>>
     */
    public function ledger(array $engagement): array
    {
        $rows = $this->recoveries->forEngagement((string) $engagement['id']);
        $takenFrom = [];
        foreach ($rows as $row) {
            if ($row['adjusts_recovery_id'] !== null) {
                $parent = (string) $row['adjusts_recovery_id'];
                $takenFrom[$parent] = ($takenFrom[$parent] ?? 0) + (int) $row['amount_cents'];
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $row['taken_back_cents'] = $takenFrom[$id] ?? 0;
            $row['remaining_cents'] = (string) $row['kind'] === RecoveryRecord::KIND_VERIFIED
                ? (int) $row['amount_cents'] - ($takenFrom[$id] ?? 0)
                : 0;
            $row['can_adjust'] = (string) $row['kind'] === RecoveryRecord::KIND_VERIFIED
                && $row['remaining_cents'] > 0;
            $out[] = $row;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function invoices(string $engagementId): array
    {
        return $this->invoices->forEngagement($engagementId);
    }

    /**
     * The batches money can be verified against right now: overturned, in
     * scope, with what the payer said and what is verified so far.
     *
     * @param array<string,mixed> $engagement
     * @return list<array<string,mixed>>
     */
    public function verifiable(array $engagement): array
    {
        $engagementId = (string) $engagement['id'];
        $scope = $this->scopes->forEngagement($engagementId);
        $inScope = $scope === null ? [] : $this->scopes->batchIds((string) $scope['id']);
        $out = [];
        foreach ($this->batches->forEngagement($engagementId) as $batch) {
            if ((string) $batch['stage'] !== BatchStage::OVERTURNED) {
                continue;
            }
            $batchId = (string) $batch['id'];
            $overturned = $this->overturnedCents($batchId);
            $verified = 0;
            foreach ($this->recoveries->forBatch($batchId) as $row) {
                if ((string) $row['kind'] === RecoveryRecord::KIND_VERIFIED) {
                    $verified += (int) $row['amount_cents'];
                }
            }
            $out[] = [
                'batch'            => $batch,
                'in_scope'         => in_array($batchId, $inScope, true),
                'overturned_cents' => $overturned,
                'verified_cents'   => $verified,
                'remaining_cents'  => max(0, $overturned - $verified),
                'has_verified'     => $verified > 0 || $this->hasVerifiedRow($batchId),
            ];
        }
        return $out;
    }

    /**
     * Section 12.4's recovery summary and section 15.9's fee block, from one
     * place, in integer cents, with the agreement that produced the fee.
     *
     * @param array<string,mixed> $engagement
     * @return array<string,mixed>
     */
    public function summary(array $engagement): array
    {
        $engagementId = (string) $engagement['id'];
        $scope = $this->scopes->forEngagement($engagementId);
        $money = $this->recoveries->totals($engagementId);
        $invoiced = $this->invoices->totals($engagementId);
        $events = $this->events->totals($engagementId);
        $agreement = $this->documents->current($engagementId, DocumentKind::RECOVERY_AGREEMENT);
        $rateBps = $scope === null || $scope['fee_rate_bps'] === null ? null : (int) $scope['fee_rate_bps'];

        $awaiting = 0;
        $awaitingCount = 0;
        foreach ($this->verifiable($engagement) as $row) {
            if (!$row['has_verified']) {
                $awaiting += $row['overturned_cents'];
                $awaitingCount++;
            }
        }

        $invoiceLine = 'Not created';
        if ($invoiced['draft_count'] > 0) {
            $invoiceLine = 'Draft, not issued';
        } elseif ($invoiced['issued_count'] > 0) {
            $invoiceLine = 'Issued, ' . Money::format($invoiced['outstanding_cents']) . ' outstanding';
        } elseif ($invoiced['paid_cents'] > 0 || $invoiced['invoiced_cents'] > 0) {
            $invoiceLine = 'Paid in full';
        }

        return [
            'scope'                => $scope,
            'rate'                 => $scope === null ? 'Not set' : RecoveryService::feeRateLabel((string) $scope['fee_basis'], $rateBps),
            'rate_bps'             => $rateBps,
            'agreement'            => $agreement,
            'agreement_ref'        => $agreement === null ? null : (string) $agreement['public_ref'],
            'agreement_executed'   => $agreement !== null && (string) $agreement['status'] === DocumentStatus::EXECUTED,

            'submitted'            => Money::format($events['submitted_cents']),
            'submitted_count'      => $events['submitted_count'],
            'overturned'           => Money::format($events['overturned_cents']),
            'overturned_count'     => $events['overturned_count'],
            'upheld'               => Money::format($events['upheld_cents']),
            'upheld_count'         => $events['upheld_count'],

            'awaiting'             => Money::format($awaiting),
            'awaiting_cents'       => $awaiting,
            'awaiting_count'       => $awaitingCount,

            'verified'             => Money::format($money['verified_cents']),
            'verified_cents'       => $money['verified_cents'],
            'verified_count'       => $money['verified_count'],
            'taken_back'           => Money::format($money['taken_back_cents']),
            'taken_back_cents'     => $money['taken_back_cents'],
            'net'                  => Money::format($money['net_cents']),
            'net_cents'            => $money['net_cents'],

            'fee'                  => Money::format($money['fee_cents']),
            'fee_cents'            => $money['fee_cents'],
            'fee_credit'           => Money::format($money['fee_credit_cents']),
            'fee_credit_cents'     => $money['fee_credit_cents'],
            'fee_net'              => Money::format($money['fee_net_cents']),
            'fee_net_cents'        => $money['fee_net_cents'],

            'uninvoiced'           => Money::format($money['uninvoiced_fee_cents']),
            'uninvoiced_cents'     => $money['uninvoiced_fee_cents'],
            'uninvoiced_count'     => $money['uninvoiced_count'],
            'invoiced'             => Money::format($invoiced['invoiced_cents']),
            'invoiced_cents'       => $invoiced['invoiced_cents'],
            'paid'                 => Money::format($invoiced['paid_cents']),
            'paid_cents'           => $invoiced['paid_cents'],
            'outstanding'          => Money::format($invoiced['outstanding_cents']),
            'outstanding_cents'    => $invoiced['outstanding_cents'],
            'draft_count'          => $invoiced['draft_count'],
            'issued_count'         => $invoiced['issued_count'],
            'invoice'              => $invoiceLine,
        ];
    }

    /**
     * The same figures across every practice, for the Desk home. Section
     * 12.4, the recovery summary cards.
     *
     * @return array<string,mixed>
     */
    public function summaryEverywhere(): array
    {
        $money = $this->recoveries->totalsEverywhere();
        $invoiced = $this->invoices->totals(null);
        $events = $this->events->totalsEverywhere();
        $awaiting = $this->recoveries->awaitingVerification();
        $awaitingCents = 0;
        foreach ($awaiting as $batch) {
            $awaitingCents += $this->overturnedCents((string) $batch['id']);
        }
        return [
            'denied_accepted'  => Money::format($this->batches->deniedAcceptedEverywhere()),
            'submitted'        => Money::format($events['submitted_cents']),
            'awaiting'         => Money::format($awaitingCents),
            'awaiting_count'   => count($awaiting),
            'verified'         => Money::format($money['verified_cents']),
            'fee'              => Money::format($money['fee_net_cents']),
            'invoiced'         => Money::format($invoiced['invoiced_cents']),
            'paid'             => Money::format($invoiced['paid_cents']),
            'taken_back'       => Money::format($money['taken_back_cents']),
            'uninvoiced'       => Money::format($money['uninvoiced_fee_cents']),
            'uninvoiced_count' => $money['uninvoiced_count'],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function awaitingVerification(): array
    {
        return $this->recoveries->awaitingVerification();
    }

    /** @return list<array<string,mixed>> */
    public function invoiceReady(): array
    {
        return $this->recoveries->invoiceReadyEverywhere();
    }

    /** @return list<array<string,mixed>> */
    public function outstandingInvoices(): array
    {
        return $this->invoices->outstandingEverywhere();
    }

    /** The rendered invoice, out of the vault, or null before it is issued. */
    public function invoiceText(array $invoice): ?string
    {
        if ($invoice['private_path'] === null) {
            return null;
        }
        return $this->vault->read((string) $invoice['private_path']);
    }

    /** @return array{found:bool,matches:bool,sha256:?string} */
    public function verifyInvoice(array $invoice): array
    {
        if ($invoice['private_path'] === null) {
            return ['found' => false, 'matches' => false, 'sha256' => null];
        }
        return $this->vault->verify((string) $invoice['private_path'], $invoice['content_sha256'] === null ? null : (string) $invoice['content_sha256']);
    }

    // ------------------------------------------------------------------
    // Verifying. Rule 6: the one thing that creates a fee.
    // ------------------------------------------------------------------

    /**
     * Record money that has actually reached the practice.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @param array<string,mixed> $batch
     * @param array<string,mixed> $input amount, source, verified_on, qualifies, note
     * @return array<string,mixed> the recovery row
     */
    public function verify(array $engagement, array $batch, array $input, ?string $userId = null): array
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];
        $batchId = (string) $batch['id'];

        $this->requireFinance();
        $this->requireMoneyStage($engagementId, 'verify a reimbursement');
        if ((string) $batch['engagement_id'] !== $engagementId) {
            throw new \RuntimeException('That batch is not on this engagement.');
        }
        if ((string) $batch['stage'] !== BatchStage::OVERTURNED) {
            $this->audit->record('recovery.verify', 'denied', 'work_batch', $batchId, [
                'reason' => 'batch is not overturned',
            ], $organizationId);
            throw new \RuntimeException(
                'Batch ' . (string) $batch['public_ref'] . ' is "'
                . BatchStage::staffLabel((string) $batch['stage']) . '". A reimbursement is verified '
                . 'against a batch the payer overturned, and nothing else.'
            );
        }

        $scope = $this->scopes->forEngagement($engagementId);
        if ($scope === null || !$this->scopes->coversBatch((string) $scope['id'], $batchId)) {
            throw new \RuntimeException(
                'Batch ' . (string) $batch['public_ref'] . ' is outside the approved scope, so no '
                . 'fee applies to it under this agreement.'
            );
        }
        $agreement = $this->documents->current($engagementId, DocumentKind::RECOVERY_AGREEMENT);
        if ($agreement === null || (string) $agreement['status'] !== DocumentStatus::EXECUTED) {
            throw new \RuntimeException('No executed Recovery Services Agreement is on this engagement.');
        }

        $text = trim((string) ($input['amount'] ?? ''));
        $cents = $text === '' ? null : Money::parseCents($text);
        if ($cents === null) {
            throw new \RuntimeException('Not saved: the verified amount has to be a plain dollar figure, like 7,000.00. Zero is allowed.');
        }

        $overturned = $this->overturnedCents($batchId);
        $already = 0;
        foreach ($this->recoveries->forBatch($batchId) as $row) {
            if ((string) $row['kind'] === RecoveryRecord::KIND_VERIFIED) {
                $already += (int) $row['amount_cents'];
            }
        }
        if ($cents + $already > $overturned) {
            $this->audit->record('recovery.verify', 'denied', 'work_batch', $batchId, [
                'reason'       => 'more than the payer overturned',
                'amount_cents' => (string) $cents,
            ], $organizationId);
            throw new \RuntimeException(
                'Not saved: ' . Money::format($cents) . ' plus the ' . Money::format($already)
                . ' already verified is more than the ' . Money::format($overturned)
                . ' the payer overturned on this batch. Check the figure, or record a further '
                . 'payer decision first.'
            );
        }

        $source = trim((string) ($input['source'] ?? ''));
        if (!RecoveryRecord::isValidSource($source)) {
            throw new \RuntimeException('Not saved: say what the money was verified against.');
        }
        $verifiedAt = $this->readDate($input['verified_on'] ?? null, 'the date the money arrived') ?? $this->clock->nowUtc();
        if (substr($verifiedAt, 0, 10) > substr($this->clock->nowUtc(), 0, 10)) {
            throw new \RuntimeException('Not saved: the date the money arrived cannot be in the future.');
        }
        $qualifies = (string) ($input['qualifies'] ?? 'yes') !== 'no';
        $note = trim((string) ($input['note'] ?? '')) === ''
            ? null
            : SafeText::require((string) $input['note'], 500, 'the note');
        if (!$qualifies && $note === null) {
            throw new \RuntimeException('Not saved: say why this reimbursement does not qualify under the agreement.');
        }

        $rateBps = $scope['fee_rate_bps'] === null ? null : (int) $scope['fee_rate_bps'];
        $fee = $qualifies && $rateBps !== null ? Money::feeCents($cents, $rateBps) : 0;

        return $this->db->transaction(function () use (
            $engagement,
            $engagementId,
            $organizationId,
            $batch,
            $batchId,
            $scope,
            $agreement,
            $cents,
            $qualifies,
            $rateBps,
            $fee,
            $source,
            $verifiedAt,
            $note,
            $userId
        ): array {
            $row = $this->recoveries->record($engagementId, $organizationId, $batchId, [
                'kind'                  => RecoveryRecord::KIND_VERIFIED,
                'adjusts_recovery_id'   => null,
                'agreement_document_id' => (string) $agreement['id'],
                'original_denied_cents' => (int) $batch['denied_amount_cents'],
                'amount_cents'          => $cents,
                'qualifies'             => $qualifies,
                'fee_basis'             => (string) $scope['fee_basis'],
                'fee_rate_bps'          => $rateBps,
                'fee_cents'             => $fee,
                'verification_source'   => $source,
                'verified_at'           => $verifiedAt,
                'note'                  => $note,
            ], $userId);

            // The batch card stops asking for a verification it has. Seen on
            // the walk: after the money was verified the room still read
            // "Verify the reimbursement when it arrives", waiting on the payer.
            $fresh = $this->batches->find($batchId);
            if ($fresh !== null) {
                $this->batches->patch($batchId, [
                    'next_owner'  => BatchStage::OWNER_SOFT_APPEALS,
                    'next_action' => $cents === 0
                        ? 'Verified: nothing arrived. Nothing further on this batch'
                        : 'Reimbursement verified. Nothing further on this batch',
                ], (int) $fresh['row_version']);
            }

            $this->timeline->record(
                $engagementId,
                'recovery.verified',
                RecoveryRecord::timelineLabel(RecoveryRecord::KIND_VERIFIED),
                null,
                null,
                StatusEventRepository::ACTOR_STAFF,
                $userId,
                ['batch_ref' => (string) $batch['public_ref'], 'amount_cents' => (string) $cents]
            );

            $this->requests->closeKind($engagement, ActionRequestKind::VERIFY_REIMBURSEMENT, $userId);

            $this->audit->record('recovery.verify', 'success', 'recovery', (string) $row['id'], [
                'batch_ref'    => (string) $batch['public_ref'],
                'amount_cents' => (string) $cents,
                'fee_rate_bps' => $rateBps === null ? null : (string) $rateBps,
                'source'       => $source,
            ], $organizationId);

            $this->notifyVerified($engagement, (string) $row['id']);

            return $row;
        });
    }

    /**
     * Take money back off a verified row. Rule 8: a new row, the original
     * untouched.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @param array<string,mixed> $original the verified row
     * @param array<string,mixed> $input kind, amount, occurred_on, note
     * @return array<string,mixed> the new row
     */
    public function adjust(array $engagement, array $original, array $input, ?string $userId = null): array
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];

        $this->requireFinance();
        $this->requireMoneyStage($engagementId, 'record an adjustment');
        if ((string) $original['engagement_id'] !== $engagementId) {
            throw new \RuntimeException('That recovery record is not on this engagement.');
        }
        if ((string) $original['kind'] !== RecoveryRecord::KIND_VERIFIED) {
            throw new \RuntimeException('An adjustment is taken off a verified reimbursement, not off another adjustment.');
        }

        $kind = trim((string) ($input['kind'] ?? ''));
        if (!RecoveryRecord::takesBack($kind)) {
            throw new \RuntimeException('Choose an adjustment or a reversal.');
        }

        $remaining = (int) $original['amount_cents'];
        foreach ($this->recoveries->takenFrom((string) $original['id']) as $row) {
            $remaining -= (int) $row['amount_cents'];
        }
        if ($remaining <= 0) {
            throw new \RuntimeException('Nothing is left on ' . (string) $original['public_ref'] . ' to take back.');
        }

        $cents = $kind === RecoveryRecord::KIND_REVERSAL
            ? $remaining
            : Money::parseCents(trim((string) ($input['amount'] ?? '')));
        if ($cents === null || $cents <= 0) {
            throw new \RuntimeException('Not saved: the amount taken back has to be a plain dollar figure above zero.');
        }
        if ($cents > $remaining) {
            throw new \RuntimeException(
                'Not saved: ' . Money::format($cents) . ' is more than the ' . Money::format($remaining)
                . ' still standing on ' . (string) $original['public_ref'] . '. Record a reversal to take all of it back.'
            );
        }

        $note = SafeText::require((string) ($input['note'] ?? ''), 500, 'the reason');
        if (mb_strlen($note) < 4) {
            throw new \RuntimeException('Not saved: say why the payer took it back. It goes on the record.');
        }
        $occurred = $this->readDate($input['occurred_on'] ?? null, 'the date it was taken back') ?? $this->clock->nowUtc();

        // The credit is at the rate the fee was charged at. Rule 10 read the
        // other way: what was charged on this money is what comes off.
        $rateBps = $original['fee_rate_bps'] === null ? null : (int) $original['fee_rate_bps'];
        $qualifies = (int) $original['qualifies'] === 1;
        $feeCredit = $qualifies && $rateBps !== null ? Money::feeCents($cents, $rateBps) : 0;

        return $this->db->transaction(function () use (
            $engagement,
            $engagementId,
            $organizationId,
            $original,
            $kind,
            $cents,
            $qualifies,
            $rateBps,
            $feeCredit,
            $occurred,
            $note,
            $userId
        ): array {
            $row = $this->recoveries->record($engagementId, $organizationId, (string) $original['work_batch_id'], [
                'kind'                  => $kind,
                'adjusts_recovery_id'   => (string) $original['id'],
                'agreement_document_id' => $original['agreement_document_id'] === null ? null : (string) $original['agreement_document_id'],
                'original_denied_cents' => (int) $original['original_denied_cents'],
                'amount_cents'          => $cents,
                'qualifies'             => $qualifies,
                'fee_basis'             => (string) $original['fee_basis'],
                'fee_rate_bps'          => $rateBps,
                'fee_cents'             => $feeCredit,
                'verification_source'   => (string) $original['verification_source'],
                'verified_at'           => $occurred,
                'note'                  => $note,
            ], $userId);

            $batch = $this->batches->find((string) $original['work_batch_id']);
            $this->timeline->record(
                $engagementId,
                'recovery.' . $kind,
                RecoveryRecord::timelineLabel($kind),
                null,
                null,
                StatusEventRepository::ACTOR_STAFF,
                $userId,
                ['batch_ref' => $batch === null ? null : (string) $batch['public_ref'], 'amount_cents' => (string) $cents]
            );

            $this->audit->record('recovery.' . $kind, 'success', 'recovery', (string) $row['id'], [
                'amount_cents' => (string) $cents,
                'fee_rate_bps' => $rateBps === null ? null : (string) $rateBps,
                'reason'       => mb_substr($note, 0, 200),
            ], $organizationId);

            return $row;
        });
    }

    // ------------------------------------------------------------------
    // Invoicing.
    // ------------------------------------------------------------------

    /**
     * Gather every row not yet on an invoice into a draft.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @return array<string,mixed> the invoice row
     */
    public function createInvoice(array $engagement, ?string $userId = null): array
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];

        $this->requireFinance();
        $this->requireMoneyStage($engagementId, 'create an invoice');
        if ($this->invoices->draftFor($engagementId) !== null) {
            throw new \RuntimeException('A draft invoice is already open on this engagement. Issue it or void it first.');
        }
        $rows = $this->recoveries->uninvoiced($engagementId);
        if ($rows === []) {
            throw new \RuntimeException('Nothing is waiting to be invoiced. A fee is created only by a verified reimbursement.');
        }
        $fee = 0;
        $credit = 0;
        foreach ($rows as $row) {
            if ((string) $row['kind'] === RecoveryRecord::KIND_VERIFIED) {
                $fee += (int) $row['fee_cents'];
            } else {
                $credit += (int) $row['fee_cents'];
            }
        }
        if ($fee === 0 && $credit === 0) {
            throw new \RuntimeException(
                'The rows waiting carry no fee, so there is nothing to invoice. They stay on the ledger as verified.'
            );
        }
        $agreement = $this->documents->current($engagementId, DocumentKind::RECOVERY_AGREEMENT);

        return $this->db->transaction(function () use ($engagementId, $organizationId, $rows, $fee, $credit, $agreement, $userId): array {
            $invoice = $this->invoices->createDraft(
                $engagementId,
                $organizationId,
                $fee,
                $credit,
                $agreement === null ? null : (string) $agreement['id'],
                $userId
            );
            $this->recoveries->attachToInvoice(
                (string) $invoice['id'],
                array_map(static fn (array $r): string => (string) $r['id'], $rows)
            );
            $this->audit->record('invoice.create', 'success', 'invoice', (string) $invoice['id'], [
                'amount_cents' => (string) ($fee - $credit),
                'count'        => (string) count($rows),
            ], $organizationId);
            return $invoice;
        });
    }

    /**
     * Issue a draft. Renders the invoice into the vault, stamps it, tells the
     * practice's billing contact there is one to read. Section 16.2, template
     * 15: a notice and a link, never the figure.
     *
     * @param array<string,mixed> $engagement joined with its organization
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $input due_on
     * @return array<string,mixed> the invoice as issued
     */
    public function issueInvoice(array $engagement, array $invoice, array $input, ?string $userId = null): array
    {
        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];

        $this->requireFinance();
        if ((string) $invoice['engagement_id'] !== $engagementId) {
            throw new \RuntimeException('That invoice is not on this engagement.');
        }
        if ((string) $invoice['status'] !== InvoiceStatus::DRAFT) {
            throw new \RuntimeException('Only a draft is issued. This one is ' . InvoiceStatus::staffLabel((string) $invoice['status']) . '.');
        }
        $due = $this->readDate($input['due_on'] ?? null, 'the date it is due')
            ?? substr($this->clock->utcPlusSeconds(self::DEFAULT_TERMS_DAYS * 86400), 0, 10) . ' 12:00:00';

        $now = $this->clock->nowUtc();
        $body = $this->renderInvoice($engagement, $invoice, $now, $due);
        $path = DocumentVault::invoicePath((string) $engagement['public_ref'], (string) $invoice['public_ref']);
        $sha = $this->vault->write($path, $body);

        return $this->db->transaction(function () use ($engagement, $engagementId, $organizationId, $invoice, $now, $due, $path, $sha, $userId): array {
            if (!$this->invoices->moveStatus((string) $invoice['id'], InvoiceStatus::DRAFT, InvoiceStatus::ISSUED, (int) $invoice['row_version'], [
                'issued_at'      => $now,
                'due_at'         => $due,
                'private_path'   => $path,
                'content_sha256' => $sha,
            ])) {
                throw new \RuntimeException('This invoice changed while you were looking at it. Reload and try again.');
            }
            $this->timeline->record(
                $engagementId,
                'invoice.issued',
                'An invoice was issued to you',
                null,
                null,
                StatusEventRepository::ACTOR_STAFF,
                $userId
            );
            $this->audit->record('invoice.issue', 'success', 'invoice', (string) $invoice['id'], [
                'amount_cents' => (string) $invoice['total_cents'],
            ], $organizationId);
            $this->notifyInvoice($engagement, $invoice);
            $issued = $this->invoices->find((string) $invoice['id']);
            if ($issued === null) {
                throw new \RuntimeException('The invoice vanished as it was issued.');
            }
            return $issued;
        });
    }

    /**
     * Her word that it was paid. Nothing here moves money.
     *
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $input paid_on, note
     */
    public function markPaid(array $engagement, array $invoice, array $input, ?string $userId = null): void
    {
        $this->requireFinance();
        if ((string) $invoice['engagement_id'] !== (string) $engagement['id']) {
            throw new \RuntimeException('That invoice is not on this engagement.');
        }
        if ((string) $invoice['status'] !== InvoiceStatus::ISSUED) {
            throw new \RuntimeException('Only an issued invoice is marked paid. This one is ' . InvoiceStatus::staffLabel((string) $invoice['status']) . '.');
        }
        $paidAt = $this->readDate($input['paid_on'] ?? null, 'the date it was paid') ?? $this->clock->nowUtc();
        $note = trim((string) ($input['note'] ?? '')) === ''
            ? null
            : SafeText::require((string) $input['note'], 500, 'the note');

        $this->db->transaction(function () use ($engagement, $invoice, $paidAt, $note, $userId): void {
            if (!$this->invoices->moveStatus((string) $invoice['id'], InvoiceStatus::ISSUED, InvoiceStatus::PAID, (int) $invoice['row_version'], [
                'paid_at'   => $paidAt,
                'paid_note' => $note,
            ])) {
                throw new \RuntimeException('This invoice changed while you were looking at it. Reload and try again.');
            }
            $this->timeline->record(
                (string) $engagement['id'],
                'invoice.paid',
                'An invoice was paid. Thank you',
                null,
                null,
                StatusEventRepository::ACTOR_STAFF,
                $userId
            );
            $this->audit->record('invoice.paid', 'success', 'invoice', (string) $invoice['id'], [
                'amount_cents' => (string) $invoice['total_cents'],
            ], (string) $engagement['organization_id']);
        });
    }

    /**
     * Void an invoice. Its rows go back to invoice-ready; the row itself
     * stays, so the number is never reused.
     *
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $invoice
     */
    public function voidInvoice(array $engagement, array $invoice, string $reason, ?string $userId = null): void
    {
        $this->requireFinance();
        if ((string) $invoice['engagement_id'] !== (string) $engagement['id']) {
            throw new \RuntimeException('That invoice is not on this engagement.');
        }
        $status = (string) $invoice['status'];
        if (!InvoiceStatus::canMove($status, InvoiceStatus::VOID)) {
            throw new \RuntimeException('A ' . InvoiceStatus::staffLabel($status) . ' invoice cannot be voided.');
        }
        $reason = SafeText::require($reason, 200, 'the reason');
        if (mb_strlen($reason) < 4) {
            throw new \RuntimeException('Say why it is being voided. It goes on the record.');
        }

        $this->db->transaction(function () use ($engagement, $invoice, $status, $reason, $userId): void {
            if (!$this->invoices->moveStatus((string) $invoice['id'], $status, InvoiceStatus::VOID, (int) $invoice['row_version'], [
                'voided_at'   => $this->clock->nowUtc(),
                'void_reason' => $reason,
            ])) {
                throw new \RuntimeException('This invoice changed while you were looking at it. Reload and try again.');
            }
            $this->recoveries->detachFromInvoice((string) $invoice['id']);
            $this->audit->record('invoice.void', 'success', 'invoice', (string) $invoice['id'], [
                'reason' => $reason,
            ], (string) $engagement['organization_id']);
        });
    }

    // ------------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------------

    /** What the payer said it overturned on a batch, in cents. */
    public function overturnedCents(string $batchId): int
    {
        $cents = 0;
        foreach ($this->events->forBatch($batchId) as $event) {
            if (in_array((string) $event['event_type'], [
                SubmissionEventType::DECISION_FAVORABLE,
                SubmissionEventType::DECISION_PARTIAL,
            ], true)) {
                $cents += (int) $event['amount_cents'];
            }
        }
        return $cents;
    }

    private function hasVerifiedRow(string $batchId): bool
    {
        foreach ($this->recoveries->forBatch($batchId) as $row) {
            if ((string) $row['kind'] === RecoveryRecord::KIND_VERIFIED) {
                return true;
            }
        }
        return false;
    }

    /**
     * Money is written at "recovery active" and at "financial
     * reconciliation", and nowhere else. After the reconciliation step is
     * confirmed the figures are final, and before recovery is active there
     * is no agreement to calculate a fee under.
     */
    private function requireMoneyStage(string $engagementId, string $verb): void
    {
        $row = $this->engagements->find($engagementId);
        if ($row === null) {
            throw new \RuntimeException('No such engagement.');
        }
        $stage = (string) $row['stage'];
        if (!in_array($stage, [Stage::RECOVERY_ACTIVE, Stage::RECONCILIATION], true)) {
            $this->audit->record('recovery.money_refused', 'denied', 'engagement', $engagementId, [
                'reason'     => 'cannot ' . $verb,
                'from_stage' => $stage,
            ]);
            throw new \RuntimeException(
                'You cannot ' . $verb . ' at "' . Stage::staffLabel($stage) . '". Money is recorded '
                . 'while recovery is active or under financial reconciliation.'
            );
        }
    }

    /** Section 20 and section 25: recovery finance is a flag, shut on production. */
    private function requireFinance(): void
    {
        if (!$this->config->recoveryFinanceEnabled()) {
            throw new \RuntimeException(
                'Recovery finance is switched off in this environment. Nothing was recorded.'
            );
        }
    }

    private function readDate(mixed $raw, string $what): ?string
    {
        $text = trim((string) ($raw ?? ''));
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            throw new \RuntimeException('Not saved: ' . $what . ' has to be a date, like 2026-09-30.');
        }
        return $text . ' 12:00:00';
    }

    /**
     * The invoice as plain text. Deterministic for a given issue moment, so
     * the hash on the row means something. Rule 10: it names the agreement
     * and every recovery record the fee came from.
     *
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $invoice
     */
    private function renderInvoice(array $engagement, array $invoice, string $issuedAt, string $dueAt): string
    {
        $rows = $this->recoveries->onInvoice((string) $invoice['id']);
        $agreement = $invoice['agreement_document_id'] === null
            ? null
            : $this->documents->find((string) $invoice['agreement_document_id']);
        $scope = $this->scopes->forEngagement((string) $engagement['id']);
        $rateBps = $scope === null || $scope['fee_rate_bps'] === null ? null : (int) $scope['fee_rate_bps'];

        $lines = [];
        $lines[] = 'INVOICE';
        $lines[] = '';
        $lines[] = 'Invoice number: ' . (string) $invoice['public_ref'];
        $lines[] = 'Issued: ' . $this->clock->displayDate($issuedAt);
        $lines[] = 'Due: ' . $this->clock->displayDate($dueAt);
        $lines[] = '';
        $lines[] = 'From';
        $lines[] = '  ' . $this->settings->legalEntity($this->config)
            . ', operating as ' . $this->settings->tradeName($this->config);
        $lines[] = 'To';
        $lines[] = '  ' . (string) $engagement['legal_name'];
        $lines[] = '';
        $lines[] = 'Engagement: ' . (string) $engagement['public_ref'];
        $lines[] = 'Agreement: ' . ($agreement === null
            ? 'not recorded'
            : (string) $agreement['public_ref'] . ' version ' . (int) $agreement['version']
                . ' (' . DocumentKind::label((string) $agreement['kind']) . ')');
        $lines[] = 'Fee basis: ' . ($scope === null
            ? EngagementTerms::feeLabel((string) $engagement['fee_basis'])
            : RecoveryService::feeRateLabel((string) $scope['fee_basis'], $rateBps));
        $lines[] = '';
        $lines[] = 'What this invoice is for';
        $lines[] = '';
        foreach ($rows as $row) {
            $kind = (string) $row['kind'];
            $sign = RecoveryRecord::takesBack($kind) ? '-' : '';
            $lines[] = '  ' . (string) $row['public_ref'] . '  ' . RecoveryRecord::kindLabel($kind)
                . ', batch ' . (string) $row['batch_ref'] . ' (' . (string) $row['batch_label'] . ')';
            $lines[] = '    Reimbursement ' . ($sign === '-' ? 'taken back' : 'verified') . ': '
                . Money::format((int) $row['amount_cents'])
                . ' on ' . $this->clock->displayDate((string) $row['verified_at'])
                . ' (' . RecoveryRecord::sourceLabel((string) $row['verification_source']) . ')';
            $lines[] = '    Fee at ' . ($row['fee_rate_bps'] === null
                ? 'the agreed basis'
                : RecoveryService::feeRateLabel((string) $row['fee_basis'], (int) $row['fee_rate_bps']))
                . ': ' . $sign . Money::format((int) $row['fee_cents'])
                . ((int) $row['qualifies'] === 1 ? '' : ' (does not qualify, no fee)');
            $lines[] = '';
        }
        $lines[] = 'Fees on verified reimbursement:   ' . Money::format((int) $invoice['fee_cents']);
        $lines[] = 'Less adjustments and reversals:  -' . Money::format((int) $invoice['credit_cents']);
        $lines[] = 'Total due:                        ' . Money::format((int) $invoice['total_cents']);
        $lines[] = '';
        $lines[] = wordwrap(
            'Every fee above is calculated in whole cents on reimbursement that was '
            . 'verified as received by the practice, at the rate in the executed agreement '
            . 'named above. A submission, a favorable decision or an expected reimbursement '
            . 'creates no fee. Payer reimbursement goes directly to the practice; Soft '
            . 'Appeals never receives or holds payer funds.',
            78,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = 'Questions: softappeals@frimpomaasync.com';

        return implode("\n", $lines) . "\n";
    }

    /**
     * The practice is told a reimbursement was verified. A notice and a
     * link, never the figure. Section 16.1.
     *
     * @param array<string,mixed> $engagement
     */
    private function notifyVerified(array $engagement, string $recoveryId): void
    {
        $signer = $this->requests->signerContact((string) $engagement['id']);
        if ($signer === null) {
            return;
        }
        $room = rtrim($this->config->string('SA_APP_URL'), '/') . '/soft-appeals-room?section=recovery';
        $organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');
        $lines = [];
        $lines[] = 'Hello ' . self::firstName((string) $signer['name']) . ',';
        $lines[] = '';
        $lines[] = wordwrap(
            'A reimbursement for ' . $organization . ' has been verified as received, and '
            . 'the recovery and fee block in your Soft Appeals Recovery Room is updated.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = 'Open the room: ' . $room;
        $lines[] = '';
        $lines[] = wordwrap(
            'Do not reply with patient, member, claim, clinical, or other protected '
            . 'health information.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = 'Nana Frimpongmaa';
        $lines[] = 'Soft Appeals';

        $this->mail->send(
            (string) $signer['work_email'],
            'A Soft Appeals recovery update is ready',
            implode("\n", $lines) . "\n",
            self::TEMPLATE_VERIFIED,
            (string) $engagement['id'],
            (string) $engagement['organization_id'],
            hash('sha256', $recoveryId . '|' . self::TEMPLATE_VERIFIED)
        );
    }

    /**
     * Section 16.2, template 15. To the billing contact the practice named,
     * or the signer when it named none. The amount stays in the room.
     *
     * @param array<string,mixed> $engagement
     * @param array<string,mixed> $invoice
     */
    private function notifyInvoice(array $engagement, array $invoice): void
    {
        $engagementId = (string) $engagement['id'];
        $preferences = $this->preferences->forEngagement($engagementId);
        $contact = null;
        if ($preferences !== null && $preferences['billing_contact_id'] !== null) {
            $contact = $this->contacts->find((string) $preferences['billing_contact_id']);
        }
        if ($contact === null) {
            $contact = $this->requests->signerContact($engagementId);
        }
        if ($contact === null) {
            return;
        }
        $room = rtrim($this->config->string('SA_APP_URL'), '/') . '/soft-appeals-room?section=recovery';
        $organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');
        $lines = [];
        $lines[] = 'Hello ' . self::firstName((string) $contact['name']) . ',';
        $lines[] = '';
        $lines[] = wordwrap(
            'An invoice for ' . $organization . ' is ready to read in your Soft Appeals '
            . 'Recovery Room, under Recovery. It is calculated only on reimbursement that '
            . 'was verified as received, at the rate in your signed agreement.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = 'Open the room: ' . $room;
        $lines[] = '';
        $lines[] = wordwrap(
            'Nothing is attached to this email on purpose. Do not reply with patient, '
            . 'member, claim, clinical, or other protected health information.',
            72,
            "\n",
            false
        );
        $lines[] = '';
        $lines[] = 'Nana Frimpongmaa';
        $lines[] = 'Soft Appeals';

        $this->mail->send(
            (string) $contact['work_email'],
            'A Soft Appeals invoice is ready for you',
            implode("\n", $lines) . "\n",
            self::TEMPLATE_INVOICE,
            $engagementId,
            (string) $engagement['organization_id'],
            hash('sha256', (string) $invoice['id'] . '|' . self::TEMPLATE_INVOICE)
        );
    }

    private static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        return $parts === [] || $parts[0] === '' ? 'there' : $parts[0];
    }
}
