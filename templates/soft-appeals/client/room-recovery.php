<?php
/**
 * Recovery, section 15.9 and the payer half of section 7.3, as the practice
 * reads it.
 *
 * Where the two Gate B documents stand, what is in scope, each batch with
 * where it is, what went to a payer and what came back, and the fee block.
 * The fee block shows a verified figure this phase never writes, so it reads
 * $0.00 and says why: a payer decision is not a reimbursement, and no fee
 * exists until the money has arrived and been checked.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var string $stage
 * @var array<string,mixed>|null $scope
 * @var array{agreement:?array<string,mixed>,scope_document:?array<string,mixed>,both_executed:bool} $agreementStatus
 * @var list<array<string,mixed>> $board
 * @var list<array<string,mixed>> $submissions
 * @var array<string,mixed> $feeBlock
 */

use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Domain\SubmissionEventType;
use SoftAppeals\Support\Money;
use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);
$agreement = $agreementStatus['agreement'];
$scopeDocument = $agreementStatus['scope_document'];
$active = in_array($stage, [
    Stage::RECOVERY_ACTIVE, Stage::RECONCILIATION, Stage::FINAL_REPORT,
    Stage::ACCESS_REVIEW, Stage::DATA_DISPOSITION, Stage::CLOSED,
], true);
$chosen = in_array($stage, [
    Stage::RECOVERY_SCOPE_SELECTED, Stage::RECOVERY_AGREEMENT_PENDING, Stage::RECOVERY_AGREEMENT_EXECUTED,
], true) || $active;
$documentLine = static function (?array $document) use ($e): string {
    if ($document === null) {
        return '<span class="sa-room-quiet">Being prepared</span>';
    }
    return '<span class="sa-pill">' . $e(DocumentStatus::clientLabel((string) $document['status'])) . '</span>';
};
?>

<?php if (!$chosen): ?>
<section aria-labelledby="room-rc-wait">
  <p class="sa-label" id="room-rc-wait">Recovery</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:16px 18px">
    <p style="margin:0 0 10px">
      Recovery work begins only if you choose it after reading the assessment,
      and only once the Recovery Services Agreement and the Approved Recovery
      Scope are both signed. Nothing here has started.
    </p>
    <p class="sa-room-note">No fee exists until reimbursement has actually arrived and been verified.</p>
  </div></div>
</section>
<?php else: ?>

<section aria-labelledby="room-rc-gate">
  <p class="sa-label" id="room-rc-gate">Before any work starts</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <dl class="sa-dl">
      <dt><?= $e(DocumentKind::label(DocumentKind::RECOVERY_AGREEMENT)) ?></dt>
      <dd><?= $documentLine($agreement) ?></dd>
      <dt><?= $e(DocumentKind::label(DocumentKind::APPROVED_SCOPE)) ?></dt>
      <dd><?= $documentLine($scopeDocument) ?></dd>
      <dt>Recovery work</dt>
      <dd><?= $active ? 'Started' : 'Not started. It starts once both are signed.' ?></dd>
    </dl>
    <p class="sa-note" style="margin-top:12px">
      Your agreements and your signed copies are under Agreements on the overview.
    </p>
  </div></div>
</section>

<?php if ($scope !== null): ?>
<section aria-labelledby="room-rc-scope">
  <p class="sa-label" id="room-rc-scope">What is in scope</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <p style="margin:0 0 10px"><?= $e((string) $scope['summary']) ?></p>
    <dl class="sa-dl">
      <dt>Batches</dt><dd><?= count($scope['batches']) ?></dd>
      <dt>Denied claims</dt><dd><?= (int) $scope['claim_count'] ?></dd>
      <dt>Denied amount</dt><dd><?= $e(Money::format((int) $scope['denied_cents'])) ?></dd>
      <dt>Fee</dt><dd><?= $e((string) $scope['fee_rate_label']) ?></dd>
      <dt>Your submission approver</dt><dd><?= $scope['approver'] === null ? 'Not named yet' : $e((string) $scope['approver']['name']) ?></dd>
    </dl>
  </div></div>
</section>
<?php endif; ?>

