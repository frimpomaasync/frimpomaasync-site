<?php
/**
 * Money. Section 19 on her side, and section 12.4's recovery summary.
 *
 * With no engagement: the summary cards across every practice and the list
 * of every practice past the PHI gate with its money. With one: the fee
 * block, the batches a reimbursement can be verified against, the ledger of
 * every recovery row with its adjustments, and the invoices.
 *
 * Every figure on this screen is integer cents formatted for reading. The
 * verify form is the only door to a fee, and it says so.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var array<string,mixed>|null $engagement
 * @var bool $canMoney
 * @var bool $financeEnabled
 * @var array<string,mixed> $recoverySummary
 * @var list<array<string,mixed>> $moneyRows
 * @var array<string,mixed> $moneySummary
 * @var list<array<string,mixed>> $verifiable
 * @var list<array<string,mixed>> $ledger
 * @var list<array<string,mixed>> $invoiceRows
 * @var array<string,array{found:bool,matches:bool,sha256:?string}> $invoiceVerifications
 * @var bool $moneyStageOpen
 * @var list<array<string,mixed>> $awaitingVerification
 * @var list<array<string,mixed>> $invoiceReady
 * @var list<array<string,mixed>> $outstandingInvoices
 */

use SoftAppeals\Domain\InvoiceStatus;
use SoftAppeals\Domain\RecoveryRecord;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Support\Money;
use SoftAppeals\Views\Desk;

$e = static fn (?string $value): string => Desk::e($value);
?>

<?php if (!$financeEnabled): ?>
  <section aria-labelledby="desk-mo-off">
    <p class="sa-label" id="desk-mo-off">Recovery finance is off here</p>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
      <p style="margin:0">
        Figures can be read. Nothing can be verified, adjusted or invoiced,
        because SA_RECOVERY_FINANCE_ENABLED is off in this environment. Section
        25 turns it on after the reconciliation tests pass.
      </p>
    </div></div>
  </section>
<?php endif; ?>

