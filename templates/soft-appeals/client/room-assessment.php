<?php
/**
 * The Assessment section of the Recovery Room, and the decision page.
 * Section 15.3 item 2, and section 22 Phase 5: "the client can choose
 * internal use, more information, recovery scope, or no further action".
 *
 * Everything on this screen is aggregate. The assessment text was screened
 * before it was stored, the counts are totals, and there is no field on this
 * page that could carry a patient: the decision is four radio buttons and a
 * note that is screened the same way.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var array<string,mixed> $engagement
 * @var array<string,mixed> $overview
 * @var string $stage
 * @var bool $canDecide
 * @var list<array<string,mixed>> $answered
 */

use SoftAppeals\Domain\ClientDecision;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);
$assessment = $overview['assessment'];
$decision = $overview['decision'];
$delivered = $overview['delivered'];
$decisionOpen = $stage === Stage::CLIENT_DECISION_PENDING;
?>

<section aria-labelledby="room-as-where">
  <p class="sa-label" id="room-as-where">Your assessment</p>
  <div class="sa-metrics">
    <div class="sa-metric">
      <p class="sa-metric-k">Status</p>
      <p class="sa-metric-v"><?= $e((string) $overview['progress']) ?></p>
      <p class="sa-metric-c">
        <?php if ($assessment !== null && $assessment['delivered_at'] !== null): ?>
          Delivered <?= $e($clock->displayDate((string) $assessment['delivered_at'])) ?>
        <?php elseif ($overview['received'] !== null): ?>
          <?= (int) $overview['received'] ?> denials received
        <?php else: ?>
          It begins once the initial set arrives through the secure route.
        <?php endif; ?>
      </p>
    </div>
    <div class="sa-metric">
      <p class="sa-metric-k">Recommended for action</p>
      <p class="sa-metric-v"><?= $overview['recommended'] === null ? 'Not yet' : (int) $overview['recommended'] ?></p>
      <p class="sa-metric-c"><?= $overview['recommended_amount'] === null ? 'Counted when the assessment is delivered.' : $e((string) $overview['recommended_amount']) . ' in denied claims' ?></p>
    </div>
    <div class="sa-metric<?= $decision !== null ? ' is-lead' : '' ?>">
      <p class="sa-metric-k">Your decision</p>
      <p class="sa-metric-v"><?= $decision === null ? ($decisionOpen ? 'Waiting on you' : 'Not yet') : $e(ClientDecision::label($decision)) ?></p>
      <p class="sa-metric-c">
        <?php if ($assessment !== null && $assessment['decision_at'] !== null): ?>
          Recorded <?= $e($clock->displayDate((string) $assessment['decision_at'])) ?>
        <?php elseif ($assessment !== null && $assessment['decision_due_at'] !== null): ?>
          We have asked for it by <?= $e($clock->displayDate((string) $assessment['decision_due_at'])) ?>
        <?php else: ?>
          No date on it.
        <?php endif; ?>
      </p>
    </div>
  </div>
</section>

<?php if (!$delivered): ?>
<section aria-labelledby="room-as-wait">
  <p class="sa-label" id="room-as-wait">What happens</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:16px 18px">
    <p style="margin:0 0 10px">
      Your denials go through the approved secure route, never through this room.
      We review them there, and what comes back here is the assessment: which
      denial types are recoverable, roughly how much is at stake, and what we
      recommend. Then one decision is yours.
    </p>
    <p class="sa-room-note">
      This room never holds patient, member or claim-level information. That is the
      boundary the whole arrangement is built on.
    </p>
  </div></div>
</section>
<?php else: ?>

<section aria-labelledby="room-as-text">
  <p class="sa-label" id="room-as-text">What we found, at aggregate level</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:16px 18px">
    <div class="sa-client-doc" tabindex="0"><?= $e((string) ($assessment['summary'] ?? '')) ?></div>
    <p class="sa-note" style="margin-top:12px">
      The claim-level detail behind this stays in the secure route you chose. Ask
      there for anything patient-specific; ask here for anything else.
    </p>
  </div></div>
