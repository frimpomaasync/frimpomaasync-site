<?php
/**
 * Closeout. Section 7.4 on her side, section 15.10 as she sees it.
 *
 * With no engagement: what is inside closeout and what could begin it. With
 * one: the four steps with who confirmed each, the open step's form and the
 * reason it cannot be confirmed yet if there is one, the access review, the
 * money as it stands, the final documents, and the sealed record.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var array<string,mixed>|null $engagement
 * @var bool $canClose
 * @var list<array<string,mixed>> $closeoutRows
 * @var list<array<string,mixed>> $closeoutCandidates
 * @var array<string,mixed> $closeoutSummary
 * @var array{ok:bool,reason:?string} $beginCheck
 * @var array{step:?string,ok:bool,reason:?string} $stepCheck
 * @var list<array<string,mixed>> $timeline
 */

use SoftAppeals\Domain\CloseoutStep;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\InvoiceStatus;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Support\Money;
use SoftAppeals\Views\Desk;

$e = static fn (?string $value): string => Desk::e($value);
?>

<?php if ($engagement === null): ?>

  <section aria-labelledby="desk-co-open">
    <p class="sa-label" id="desk-co-open">Inside closeout</p>
    <div class="sa-panel">
      <?php if (($closeoutRows ?? []) === []): ?>
        <div class="sa-empty">No engagement is being closed out.</div>
      <?php else: ?>
        <div class="sa-tablewrap">
          <table class="sa-table">
            <thead><tr><th>Practice</th><th>Reference</th><th>Stage</th><th>Began</th><th>Closed</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($closeoutRows as $row): ?>
                <tr>
                  <td class="sa-strong"><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></td>
                  <td class="sa-desk-mono"><?= $e((string) $row['public_ref']) ?></td>
                  <td><?= $e(Stage::staffLabel((string) $row['stage'])) ?></td>
                  <td><?= $e($clock->displayDate((string) $row['started_at'])) ?></td>
                  <td><?= $row['closeout_closed_at'] === null ? '<span class="sa-desk-quiet">Open</span>' : $e($clock->displayDate((string) $row['closeout_closed_at'])) ?></td>
                  <td><a class="sa-btn is-sm" href="/sa-desk.php?view=closeout&amp;e=<?= $e(urlencode((string) $row['public_ref'])) ?>">Open</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section aria-labelledby="desk-co-cand">
    <p class="sa-label" id="desk-co-cand">Could begin closeout</p>
    <div class="sa-panel">
      <?php if (($closeoutCandidates ?? []) === []): ?>
        <div class="sa-empty">No engagement is at "Recovery active".</div>
      <?php else: ?>
        <div class="sa-tablewrap">
          <table class="sa-table">
            <thead><tr><th>Practice</th><th>Reference</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($closeoutCandidates as $row): ?>
                <tr>
                  <td class="sa-strong"><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></td>
                  <td class="sa-desk-mono"><?= $e((string) $row['public_ref']) ?></td>
                  <td><a class="sa-btn is-sm" href="/sa-desk.php?view=closeout&amp;e=<?= $e(urlencode((string) $row['public_ref'])) ?>">Open</a></td>
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
  $s = $closeoutSummary;
  $closeout = $s['closeout'];
  $m = $s['money'];
  $openStep = $stepCheck['step'];
  $closed = $stage === Stage::CLOSED;
  ?>

  <section aria-labelledby="desk-co-who">
    <p class="sa-label" id="desk-co-who"><?= $e((string) ($engagement['display_name'] ?? $engagement['legal_name'])) ?></p>
    <div class="sa-metrics">
      <div class="sa-metric"><p class="sa-metric-k">Stage</p><p class="sa-metric-v"><?= $e(Stage::staffLabel($stage)) ?></p><p class="sa-metric-c"><?= $e(Stage::nextAction($stage)) ?></p></div>
      <div class="sa-metric"><p class="sa-metric-k">Final verified recovery</p><p class="sa-metric-v"><?= $e((string) $m['net']) ?></p><p class="sa-metric-c">fee <?= $e((string) $m['fee_net']) ?></p></div>
      <div class="sa-metric"><p class="sa-metric-k">Invoiced &middot; paid</p><p class="sa-metric-v"><?= $e((string) $m['invoiced']) ?> &middot; <?= $e((string) $m['paid']) ?></p><p class="sa-metric-c"><?= $e((string) $m['invoice']) ?></p></div>
      <div class="sa-metric"><p class="sa-metric-k">Closed</p><p class="sa-metric-v"><?= $s['closed_at'] === null ? 'Not yet' : $e($clock->displayDate((string) $s['closed_at'])) ?></p><p class="sa-metric-c"><?= $s['closed_by_email'] === null ? '' : 'by ' . $e((string) $s['closed_by_email']) ?></p></div>
    </div>
  </section>

  <?php if ($closeout === null): ?>
    <section aria-labelledby="desk-co-begin">
      <p class="sa-label" id="desk-co-begin">Begin closeout</p>
      <div class="sa-panel"><div class="sa-panel-b" style="padding:16px 18px">
        <?php if ($beginCheck['ok'] && $canClose): ?>
          <p style="margin:0 0 12px">
            Every batch in scope is resolved and nothing is open with the payer.
            Beginning closeout moves this engagement to financial reconciliation
            and opens the access and data-disposition checklist.
          </p>
          <form method="post" action="/sa-desk.php" style="margin:0">
            <?= $csrf->field('closeout.begin') ?>
            <input type="hidden" name="action" value="closeout.begin">
            <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
            <button type="submit" class="sa-btn is-primary">Begin closeout</button>
          </form>
          <?php if ((int) $m['verified_count'] === 0): ?>
            <form method="post" action="/sa-desk.php" style="margin-top:16px">
              <?= $csrf->field('closeout.without_recovery') ?>
              <input type="hidden" name="action" value="closeout.without_recovery">
              <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
              <p class="sa-label" style="margin:0 0 8px">Or close with no recovery</p>
              <label class="sa-field"><span class="sa-fieldlabel">Nothing was verified on this engagement. Say why it ends here. Screened.</span><input class="sa-input" type="text" name="reason" maxlength="500"></label>
              <button type="submit" class="sa-btn is-quiet is-sm" style="margin-top:8px">Close with no recovery</button>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <p style="margin:0"><?= $e((string) ($beginCheck['reason'] ?? 'Closeout is the owner\'s to begin.')) ?></p>
        <?php endif; ?>
      </div></div>
    </section>
  <?php else: ?>

    <section aria-labelledby="desk-co-steps">
      <p class="sa-label" id="desk-co-steps">The four steps</p>
      <div class="sa-panel"><div class="sa-panel-b" style="padding:6px 18px 10px">
        <?php foreach ($s['steps'] as $step): ?>
          <?php $key = (string) $step['step_key']; $done = $step['confirmed_at'] !== null; ?>
          <div class="sa-client-docrow">
            <div>
              <?= $done ? '&#10003;' : ($key === $openStep ? '&rarr;' : '&middot;') ?>
              <b><?= $e((string) $step['label']) ?></b>
              <?php if ($done): ?>
                <br><span class="sa-desk-quiet">confirmed by <?= $e((string) ($step['confirmed_by_email'] ?? 'Soft Appeals')) ?> on <?= $e($clock->displayDateTime((string) $step['confirmed_at'])) ?></span>
              <?php elseif ($key === $openStep): ?>
                <br><span class="sa-desk-quiet">open now</span>
              <?php endif; ?>
            </div>
            <span class="sa-pill<?= $done ? ' is-ok' : ($key === $openStep ? ' is-action' : '') ?>"><?= $done ? 'Done' : ($key === $openStep ? 'Now' : 'Later') ?></span>
          </div>
        <?php endforeach; ?>
      </div></div>
    </section>

    <?php if ($openStep !== null && !$closed): ?>
      <section aria-labelledby="desk-co-now">
        <p class="sa-label" id="desk-co-now">Now: <?= $e(CloseoutStep::label($openStep)) ?></p>
        <div class="sa-panel"><div class="sa-panel-b" style="padding:16px 18px">
          <p style="margin:0 0 12px"><?= $e(CloseoutStep::instructions($openStep)) ?></p>
          <?php if (!$stepCheck['ok']): ?>
            <p class="sa-desk-note" style="margin:0 0 12px"><b>Not yet.</b> <?= $e((string) $stepCheck['reason']) ?></p>
          <?php endif; ?>

          <?php if ($openStep === CloseoutStep::RECONCILIATION): ?>
            <p style="margin:0 0 12px"><a class="sa-btn is-sm" href="/sa-desk.php?view=money&amp;e=<?= $e(urlencode($engagementRef)) ?>">Open the money for this practice</a></p>
            <?php if ($canClose && $stepCheck['ok']): ?>
              <form method="post" action="/sa-desk.php" style="margin:0">
                <?= $csrf->field('closeout.reconciliation') ?>
                <input type="hidden" name="action" value="closeout.reconciliation">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <label class="sa-field"><span class="sa-fieldlabel">Note, optional. Screened.</span><input class="sa-input" type="text" name="note" maxlength="500"></label>
                <button type="submit" class="sa-btn is-primary" style="margin-top:10px">Confirm the money is reconciled</button>
              </form>
            <?php endif; ?>

          <?php elseif ($openStep === CloseoutStep::FINAL_REPORT): ?>
            <?php if ($canClose): ?>
              <form method="post" action="/sa-desk.php" style="margin:0">
                <?= $csrf->field('closeout.final_report') ?>
                <input type="hidden" name="action" value="closeout.final_report">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <label class="sa-field">
                  <span class="sa-fieldlabel">The final report. The practice keeps this. Aggregate only, screened.</span>
                  <textarea class="sa-textarea" name="summary" rows="8" maxlength="2000"><?= $e((string) ($closeout['final_summary'] ?? '')) ?></textarea>
                </label>
                <p class="sa-desk-note" style="margin:8px 0">Where it stands: <?= $e((string) $s['batches']['count']) ?> batches, <?= (int) $s['events']['submitted_count'] ?> claims submitted, <?= (int) $s['events']['overturned_count'] ?> overturned, <?= (int) $s['events']['upheld_count'] ?> upheld. Verified <?= $e((string) $m['net']) ?>, fee <?= $e((string) $m['fee_net']) ?>.</p>
                <button type="submit" class="sa-btn is-primary">Record the final report</button>
              </form>
            <?php endif; ?>

          <?php elseif ($openStep === CloseoutStep::ACCESS_REVIEW): ?>
            <?php if ($canClose && $stepCheck['ok']): ?>
              <form method="post" action="/sa-desk.php" style="margin:0">
                <?= $csrf->field('closeout.access_confirm') ?>
                <input type="hidden" name="action" value="closeout.access_confirm">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <label class="sa-field"><span class="sa-fieldlabel">Note, optional. Screened.</span><input class="sa-input" type="text" name="note" maxlength="500"></label>
                <button type="submit" class="sa-btn is-primary" style="margin-top:10px">Confirm the access review</button>
              </form>
            <?php else: ?>
              <p class="sa-desk-note" style="margin:0">Decide each person below first.</p>
            <?php endif; ?>

          <?php elseif ($openStep === CloseoutStep::DATA_DISPOSITION): ?>
            <?php if ($canClose && $stepCheck['ok']): ?>
              <form method="post" action="/sa-desk.php" style="margin:0">
                <?= $csrf->field('closeout.disposition') ?>
                <input type="hidden" name="action" value="closeout.disposition">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <div class="sa-choices">
                  <?php foreach (CloseoutStep::dispositions() as $option): ?>
                    <label class="sa-choice">
                      <input type="radio" name="disposition" value="<?= $e($option) ?>">
                      <span class="sa-choice-t"><b><?= $e(CloseoutStep::dispositionLabel($option)) ?></b></span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <label class="sa-field" style="margin-top:10px"><span class="sa-fieldlabel">Note, optional. What was returned or destroyed, at business level. Screened.</span><input class="sa-input" type="text" name="note" maxlength="500"></label>
                <button type="submit" class="sa-btn is-primary" style="margin-top:12px">Confirm and close the engagement</button>
                <p class="sa-desk-note" style="margin-top:8px">This seals the closeout record into the vault, moves the engagement to closed, and tells the practice. Closed is terminal.</p>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </div></div>
      </section>
    <?php endif; ?>

    <section aria-labelledby="desk-co-access">
      <p class="sa-label" id="desk-co-access">Access review &middot; <?= (int) $s['undecided'] ?> undecided</p>
      <div class="sa-panel">
        <?php if ($s['access'] === []): ?>
          <div class="sa-empty">Nobody holds a sign-in at this practice.</div>
        <?php else: ?>
          <div class="sa-tablewrap">
            <table class="sa-table">
              <thead><tr><th>Person</th><th>Roles</th><th>Decision</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($s['access'] as $row): ?>
                  <tr>
                    <td class="sa-strong"><?= $e((string) ($row['contact_name'] ?? '')) ?><div class="sa-desk-mono"><?= $e((string) $row['email']) ?></div></td>
                    <td><?= $e(str_replace(',', ', ', (string) $row['roles'])) ?></td>
                    <td>
                      <?php if ($row['decision'] === null): ?>
                        <span class="sa-pill is-wait">Undecided</span>
                      <?php else: ?>
                        <span class="sa-pill<?= (string) $row['decision'] === CloseoutStep::ACCESS_REMOVED ? '' : ' is-ok' ?>"><?= $e(CloseoutStep::accessLabel((string) $row['decision'])) ?></span>
                        <div class="sa-desk-quiet"><?= $e($clock->displayDate((string) $row['decided_at'])) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($canClose && $row['decision'] === null && $openStep === CloseoutStep::ACCESS_REVIEW): ?>
                        <?php foreach (CloseoutStep::accessDecisions() as $decision): ?>
                          <form method="post" action="/sa-desk.php" style="display:inline-block;margin:0 6px 6px 0">
                            <?= $csrf->field('closeout.access_decide') ?>
                            <input type="hidden" name="action" value="closeout.access_decide">
                            <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                            <input type="hidden" name="row" value="<?= $e((string) $row['id']) ?>">
                            <input type="hidden" name="decision" value="<?= $e($decision) ?>">
                            <button type="submit" class="sa-btn is-sm<?= $decision === CloseoutStep::ACCESS_REMOVED ? '' : ' is-quiet' ?>"><?= $decision === CloseoutStep::ACCESS_REMOVED ? 'Remove access' : 'Retain' ?></button>
                          </form>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <div class="sa-desk-grid2">
      <section aria-labelledby="desk-co-money">
        <p class="sa-label" id="desk-co-money">The money, final</p>
        <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
          <dl class="sa-dl">
            <dt>Verified</dt><dd><?= $e((string) $m['verified']) ?></dd>
            <dt>Taken back</dt><dd><?= $e((string) $m['taken_back']) ?></dd>
            <dt>Net recovery</dt><dd><b><?= $e((string) $m['net']) ?></b></dd>
            <dt>Fee</dt><dd><?= $e((string) $m['fee_net']) ?> at <?= $e((string) $m['rate']) ?></dd>
            <dt>Agreement</dt><dd class="sa-desk-mono"><?= $e((string) ($m['agreement_ref'] ?? 'none')) ?></dd>
            <?php foreach ($s['invoices'] as $invoice): ?>
              <dt>Invoice <?= $e((string) $invoice['public_ref']) ?></dt><dd><?= $e(Money::format((int) $invoice['total_cents'])) ?> &middot; <?= $e(InvoiceStatus::staffLabel((string) $invoice['status'])) ?></dd>
            <?php endforeach; ?>
          </dl>
        </div></div>
      </section>

      <section aria-labelledby="desk-co-docs">
        <p class="sa-label" id="desk-co-docs">Final documents</p>
        <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
          <?php if ($s['final'] === []): ?>
            <p class="sa-desk-quiet" style="margin:0">Nothing executed.</p>
          <?php else: ?>
            <ul class="sa-desk-list" style="margin:0">
              <?php foreach ($s['final'] as $document): ?>
                <li>
                  <?= $e(DocumentKind::label((string) $document['kind'])) ?>
                  <span class="sa-desk-mono"><?= $e((string) $document['public_ref']) ?> v<?= (int) $document['version'] ?></span>
                  &middot; <a href="/sa-desk.php?view=documents&amp;e=<?= $e(urlencode($engagementRef)) ?>&amp;open=<?= $e(urlencode((string) $document['public_ref'])) ?>" target="_blank" rel="noopener">open</a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if ($s['disposition'] !== null): ?>
            <p style="margin:12px 0 0"><b>Data disposition:</b> <?= $e((string) $s['disposition']) ?><?= $closeout['disposition_note'] === null ? '' : '. ' . $e((string) $closeout['disposition_note']) ?></p>
          <?php endif; ?>
          <?php if ($s['access_outcome'] !== null): ?>
            <p style="margin:6px 0 0"><b>Access:</b> <?= $e((string) $s['access_outcome']) ?></p>
          <?php endif; ?>
        </div></div>
      </section>
    </div>

    <?php if ($closeout['final_summary'] !== null): ?>
      <section aria-labelledby="desk-co-report">
        <p class="sa-label" id="desk-co-report">The final report, as the practice reads it</p>
        <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
          <p class="sa-desk-email" style="margin:0"><?= nl2br($e((string) $closeout['final_summary'])) ?></p>
        </div></div>
      </section>
    <?php endif; ?>

  <?php endif; ?>

  <section aria-labelledby="desk-co-back">
    <p class="sa-label" id="desk-co-back">Elsewhere</p>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
      <a class="sa-btn is-sm" href="/sa-desk.php?view=closeout">All closeouts</a>
      <a class="sa-btn is-sm" href="/sa-desk.php?view=money&amp;e=<?= $e(urlencode($engagementRef)) ?>">Money</a>
      <a class="sa-btn is-sm" href="/sa-desk.php?view=recovery&amp;e=<?= $e(urlencode($engagementRef)) ?>">The recovery</a>
      <a class="sa-btn is-sm" href="/sa-desk.php?view=documents&amp;e=<?= $e(urlencode($engagementRef)) ?>">Agreements</a>
    </div></div>
  </section>

<?php endif; ?>
