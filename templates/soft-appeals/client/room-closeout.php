<?php
/**
 * Closeout, section 15.10, as the practice reads it.
 *
 * The final aggregate disposition, the final verified recovery, invoices and
 * adjustments, the final documents, who kept access and who did not, what
 * happened to the material, and the day it closed. The money lines are shown
 * to the people section 8.2 lets see them; the access lines to the people it
 * lets see those. Everybody sees the rest.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var string $stage
 * @var bool $inCloseout
 * @var bool $canViewFinance
 * @var bool $canViewCompliance
 * @var array<string,mixed> $closeoutSummary
 * @var list<array<string,mixed>> $invoices
 */

use SoftAppeals\Domain\CloseoutStep;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\InvoiceStatus;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Support\Money;
use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);
$s = $closeoutSummary;
$closeout = $s['closeout'];
$m = $s['money'];
$closed = $stage === Stage::CLOSED;
?>

<?php if (!$inCloseout || $closeout === null): ?>
<section aria-labelledby="room-co-wait">
  <p class="sa-label" id="room-co-wait">Closeout</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:16px 18px">
    <?php if ($stage === Stage::CLOSED_NO_RECOVERY): ?>
      <p style="margin:0">This engagement closed with no recovery. Your agreements stay under Agreements on the overview.</p>
    <?php else: ?>
      <p style="margin:0 0 10px">
        Closeout begins once every batch in scope has a payer answer and the
        money has been verified. It has not started.
      </p>
      <p class="sa-room-note">When it does, this page shows the final figures, your final report, your documents, and what happened to your material.</p>
    <?php endif; ?>
  </div></div>
</section>
<?php else: ?>

<section aria-labelledby="room-co-where">
  <p class="sa-label" id="room-co-where"><?= $closed ? 'Closed' : 'Closing out' ?></p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <dl class="sa-dl">
      <dt>Began</dt><dd><?= $e($clock->displayDate((string) $closeout['started_at'])) ?></dd>
      <?php foreach ($s['steps'] as $step): ?>
        <dt><?= $e((string) $step['client_label']) ?></dt>
        <dd><?= $step['confirmed_at'] === null ? '<span class="sa-room-quiet">Not yet</span>' : $e($clock->displayDate((string) $step['confirmed_at'])) ?></dd>
      <?php endforeach; ?>
      <dt>Closeout completed</dt><dd><?= $s['closed_at'] === null ? '<span class="sa-room-quiet">Not yet</span>' : $e($clock->displayDate((string) $s['closed_at'])) ?></dd>
    </dl>
  </div></div>
</section>

<section aria-labelledby="room-co-final">
  <p class="sa-label" id="room-co-final">Final aggregate disposition</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <dl class="sa-dl">
      <dt>Batches</dt><dd><?= (int) $s['batches']['count'] ?></dd>
      <dt>Claims submitted</dt><dd><?= (int) $s['events']['submitted_count'] ?></dd>
      <dt>Overturned in your favour</dt><dd><?= (int) $s['events']['overturned_count'] ?></dd>
      <dt>Upheld by the payer</dt><dd><?= (int) $s['events']['upheld_count'] ?></dd>
    </dl>
    <?php if ($closeout['final_summary'] !== null): ?>
      <p class="sa-label" style="margin:14px 0 6px">Your final report</p>
      <p style="margin:0"><?= nl2br($e((string) $closeout['final_summary'])) ?></p>
    <?php endif; ?>
  </div></div>
</section>

<?php if ($canViewFinance): ?>
<section aria-labelledby="room-co-money">
  <p class="sa-label" id="room-co-money">Final verified recovery, invoices and adjustments</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <dl class="sa-dl">
      <dt>Verified reimbursement</dt><dd><?= $e((string) $m['verified']) ?></dd>
      <dt>Taken back by the payer</dt><dd><?= $e((string) $m['taken_back']) ?></dd>
      <dt>Net</dt><dd><b><?= $e((string) $m['net']) ?></b></dd>
      <dt>Soft Appeals fee</dt><dd><?= $e((string) $m['fee_net']) ?> at <?= $e((string) $m['rate']) ?></dd>
      <?php foreach ($invoices as $invoice): ?>
        <dt>Invoice <?= $e((string) $invoice['public_ref']) ?></dt>
        <dd>
          <?= $e(Money::format((int) $invoice['total_cents'])) ?> &middot; <?= $e(InvoiceStatus::clientLabel((string) $invoice['status'])) ?>
          <?php if ($invoice['private_path'] !== null): ?>
            &middot; <a href="/soft-appeals-room.php?invoice=<?= $e(urlencode((string) $invoice['public_ref'])) ?>" target="_blank" rel="noopener">read it</a>
          <?php endif; ?>
        </dd>
      <?php endforeach; ?>
    </dl>
    <p class="sa-room-note" style="margin-top:12px">Every fee was calculated only on reimbursement verified as received by you, under agreement <?= $e((string) ($m['agreement_ref'] ?? '')) ?>.</p>
  </div></div>
</section>
<?php endif; ?>

<section aria-labelledby="room-co-docs">
  <p class="sa-label" id="room-co-docs">Final documents</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:6px 18px 10px">
    <?php if ($s['final'] === []): ?>
      <p class="sa-room-quiet" style="margin:8px 0">Nothing executed.</p>
    <?php else: ?>
      <?php foreach ($s['final'] as $document): ?>
        <div class="sa-client-docrow">
          <div>
            <b><?= $e(DocumentKind::label((string) $document['kind'])) ?></b>
            <span class="sa-room-quiet"><?= $e((string) $document['public_ref']) ?> &middot; <?= $e($clock->displayDate((string) $document['executed_at'])) ?></span>
          </div>
          <a class="sa-btn is-sm" target="_blank" rel="noopener" href="/soft-appeals-room.php?document=<?= $e(urlencode((string) $document['public_ref'])) ?>">Open your copy</a>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div></div>
</section>

<?php if ($canViewCompliance): ?>
<section aria-labelledby="room-co-access">
  <p class="sa-label" id="room-co-access">Access removed or retained</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <?php if ($s['access'] === []): ?>
      <p class="sa-room-quiet" style="margin:0">Nobody held a sign-in.</p>
    <?php else: ?>
      <dl class="sa-dl">
        <?php foreach ($s['access'] as $row): ?>
          <dt><?= $e((string) ($row['contact_name'] ?? $row['email'])) ?></dt>
          <dd><?= $row['decision'] === null ? '<span class="sa-room-quiet">Being reviewed</span>' : $e(CloseoutStep::accessLabel((string) $row['decision'])) ?></dd>
        <?php endforeach; ?>
      </dl>
    <?php endif; ?>
    <p class="sa-label" style="margin:14px 0 6px">Your material</p>
    <p style="margin:0"><?= $s['disposition'] === null ? '<span class="sa-room-quiet">The data disposition is confirmed at the last step.</span>' : $e((string) $s['disposition']) . ($closeout['disposition_note'] === null ? '' : '. ' . $e((string) $closeout['disposition_note'])) ?></p>
  </div></div>
</section>
<?php endif; ?>

<?php endif; ?>

<section aria-labelledby="room-co-back">
  <p class="sa-label" id="room-co-back">Elsewhere in your room</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <a class="sa-btn is-sm" href="/soft-appeals-room.php">Overview</a>
    <a class="sa-btn is-sm" href="/soft-appeals-room.php?section=recovery">Recovery</a>
  </div></div>
</section>