</section>

<?php if ($answered !== []): ?>
<section aria-labelledby="room-as-answers">
  <p class="sa-label" id="room-as-answers">Your questions and our answers</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:6px 18px 14px">
    <?php foreach ($answered as $row): ?>
      <div class="sa-client-docrow" style="display:block">
        <p style="margin:8px 0 4px"><b>You asked:</b> <?= $e((string) ($row['note'] ?? '')) ?></p>
        <p style="margin:0"><b>We answered:</b> <?= $e((string) $row['response']) ?>
          <span class="sa-room-quiet"><?= $e($clock->displayDate((string) $row['completed_at'])) ?></span></p>
      </div>
    <?php endforeach; ?>
  </div></div>
</section>
<?php endif; ?>

<?php if ($decision !== null): ?>
<section aria-labelledby="room-as-done">
  <p class="sa-label" id="room-as-done">What you chose</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:16px 18px">
    <p style="margin:0 0 8px"><b><?= $e(ClientDecision::label($decision)) ?></b></p>
    <p style="margin:0"><?= $e(ClientDecision::explanation($decision)) ?></p>
    <?php if ($assessment['decision_note'] !== null): ?>
      <p class="sa-room-note" style="margin-top:12px">Your note: <?= $e((string) $assessment['decision_note']) ?></p>
    <?php endif; ?>
    <?php if ($stage === Stage::RECOVERY_SCOPE_SELECTED): ?>
      <p class="sa-note" style="margin-top:12px">
        Next is the Recovery Services Agreement. Nothing is submitted to any payer
        before you have signed it, and each submission after that is yours to approve.
      </p>
    <?php endif; ?>
  </div></div>
</section>

<?php elseif ($decisionOpen): ?>
<section aria-labelledby="room-as-decide">
  <p class="sa-label" id="room-as-decide">Your decision</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:16px 18px">
    <?php if (!$canDecide): ?>
      <p style="margin:0">
        The decision belongs to your organization admin or your authorized signer.
        This sign-in can read everything and decide nothing, which is by design.
      </p>
    <?php else: ?>
      <form method="post" action="/soft-appeals-room.php" style="margin:0">
        <?= $csrf->field('client.decide') ?>
        <input type="hidden" name="action" value="client.decide">
        <div class="sa-choices">
          <?php foreach (ClientDecision::all() as $option): ?>
            <label class="sa-choice">
              <input type="radio" name="decision" value="<?= $e($option) ?>">
              <span class="sa-choice-t">
                <b><?= $e(ClientDecision::label($option)) ?></b><br>
                <?= $e(ClientDecision::explanation($option)) ?>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
        <label class="sa-field" style="margin-top:14px">
          <span class="sa-fieldlabel">A note, or your question. No patient, member or claim detail here; use the secure route for that.</span>
          <textarea class="sa-textarea" name="note" rows="4" maxlength="500"></textarea>
        </label>
        <label class="sa-choice" style="margin-top:12px">
          <input type="checkbox" name="confirm_close" value="yes">
          <span class="sa-choice-t"><b>If I chose internal use or no further action, I understand this closes the engagement.</b></span>
        </label>
        <button type="submit" class="sa-btn is-primary" style="margin-top:14px">Record my decision</button>
      </form>
    <?php endif; ?>
  </div></div>
</section>
<?php endif; ?>

<?php endif; ?>

<section aria-labelledby="room-as-back">
  <p class="sa-label" id="room-as-back">Elsewhere in your room</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <a class="sa-btn is-sm" href="/soft-appeals-room.php">Overview</a>
    <a class="sa-btn is-sm" href="/soft-appeals-room.php?section=batches">Work batches</a>
    <a class="sa-btn is-sm" href="/soft-appeals-room.php?section=requests">Action requests</a>
  </div></div>
</section>