<section aria-labelledby="room-rc-board">
  <p class="sa-label" id="room-rc-board">Each batch</p>
  <?php $inScope = array_values(array_filter($board, static fn (array $row): bool => (bool) $row['in_scope'])); ?>
  <?php if ($inScope === []): ?>
    <div class="sa-panel"><div class="sa-empty">No batch is in scope yet.</div></div>
  <?php else: ?>
    <?php foreach ($inScope as $row): ?>
      <?php $card = $row['card']; $event = $row['event']; ?>
      <div class="sa-panel" style="margin-bottom:14px">
        <div class="sa-panel-h">
          <div>
            <b><?= $e((string) $card['label']) ?></b>
            <span class="sa-room-quiet"><?= $e((string) $card['ref']) ?></span>
          </div>
          <span class="sa-pill"><?= $e((string) $card['stage']) ?></span>
        </div>
        <div class="sa-panel-b" style="padding:14px 18px">
          <dl class="sa-dl">
            <?php if ($card['payer'] !== null): ?>
              <dt>Payer</dt><dd><?= $e((string) $card['payer']) ?></dd>
            <?php endif; ?>
            <dt>Denials</dt><dd><?= (int) $card['count'] ?></dd>
            <dt>Denied amount</dt><dd><?= $e((string) $card['denied']) ?></dd>
            <dt>Waiting on</dt><dd><?= $e((string) $card['owner']) ?></dd>
            <dt>Next</dt><dd><?= $e((string) $card['action']) ?></dd>
            <?php if ($event !== null): ?>
              <dt>Latest</dt><dd><?= $e(SubmissionEventType::clientLabel((string) $event['event_type'])) ?> &middot; <?= $e($clock->displayDate((string) $event['occurred_at'])) ?></dd>
            <?php endif; ?>
          </dl>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<section aria-labelledby="room-rc-events">
  <p class="sa-label" id="room-rc-events">What went to the payer, and what came back</p>
  <?php if ($submissions === []): ?>
    <div class="sa-panel"><div class="sa-empty">Nothing has gone to a payer yet. Each submission appears here once you have approved it and we have sent it.</div></div>
  <?php else: ?>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:6px 18px 10px">
      <?php foreach ($submissions as $row): ?>
        <div class="sa-client-docrow">
          <div>
            <?= $e(SubmissionEventType::clientLabel((string) $row['event_type'])) ?>
            <span class="sa-room-quiet">batch <?= $e((string) $row['batch_label']) ?> &middot; <?= (int) $row['claim_count'] ?> claims, <?= $e(Money::format((int) $row['amount_cents'])) ?></span>
          </div>
          <span class="sa-room-quiet"><?= $e($clock->displayDate((string) $row['occurred_at'])) ?></span>
        </div>
      <?php endforeach; ?>
    </div></div>
  <?php endif; ?>
</section>

<?php if ($feeBlock['shown']): ?>
<section aria-labelledby="room-rc-fee">
  <p class="sa-label" id="room-rc-fee">Recovery and fee</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <dl class="sa-dl">
      <dt>Verified recovered reimbursement</dt><dd><?= $e((string) $feeBlock['verified']) ?></dd>
      <dt>Applicable fee rate</dt><dd><?= $e((string) $feeBlock['rate']) ?></dd>
      <dt>Calculated Soft Appeals fee</dt><dd><?= $e((string) $feeBlock['fee']) ?></dd>
      <dt>Invoice status</dt><dd><?= $e((string) $feeBlock['invoice']) ?></dd>
    </dl>
    <p class="sa-room-note" style="margin-top:12px">
      A payer decision is not a reimbursement. Overturned so far, per the payer:
      <?= $e((string) $feeBlock['overturned']) ?>. The verified figure is recorded
      when the money has actually reached you and been checked, and the fee is
      calculated from that figure alone. Every calculated fee shows the agreement
      and the recovery record that produced it.
    </p>
  </div></div>
</section>
<?php endif; ?>

<?php endif; ?>

<section aria-labelledby="room-rc-back">
  <p class="sa-label" id="room-rc-back">Elsewhere in your room</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <a class="sa-btn is-sm" href="/soft-appeals-room.php">Overview</a>
    <a class="sa-btn is-sm" href="/soft-appeals-room.php?section=approvals">Approvals</a>
    <a class="sa-btn is-sm" href="/soft-appeals-room.php?section=batches">Work batches</a>
  </div></div>
</section>
