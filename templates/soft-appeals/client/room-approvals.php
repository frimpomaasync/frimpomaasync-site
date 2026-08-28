<?php
/**
 * Approvals, section 6 Gate C, the practice's side.
 *
 * Each pending request shows what is being sent and to whom at business
 * level, the count, the dollars, and two buttons: approve, or return with a
 * note. The appeal materials themselves are in the approved secure route and
 * the card says so. Nothing on this screen opens a claim, and the note is
 * screened the same way every other free-text field in this room is.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var list<array<string,mixed>> $pendingApprovals
 * @var list<array<string,mixed>> $decidedApprovals
 * @var bool $canApprove
 * @var array<string,mixed>|null $scope
 */

use SoftAppeals\Domain\ApprovalState;
use SoftAppeals\Support\Money;
use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);
$approver = $scope === null ? null : $scope['approver'];
?>

<section aria-labelledby="room-ap-open">
  <p class="sa-label" id="room-ap-open">Waiting on you</p>
  <?php if ($pendingApprovals === []): ?>
    <div class="sa-panel"><div class="sa-empty">
      No submission is waiting for your approval. Each one appears here before it
      goes to a payer, and nothing goes without it.
    </div></div>
  <?php else: ?>
    <?php foreach ($pendingApprovals as $row): ?>
      <div class="sa-panel" style="margin-bottom:14px">
        <div class="sa-panel-h">
          <div>
            <b>Batch <?= $e((string) $row['batch_label']) ?></b>
            <span class="sa-room-quiet"><?= $e((string) $row['public_ref']) ?></span>
          </div>
          <span class="sa-pill is-action">
            <?= $row['due_at'] === null ? 'No date on it' : 'By ' . $e($clock->displayDate((string) $row['due_at'])) ?>
          </span>
        </div>
        <div class="sa-panel-b" style="padding:14px 18px">
          <p style="margin:0 0 10px"><?= $e((string) $row['safe_summary']) ?></p>
          <dl class="sa-dl">
            <dt>Claims</dt><dd><?= (int) $row['claim_count'] ?></dd>
            <dt>Denied amount</dt><dd><?= $e(Money::format((int) $row['amount_cents'])) ?></dd>
            <dt>Asked</dt><dd><?= $e($clock->displayDate((string) $row['created_at'])) ?></dd>
          </dl>
          <p class="sa-room-note" style="margin:10px 0">
            The appeal materials are in the approved secure route you chose, not in
            this room. Review them there first. Approving here is what lets them go
            to the payer.
          </p>

          <?php if ($canApprove): ?>
            <form method="post" action="/soft-appeals-room.php" style="margin:0">
              <?= $csrf->field('client.approval_decide') ?>
              <input type="hidden" name="action" value="client.approval_decide">
              <input type="hidden" name="approval" value="<?= $e((string) $row['public_ref']) ?>">
              <div class="sa-choices">
                <label class="sa-choice">
                  <input type="radio" name="decision" value="<?= $e(ApprovalState::APPROVED) ?>">
                  <span class="sa-choice-t"><b>Approve</b><br>Send it to the payer as described.</span>
                </label>
                <label class="sa-choice">
                  <input type="radio" name="decision" value="<?= $e(ApprovalState::RETURNED) ?>">
                  <span class="sa-choice-t"><b>Return with a note</b><br>Nothing is sent. We revise it and ask again.</span>
                </label>
              </div>
              <label class="sa-field" style="margin-top:12px">
                <span class="sa-fieldlabel">A note. Required if returning. No patient, member or claim detail here; use the secure route for that.</span>
                <textarea class="sa-textarea" name="note" rows="3" maxlength="500"></textarea>
              </label>
              <label class="sa-choice" style="margin-top:12px">
                <input type="checkbox" name="reviewed" value="yes">
                <span class="sa-choice-t"><b>If approving: I reviewed the materials in the secure route.</b></span>
              </label>
              <button type="submit" class="sa-btn is-primary" style="margin-top:14px">Record my decision</button>
            </form>
          <?php else: ?>
            <p class="sa-room-quiet" style="margin:0">
              The decision belongs to your organization admin or your named submission
              approver<?= $approver === null ? '' : ', ' . $e((string) $approver['name']) ?>.
              This sign-in can read it and decide nothing, which is by design.
            </p>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php if ($decidedApprovals !== []): ?>
<section aria-labelledby="room-ap-done">
  <p class="sa-label" id="room-ap-done">Decided</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:6px 18px 10px">
    <?php foreach ($decidedApprovals as $row): ?>
      <div class="sa-client-docrow">
        <div>
          Batch <?= $e((string) $row['batch_label']) ?>
          <span class="sa-room-quiet"><?= (int) $row['claim_count'] ?> claims, <?= $e(Money::format((int) $row['amount_cents'])) ?><?= $row['decision_at'] === null ? '' : ' &middot; ' . $e($clock->displayDate((string) $row['decision_at'])) ?></span>
          <?php if ($row['decision_note'] !== null): ?>
            <br><span class="sa-room-quiet">Your note: <?= $e((string) $row['decision_note']) ?></span>
          <?php endif; ?>
        </div>
        <span class="sa-pill"><?= $e(ApprovalState::clientLabel((string) $row['state'])) ?></span>
      </div>
    <?php endforeach; ?>
  </div></div>
</section>
<?php endif; ?>

<section aria-labelledby="room-ap-back">
  <p class="sa-label" id="room-ap-back">Elsewhere in your room</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <a class="sa-btn is-sm" href="/soft-appeals-room.php">Overview</a>
    <a class="sa-btn is-sm" href="/soft-appeals-room.php?section=recovery">Recovery</a>
    <a class="sa-btn is-sm" href="/soft-appeals-room.php?section=batches">Work batches</a>
  </div></div>
</section>
