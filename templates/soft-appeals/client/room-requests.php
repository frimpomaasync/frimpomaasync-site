<?php
/**
 * Action requests, section 15.8. What is waiting on the practice, and what
 * they have already done.
 *
 * Each card carries the owner, the due date, the status, the safe
 * instructions, and one of two things: a portal action, or a pointer to the
 * approved secure channel. There is no third kind. The portal never takes a
 * file and never takes a patient.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var list<array<string,mixed>> $clientRequests
 * @var bool $canConfirmReceipt
 * @var bool $canDecide
 * @var array<string,mixed> $overview
 */

use SoftAppeals\Domain\ActionRequestKind;
use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);

$open = [];
$closed = [];
foreach ($clientRequests as $row) {
    if ((string) $row['status'] === ActionRequestKind::STATUS_OPEN) {
        $open[] = $row;
    } else {
        $closed[] = $row;
    }
}
?>

<section aria-labelledby="room-r-open">
  <p class="sa-label" id="room-r-open">Waiting on you</p>
  <?php if ($open === []): ?>
    <div class="sa-panel"><div class="sa-empty">Nothing is waiting on you right now.</div></div>
  <?php else: ?>
    <?php foreach ($open as $row): ?>
      <?php
      $kind = (string) $row['kind'];
      $portal = ActionRequestKind::portalAction($kind);
      $secure = ActionRequestKind::directsToSecureChannel($kind);
      ?>
      <div class="sa-panel" style="margin-bottom:14px">
        <div class="sa-panel-h">
          <b><?= $e(ActionRequestKind::title($kind)) ?></b>
          <span class="sa-pill is-action">
            <?= $row['due_at'] === null ? 'No date on it' : 'By ' . $e($clock->displayDate((string) $row['due_at'])) ?>
          </span>
        </div>
        <div class="sa-panel-b" style="padding:14px 18px">
          <p style="margin:0 0 8px"><?= $e(ActionRequestKind::instructions($kind)) ?></p>
          <?php if ($row['note'] !== null): ?>
            <p class="sa-room-note" style="margin:0 0 10px"><?= $e((string) $row['note']) ?></p>
          <?php endif; ?>

          <?php if ($portal === ActionRequestKind::ACTION_CONFIRM_RECEIPT): ?>
            <?php if ($canConfirmReceipt): ?>
              <form method="post" action="/soft-appeals-room.php" style="margin:0">
                <?= $csrf->field('client.confirm_receipt') ?>
                <input type="hidden" name="action" value="client.confirm_receipt">
                <p style="margin:0 0 10px">
                  We recorded <b><?= $overview['received'] === null ? 'the count' : (int) $overview['received'] ?></b>
                  denials received. If that matches what you sent, confirm it. If it does
                  not, write to softappeals@frimpomaasync.com with the number, and nothing else.
                </p>
                <button type="submit" class="sa-btn is-primary">That count is right</button>
              </form>
            <?php else: ?>
              <p class="sa-room-quiet" style="margin:0">Your organization admin, authorized signer or submission approver confirms this.</p>
            <?php endif; ?>
          <?php elseif ($portal === ActionRequestKind::ACTION_READ_ASSESSMENT): ?>
            <a class="sa-btn is-primary" href="/soft-appeals-room.php?section=assessment">Read the assessment</a>
          <?php elseif ($portal === ActionRequestKind::ACTION_DECIDE): ?>
            <a class="sa-btn is-primary" href="/soft-appeals-room.php?section=assessment">Go to the decision</a>
          <?php elseif ($portal === ActionRequestKind::ACTION_APPROVE): ?>
            <a class="sa-btn is-primary" href="/soft-appeals-room.php?section=approvals">Go to the approvals</a>
            <p class="sa-room-note" style="margin:10px 0 0">
              The materials are in the approved secure channel you chose. The
              approval itself is recorded here, and nothing goes to a payer without it.
            </p>
          <?php elseif ($secure): ?>
            <p class="sa-room-note" style="margin:0">
              This one happens in the approved secure channel you chose, not in this
              room. Nothing at patient level ever goes through here.
            </p>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php if ($closed !== []): ?>
<section aria-labelledby="room-r-done">
  <p class="sa-label" id="room-r-done">Done</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:6px 18px 10px">
    <?php foreach ($closed as $row): ?>
      <div class="sa-client-docrow">
        <div>
          <?= $e(ActionRequestKind::title((string) $row['kind'])) ?>
          <span class="sa-room-quiet"><?= $e($clock->displayDate((string) $row['completed_at'])) ?></span>
        </div>
        <span class="sa-pill"><?= $e(ActionRequestKind::statusLabel((string) $row['status'], true)) ?></span>
      </div>
    <?php endforeach; ?>
  </div></div>
</section>
<?php endif; ?>

<section aria-labelledby="room-r-back">
  <p class="sa-label" id="room-r-back">Elsewhere in your room</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <a class="sa-btn is-sm" href="/soft-appeals-room.php">Overview</a>
    <a class="sa-btn is-sm" href="/soft-appeals-room.php?section=assessment">Assessment</a>
    <a class="sa-btn is-sm" href="/soft-appeals-room.php?section=batches">Work batches</a>
  </div></div>
</section>
