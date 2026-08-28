<?php
/**
 * The Recovery Room overview. Section 15.4 and 15.5.
 *
 * Four cards are asked for: engagement stage, aggregate claims received,
 * assessment progress, and how much is recommended for action. One of those
 * exists in Phase 3. The other three are printed as what they are, which is not
 * started, because a card showing "0 of 20 claims received" reads as a count and
 * a practice would take it for one.
 *
 * The timeline underneath is sa_status_events, which is written to be read by
 * the practice. It is not the audit trail: that one holds refusals and digests
 * of addresses, and it is hers alone.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var array<string,mixed> $engagement
 * @var list<array<string,mixed>> $timeline
 * @var list<array{label:string,value:string}> $chosen
 * @var string $nextOwner
 * @var string $nextAction
 * @var bool $preferencesOpen
 */

use SoftAppeals\Domain\Stage;
use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);
?>

<?php if ($preferencesOpen): ?>
<section aria-labelledby="room-needs">
  <p class="sa-label" id="room-needs">Needs you</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:16px 18px">
    <p style="margin:0 0 12px">
      Your onboarding preferences are not confirmed yet. Eight questions, and it
      is the step everything else waits on.
    </p>
    <a class="sa-btn is-primary" href="/soft-appeals-preferences.php">Confirm your preferences</a>
  </div></div>
</section>
<?php endif; ?>

<section aria-labelledby="room-where">
  <p class="sa-label" id="room-where">Where this is</p>
  <div class="sa-metrics">
    <div class="sa-metric">
      <p class="sa-metric-k">Stage</p>
      <p class="sa-metric-v"><?= $e(Stage::clientLabel((string) $engagement['stage'])) ?></p>
      <p class="sa-metric-c">Opened <?= $e($clock->displayDate((string) $engagement['opened_at'])) ?></p>
    </div>
    <div class="sa-metric">
      <p class="sa-metric-k">Waiting on</p>
      <p class="sa-metric-v"><?= $e($nextOwner) ?></p>
      <p class="sa-metric-c"><?= $e($nextAction) ?></p>
    </div>
    <div class="sa-metric">
      <p class="sa-metric-k">Denials received</p>
      <p class="sa-metric-v">None yet</p>
      <p class="sa-metric-c">Nothing at patient level moves until both agreements are signed.</p>
    </div>
    <div class="sa-metric">
      <p class="sa-metric-k">Assessment</p>
      <p class="sa-metric-v">Not started</p>
      <p class="sa-metric-c">It begins once the denials arrive.</p>
    </div>
  </div>
  <p class="sa-room-note" style="margin-top:14px">
    Your reference is <?= $e((string) $engagement['public_ref']) ?>. Quote it in any
    email and it finds this engagement straight away.
  </p>
</section>

<div class="sa-room-grid2">

  <section aria-labelledby="room-chose">
    <p class="sa-label" id="room-chose">What you chose</p>
    <div class="sa-panel">
      <?php if ($chosen === []): ?>
        <div class="sa-empty">Nothing confirmed yet.</div>
      <?php else: ?>
        <div class="sa-panel-b" style="padding:14px 18px">
          <dl class="sa-dl">
            <?php foreach ($chosen as $row): ?>
              <dt><?= $e($row['label']) ?></dt>
              <dd><?= $e($row['value']) ?></dd>
            <?php endforeach; ?>
          </dl>
          <p class="sa-note">
            Any of this can change. Write to hello@frimpomaasync.com and say which.
          </p>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section aria-labelledby="room-history">
    <p class="sa-label" id="room-history">Your history</p>
    <div class="sa-panel">
      <?php if ($timeline === []): ?>
        <div class="sa-empty">Nothing has happened on this engagement yet.</div>
      <?php else: ?>
        <div class="sa-panel-b" style="padding:14px 18px">
          <ul class="sa-room-list">
            <?php foreach ($timeline as $event): ?>
              <li>
                <?= $e((string) $event['public_label']) ?>
                <span class="sa-room-quiet"><?= $e($clock->displayDate((string) $event['created_at'])) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
  </section>

</div>

<section aria-labelledby="room-later">
  <p class="sa-label" id="room-later">Not here yet</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <p style="margin:0 0 10px">
      Your agreements, the work batches, anything waiting on your approval, and
      what has actually been recovered each appear in this room as they start.
      Nothing is kept from you: a section that is not listed has not begun.
    </p>
    <p class="sa-room-note">
      Nothing in this room ever holds patient, member or claim-level information.
      That is the boundary the whole arrangement is built on, and it does not
      change once the agreements are signed.
    </p>
  </div></div>
</section>
