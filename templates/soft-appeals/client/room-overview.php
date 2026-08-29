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
 * @var list<array<string,mixed>> $documents
 * @var array<string,mixed>|null $signable
 * @var array<string,mixed> $overview
 */

use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);
?>

<?php if ($signable !== null): ?>
<section aria-labelledby="room-sign">
  <p class="sa-label" id="room-sign">Needs you</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:16px 18px">
    <p style="margin:0 0 12px">
      <b><?= $e(DocumentKind::label((string) $signable['kind'])) ?></b> is waiting for
      your signature. You can read all of it before you sign anything.
    </p>
    <a class="sa-btn is-primary" href="/soft-appeals-sign.php">Read it and sign</a>
  </div></div>
</section>
<?php endif; ?>

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
      <p class="sa-metric-v"><?= $overview['received'] === null ? 'None yet' : (int) $overview['received'] ?></p>
      <p class="sa-metric-c">
        <?php if ($overview['received'] === null): ?>
          Nothing at patient level moves until both agreements are signed and the route is open.
        <?php elseif ($overview['client_confirmed']): ?>
          Count confirmed by you.
        <?php elseif ($overview['receipt_request_open']): ?>
          Please confirm the count under Action requests.
        <?php else: ?>
          Recorded when the set arrived.
        <?php endif; ?>
      </p>
    </div>
    <div class="sa-metric">
      <p class="sa-metric-k">Assessment</p>
      <p class="sa-metric-v"><?= $e((string) $overview['progress']) ?></p>
      <p class="sa-metric-c">
        <?= $overview['recommended'] === null
          ? 'It begins once the denials arrive.'
          : (int) $overview['recommended'] . ' recommended for action' ?>
      </p>
    </div>
    <div class="sa-metric<?= (int) $overview['client_requests_open'] > 0 ? ' is-lead' : '' ?>">
      <p class="sa-metric-k">Waiting on you</p>
      <p class="sa-metric-v"><?= (int) $overview['client_requests_open'] === 0 ? 'Nothing' : (int) $overview['client_requests_open'] ?></p>
      <p class="sa-metric-c">
        <?php if ((int) $overview['client_requests_open'] > 0): ?>
          <a href="/soft-appeals-room.php?section=requests">See what is asked</a>
        <?php else: ?>
          Anything we need from you appears under Action requests.
        <?php endif; ?>
      </p>
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
            Any of this can change. Write to softappeals@frimpomaasync.com and say which.
          </p>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section aria-labelledby="room-checklist">
    <p class="sa-label" id="room-checklist">
      Checklist &middot; <?= (int) $overview['checklist_progress']['done'] ?> of <?= (int) $overview['checklist_progress']['total'] ?>
    </p>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
      <ul class="sa-room-list" style="list-style:none;padding-left:0">
        <?php foreach ($overview['checklist'] as $item): ?>
          <li>
            <?= $item['completed_at'] !== null ? '&#10003;' : '&middot;' ?>
            <?= $e((string) $item['label']) ?>
            <?php if ($item['completed_at'] !== null): ?>
              <span class="sa-room-quiet"><?= $e($clock->displayDate((string) $item['completed_at'])) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div></div>
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

<section aria-labelledby="room-agreements-h" id="room-agreements">
  <p class="sa-label" id="room-agreements-h">Your agreements</p>
  <div class="sa-panel">
    <?php if ($documents === []): ?>
      <div class="sa-empty">
        Nothing to sign yet. Your agreements appear here as they are issued, and
        they stay here afterwards.
      </div>
    <?php else: ?>
      <div class="sa-panel-b" style="padding:6px 18px 14px">
        <?php foreach ($documents as $document): ?>
          <?php
          // Worked out here rather than inside the markup, because the static
          // check counts `if ...:` a line at a time and a condition wrapped
          // over three lines reads to it as an endif with no if.
          $isSignable = (string) $document['status'] === DocumentStatus::SENT
              && $signable !== null
              && (string) $signable['id'] === (string) $document['id'];
          ?>
          <div class="sa-client-docrow">
            <div>
              <b><?= $e(DocumentKind::label((string) $document['kind'])) ?></b>
              <?php if ((int) $document['version'] > 1): ?>
                <span class="sa-room-quiet">version <?= (int) $document['version'] ?></span>
              <?php endif; ?>
              <br>
              <span class="sa-room-quiet">
                <?= $e((string) $document['public_ref']) ?>
                <?php if ($document['executed_at'] !== null): ?>
                  &middot; signed <?= $e($clock->displayDate((string) $document['executed_at'])) ?>
                <?php elseif ($document['sent_at'] !== null): ?>
                  &middot; sent <?= $e($clock->displayDate((string) $document['sent_at'])) ?>
                <?php endif; ?>
              </span>
              <?php if ((string) $document['status'] === DocumentStatus::VOID): ?>
                <br><span class="sa-room-quiet">Replaced by a later version.</span>
              <?php endif; ?>
            </div>
            <div>
              <span class="sa-pill"><?= $e(DocumentStatus::clientLabelFor((string) $document['kind'], (string) $document['status'])) ?></span>
              <?php if ($isSignable): ?>
                <a class="sa-btn is-primary" href="/soft-appeals-sign.php" style="margin-left:8px">Sign</a>
              <?php endif; ?>
              <?php if ($document['executed_at'] !== null): ?>
                <a class="sa-btn is-sm" style="margin-left:8px" target="_blank" rel="noopener"
                   href="/soft-appeals-room.php?document=<?= $e(urlencode((string) $document['public_ref'])) ?>">
                  Open your copy
                </a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <p class="sa-note" style="margin-top:12px">
          Signed copies stay in this room rather than being emailed to you, so
          your agreements are never sitting in an inbox. Ask for a paper copy at
          any time by writing to softappeals@frimpomaasync.com.
        </p>
      </div>
    <?php endif; ?>
  </div>
</section>

<section aria-labelledby="room-later">
  <p class="sa-label" id="room-later">Not here yet</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <p style="margin:0 0 10px">
      Messages and access each appear in this room as they start. Nothing is
      kept from you: a section that is marked "later" has not begun. Approvals
      and recovery are in the rail now, and fill in if you choose recovery.
    </p>
    <p class="sa-room-note">
      Nothing in this room ever holds patient, member or claim-level information.
      That is the boundary the whole arrangement is built on, and it does not
      change once the agreements are signed.
    </p>
  </div></div>
</section>
