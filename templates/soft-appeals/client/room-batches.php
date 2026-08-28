<?php
/**
 * Work batches, section 15.7. The card and nothing else.
 *
 * Permitted fields only: the opaque reference, the payer label if approved,
 * the aggregate count, the aggregate denied amount, the stage, the next
 * owner, the next safe action, and the deadline with its confirmed or
 * unconfirmed label. No card opens anything. Patient-level review happens in
 * the approved secure channel and the card says so.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var list<array<string,mixed>> $batchCards
 * @var array<string,mixed> $overview
 */

use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);
$totals = $overview['totals'];
?>

<section aria-labelledby="room-b-where">
  <p class="sa-label" id="room-b-where">Your work batches</p>
  <div class="sa-metrics">
    <div class="sa-metric">
      <p class="sa-metric-k">Batches</p>
      <p class="sa-metric-v"><?= (int) $totals['batches'] ?></p>
      <p class="sa-metric-c">Each one is a group of denials, counted, never listed.</p>
    </div>
    <div class="sa-metric">
      <p class="sa-metric-k">Denials received</p>
      <p class="sa-metric-v"><?= (int) $totals['received'] === 0 ? 'None yet' : (int) $totals['received'] ?></p>
      <p class="sa-metric-c">Across every batch.</p>
    </div>
    <div class="sa-metric">
      <p class="sa-metric-k">Recommended for action</p>
      <p class="sa-metric-v"><?= (int) $totals['recommended'] ?></p>
      <p class="sa-metric-c">Batches we recommend pursuing.</p>
    </div>
  </div>
</section>

<section aria-labelledby="room-b-list">
  <p class="sa-label" id="room-b-list">Each batch</p>
  <?php if ($batchCards === []): ?>
    <div class="sa-panel"><div class="sa-empty">
      No batch yet. The first one opens when your initial set arrives through the
      secure route and we confirm the count.
    </div></div>
  <?php else: ?>
    <?php foreach ($batchCards as $card): ?>
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
            <dt>Deadline</dt>
            <dd>
              <?php if ($card['deadline'] === null): ?>
                <span class="sa-room-quiet">No deadline recorded</span>
              <?php else: ?>
                <?= $e((string) $card['deadline']) ?>
                <span class="sa-pill<?= $card['confirmed'] ? '' : ' is-wait' ?>" style="margin-left:6px"><?= $e((string) $card['deadline_words']) ?></span>
              <?php endif; ?>
            </dd>
          </dl>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <p class="sa-room-note">
    Nothing here opens a claim. When a batch needs your eyes at patient level, the
    request tells you to go to the approved secure channel, and that is where it
    happens. An unconfirmed deadline is one we have not verified with the payer
    or the notice; it is shown so nothing is hidden, and it is labelled so nothing
    is assumed.
  </p>
</section>

<section aria-labelledby="room-b-back">
  <p class="sa-label" id="room-b-back">Elsewhere in your room</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <a class="sa-btn is-sm" href="/soft-appeals-room.php">Overview</a>
    <a class="sa-btn is-sm" href="/soft-appeals-room.php?section=assessment">Assessment</a>
    <a class="sa-btn is-sm" href="/soft-appeals-room.php?section=requests">Action requests</a>
  </div></div>
</section>
