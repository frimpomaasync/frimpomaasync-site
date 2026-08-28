<?php
/**
 * Assessments. Section 7.2 on her side, and the Desk half of section 15.
 *
 * One engagement at a time. The milestone panel offers exactly the one move
 * the stage allows, in the order the plan walks: confirm receipt, start,
 * quality review, deliver. Under it, the batches, the action requests, the
 * checklist and the timeline, so the whole of what the practice is shown is
 * visible from here without opening the room as them.
 *
 * Nothing on this screen takes a claim. The receipt form takes a count. The
 * batch form takes counts and a dollar figure. The delivery form takes an
 * aggregate summary that is screened before it is stored.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var array<string,mixed>|null $engagement
 * @var bool $canManage
 * @var bool $canBatches
 * @var list<array<string,mixed>> $assessmentRows
 * @var array<string,mixed> $overview
 * @var list<array<string,mixed>> $batches
 * @var list<array<string,mixed>> $batchCards
 * @var list<array<string,mixed>> $requests
 * @var list<array<string,mixed>> $timeline
 * @var array<string,mixed>|null $signer
 * @var list<array<string,mixed>> $requestsForHer
 */

use SoftAppeals\Domain\ActionRequestKind;
use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Domain\Checklist;
use SoftAppeals\Domain\ClientDecision;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Support\Money;
use SoftAppeals\Views\Desk;

$e = static fn (?string $value): string => Desk::e($value);
?>

<?php if ($engagement === null): ?>

  <section aria-labelledby="desk-as-you">
    <p class="sa-label" id="desk-as-you">Waiting on you</p>
    <?php if ($requestsForHer === []): ?>
      <div class="sa-panel"><div class="sa-empty">No practice is waiting on an answer from you.</div></div>
    <?php else: ?>
      <div class="sa-desk-cards">
        <?php foreach ($requestsForHer as $row): ?>
          <div class="sa-desk-card is-urgent">
            <div class="sa-desk-card-t">
              <b><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></b>
              <span><?= $e(ActionRequestKind::title((string) $row['kind'])) ?> &middot; <?= $e(Desk::ago($clock, (string) $row['created_at'])) ?></span>
            </div>
            <div class="sa-desk-card-a">
              <a class="sa-btn is-action is-sm"
                 href="/sa-desk.php?view=assessments&amp;e=<?= $e(urlencode((string) $row['engagement_ref'])) ?>#desk-as-requests">Answer</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section aria-labelledby="desk-as-list">
    <p class="sa-label" id="desk-as-list">Every engagement at or past the gate</p>
    <div class="sa-panel">
      <?php if ($assessmentRows === []): ?>
        <div class="sa-empty">
          No engagement has reached the secure route yet. The button that opens it is on
          the Agreements screen, once both agreements are executed.
        </div>
      <?php else: ?>
        <div class="sa-tablewrap">
          <table class="sa-table">
            <thead><tr><th>Practice</th><th>Reference</th><th>Stage</th><th>Next</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($assessmentRows as $row): ?>
                <?php $stage = (string) $row['stage']; ?>
                <tr>
                  <td class="sa-strong"><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></td>
                  <td class="sa-desk-mono"><?= $e((string) $row['public_ref']) ?></td>
                  <td><?= $e(Stage::staffLabel($stage)) ?></td>
                  <td><?= $e(Stage::nextOwner($stage)) ?> &middot; <?= $e(Stage::nextAction($stage)) ?></td>
                  <td>
                    <?php if ($stage === Stage::REVIEW_AUTH_EXECUTED): ?>
                      <a class="sa-btn is-sm" href="/sa-desk.php?view=documents&amp;e=<?= $e(urlencode((string) $row['public_ref'])) ?>">Open the secure route</a>
                    <?php else: ?>
                      <a class="sa-btn is-sm" href="/sa-desk.php?view=assessments&amp;e=<?= $e(urlencode((string) $row['public_ref'])) ?>">Open</a>
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

