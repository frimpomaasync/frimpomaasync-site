<?php
/**
 * Recovery. Section 7.3 on her side: the scope, the two Gate B documents,
 * starting the work, and then batch by batch, approvals, submissions and
 * payer responses.
 *
 * One engagement at a time. The scope form takes a fee basis, a summary,
 * the recommended batches and an approver. The approval form takes a
 * screened summary, a count, a dollar figure and a date. The submission and
 * response forms take a count, a dollar figure, a date and a note. Nothing
 * on this screen takes a claim.
 *
 * Nothing on this screen calculates a fee. The block at the bottom says so.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var array<string,mixed>|null $engagement
 * @var bool $canManage
 * @var bool $canBatches
 * @var bool $canGenerate
 * @var bool $eSignEnabled
 * @var list<array<string,mixed>> $recoveryRows
 * @var list<array<string,mixed>> $pendingApprovals
 * @var list<array<string,mixed>> $awaitingSubmission
 * @var list<array<string,mixed>> $followUps
 * @var list<array<string,mixed>> $recoveryWaiting
 * @var array<string,mixed>|null $scope
 * @var array<string,mixed>|null $preferredApprover
 * @var list<array<string,mixed>> $orgContacts
 * @var array{agreement:?array<string,mixed>,scope_document:?array<string,mixed>,both_executed:bool} $agreementStatus
 * @var list<array<string,mixed>> $board
 * @var list<array<string,mixed>> $approvals
 * @var list<array<string,mixed>> $submissions
 * @var array<string,mixed> $feeBlock
 * @var list<array<string,mixed>> $timeline
 * @var array<string,mixed> $overview
 * @var array{ok:bool,reason:?string} $generateCheck
 */

use SoftAppeals\Domain\ApprovalState;
use SoftAppeals\Domain\BatchStage;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Domain\SubmissionEventType;
use SoftAppeals\Support\Money;
use SoftAppeals\Views\Desk;

$e = static fn (?string $value): string => Desk::e($value);
?>