<?php if ($engagement === null): ?>

  <section aria-labelledby="desk-mo-sum">
    <p class="sa-label" id="desk-mo-sum">Recovery summary, every practice</p>
    <div class="sa-metrics">
      <div class="sa-metric"><p class="sa-metric-k">Denied dollars accepted</p><p class="sa-metric-v"><?= $e((string) $recoverySummary['denied_accepted']) ?></p></div>
      <div class="sa-metric"><p class="sa-metric-k">Submitted to payers</p><p class="sa-metric-v"><?= $e((string) $recoverySummary['submitted']) ?></p></div>
      <div class="sa-metric"><p class="sa-metric-k">Overturned, awaiting verification</p><p class="sa-metric-v"><?= $e((string) $recoverySummary['awaiting']) ?></p><p class="sa-metric-c"><?= (int) $recoverySummary['awaiting_count'] ?> <?= $e(Desk::plural((int) $recoverySummary['awaiting_count'], 'batch')) ?></p></div>
      <div class="sa-metric"><p class="sa-metric-k">Verified reimbursement</p><p class="sa-metric-v"><?= $e((string) $recoverySummary['verified']) ?></p><p class="sa-metric-c">less <?= $e((string) $recoverySummary['taken_back']) ?> taken back</p></div>
      <div class="sa-metric"><p class="sa-metric-k">Calculated fees</p><p class="sa-metric-v"><?= $e((string) $recoverySummary['fee']) ?></p><p class="sa-metric-c"><?= $e((string) $recoverySummary['uninvoiced']) ?> not invoiced yet</p></div>
      <div class="sa-metric"><p class="sa-metric-k">Invoiced</p><p class="sa-metric-v"><?= $e((string) $recoverySummary['invoiced']) ?></p></div>
      <div class="sa-metric"><p class="sa-metric-k">Paid</p><p class="sa-metric-v"><?= $e((string) $recoverySummary['paid']) ?></p></div>
    </div>
    <p class="sa-desk-note" style="margin-top:10px">
      Fees are calculated only from verified qualifying reimbursement recorded
      under the agreement. A favorable decision alone never creates a fee.
    </p>
  </section>

  <section aria-labelledby="desk-mo-you">
    <p class="sa-label" id="desk-mo-you">Waiting on you</p>
    <?php if (($awaitingVerification ?? []) === [] && ($invoiceReady ?? []) === [] && ($outstandingInvoices ?? []) === []): ?>
      <div class="sa-panel"><div class="sa-empty">No money is waiting on a move from you.</div></div>
    <?php else: ?>
      <div class="sa-desk-cards">
        <?php foreach ($awaitingVerification ?? [] as $row): ?>
          <div class="sa-desk-card is-urgent">
            <div class="sa-desk-card-t">
              <b><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></b>
              <span>Overturned, nothing verified yet &middot; batch <?= $e((string) $row['label']) ?> &middot; <?= (int) $row['overturned_count'] ?> claims</span>
            </div>
            <div class="sa-desk-card-a">
              <a class="sa-btn is-action is-sm" href="/sa-desk.php?view=money&amp;e=<?= $e(urlencode((string) $row['engagement_ref'])) ?>#desk-mo-verify">Verify what arrived</a>
            </div>
          </div>
        <?php endforeach; ?>
        <?php foreach ($invoiceReady ?? [] as $row): ?>
          <div class="sa-desk-card">
            <div class="sa-desk-card-t">
              <b><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></b>
              <span>Invoice-ready &middot; <?= $e(Money::format((int) $row['fee_net_cents'])) ?> in fees across <?= (int) $row['n'] ?> <?= $e(Desk::plural((int) $row['n'], 'row')) ?></span>
            </div>
            <div class="sa-desk-card-a">
              <a class="sa-btn is-action is-sm" href="/sa-desk.php?view=money&amp;e=<?= $e(urlencode((string) $row['engagement_ref'])) ?>#desk-mo-invoices">Create the invoice</a>
            </div>
          </div>
        <?php endforeach; ?>
        <?php foreach ($outstandingInvoices ?? [] as $row): ?>
          <?php $days = $row['due_at'] === null ? null : $clock->daysUntil((string) $row['due_at']); ?>
          <div class="sa-desk-card<?= $days !== null && $days < 0 ? ' is-urgent' : '' ?>">
            <div class="sa-desk-card-t">
              <b><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></b>
              <span>Invoice <?= $e((string) $row['public_ref']) ?> issued, unpaid &middot; <?= $e(Money::format((int) $row['total_cents'])) ?><?= $days === null ? '' : ' &middot; ' . $e(Desk::deadlineWords($days, true)) ?></span>
            </div>
            <div class="sa-desk-card-a">
              <a class="sa-btn is-sm" href="/sa-desk.php?view=money&amp;e=<?= $e(urlencode((string) $row['engagement_ref'])) ?>#desk-mo-invoices">Open</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section aria-labelledby="desk-mo-list">
    <p class="sa-label" id="desk-mo-list">Every practice past the PHI gate</p>
    <div class="sa-panel">
      <?php if (($moneyRows ?? []) === []): ?>
        <div class="sa-empty">No practice is past the PHI gate yet, so there is no money to show.</div>
      <?php else: ?>
        <div class="sa-tablewrap">
          <table class="sa-table">
            <thead><tr><th>Practice</th><th>Stage</th><th>Overturned</th><th>Verified</th><th>Fee</th><th>Invoiced</th><th>Paid</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($moneyRows as $row): ?>
                <?php $m = $row['money']; ?>
                <tr>
                  <td class="sa-strong"><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?> <div class="sa-desk-mono"><?= $e((string) $row['public_ref']) ?></div></td>
                  <td><?= $e(Stage::staffLabel((string) $row['stage'])) ?></td>
                  <td><?= $e((string) $m['overturned']) ?></td>
                  <td><?= $e((string) $m['net']) ?></td>
                  <td><?= $e((string) $m['fee_net']) ?></td>
                  <td><?= $e((string) $m['invoiced']) ?></td>
                  <td><?= $e((string) $m['paid']) ?></td>
                  <td><a class="sa-btn is-sm" href="/sa-desk.php?view=money&amp;e=<?= $e(urlencode((string) $row['public_ref'])) ?>">Open</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </section>