<?php else: ?>

  <?php
  $engagementRef = (string) $engagement['public_ref'];
  $stage = (string) $overview['stage'];
  $assessment = $overview['assessment'];
  $totals = $overview['totals'];
  $decision = $overview['decision'];
  ?>

  <section aria-labelledby="desk-as-who">
    <p class="sa-label" id="desk-as-who"><?= $e((string) ($engagement['display_name'] ?? $engagement['legal_name'])) ?></p>
    <div class="sa-metrics">
      <div class="sa-metric">
        <p class="sa-metric-k">Stage</p>
        <p class="sa-metric-v"><?= $e(Stage::staffLabel($stage)) ?></p>
        <p class="sa-metric-c"><?= $e(Stage::nextOwner($stage)) ?> &middot; <?= $e(Stage::nextAction($stage)) ?></p>
      </div>
      <div class="sa-metric">
        <p class="sa-metric-k">Denials received</p>
        <p class="sa-metric-v"><?= $overview['received'] === null ? 'None yet' : (int) $overview['received'] ?></p>
        <p class="sa-metric-c">
          <?php if ($overview['received'] === null): ?>
            Expected set of <?= (int) $overview['expected'] ?>.
          <?php elseif ($overview['client_confirmed']): ?>
            Count confirmed by the practice.
          <?php else: ?>
            Not yet confirmed by the practice.
          <?php endif; ?>
        </p>
      </div>
      <div class="sa-metric">
        <p class="sa-metric-k">Assessment</p>
        <p class="sa-metric-v"><?= $e((string) $overview['progress']) ?></p>
        <p class="sa-metric-c">
          <?= $overview['recommended'] === null
            ? 'Recommendation not written yet.'
            : (int) $overview['recommended'] . ' recommended for action'
              . ($overview['recommended_amount'] === null ? '' : ', ' . $e((string) $overview['recommended_amount'])) ?>
        </p>
      </div>
      <div class="sa-metric<?= $decision !== null ? ' is-lead' : '' ?>">
        <p class="sa-metric-k">Their decision</p>
        <p class="sa-metric-v"><?= $decision === null ? 'Not yet' : $e(ClientDecision::staffLabel($decision)) ?></p>
        <p class="sa-metric-c">
          <?php if ($assessment !== null && $assessment['decision_at'] !== null): ?>
            <?= $e($clock->displayDateTime((string) $assessment['decision_at'])) ?>
          <?php elseif ($assessment !== null && $assessment['decision_due_at'] !== null): ?>
            Asked for by <?= $e($clock->displayDate((string) $assessment['decision_due_at'])) ?>
          <?php else: ?>
            No date asked for.
          <?php endif; ?>
        </p>
      </div>
    </div>
  </section>

  <?php if ($canManage): ?>
  <section aria-labelledby="desk-as-move">
    <p class="sa-label" id="desk-as-move">The next milestone</p>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:16px 18px">

      <?php if ($stage === Stage::SECURE_INTAKE_READY): ?>
        <p style="margin:0 0 12px">
          The secure route is open. When the initial set arrives through it, record
          how many. This opens the first batch from the same number and asks the
          practice to confirm the count. Counts only: nothing here is a claim.
        </p>
        <form method="post" action="/sa-desk.php" style="margin:0">
          <?= $csrf->field('assessment.confirm_receipt') ?>
          <input type="hidden" name="action" value="assessment.confirm_receipt">
          <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
          <input type="hidden" name="label" value="Initial set">
          <div class="sa-desk-grid2">
            <label class="sa-field">
              <span class="sa-fieldlabel">Denials received</span>
              <input class="sa-input" type="text" inputmode="numeric" name="received_count" maxlength="6" placeholder="20">
            </label>
            <label class="sa-field">
              <span class="sa-fieldlabel">Expected in the initial set</span>
              <input class="sa-input" type="text" inputmode="numeric" name="expected_count" maxlength="6" value="<?= (int) $overview['expected'] ?>">
            </label>
            <label class="sa-field">
              <span class="sa-fieldlabel">Payer label for the first batch, optional</span>
              <input class="sa-input" type="text" name="payer_label" maxlength="80" placeholder="Commercial, behavioral health">
            </label>
            <label class="sa-field">
              <span class="sa-fieldlabel">Aggregate denied amount, optional</span>
              <input class="sa-input" type="text" inputmode="decimal" name="denied_amount" maxlength="20" placeholder="12,345.67">
            </label>
            <label class="sa-field">
              <span class="sa-fieldlabel">Earliest known deadline, optional</span>
              <input class="sa-input" type="date" name="earliest_deadline">
            </label>
            <label class="sa-choice" style="align-self:end">
              <input type="checkbox" name="deadline_confirmed" value="yes">
              <span class="sa-choice-t"><b>That deadline is confirmed</b><br>Unticked, it is shown as unconfirmed everywhere.</span>
            </label>
          </div>
          <button type="submit" class="sa-btn is-primary" style="margin-top:14px">Confirm receipt</button>
        </form>

      <?php elseif ($stage === Stage::RECEIPT_CONFIRMED): ?>
        <p style="margin:0 0 12px">
          Denials received and recorded. Starting moves every received batch into
          review and tells the practice the review has begun.
        </p>
        <form method="post" action="/sa-desk.php" style="margin:0">
          <?= $csrf->field('assessment.start') ?>
          <input type="hidden" name="action" value="assessment.start">
          <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
          <button type="submit" class="sa-btn is-primary">Start the assessment</button>
        </form>

      <?php elseif ($stage === Stage::ASSESSMENT_IN_PROGRESS): ?>
        <p style="margin:0 0 12px">
          In progress. Mark batches recommended or not as you go, then send the whole
          thing to quality review before it is delivered.
        </p>
        <form method="post" action="/sa-desk.php" style="margin:0">
          <?= $csrf->field('assessment.quality_review') ?>
          <input type="hidden" name="action" value="assessment.quality_review">
          <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
          <button type="submit" class="sa-btn is-primary">Send to quality review</button>
        </form>

      <?php elseif ($stage === Stage::ASSESSMENT_QA): ?>
        <p style="margin:0 0 12px">
          Quality review. Read every batch card as the practice will read it, then
          deliver. Delivering emails the practice that it is in their room and
          hands them the decision. It sends nothing claim-level anywhere.
        </p>
        <form method="post" action="/sa-desk.php" style="margin:0 0 18px">
          <?= $csrf->field('assessment.deliver') ?>
          <input type="hidden" name="action" value="assessment.deliver">
          <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
          <label class="sa-field">
            <span class="sa-fieldlabel">The assessment, at aggregate level</span>
            <textarea class="sa-textarea" name="summary" rows="8" maxlength="2000"
                      placeholder="What the set showed, which denial types are recoverable, what is not worth pursuing, and what happens next. No patient, member, claim or date of service."></textarea>
          </label>
          <div class="sa-desk-grid2" style="margin-top:10px">
            <label class="sa-field">
              <span class="sa-fieldlabel">Recommended for action, count</span>
              <input class="sa-input" type="text" inputmode="numeric" name="recommended_count" maxlength="6" placeholder="12">
            </label>
            <label class="sa-field">
              <span class="sa-fieldlabel">Recommended for action, denied dollars</span>
              <input class="sa-input" type="text" inputmode="decimal" name="recommended_amount" maxlength="20" placeholder="8,400.00">
            </label>
            <label class="sa-field">
              <span class="sa-fieldlabel">Ask for a decision by, optional</span>
              <input class="sa-input" type="date" name="decision_due">
            </label>
          </div>
          <button type="submit" class="sa-btn is-primary" style="margin-top:14px">Deliver the assessment</button>
        </form>
        <form method="post" action="/sa-desk.php" style="margin:0">
          <?= $csrf->field('assessment.return') ?>
          <input type="hidden" name="action" value="assessment.return">
          <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
          <button type="submit" class="sa-btn is-sm">Send it back to in progress</button>
        </form>

      <?php elseif ($stage === Stage::ASSESSMENT_DELIVERED): ?>
        <p style="margin:0">
          Delivered. The practice has not opened it yet. The stage moves to
          "decision pending" the moment they do, and nothing here can move it for them.
        </p>

      <?php elseif ($stage === Stage::CLIENT_DECISION_PENDING): ?>
        <p style="margin:0">
          They have read it. The decision is theirs: recovery scope, more information,
          internal use, or no further action. A question from them appears under
          Action requests below, waiting on you.
        </p>

      <?php elseif ($stage === Stage::RECOVERY_SCOPE_SELECTED): ?>
        <p style="margin:0 0 12px">
          They chose recovery. The next gate is the Recovery Services Agreement,
          generated from the scope you record on the Recovery screen. Nothing can
          be submitted to a payer before it is executed.
        </p>
        <a class="sa-btn is-primary" href="/sa-desk.php?view=recovery&amp;e=<?= $e($engagementRef) ?>">Record the scope</a>

      <?php elseif (in_array($stage, [Stage::RECOVERY_AGREEMENT_PENDING, Stage::RECOVERY_AGREEMENT_EXECUTED, Stage::RECOVERY_ACTIVE], true)): ?>
        <p style="margin:0 0 12px">Recovery is under way. The work is on the Recovery screen.</p>
        <a class="sa-btn is-sm" href="/sa-desk.php?view=recovery&amp;e=<?= $e($engagementRef) ?>">Open the recovery</a>

      <?php elseif ($stage === Stage::CLOSED_NO_RECOVERY): ?>
        <p style="margin:0">
          Closed without recovery, on their word. The assessment stays in their room.
        </p>

      <?php else: ?>
        <p style="margin:0">Nothing to do on the assessment at "<?= $e(Stage::staffLabel($stage)) ?>".</p>
      <?php endif; ?>

    </div></div>
  </section>
  <?php endif; ?>

  <?php if ($assessment !== null && $assessment['summary'] !== null): ?>
  <section aria-labelledby="desk-as-summary">
    <p class="sa-label" id="desk-as-summary">The assessment, as delivered</p>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
      <p class="sa-desk-email"><?= $e((string) $assessment['summary']) ?></p>
      <?php if ($assessment['decision_note'] !== null): ?>
        <p class="sa-desk-note" style="margin-top:12px">Their note with the decision: <?= $e((string) $assessment['decision_note']) ?></p>
      <?php endif; ?>
    </div></div>
  </section>
  <?php endif; ?>

  <section aria-labelledby="desk-as-batches">
    <p class="sa-label" id="desk-as-batches">Work batches</p>
    <?php if ($batches === []): ?>
      <div class="sa-panel"><div class="sa-empty">
        No batch yet. Confirming receipt opens the first one.
      </div></div>
    <?php else: ?>
      <?php foreach ($batches as $index => $batch): ?>
        <?php
        $card = $batchCards[$index];
        $batchStage = (string) $batch['stage'];
        $days = $card['deadline_days'];
        ?>
        <div class="sa-panel" style="margin-bottom:14px">
          <div class="sa-panel-h">
            <div>
              <b><?= $e((string) $batch['label']) ?></b>
              <span class="sa-desk-mono"><?= $e((string) $batch['public_ref']) ?></span>
            </div>
            <span class="sa-pill"><?= $e(BatchStage::staffLabel($batchStage)) ?></span>
          </div>
          <div class="sa-panel-b" style="padding:14px 18px">
            <dl class="sa-dl">
              <dt>Payer</dt>
              <dd><?= $batch['payer_label'] === null ? '<span class="sa-desk-quiet">No label</span>' : $e((string) $batch['payer_label']) . ((int) $batch['payer_label_approved'] === 1 ? '' : ' <span class="sa-desk-quiet">(not shown to the practice)</span>') ?></dd>
              <dt>Claims</dt><dd><?= (int) $batch['claim_count'] ?> in the batch, <?= (int) $batch['received_count'] ?> received</dd>
              <dt>Denied</dt><dd><?= $e(Money::format((int) $batch['denied_amount_cents'])) ?></dd>
              <dt>Next</dt><dd><?= $e(BatchStage::ownerLabel((string) $batch['next_owner'])) ?> &middot; <?= $e((string) $card['action']) ?></dd>
              <dt>Deadline</dt>
              <dd>
                <?php if ($card['deadline'] === null): ?>
                  <span class="sa-desk-quiet">None recorded</span>
                <?php else: ?>
                  <span class="<?= $e(Desk::deadlinePill($days, $card['confirmed'])) ?>"><?= $e(Desk::deadlineWords($days, $card['confirmed'])) ?></span>
                  <span class="sa-desk-mono"><?= $e((string) $card['deadline']) ?> &middot; <?= $e((string) $card['deadline_words']) ?></span>
                <?php endif; ?>
              </dd>
            </dl>

            <?php if ($canBatches && !BatchStage::isTerminal($batchStage)): ?>
              <form method="post" action="/sa-desk.php" style="margin-top:14px">
                <?= $csrf->field('batch.update') ?>
                <input type="hidden" name="action" value="batch.update">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <input type="hidden" name="batch" value="<?= $e((string) $batch['public_ref']) ?>">
                <div class="sa-desk-grid2">
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Stage</span>
                    <select class="sa-select" name="stage">
                      <?php foreach (BatchStage::phaseFive() as $option): ?>
                        <option value="<?= $e($option) ?>"<?= $option === $batchStage ? ' selected' : '' ?>><?= $e(BatchStage::staffLabel($option)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Next owner</span>
                    <select class="sa-select" name="next_owner">
                      <?php foreach (BatchStage::owners() as $option): ?>
                        <option value="<?= $e($option) ?>"<?= $option === (string) $batch['next_owner'] ? ' selected' : '' ?>><?= $e(BatchStage::ownerLabel($option)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Batch name</span>
                    <input class="sa-input" type="text" name="label" maxlength="80" value="<?= $e((string) $batch['label']) ?>">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Payer label</span>
                    <input class="sa-input" type="text" name="payer_label" maxlength="80" value="<?= $e((string) ($batch['payer_label'] ?? '')) ?>">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Claims in the batch</span>
                    <input class="sa-input" type="text" inputmode="numeric" name="claim_count" maxlength="6" value="<?= (int) $batch['claim_count'] ?>">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Denied amount</span>
                    <input class="sa-input" type="text" inputmode="decimal" name="denied_amount" maxlength="20" value="<?= $e(ltrim(Money::format((int) $batch['denied_amount_cents']), '$')) ?>">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Next safe action, in words the practice reads</span>
                    <input class="sa-input" type="text" name="next_action" maxlength="160" value="<?= $e((string) ($batch['next_action'] ?? '')) ?>">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Earliest known deadline</span>
                    <input class="sa-input" type="date" name="earliest_deadline" value="<?= $e($batch['earliest_deadline_at'] === null ? '' : substr((string) $batch['earliest_deadline_at'], 0, 10)) ?>">
                  </label>
                  <label class="sa-choice">
                    <input type="checkbox" name="deadline_confirmed" value="yes"<?= (int) $batch['deadline_confirmed'] === 1 ? ' checked' : '' ?>>
                    <span class="sa-choice-t"><b>Deadline confirmed</b></span>
                  </label>
                  <label class="sa-choice">
                    <input type="checkbox" name="payer_label_approved" value="yes"<?= (int) $batch['payer_label_approved'] === 1 ? ' checked' : '' ?>>
                    <span class="sa-choice-t"><b>Show the payer label to the practice</b></span>
                  </label>
                </div>
                <button type="submit" class="sa-btn is-sm" style="margin-top:12px">Save this batch</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($canBatches && Stage::phiGatePassed($stage) && !Stage::isTerminal($stage)): ?>
      <div class="sa-panel" style="margin-top:14px"><div class="sa-panel-b" style="padding:14px 18px">
        <p class="sa-label">Open another batch</p>
        <form method="post" action="/sa-desk.php" style="margin:0">
          <?= $csrf->field('batch.open') ?>
          <input type="hidden" name="action" value="batch.open">
          <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
          <div class="sa-desk-grid2">
            <label class="sa-field">
              <span class="sa-fieldlabel">Batch name</span>
              <input class="sa-input" type="text" name="label" maxlength="80" placeholder="Second set">
            </label>
            <label class="sa-field">
              <span class="sa-fieldlabel">Payer label, optional</span>
              <input class="sa-input" type="text" name="payer_label" maxlength="80">
            </label>
            <label class="sa-field">
              <span class="sa-fieldlabel">Claims in the batch</span>
              <input class="sa-input" type="text" inputmode="numeric" name="claim_count" maxlength="6" placeholder="10">
            </label>
            <label class="sa-field">
              <span class="sa-fieldlabel">Denied amount</span>
              <input class="sa-input" type="text" inputmode="decimal" name="denied_amount" maxlength="20" placeholder="4,200.00">
            </label>
            <label class="sa-field">
              <span class="sa-fieldlabel">Earliest known deadline, optional</span>
              <input class="sa-input" type="date" name="earliest_deadline">
            </label>
            <label class="sa-choice" style="align-self:end">
              <input type="checkbox" name="deadline_confirmed" value="yes">
              <span class="sa-choice-t"><b>That deadline is confirmed</b></span>
            </label>
          </div>
          <button type="submit" class="sa-btn is-sm" style="margin-top:12px">Open the batch</button>
        </form>
      </div></div>
    <?php endif; ?>
  </section>

  <section aria-labelledby="desk-as-requests" id="desk-as-requests">
    <p class="sa-label" id="desk-as-requests-h">Action requests</p>
    <?php if ($requests === []): ?>
      <div class="sa-panel"><div class="sa-empty">Nothing has been asked of anybody yet.</div></div>
    <?php else: ?>
      <div class="sa-desk-cards">
        <?php foreach ($requests as $request): ?>
          <?php
          $kind = (string) $request['kind'];
          $status = (string) $request['status'];
          $isOpen = $status === ActionRequestKind::STATUS_OPEN;
          $hers = (string) $request['owner'] === ActionRequestKind::OWNER_SOFT_APPEALS;
          ?>
          <div class="sa-desk-card<?= $isOpen && $hers ? ' is-urgent' : '' ?>" style="align-items:flex-start">
            <div class="sa-desk-card-t" style="flex:1 1 320px">
              <b><?= $e(ActionRequestKind::title($kind)) ?></b>
              <span>
                <?= $hers ? 'Waiting on you' : 'Waiting on the practice' ?>
                &middot; <?= $e(ActionRequestKind::statusLabel($status)) ?>
                &middot; <?= $e(Desk::ago($clock, (string) $request['created_at'])) ?>
                <?php if ($request['due_at'] !== null): ?>
                  &middot; due <?= $e($clock->displayDate((string) $request['due_at'])) ?>
                <?php endif; ?>
              </span>
              <?php if ($request['note'] !== null): ?>
                <span style="color:var(--sa-ink);margin-top:6px"><?= $e((string) $request['note']) ?></span>
              <?php endif; ?>
              <?php if ($request['response'] !== null): ?>
                <span style="margin-top:6px">Answer: <?= $e((string) $request['response']) ?></span>
              <?php endif; ?>
              <?php if ($isOpen && $hers && $canManage): ?>
                <form method="post" action="/sa-desk.php" style="margin-top:10px">
                  <?= $csrf->field('assessment.answer') ?>
                  <input type="hidden" name="action" value="assessment.answer">
                  <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                  <input type="hidden" name="request" value="<?= $e((string) $request['public_ref']) ?>">
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Your answer, which they read in the room</span>
                    <textarea class="sa-textarea" name="response" rows="4" maxlength="1000"></textarea>
                  </label>
                  <button type="submit" class="sa-btn is-action is-sm" style="margin-top:8px">Answer and hand back the decision</button>
                </form>
              <?php endif; ?>
            </div>
            <?php if ($isOpen && !$hers && $canManage): ?>
              <div class="sa-desk-card-a">
                <form method="post" action="/sa-desk.php" style="margin:0">
                  <?= $csrf->field('request.complete') ?>
                  <input type="hidden" name="action" value="request.complete">
                  <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                  <input type="hidden" name="request" value="<?= $e((string) $request['public_ref']) ?>">
                  <button type="submit" class="sa-btn is-sm">Mark done</button>
                </form>
                <form method="post" action="/sa-desk.php" style="margin:0">
                  <?= $csrf->field('request.cancel') ?>
                  <input type="hidden" name="action" value="request.cancel">
                  <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                  <input type="hidden" name="request" value="<?= $e((string) $request['public_ref']) ?>">
                  <button type="submit" class="sa-btn is-quiet is-sm">Withdraw</button>
                </form>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($canManage && Stage::phiGatePassed($stage) && !Stage::isTerminal($stage)): ?>
      <div class="sa-panel" style="margin-top:14px"><div class="sa-panel-b" style="padding:14px 18px">
        <p class="sa-label">Ask the practice for something</p>
        <form method="post" action="/sa-desk.php" style="margin:0">
          <?= $csrf->field('request.open') ?>
          <input type="hidden" name="action" value="request.open">
          <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
          <div class="sa-desk-grid2">
            <label class="sa-field">
              <span class="sa-fieldlabel">What</span>
              <select class="sa-select" name="kind">
                <?php foreach (ActionRequestKind::all() as $option): ?>
                  <?php if (ActionRequestKind::owner($option) !== ActionRequestKind::OWNER_CLIENT): ?>
                    <?php continue; ?>
                  <?php endif; ?>
                  <option value="<?= $e($option) ?>"><?= $e(ActionRequestKind::title($option)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="sa-field">
              <span class="sa-fieldlabel">Due, optional</span>
              <input class="sa-input" type="date" name="due">
            </label>
          </div>
          <label class="sa-field" style="margin-top:10px">
            <span class="sa-fieldlabel">A line to add, optional. Screened: no patient, member or claim.</span>
            <input class="sa-input" type="text" name="note" maxlength="1000">
          </label>
          <button type="submit" class="sa-btn is-sm" style="margin-top:12px">Ask</button>
        </form>
        <p class="sa-desk-note" style="margin-top:10px">
          Anything claim-level is asked for through the approved secure route. The
          request kinds that need it say so on the practice's card, and the portal
          itself never takes a file.
        </p>
      </div></div>
    <?php endif; ?>
  </section>

  <div class="sa-desk-grid2">
    <section aria-labelledby="desk-as-check">
      <p class="sa-label" id="desk-as-check">
        Checklist &middot; <?= (int) $overview['checklist_progress']['done'] ?> of <?= (int) $overview['checklist_progress']['total'] ?>
      </p>
      <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
        <ul class="sa-desk-list" style="list-style:none;padding-left:0">
          <?php foreach ($overview['checklist'] as $item): ?>
            <li>
              <?= $item['completed_at'] !== null ? '&#10003;' : '&middot;' ?>
              <?= $e((string) $item['label']) ?>
              <span class="sa-desk-quiet">
                <?= $e(Checklist::categoryLabel((string) $item['category'])) ?>
                <?php if ($item['completed_at'] !== null): ?>
                  &middot; <?= $e($clock->displayDate((string) $item['completed_at'])) ?>
                <?php endif; ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div></div>
    </section>

    <section aria-labelledby="desk-as-history">
      <p class="sa-label" id="desk-as-history">What the practice reads as its history</p>
      <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
        <ul class="sa-desk-list">
          <?php foreach ($timeline as $event): ?>
            <li>
              <?= $e((string) $event['public_label']) ?>
              <span class="sa-desk-quiet"><?= $e($clock->displayDate((string) $event['created_at'])) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div></div>
    </section>
  </div>

  <section aria-labelledby="desk-as-back">
    <p class="sa-label" id="desk-as-back">Elsewhere</p>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
      <a class="sa-btn is-sm" href="/sa-desk.php?view=assessments">All assessments</a>
      <a class="sa-btn is-sm" href="/sa-desk.php?view=documents&amp;e=<?= $e(urlencode($engagementRef)) ?>">Agreements for this practice</a>
      <?php if ($signer !== null): ?>
        <span class="sa-desk-quiet" style="margin-left:8px">Signer: <?= $e((string) $signer['name']) ?>, <?= $e((string) $signer['work_email']) ?></span>
      <?php endif; ?>
    </div></div>
  </section>

<?php endif; ?>