<?php if ($engagement === null): ?>

  <section aria-labelledby="desk-rc-you">
    <p class="sa-label" id="desk-rc-you">Waiting on you</p>
    <?php $hers = array_merge($recoveryWaiting ?? [], []); ?>
    <?php if ($hers === [] && ($awaitingSubmission ?? []) === [] && ($followUps ?? []) === []): ?>
      <div class="sa-panel"><div class="sa-empty">No recovery is waiting on a move from you.</div></div>
    <?php else: ?>
      <div class="sa-desk-cards">
        <?php foreach ($hers as $row): ?>
          <?php $stage = (string) $row['stage']; ?>
          <div class="sa-desk-card">
            <div class="sa-desk-card-t">
              <b><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></b>
              <span><?= $e(Stage::staffLabel($stage)) ?> &middot; <?= $e(Stage::nextAction($stage)) ?></span>
            </div>
            <div class="sa-desk-card-a">
              <a class="sa-btn is-action is-sm" href="/sa-desk.php?view=recovery&amp;e=<?= $e(urlencode((string) $row['public_ref'])) ?>">Open</a>
            </div>
          </div>
        <?php endforeach; ?>
        <?php foreach ($awaitingSubmission ?? [] as $row): ?>
          <div class="sa-desk-card is-urgent">
            <div class="sa-desk-card-t">
              <b><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></b>
              <span>Approved, waiting on your submission &middot; batch <?= $e((string) $row['batch_label']) ?> &middot; approved <?= $e(Desk::ago($clock, (string) $row['decision_at'])) ?></span>
            </div>
            <div class="sa-desk-card-a">
              <a class="sa-btn is-action is-sm" href="/sa-desk.php?view=recovery&amp;e=<?= $e(urlencode((string) $row['engagement_ref'])) ?>#desk-rc-board">Record the submission</a>
            </div>
          </div>
        <?php endforeach; ?>
        <?php foreach ($followUps ?? [] as $row): ?>
          <?php $days = $clock->daysUntil((string) $row['follow_up_due_at']); ?>
          <div class="sa-desk-card<?= $days !== null && $days <= 0 ? ' is-urgent' : '' ?>">
            <div class="sa-desk-card-t">
              <b><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></b>
              <span>Follow-up on <?= $e(SubmissionEventType::staffLabel((string) $row['event_type'])) ?> &middot; batch <?= $e((string) $row['batch_label']) ?> &middot; <?= $e(Desk::deadlineWords($days, true)) ?></span>
            </div>
            <div class="sa-desk-card-a">
              <a class="sa-btn is-sm" href="/sa-desk.php?view=recovery&amp;e=<?= $e(urlencode((string) $row['engagement_ref'])) ?>#desk-rc-events">Open</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section aria-labelledby="desk-rc-them">
    <p class="sa-label" id="desk-rc-them">With a practice for approval</p>
    <?php if (($pendingApprovals ?? []) === []): ?>
      <div class="sa-panel"><div class="sa-empty">No approval is waiting on a practice.</div></div>
    <?php else: ?>
      <div class="sa-desk-cards">
        <?php foreach ($pendingApprovals as $row): ?>
          <?php $days = $row['due_at'] === null ? null : $clock->daysUntil((string) $row['due_at']); ?>
          <div class="sa-desk-card">
            <div class="sa-desk-card-t">
              <b><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></b>
              <span>Batch <?= $e((string) $row['batch_label']) ?> &middot; asked <?= $e(Desk::ago($clock, (string) $row['created_at'])) ?><?= $days === null ? '' : ' &middot; ' . $e(Desk::deadlineWords($days, true)) ?></span>
            </div>
            <div class="sa-desk-card-a">
              <a class="sa-btn is-quiet is-sm" href="/sa-desk.php?view=recovery&amp;e=<?= $e(urlencode((string) $row['engagement_ref'])) ?>#desk-rc-approvals">View</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section aria-labelledby="desk-rc-list">
    <p class="sa-label" id="desk-rc-list">Every engagement that chose recovery</p>
    <div class="sa-panel">
      <?php if (($recoveryRows ?? []) === []): ?>
        <div class="sa-empty">
          No practice has chosen recovery yet. The decision is theirs, on the
          assessment, and this screen fills in the moment one does.
        </div>
      <?php else: ?>
        <div class="sa-tablewrap">
          <table class="sa-table">
            <thead><tr><th>Practice</th><th>Reference</th><th>Stage</th><th>Next</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($recoveryRows as $row): ?>
                <?php $stage = (string) $row['stage']; ?>
                <tr>
                  <td class="sa-strong"><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></td>
                  <td class="sa-desk-mono"><?= $e((string) $row['public_ref']) ?></td>
                  <td><?= $e(Stage::staffLabel($stage)) ?></td>
                  <td><?= $e(Stage::nextOwner($stage)) ?> &middot; <?= $e(Stage::nextAction($stage)) ?></td>
                  <td><a class="sa-btn is-sm" href="/sa-desk.php?view=recovery&amp;e=<?= $e(urlencode((string) $row['public_ref'])) ?>">Open</a></td>
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
  $agreement = $agreementStatus['agreement'];
  $scopeDocument = $agreementStatus['scope_document'];
  $scopeOpen = in_array($stage, [Stage::RECOVERY_SCOPE_SELECTED, Stage::RECOVERY_AGREEMENT_PENDING], true);
  $active = $stage === Stage::RECOVERY_ACTIVE;
  $recommended = [];
  foreach ($board as $row) {
      if ((string) $row['batch']['stage'] === BatchStage::RECOMMENDED || $row['in_scope']) {
          $recommended[] = $row['batch'];
      }
  }
  $documentLine = static function (?array $document) use ($e): string {
      if ($document === null) {
          return '<span class="sa-desk-quiet">Not generated</span>';
      }
      return $e(DocumentStatus::staffLabel((string) $document['status']))
          . ' <span class="sa-desk-mono">' . $e((string) $document['public_ref']) . ' v' . (int) $document['version'] . '</span>';
  };
  ?>

  <section aria-labelledby="desk-rc-who">
    <p class="sa-label" id="desk-rc-who"><?= $e((string) ($engagement['display_name'] ?? $engagement['legal_name'])) ?></p>
    <div class="sa-metrics">
      <div class="sa-metric">
        <p class="sa-metric-k">Stage</p>
        <p class="sa-metric-v"><?= $e(Stage::staffLabel($stage)) ?></p>
        <p class="sa-metric-c"><?= $e(Stage::nextOwner($stage)) ?> &middot; <?= $e(Stage::nextAction($stage)) ?></p>
      </div>
      <div class="sa-metric">
        <p class="sa-metric-k">Scope</p>
        <p class="sa-metric-v"><?= $scope === null ? 'Not recorded' : count($scope['batches']) . ' ' . Desk::plural(count($scope['batches']), 'batch') ?></p>
        <p class="sa-metric-c"><?= $scope === null ? 'Record it below.' : (int) $scope['claim_count'] . ' denied claims, ' . $e(Money::format((int) $scope['denied_cents'])) ?></p>
      </div>
      <div class="sa-metric">
        <p class="sa-metric-k">Fee basis</p>
        <p class="sa-metric-v"><?= $scope === null ? 'Not set' : $e(EngagementTerms::feeLabel((string) $scope['fee_basis'])) ?></p>
        <p class="sa-metric-c"><?= $scope === null ? 'From the scope.' : $e((string) $scope['fee_rate_label']) ?></p>
      </div>
      <div class="sa-metric">
        <p class="sa-metric-k">Approver</p>
        <p class="sa-metric-v"><?= $scope === null || $scope['approver'] === null ? 'Nobody named' : $e((string) $scope['approver']['name']) ?></p>
        <p class="sa-metric-c"><?= $scope === null || $scope['approver'] === null ? 'Every submission needs one.' : $e((string) $scope['approver']['work_email']) ?></p>
      </div>
    </div>
  </section>

  <?php if ($canManage): ?>
  <section aria-labelledby="desk-rc-move">
    <p class="sa-label" id="desk-rc-move">The next milestone</p>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:16px 18px">

      <?php if ($stage === Stage::RECOVERY_SCOPE_SELECTED && $scope === null): ?>
        <p style="margin:0">
          They chose recovery. Record the scope below: the fee basis, the batches
          in scope, and who approves each submission. The Recovery Services
          Agreement and the Approved Recovery Scope are generated from it.
        </p>

      <?php elseif ($stage === Stage::RECOVERY_SCOPE_SELECTED && $agreement === null): ?>
        <p style="margin:0 0 12px">
          Scope recorded. Generate the two Gate B documents from it. They are
          drafts until sent, and sending the agreement sends the scope with it.
        </p>
        <?php if ($canGenerate && $generateCheck['ok']): ?>
          <form method="post" action="/sa-desk.php" style="margin:0">
            <?= $csrf->field('document.generate_recovery') ?>
            <input type="hidden" name="action" value="document.generate_recovery">
            <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
            <button type="submit" class="sa-btn is-primary">Generate the agreement and the scope</button>
          </form>
        <?php else: ?>
          <p class="sa-desk-note" style="margin:0"><?= $e((string) ($generateCheck['reason'] ?? 'Not checked.')) ?></p>
        <?php endif; ?>

      <?php elseif ($stage === Stage::RECOVERY_SCOPE_SELECTED): ?>
        <p style="margin:0 0 12px">
          Both documents are generated. Read them, then send the agreement for
          signature from the Agreements screen. The scope goes with it.
        </p>
        <a class="sa-btn is-primary" href="/sa-desk.php?view=documents&amp;e=<?= $e(urlencode($engagementRef)) ?>">Open the agreements</a>

      <?php elseif ($stage === Stage::RECOVERY_AGREEMENT_PENDING): ?>
        <p style="margin:0">
          Out for signature. The practice signs the agreement, then the scope,
          in the room. Your countersignature on the agreement is what executes
          it; the scope executes on their signature alone.
        </p>

      <?php elseif ($stage === Stage::RECOVERY_AGREEMENT_EXECUTED): ?>
        <?php if ($agreementStatus['both_executed']): ?>
          <p style="margin:0 0 12px">
            Both documents are executed. Starting the work opens the recovery
            checklist and lets each batch in scope be put up for approval.
          </p>
          <form method="post" action="/sa-desk.php" style="margin:0">
            <?= $csrf->field('recovery.activate') ?>
            <input type="hidden" name="action" value="recovery.activate">
            <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
            <button type="submit" class="sa-btn is-primary">Start the recovery work</button>
          </form>
        <?php else: ?>
          <p style="margin:0">
            The agreement is executed. The Approved Recovery Scope is not signed
            yet; the practice signs it in the room and it executes on their
            signature. Recovery starts once both are done.
          </p>
        <?php endif; ?>

      <?php elseif ($active): ?>
        <p style="margin:0">
          Recovery is active. Work the board below: ask for approval on a batch in
          scope, record the submission once approved, then record what the payer
          did. Nothing here creates a fee.
        </p>

      <?php else: ?>
        <p style="margin:0">Nothing to do on recovery at "<?= $e(Stage::staffLabel($stage)) ?>".</p>
      <?php endif; ?>

    </div></div>
  </section>
  <?php endif; ?>

  <section aria-labelledby="desk-rc-gate">
    <p class="sa-label" id="desk-rc-gate">Gate B</p>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
      <dl class="sa-dl">
        <dt><?= $e(DocumentKind::label(DocumentKind::RECOVERY_AGREEMENT)) ?></dt>
        <dd><?= $documentLine($agreement) ?></dd>
        <dt><?= $e(DocumentKind::label(DocumentKind::APPROVED_SCOPE)) ?></dt>
        <dd><?= $documentLine($scopeDocument) ?></dd>
      </dl>
      <?php if (!$eSignEnabled): ?>
        <p class="sa-desk-note" style="margin-top:12px">Signing is off in this environment. The documents can be generated and read, not sent.</p>
      <?php endif; ?>
      <p style="margin:12px 0 0"><a class="sa-btn is-sm" href="/sa-desk.php?view=documents&amp;e=<?= $e(urlencode($engagementRef)) ?>">Agreements for this practice</a></p>
    </div></div>
  </section>

  <?php if ($canManage && ($scopeOpen || $scope === null)): ?>
  <section aria-labelledby="desk-rc-scope">
    <p class="sa-label" id="desk-rc-scope"><?= $scope === null ? 'Record the recovery scope' : 'The recovery scope' ?></p>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:16px 18px">
      <?php if (!$scopeOpen): ?>
        <p style="margin:0">The scope is recorded at "recovery scope selected". This engagement is at "<?= $e(Stage::staffLabel($stage)) ?>".</p>
      <?php elseif ($recommended === []): ?>
        <p style="margin:0">
          No batch is marked recommended, so there is nothing to put in scope.
          Mark the batches the assessment recommended on the Assessments screen first.
        </p>
      <?php else: ?>
        <form method="post" action="/sa-desk.php" style="margin:0">
          <?= $csrf->field('recovery.scope_save') ?>
          <input type="hidden" name="action" value="recovery.scope_save">
          <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">

          <p class="sa-fieldlabel" style="margin:0 0 6px">Batches in scope. Recommended batches only.</p>
          <div class="sa-choices">
            <?php foreach ($recommended as $batch): ?>
              <?php $inScope = $scope !== null && in_array((string) $batch['id'], $scope['batch_ids'], true); ?>
              <label class="sa-choice">
                <input type="checkbox" name="batch_refs[]" value="<?= $e((string) $batch['public_ref']) ?>"<?= $inScope ? ' checked' : '' ?>>
                <span class="sa-choice-t">
                  <b><?= $e((string) $batch['label']) ?></b> <span class="sa-desk-mono"><?= $e((string) $batch['public_ref']) ?></span><br>
                  <?= (int) $batch['claim_count'] ?> denied claims, <?= $e(Money::format((int) $batch['denied_amount_cents'])) ?>
                  <?= $batch['payer_label'] === null ? '' : ' &middot; ' . $e((string) $batch['payer_label']) ?>
                  <?= (string) $batch['stage'] === BatchStage::RECOMMENDED ? '' : ' &middot; ' . $e(BatchStage::staffLabel((string) $batch['stage'])) ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="sa-desk-grid2" style="margin-top:14px">
            <label class="sa-field">
              <span class="sa-fieldlabel">Fee basis</span>
              <select class="sa-select" name="fee_basis">
                <?php foreach (EngagementTerms::feeBases() as $value => $label): ?>
                  <?php if ($value === EngagementTerms::FEE_NOT_SET): ?>
                    <?php continue; ?>
                  <?php endif; ?>
                  <?php $current = $scope === null ? (string) $engagement['fee_basis'] : (string) $scope['fee_basis']; ?>
                  <option value="<?= $e($value) ?>"<?= $value === $current ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="sa-field">
              <span class="sa-fieldlabel">Rate, percent. Leave blank for the standard 25 on contingency.</span>
              <input class="sa-input" type="text" inputmode="decimal" name="fee_rate" maxlength="6"
                     value="<?= $scope === null || $scope['fee_rate_bps'] === null ? '' : $e(rtrim(rtrim(number_format((int) $scope['fee_rate_bps'] / 100, 2, '.', ''), '0'), '.')) ?>"
                     placeholder="25">
            </label>
          </div>

          <label class="sa-field" style="margin-top:10px">
            <span class="sa-fieldlabel">What is in scope, in a sentence or two. It goes on the face of both documents. No patient, member, claim or date of service.</span>
            <textarea class="sa-textarea" name="summary" rows="4" maxlength="1000"><?= $scope === null ? '' : $e((string) $scope['summary']) ?></textarea>
          </label>

          <p class="sa-fieldlabel" style="margin:14px 0 6px">Submission approver. The person who approves each submission before it goes to a payer.</p>
          <div class="sa-desk-grid2">
            <label class="sa-field">
              <span class="sa-fieldlabel">A contact already at the practice</span>
              <select class="sa-select" name="approver_contact">
                <option value="">Nobody yet</option>
                <?php foreach ($orgContacts as $contact): ?>
                  <?php
                  $selected = $scope !== null && $scope['approver_contact_id'] !== null
                      ? (string) $scope['approver_contact_id'] === (string) $contact['id']
                      : ($preferredApprover !== null && (string) $preferredApprover['id'] === (string) $contact['id']);
                  ?>
                  <option value="<?= $e((string) $contact['id']) ?>"<?= $selected ? ' selected' : '' ?>><?= $e((string) $contact['name']) ?>, <?= $e((string) $contact['work_email']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <div class="sa-field">
              <span class="sa-fieldlabel">Or a new person, who gets a passwordless sign-in as approver</span>
              <input class="sa-input" type="text" name="approver_name" maxlength="120" placeholder="Name" style="margin-bottom:6px">
              <input class="sa-input" type="email" name="approver_email" maxlength="160" placeholder="Work email" style="margin-bottom:6px">
              <input class="sa-input" type="text" name="approver_role" maxlength="80" placeholder="Title, optional">
            </div>
          </div>

          <button type="submit" class="sa-btn is-primary" style="margin-top:14px"><?= $scope === null ? 'Record the scope' : 'Update the scope' ?></button>
          <?php if ($scope !== null && $agreement !== null): ?>
            <p class="sa-desk-note" style="margin-top:10px">Documents already generated keep the scope they were generated from. Void and replace them on the Agreements screen to carry a change.</p>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </div></div>
  </section>
  <?php elseif ($scope !== null): ?>
  <section aria-labelledby="desk-rc-scope">
    <p class="sa-label" id="desk-rc-scope">The recovery scope, as signed</p>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
      <p class="sa-desk-email"><?= $e((string) $scope['summary']) ?></p>
      <ul class="sa-desk-list" style="margin-top:10px">
        <?php foreach ($scope['batches'] as $batch): ?>
          <li><?= $e((string) $batch['label']) ?> <span class="sa-desk-mono"><?= $e((string) $batch['public_ref']) ?></span> &middot; <?= (int) $batch['claim_count'] ?> claims, <?= $e(Money::format((int) $batch['denied_amount_cents'])) ?></li>
        <?php endforeach; ?>
      </ul>
    </div></div>
  </section>
  <?php endif; ?>

  <section aria-labelledby="desk-rc-board" id="desk-rc-board">
    <p class="sa-label" id="desk-rc-board-h">The batch board</p>
    <?php if ($board === []): ?>
      <div class="sa-panel"><div class="sa-empty">No batch on this engagement.</div></div>
    <?php else: ?>
      <?php foreach ($board as $row): ?>
        <?php
        $batch = $row['batch'];
        $card = $row['card'];
        $batchStage = (string) $batch['stage'];
        $approval = $row['approval'];
        $event = $row['event'];
        ?>
        <div class="sa-panel" style="margin-bottom:14px">
          <div class="sa-panel-h">
            <div>
              <b><?= $e((string) $batch['label']) ?></b>
              <span class="sa-desk-mono"><?= $e((string) $batch['public_ref']) ?></span>
              <?php if (!$row['in_scope']): ?>
                <span class="sa-desk-quiet">outside the scope</span>
              <?php endif; ?>
            </div>
            <span class="sa-pill<?= BatchStage::isInRecovery($batchStage) ? ' is-action' : '' ?>"><?= $e((string) $row['staff_stage']) ?></span>
          </div>
          <div class="sa-panel-b" style="padding:14px 18px">
            <dl class="sa-dl">
              <dt>Claims</dt><dd><?= (int) $batch['claim_count'] ?> in the batch &middot; <?= (int) $batch['submitted_count'] ?> submitted &middot; <?= (int) $batch['overturned_count'] ?> overturned &middot; <?= (int) $batch['upheld_count'] ?> upheld</dd>
              <dt>Denied</dt><dd><?= $e(Money::format((int) $batch['denied_amount_cents'])) ?></dd>
              <dt>Next</dt><dd><?= $e(BatchStage::ownerLabel((string) $batch['next_owner'])) ?> &middot; <?= $e((string) $card['action']) ?></dd>
              <?php if ($approval !== null): ?>
                <dt>Approval</dt>
                <dd><?= $e(ApprovalState::staffLabel((string) $approval['state'])) ?> <span class="sa-desk-mono"><?= $e((string) $approval['public_ref']) ?></span><?= $approval['decision_note'] === null ? '' : ' &middot; ' . $e((string) $approval['decision_note']) ?></dd>
              <?php endif; ?>
              <?php if ($event !== null): ?>
                <dt>Last event</dt>
                <dd><?= $e(SubmissionEventType::staffLabel((string) $event['event_type'])) ?> &middot; <?= $e($clock->displayDate((string) $event['occurred_at'])) ?> &middot; <?= (int) $event['claim_count'] ?> claims, <?= $e(Money::format((int) $event['amount_cents'])) ?></dd>
              <?php endif; ?>
            </dl>

            <?php if ($canBatches && $active && $row['can_ask']): ?>
              <form method="post" action="/sa-desk.php" style="margin-top:14px">
                <?= $csrf->field('approval.request') ?>
                <input type="hidden" name="action" value="approval.request">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <input type="hidden" name="batch" value="<?= $e((string) $batch['public_ref']) ?>">
                <p class="sa-label" style="margin:0 0 8px">Ask for approval</p>
                <label class="sa-field">
                  <span class="sa-fieldlabel">What the approver reads: what is being sent and to whom, at business level. Screened.</span>
                  <textarea class="sa-textarea" name="safe_summary" rows="3" maxlength="500" placeholder="First-level appeals for the timely-filing denials in this batch, to the commercial payer, citing the contract filing window."></textarea>
                </label>
                <div class="sa-desk-grid2" style="margin-top:10px">
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Claims in this submission</span>
                    <input class="sa-input" type="text" inputmode="numeric" name="claim_count" maxlength="6" value="<?= (int) $batch['claim_count'] ?>">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Denied dollars in this submission</span>
                    <input class="sa-input" type="text" inputmode="decimal" name="amount" maxlength="20" value="<?= $e(ltrim(Money::format((int) $batch['denied_amount_cents']), '$')) ?>">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Asked for by, optional. Seven days if blank.</span>
                    <input class="sa-input" type="date" name="due">
                  </label>
                </div>
                <button type="submit" class="sa-btn is-primary" style="margin-top:12px">Ask the approver</button>
              </form>
            <?php endif; ?>

            <?php if ($canBatches && $active && $approval !== null && (string) $approval['state'] === ApprovalState::PENDING): ?>
              <form method="post" action="/sa-desk.php" style="margin-top:14px">
                <?= $csrf->field('approval.cancel') ?>
                <input type="hidden" name="action" value="approval.cancel">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <input type="hidden" name="approval" value="<?= $e((string) $approval['public_ref']) ?>">
                <button type="submit" class="sa-btn is-quiet is-sm">Withdraw the approval request</button>
              </form>
            <?php endif; ?>

            <?php if ($canBatches && $active && $row['can_submit']): ?>
              <form method="post" action="/sa-desk.php" style="margin-top:14px">
                <?= $csrf->field('submission.record') ?>
                <input type="hidden" name="action" value="submission.record">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <input type="hidden" name="batch" value="<?= $e((string) $batch['public_ref']) ?>">
                <p class="sa-label" style="margin:0 0 8px">Record the submission</p>
                <div class="sa-desk-grid2">
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Claims sent</span>
                    <input class="sa-input" type="text" inputmode="numeric" name="claim_count" maxlength="6" value="<?= (int) $approval['claim_count'] ?>">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Denied dollars sent</span>
                    <input class="sa-input" type="text" inputmode="decimal" name="amount" maxlength="20" value="<?= $e(ltrim(Money::format((int) $approval['amount_cents']), '$')) ?>">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Sent on. Today if blank.</span>
                    <input class="sa-input" type="date" name="occurred">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Follow up with the payer on</span>
                    <input class="sa-input" type="date" name="follow_up">
                  </label>
                </div>
                <label class="sa-field" style="margin-top:10px">
                  <span class="sa-fieldlabel">Note, optional. Screened.</span>
                  <input class="sa-input" type="text" name="note" maxlength="500">
                </label>
                <button type="submit" class="sa-btn is-primary" style="margin-top:12px">Record as submitted</button>
              </form>
            <?php endif; ?>

            <?php if ($canBatches && $active && $row['can_respond']): ?>
              <form method="post" action="/sa-desk.php" style="margin-top:14px">
                <?= $csrf->field('payer.response') ?>
                <input type="hidden" name="action" value="payer.response">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <input type="hidden" name="batch" value="<?= $e((string) $batch['public_ref']) ?>">
                <p class="sa-label" style="margin:0 0 8px">Record what the payer did</p>
                <div class="sa-desk-grid2">
                  <label class="sa-field">
                    <span class="sa-fieldlabel">What happened</span>
                    <select class="sa-select" name="event_type">
                      <?php foreach (SubmissionEventType::responses() as $option): ?>
                        <option value="<?= $e($option) ?>"><?= $e(SubmissionEventType::staffLabel($option)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Claims it covers</span>
                    <input class="sa-input" type="text" inputmode="numeric" name="claim_count" maxlength="6" value="<?= $event === null ? (int) $batch['claim_count'] : (int) $event['claim_count'] ?>">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Dollars it covers</span>
                    <input class="sa-input" type="text" inputmode="decimal" name="amount" maxlength="20" value="<?= $e(ltrim(Money::format($event === null ? (int) $batch['denied_amount_cents'] : (int) $event['amount_cents']), '$')) ?>">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">On. Today if blank.</span>
                    <input class="sa-input" type="date" name="occurred">
                  </label>
                  <label class="sa-field">
                    <span class="sa-fieldlabel">Follow up on, optional</span>
                    <input class="sa-input" type="date" name="follow_up">
                  </label>
                </div>
                <label class="sa-field" style="margin-top:10px">
                  <span class="sa-fieldlabel">Note, optional. Screened.</span>
                  <input class="sa-input" type="text" name="note" maxlength="500">
                </label>
                <button type="submit" class="sa-btn is-sm" style="margin-top:12px">Record the response</button>
                <p class="sa-desk-note" style="margin-top:10px">A decision is not a reimbursement. Nothing recorded here creates a fee; the money phase verifies what actually arrives.</p>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <section aria-labelledby="desk-rc-approvals" id="desk-rc-approvals">
    <p class="sa-label" id="desk-rc-approvals-h">Approval requests</p>
    <?php if ($approvals === []): ?>
      <div class="sa-panel"><div class="sa-empty">Nothing has been put to the approver yet.</div></div>
    <?php else: ?>
      <div class="sa-panel"><div class="sa-tablewrap">
        <table class="sa-table">
          <thead><tr><th>Reference</th><th>Batch</th><th>Asked</th><th>State</th><th>Decided</th><th>Note</th></tr></thead>
          <tbody>
            <?php foreach ($approvals as $row): ?>
              <tr>
                <td class="sa-desk-mono"><?= $e((string) $row['public_ref']) ?></td>
                <td><?= $e((string) $row['batch_label']) ?> &middot; <?= (int) $row['claim_count'] ?> claims, <?= $e(Money::format((int) $row['amount_cents'])) ?></td>
                <td><?= $e(Desk::ago($clock, (string) $row['created_at'])) ?><?= $row['due_at'] === null ? '' : '<div class="sa-desk-mono">by ' . $e($clock->displayDate((string) $row['due_at'])) . '</div>' ?></td>
                <td><span class="sa-pill<?= (string) $row['state'] === ApprovalState::PENDING ? ' is-wait' : '' ?>"><?= $e(ApprovalState::staffLabel((string) $row['state'])) ?></span></td>
                <td><?= $row['decision_at'] === null ? '<span class="sa-desk-quiet">Not yet</span>' : $e($clock->displayDateTime((string) $row['decision_at'])) ?></td>
                <td><?= $row['decision_note'] === null ? '' : $e((string) $row['decision_note']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div></div>
    <?php endif; ?>
  </section>

  <section aria-labelledby="desk-rc-events" id="desk-rc-events">
    <p class="sa-label" id="desk-rc-events-h">Submissions and payer responses</p>
    <?php if ($submissions === []): ?>
      <div class="sa-panel"><div class="sa-empty">Nothing has gone to a payer yet.</div></div>
    <?php else: ?>
      <div class="sa-panel"><div class="sa-tablewrap">
        <table class="sa-table">
          <thead><tr><th>When</th><th>Batch</th><th>Event</th><th>Claims</th><th>Dollars</th><th>Follow-up</th></tr></thead>
          <tbody>
            <?php foreach ($submissions as $row): ?>
              <?php $followDays = $row['follow_up_due_at'] === null ? null : $clock->daysUntil((string) $row['follow_up_due_at']); ?>
              <tr>
                <td><?= $e($clock->displayDate((string) $row['occurred_at'])) ?></td>
                <td><?= $e((string) $row['batch_label']) ?> <span class="sa-desk-mono"><?= $e((string) $row['batch_ref']) ?></span></td>
                <td><?= $e(SubmissionEventType::staffLabel((string) $row['event_type'])) ?><?= $row['note'] === null ? '' : '<div class="sa-desk-quiet">' . $e((string) $row['note']) . '</div>' ?></td>
                <td><?= (int) $row['claim_count'] ?></td>
                <td><?= $e(Money::format((int) $row['amount_cents'])) ?></td>
                <td>
                  <?php if ($row['follow_up_due_at'] === null): ?>
                    <span class="sa-desk-quiet">None</span>
                  <?php elseif ($row['follow_up_done_at'] !== null): ?>
                    Done <?= $e($clock->displayDate((string) $row['follow_up_done_at'])) ?>
                  <?php else: ?>
                    <span class="<?= $e(Desk::deadlinePill($followDays, true)) ?>"><?= $e(Desk::deadlineWords($followDays, true)) ?></span>
                    <?php if ($canBatches): ?>
                      <form method="post" action="/sa-desk.php" style="margin-top:6px">
                        <?= $csrf->field('followup.done') ?>
                        <input type="hidden" name="action" value="followup.done">
                        <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                        <input type="hidden" name="event" value="<?= $e((string) $row['public_ref']) ?>">
                        <button type="submit" class="sa-btn is-quiet is-sm">Done</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div></div>
    <?php endif; ?>
  </section>

  <div class="sa-desk-grid2">
    <section aria-labelledby="desk-rc-fee">
      <p class="sa-label" id="desk-rc-fee">Recovery and fee</p>
      <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
        <dl class="sa-dl">
          <dt>Submitted to payers</dt><dd><?= $e((string) $feeBlock['submitted']) ?> &middot; <?= (int) $feeBlock['submitted_count'] ?> claims</dd>
          <dt>Overturned, per the payer</dt><dd><?= $e((string) $feeBlock['overturned']) ?> &middot; <?= (int) $feeBlock['overturned_count'] ?> claims</dd>
          <dt>Upheld</dt><dd><?= $e((string) $feeBlock['upheld']) ?> &middot; <?= (int) $feeBlock['upheld_count'] ?> claims</dd>
          <dt>Verified recovered reimbursement</dt><dd><?= $e((string) $feeBlock['verified']) ?></dd>
          <dt>Applicable fee rate</dt><dd><?= $e((string) $feeBlock['rate']) ?></dd>
          <dt>Calculated Soft Appeals fee</dt><dd><?= $e((string) $feeBlock['fee']) ?></dd>
          <dt>Invoice status</dt><dd><?= $e((string) $feeBlock['invoice']) ?></dd>
        </dl>
        <p class="sa-desk-note" style="margin-top:12px">
          A payer decision is not a reimbursement. The verified figure is written by
          the money phase, when the money has actually arrived at the practice and
          been checked, and the fee is calculated from that figure alone.
        </p>
      </div></div>
    </section>

    <section aria-labelledby="desk-rc-check">
      <p class="sa-label" id="desk-rc-check">
        Checklist &middot; <?= (int) $overview['checklist_progress']['done'] ?> of <?= (int) $overview['checklist_progress']['total'] ?>
      </p>
      <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
        <ul class="sa-desk-list" style="list-style:none;padding-left:0">
          <?php foreach ($overview['checklist'] as $item): ?>
            <li>
              <?= $item['completed_at'] !== null ? '&#10003;' : '&middot;' ?>
              <?= $e((string) $item['label']) ?>
              <?php if ($item['completed_at'] !== null): ?>
                <span class="sa-desk-quiet"><?= $e($clock->displayDate((string) $item['completed_at'])) ?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div></div>
    </section>
  </div>

  <section aria-labelledby="desk-rc-back">
    <p class="sa-label" id="desk-rc-back">Elsewhere</p>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
      <a class="sa-btn is-sm" href="/sa-desk.php?view=recovery">All recoveries</a>
      <a class="sa-btn is-sm" href="/sa-desk.php?view=assessments&amp;e=<?= $e(urlencode($engagementRef)) ?>">The assessment</a>
      <a class="sa-btn is-sm" href="/sa-desk.php?view=documents&amp;e=<?= $e(urlencode($engagementRef)) ?>">Agreements</a>
    </div></div>
  </section>

<?php endif; ?>