<?php else: ?>

  <?php
  $engagementRef = (string) $engagement['public_ref'];
  $stage = (string) $engagement['stage'];
  $m = $moneySummary;
  $writable = $canMoney && $financeEnabled && $moneyStageOpen;
  ?>

  <section aria-labelledby="desk-mo-who">
    <p class="sa-label" id="desk-mo-who"><?= $e((string) ($engagement['display_name'] ?? $engagement['legal_name'])) ?> &middot; <?= $e(Stage::staffLabel($stage)) ?></p>
    <div class="sa-metrics">
      <div class="sa-metric"><p class="sa-metric-k">Overturned, per the payer</p><p class="sa-metric-v"><?= $e((string) $m['overturned']) ?></p><p class="sa-metric-c"><?= $e((string) $m['awaiting']) ?> not verified yet</p></div>
      <div class="sa-metric"><p class="sa-metric-k">Verified reimbursement</p><p class="sa-metric-v"><?= $e((string) $m['net']) ?></p><p class="sa-metric-c"><?= $e((string) $m['verified']) ?> verified, <?= $e((string) $m['taken_back']) ?> taken back</p></div>
      <div class="sa-metric"><p class="sa-metric-k">Calculated fee</p><p class="sa-metric-v"><?= $e((string) $m['fee_net']) ?></p><p class="sa-metric-c"><?= $e((string) $m['rate']) ?></p></div>
      <div class="sa-metric"><p class="sa-metric-k">Invoiced &middot; paid</p><p class="sa-metric-v"><?= $e((string) $m['invoiced']) ?> &middot; <?= $e((string) $m['paid']) ?></p><p class="sa-metric-c"><?= $e((string) $m['invoice']) ?></p></div>
    </div>
    <p class="sa-desk-note" style="margin-top:10px">
      Agreement: <?= $m['agreement_ref'] === null ? 'none executed' : '<span class="sa-desk-mono">' . $e((string) $m['agreement_ref']) . '</span>' ?>.
      Every fee below names it and the recovery record it came from.
      <?php if (!$moneyStageOpen): ?>
        Money is written at "Recovery active" and "Financial reconciliation"; this engagement is at "<?= $e(Stage::staffLabel($stage)) ?>", so the figures are read-only.
      <?php endif; ?>
    </p>
  </section>

  <section aria-labelledby="desk-mo-verify" id="desk-mo-verify">
    <p class="sa-label" id="desk-mo-verify-h">Verify a reimbursement</p>
    <?php if ($verifiable === []): ?>
      <div class="sa-panel"><div class="sa-empty">No batch on this engagement has been overturned by a payer yet. A reimbursement is verified against an overturned batch and nothing else.</div></div>
    <?php else: ?>
      <?php foreach ($verifiable as $row): ?>
        <?php $batch = $row['batch']; ?>
        <div class="sa-panel" style="margin-bottom:14px">
          <div class="sa-panel-h">
            <div>
              <b><?= $e((string) $batch['label']) ?></b>
              <span class="sa-desk-mono"><?= $e((string) $batch['public_ref']) ?></span>
              <?php if (!$row['in_scope']): ?><span class="sa-desk-quiet">outside the scope, no fee applies</span><?php endif; ?>
            </div>
            <span class="sa-pill<?= $row['has_verified'] ? ' is-ok' : ' is-action' ?>"><?= $row['has_verified'] ? 'Verified' : 'Awaiting verification' ?></span>
          </div>
          <div class="sa-panel-b" style="padding:14px 18px">
            <dl class="sa-dl">
              <dt>Overturned, per the payer</dt><dd><?= $e(Money::format((int) $row['overturned_cents'])) ?> &middot; <?= (int) $batch['overturned_count'] ?> claims</dd>
              <dt>Verified so far</dt><dd><?= $e(Money::format((int) $row['verified_cents'])) ?></dd>
              <dt>Still unverified</dt><dd><?= $e(Money::format((int) $row['remaining_cents'])) ?></dd>
            </dl>
            <?php if ($writable && $row['in_scope'] && ($row['remaining_cents'] > 0 || !$row['has_verified'])): ?>
              <form method="post" action="/sa-desk.php" style="margin-top:14px">
                <?= $csrf->field('recovery.verify') ?>
                <input type="hidden" name="action" value="recovery.verify">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <input type="hidden" name="batch" value="<?= $e((string) $batch['public_ref']) ?>">
                <div class="sa-desk-grid2">
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Dollars that actually reached the practice. Zero is allowed and is a record.</span>
                    <input class="sa-input" type="text" inputmode="decimal" name="amount" maxlength="20" value="<?= $e(ltrim(Money::format((int) $row['remaining_cents']), '$')) ?>">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Verified against</span>
                    <select class="sa-select" name="source">
                      <?php foreach (RecoveryRecord::sources() as $source): ?>
                        <option value="<?= $e($source) ?>"><?= $e(RecoveryRecord::sourceLabel($source)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">The day the money arrived. Today if blank.</span>
                    <input class="sa-input" type="date" name="verified_on">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Qualifies under the agreement</span>
                    <select class="sa-select" name="qualifies">
                      <option value="yes">Yes, a fee applies</option>
                      <option value="no">No, no fee on this money</option>
                    </select>
                  </label>
                </div>
                <label class="sa-field" style="margin-top:10px">
                  <span class="sa-fieldlabel">Note, optional. Required if it does not qualify. Screened.</span>
                  <input class="sa-input" type="text" name="note" maxlength="500">
                </label>
                <button type="submit" class="sa-btn is-primary" style="margin-top:12px">Verify and calculate the fee</button>
                <p class="sa-desk-note" style="margin-top:10px">
                  This is the only action that creates a fee. It is calculated in whole cents
                  at <?= $e((string) $m['rate']) ?>, half up, and it cannot exceed what the payer overturned.
                </p>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <section aria-labelledby="desk-mo-ledger" id="desk-mo-ledger">
    <p class="sa-label" id="desk-mo-ledger-h">The ledger</p>
    <?php if ($ledger === []): ?>
      <div class="sa-panel"><div class="sa-empty">Nothing verified yet. Every fee starts as a row here.</div></div>
    <?php else: ?>
      <?php foreach ($ledger as $row): ?>
        <?php $kind = (string) $row['kind']; $takes = RecoveryRecord::takesBack($kind); ?>
        <div class="sa-panel" style="margin-bottom:10px">
          <div class="sa-panel-h">
            <div>
              <b><?= $e(RecoveryRecord::kindLabel($kind)) ?></b>
              <span class="sa-desk-mono"><?= $e((string) $row['public_ref']) ?></span>
              <span class="sa-desk-quiet">batch <?= $e((string) $row['batch_label']) ?></span>
            </div>
            <span class="sa-pill<?= $takes ? ' is-wait' : '' ?>"><?= $takes ? '-' : '' ?><?= $e(Money::format((int) $row['amount_cents'])) ?></span>
          </div>
          <div class="sa-panel-b" style="padding:12px 18px">
            <dl class="sa-dl">
              <dt><?= $takes ? 'Taken back' : 'Verified' ?></dt><dd><?= $e($clock->displayDate((string) $row['verified_at'])) ?> &middot; <?= $e(RecoveryRecord::sourceLabel((string) $row['verification_source'])) ?><?= (int) $row['qualifies'] === 1 ? '' : ' &middot; does not qualify, no fee' ?></dd>
              <dt>Fee<?= $takes ? ' credit' : '' ?></dt><dd><?= $takes ? '-' : '' ?><?= $e(Money::format((int) $row['fee_cents'])) ?><?= $row['fee_rate_bps'] === null ? '' : ' at ' . $e(\SoftAppeals\Services\RecoveryService::feeRateLabel((string) $row['fee_basis'], (int) $row['fee_rate_bps'])) ?></dd>
              <?php if (!$takes): ?>
                <dt>Still standing</dt><dd><?= $e(Money::format((int) $row['remaining_cents'])) ?><?= (int) $row['taken_back_cents'] > 0 ? ' after ' . $e(Money::format((int) $row['taken_back_cents'])) . ' taken back' : '' ?></dd>
              <?php else: ?>
                <dt>Taken from</dt><dd class="sa-desk-mono"><?= $e((string) $row['adjusts_recovery_id']) ?></dd>
              <?php endif; ?>
              <dt>Invoice</dt><dd><?= $row['invoice_ref'] === null ? '<span class="sa-desk-quiet">Not yet, invoice-ready</span>' : '<span class="sa-desk-mono">' . $e((string) $row['invoice_ref']) . '</span> &middot; ' . $e(InvoiceStatus::staffLabel((string) $row['invoice_status'])) ?></dd>
              <?php if ($row['note'] !== null): ?><dt>Note</dt><dd><?= $e((string) $row['note']) ?></dd><?php endif; ?>
            </dl>
            <?php if ($writable && $row['can_adjust']): ?>
              <form method="post" action="/sa-desk.php" style="margin-top:12px">
                <?= $csrf->field('recovery.adjust') ?>
                <input type="hidden" name="action" value="recovery.adjust">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <input type="hidden" name="recovery" value="<?= $e((string) $row['public_ref']) ?>">
                <p class="sa-label" style="margin:0 0 8px">The payer took some back</p>
                <div class="sa-desk-grid2">
                  <label class="sa-field">
                    <span class="sa-fieldlabel">What happened</span>
                    <select class="sa-select" name="kind">
                      <option value="<?= $e(RecoveryRecord::KIND_ADJUSTMENT) ?>">Adjustment, part of it</option>
                      <option value="<?= $e(RecoveryRecord::KIND_REVERSAL) ?>">Reversal, all of what still stands</option>
                    </select>
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Dollars taken back. Ignored for a reversal.</span>
                    <input class="sa-input" type="text" inputmode="decimal" name="amount" maxlength="20">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">On. Today if blank.</span>
                    <input class="sa-input" type="date" name="occurred_on">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Why. Screened. Goes on the record.</span>
                    <input class="sa-input" type="text" name="note" maxlength="500">
                  </label>
                </div>
                <button type="submit" class="sa-btn is-sm" style="margin-top:10px">Record it as a new row</button>
                <p class="sa-desk-note" style="margin-top:8px">The original row is never changed. The fee credit is at the rate the fee was charged at and comes off the next invoice.</p>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <section aria-labelledby="desk-mo-invoices" id="desk-mo-invoices">
    <p class="sa-label" id="desk-mo-invoices-h">Invoices</p>
    <?php if ($writable && (int) $m['uninvoiced_count'] > 0 && (int) $m['draft_count'] === 0): ?>
      <div class="sa-panel" style="margin-bottom:14px"><div class="sa-panel-b" style="padding:14px 18px">
        <p style="margin:0 0 10px">
          <?= (int) $m['uninvoiced_count'] ?> <?= $e(Desk::plural((int) $m['uninvoiced_count'], 'row')) ?> not on an invoice,
          <?= $e((string) $m['uninvoiced']) ?> in fees net of credits. Creating the invoice gathers them into a draft; nothing goes to the practice until you issue it.
        </p>
        <form method="post" action="/sa-desk.php" style="margin:0">
          <?= $csrf->field('invoice.create') ?>
          <input type="hidden" name="action" value="invoice.create">
          <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
          <button type="submit" class="sa-btn is-primary">Create the draft invoice</button>
        </form>
      </div></div>
    <?php endif; ?>
    <?php if ($invoiceRows === []): ?>
      <div class="sa-panel"><div class="sa-empty">No invoice yet. One is created from verified rows, never typed.</div></div>
    <?php else: ?>
      <?php foreach ($invoiceRows as $invoice): ?>
        <?php $status = (string) $invoice['status']; $check = $invoiceVerifications[(string) $invoice['id']] ?? null; ?>
        <div class="sa-panel" style="margin-bottom:12px">
          <div class="sa-panel-h">
            <div><b><?= $e((string) $invoice['public_ref']) ?></b> <span class="sa-desk-quiet"><?= $e($clock->displayDate((string) $invoice['created_at'])) ?></span></div>
            <span class="sa-pill<?= $status === InvoiceStatus::ISSUED ? ' is-wait' : ($status === InvoiceStatus::PAID ? ' is-ok' : '') ?>"><?= $e(InvoiceStatus::staffLabel($status)) ?></span>
          </div>
          <div class="sa-panel-b" style="padding:12px 18px">
            <dl class="sa-dl">
              <dt>Fees</dt><dd><?= $e(Money::format((int) $invoice['fee_cents'])) ?></dd>
              <dt>Less credits</dt><dd>-<?= $e(Money::format((int) $invoice['credit_cents'])) ?></dd>
              <dt>Total</dt><dd><b><?= $e(Money::format((int) $invoice['total_cents'])) ?></b></dd>
              <?php if ($invoice['issued_at'] !== null): ?><dt>Issued</dt><dd><?= $e($clock->displayDate((string) $invoice['issued_at'])) ?>, due <?= $e($clock->displayDate((string) $invoice['due_at'])) ?></dd><?php endif; ?>
              <?php if ($invoice['paid_at'] !== null): ?><dt>Paid</dt><dd><?= $e($clock->displayDate((string) $invoice['paid_at'])) ?><?= $invoice['paid_note'] === null ? '' : ' &middot; ' . $e((string) $invoice['paid_note']) ?></dd><?php endif; ?>
              <?php if ($invoice['void_reason'] !== null): ?><dt>Void</dt><dd><?= $e((string) $invoice['void_reason']) ?></dd><?php endif; ?>
              <?php if ($check !== null && $check['found']): ?>
                <dt>Stored copy</dt><dd><?= $check['matches'] ? 'Reopened on this request and matches its hash' : '<b>Does not match its hash</b>' ?></dd>
              <?php endif; ?>
            </dl>
            <p style="margin:10px 0 0">
              <?php if ($invoice['private_path'] !== null): ?>
                <a class="sa-btn is-sm" target="_blank" rel="noopener" href="/sa-desk.php?view=money&amp;e=<?= $e(urlencode($engagementRef)) ?>&amp;invoice=<?= $e(urlencode((string) $invoice['public_ref'])) ?>">Open the invoice</a>
              <?php endif; ?>
            </p>
            <?php if ($canMoney && $financeEnabled && $status === InvoiceStatus::DRAFT): ?>
              <form method="post" action="/sa-desk.php" style="margin-top:12px">
                <?= $csrf->field('invoice.issue') ?>
                <input type="hidden" name="action" value="invoice.issue">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <input type="hidden" name="invoice" value="<?= $e((string) $invoice['public_ref']) ?>">
                <label class="sa-field">
                  <span class="sa-fieldlabel">Due on. Thirty days if blank.</span>
                  <input class="sa-input" type="date" name="due_on">
                </label>
                <button type="submit" class="sa-btn is-primary" style="margin-top:10px">Issue it to the practice</button>
              </form>
            <?php endif; ?>
            <?php if ($canMoney && $financeEnabled && $status === InvoiceStatus::ISSUED): ?>
              <form method="post" action="/sa-desk.php" style="margin-top:12px">
                <?= $csrf->field('invoice.paid') ?>
                <input type="hidden" name="action" value="invoice.paid">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <input type="hidden" name="invoice" value="<?= $e((string) $invoice['public_ref']) ?>">
                <div class="sa-desk-grid2">
                  <label class="sa-field"><span class="sa-fieldlabel">Paid on. Today if blank.</span><input class="sa-input" type="date" name="paid_on"></label>
                  <label class="sa-field"><span class="sa-fieldlabel">Note, optional. Screened.</span><input class="sa-input" type="text" name="note" maxlength="500"></label>
                </div>
                <button type="submit" class="sa-btn is-sm" style="margin-top:10px">Mark it paid</button>
              </form>
            <?php endif; ?>
            <?php if ($canMoney && $financeEnabled && InvoiceStatus::canMove($status, InvoiceStatus::VOID)): ?>
              <form method="post" action="/sa-desk.php" style="margin-top:12px">
                <?= $csrf->field('invoice.void') ?>
                <input type="hidden" name="action" value="invoice.void">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <input type="hidden" name="invoice" value="<?= $e((string) $invoice['public_ref']) ?>">
                <label class="sa-field"><span class="sa-fieldlabel">Void it. Say why; the rows go back to invoice-ready.</span><input class="sa-input" type="text" name="reason" maxlength="200"></label>
                <button type="submit" class="sa-btn is-quiet is-sm" style="margin-top:8px">Void this invoice</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <section aria-labelledby="desk-mo-back">
    <p class="sa-label" id="desk-mo-back">Elsewhere</p>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
      <a class="sa-btn is-sm" href="/sa-desk.php?view=money">All money</a>
      <a class="sa-btn is-sm" href="/sa-desk.php?view=recovery&amp;e=<?= $e(urlencode($engagementRef)) ?>">The recovery</a>
      <a class="sa-btn is-sm" href="/sa-desk.php?view=closeout&amp;e=<?= $e(urlencode($engagementRef)) ?>">Closeout</a>
      <a class="sa-btn is-sm" href="/sa-desk.php?view=documents&amp;e=<?= $e(urlencode($engagementRef)) ?>">Agreements</a>
    </div></div>
  </section>

<?php endif; ?>
