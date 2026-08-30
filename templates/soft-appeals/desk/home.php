<?php
/**
 * Desk home. Section 12.4, in the order the plan puts it.
 *
 * Needs you, pipeline, deadlines, recent inquiries, active organizations,
 * and, since Phase 7, the recovery summary: verified reimbursement and the
 * fees calculated from it, which exist now.
 *
 * @var array<string,mixed> $recoverySummary
 * @var list<array<string,mixed>> $awaitingVerification
 * @var list<array<string,mixed>> $invoiceReady
 * @var list<array<string,mixed>> $closeoutOpen
 * @var \SoftAppeals\Support\Clock $clock
 * @var array<string,int> $pipeline
 * @var array<string,int> $intakeCounts
 * @var list<array<string,mixed>> $awaitingReview
 * @var list<array{intake:array<string,mixed>,drafts:array<string,array{subject:string,body:string}>}> $fitReplies
 * @var list<array<string,mixed>> $termsReady
 * @var list<array<string,mixed>> $activeEngagements
 * @var list<array<string,mixed>> $recentIntakes
 * @var list<array<string,mixed>> $deadlines
 * @var list<array<string,mixed>> $batchDeadlines
 * @var list<array<string,mixed>> $requestsForHer
 * @var list<array<string,mixed>> $assessmentsWaiting
 * @var list<array<string,mixed>> $awaitingCountersignature
 * @var list<array<string,mixed>> $recentTimeline
 * @var list<array<string,mixed>> $awaitingSubmission
 * @var list<array<string,mixed>> $followUps
 * @var list<array<string,mixed>> $recoveryWaiting
 * @var list<array<string,mixed>> $pendingApprovals
 * @var bool $canReview
 * @var bool $canSendTerms
 * @var list<array<string,mixed>> $attentionOpen
 * @var bool $canDismiss
 * @var ?string $jobsNote
 * @var \SoftAppeals\Security\Csrf $csrf
 */

use SoftAppeals\Domain\IntakeStatus;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Views\Desk;

$e = static fn (?string $value): string => Desk::e($value);
$cards = [];

foreach ($awaitingReview as $intake) {
    $cards[] = [
        'urgent' => (int) $intake['time_sensitive'] === 1,
        'title'  => 'Inquiry awaiting fit review',
        'line'   => $intake['organization_name'] . ' · ' . $intake['contact_name']
            . ' · ' . Desk::ago($clock, (string) $intake['submitted_at'])
            . ((int) $intake['time_sensitive'] === 1 ? ' · they flagged approaching deadlines' : ''),
        'action' => ['Review fit', '/sa-desk.php?view=inquiries#' . (string) $intake['public_ref']],
        'view'   => ['View', '/sa-desk.php?view=inquiries#' . (string) $intake['public_ref']],
        'allowed' => $canReview,
    ];
}

foreach ($termsReady as $engagement) {
    $name = (string) ($engagement['display_name'] ?? $engagement['legal_name']);
    $cards[] = [
        'urgent' => false,
        'title'  => 'Assessment terms ready to send',
        'line'   => $name . ' · nothing has been emailed yet',
        'action' => ['Read the terms', '/sa-desk.php?view=terms&e=' . urlencode((string) $engagement['public_ref'])],
        'view'   => ['View', '/sa-desk.php?view=terms&e=' . urlencode((string) $engagement['public_ref'])],
        'allowed' => $canSendTerms,
    ];
}

// Phase 4. Agreements signed by the practice and waiting on her.
foreach ($awaitingCountersignature ?? [] as $row) {
    $cards[] = [
        'urgent' => true,
        'title'  => \SoftAppeals\Domain\DocumentKind::label((string) $row['kind']) . ' waiting for your countersignature',
        'line'   => (string) ($row['display_name'] ?? $row['legal_name'])
            . ' · signed ' . Desk::ago($clock, (string) $row['client_signed_at']),
        'action' => ['Countersign', '/sa-desk.php?view=documents&e=' . urlencode((string) $row['engagement_ref'])],
        'view'   => ['View', '/sa-desk.php?view=documents&e=' . urlencode((string) $row['engagement_ref'])],
        'allowed' => true,
    ];
}

// Phase 5. The assessment milestones that are hers, and a practice's question.
foreach ($assessmentsWaiting ?? [] as $row) {
    $stage = (string) $row['stage'];
    $title = match ($stage) {
        Stage::SECURE_INTAKE_READY    => 'Aggregate intake receipt not confirmed',
        Stage::RECEIPT_CONFIRMED      => 'Assessment ready to start',
        Stage::ASSESSMENT_IN_PROGRESS => 'Assessment in progress',
        Stage::ASSESSMENT_QA          => 'Assessment in quality review',
        default                       => 'Assessment',
    };
    $cards[] = [
        'urgent' => $stage === Stage::ASSESSMENT_QA,
        'title'  => $title,
        'line'   => (string) ($row['display_name'] ?? $row['legal_name']) . ' · ' . Stage::nextAction($stage),
        'action' => ['Open', '/sa-desk.php?view=assessments&e=' . urlencode((string) $row['public_ref'])],
        'view'   => ['View', '/sa-desk.php?view=assessments&e=' . urlencode((string) $row['public_ref'])],
        'allowed' => true,
    ];
}
foreach ($requestsForHer ?? [] as $row) {
    $cards[] = [
        'urgent' => true,
        'title'  => \SoftAppeals\Domain\ActionRequestKind::title((string) $row['kind']),
        'line'   => (string) ($row['display_name'] ?? $row['legal_name']) . ' · ' . Desk::ago($clock, (string) $row['created_at']),
        'action' => ['Answer', '/sa-desk.php?view=assessments&e=' . urlencode((string) $row['engagement_ref']) . '#desk-as-requests'],
        'view'   => ['View', '/sa-desk.php?view=assessments&e=' . urlencode((string) $row['engagement_ref'])],
        'allowed' => true,
    ];
}

// Phase 6. Recovery: the two gates that are hers, approved batches she has
// not submitted, and follow-ups that have come due.
foreach ($recoveryWaiting ?? [] as $row) {
    $stage = (string) $row['stage'];
    $cards[] = [
        'urgent' => false,
        'title'  => $stage === Stage::RECOVERY_SCOPE_SELECTED
            ? 'Recovery chosen, scope and agreement next'
            : 'Recovery agreement executed, work ready to start',
        'line'   => (string) ($row['display_name'] ?? $row['legal_name']) . ' · ' . Stage::nextAction($stage),
        'action' => ['Open', '/sa-desk.php?view=recovery&e=' . urlencode((string) $row['public_ref'])],
        'view'   => ['View', '/sa-desk.php?view=recovery&e=' . urlencode((string) $row['public_ref'])],
        'allowed' => true,
    ];
}
foreach ($awaitingSubmission ?? [] as $row) {
    $cards[] = [
        'urgent' => true,
        'title'  => 'Approved by the practice, waiting on your submission',
        'line'   => (string) ($row['display_name'] ?? $row['legal_name']) . ' · batch ' . (string) $row['batch_label']
            . ' · approved ' . Desk::ago($clock, (string) $row['decision_at']),
        'action' => ['Record the submission', '/sa-desk.php?view=recovery&e=' . urlencode((string) $row['engagement_ref']) . '#desk-rc-board'],
        'view'   => ['View', '/sa-desk.php?view=recovery&e=' . urlencode((string) $row['engagement_ref'])],
        'allowed' => true,
    ];
}
foreach ($followUps ?? [] as $row) {
    $days = $clock->daysUntil((string) $row['follow_up_due_at']);
    if ($days === null || $days > 0) {
        continue;
    }
    $cards[] = [
        'urgent' => true,
        'title'  => 'Payer follow-up due',
        'line'   => (string) ($row['display_name'] ?? $row['legal_name']) . ' · batch ' . (string) $row['batch_label']
            . ' · ' . Desk::deadlineWords($days, true),
        'action' => ['Open', '/sa-desk.php?view=recovery&e=' . urlencode((string) $row['engagement_ref']) . '#desk-rc-events'],
        'view'   => ['View', '/sa-desk.php?view=recovery&e=' . urlencode((string) $row['engagement_ref'])],
        'allowed' => true,
    ];
}

// Phase 7. Money waiting on her, and closeouts in progress.
foreach ($awaitingVerification ?? [] as $row) {
    $cards[] = [
        'urgent' => false,
        'title'  => 'Overturned, waiting on payment verification',
        'line'   => (string) ($row['display_name'] ?? $row['legal_name']) . ' · batch ' . (string) $row['label']
            . ' · no fee until the money is verified',
        'action' => ['Verify what arrived', '/sa-desk.php?view=money&e=' . urlencode((string) $row['engagement_ref']) . '#desk-mo-verify'],
        'view'   => ['View', '/sa-desk.php?view=money&e=' . urlencode((string) $row['engagement_ref'])],
        'allowed' => true,
    ];
}
foreach ($invoiceReady ?? [] as $row) {
    $cards[] = [
        'urgent' => false,
        'title'  => 'Invoice-ready',
        'line'   => (string) ($row['display_name'] ?? $row['legal_name']) . ' · '
            . \SoftAppeals\Support\Money::format((int) $row['fee_net_cents']) . ' in fees not on an invoice',
        'action' => ['Create the invoice', '/sa-desk.php?view=money&e=' . urlencode((string) $row['engagement_ref']) . '#desk-mo-invoices'],
        'view'   => ['View', '/sa-desk.php?view=money&e=' . urlencode((string) $row['engagement_ref'])],
        'allowed' => true,
    ];
}
foreach ($closeoutOpen ?? [] as $row) {
    $stage = (string) $row['stage'];
    $cards[] = [
        'urgent' => false,
        'title'  => 'Closeout in progress',
        'line'   => (string) ($row['display_name'] ?? $row['legal_name']) . ' · ' . Stage::staffLabel($stage) . ' · ' . Stage::nextAction($stage),
        'action' => ['Open', '/sa-desk.php?view=closeout&e=' . urlencode((string) $row['public_ref'])],
        'view'   => ['View', '/sa-desk.php?view=closeout&e=' . urlencode((string) $row['public_ref'])],
        'allowed' => true,
    ];
}

// Phase 8. What the jobs surfaced: deadline groups at a threshold, favorable
// decisions waiting on payment, open access at closeout, overdue internal
// work, a failed job, a backup that did not verify. Section 17.2.
foreach ($attentionOpen ?? [] as $item) {
    $kind = (string) $item['kind'];
    $cards[] = [
        'urgent'  => (string) $item['severity'] === \SoftAppeals\Repositories\AttentionRepository::SEVERITY_URGENT,
        'title'   => \SoftAppeals\Repositories\AttentionRepository::kindLabel($kind),
        'line'    => (string) $item['label'] . ($item['detail'] === null ? '' : ' · ' . (string) $item['detail']),
        'action'  => ['Open', (string) ($item['link'] ?? '/sa-desk.php?view=jobs')],
        'view'    => ['Seen', '/sa-desk.php?view=jobs'],
        'allowed' => true,
        'dismiss' => ($canDismiss ?? false) ? (string) $item['id'] : null,
    ];
}

// Urgent first, then in the order they were added, which is oldest first
// inside each group because that is how the repositories return them.
usort($cards, static fn (array $a, array $b): int => ($b['urgent'] <=> $a['urgent']));
$shown = array_slice($cards, 0, 8);
$hidden = count($cards) - count($shown);
?>

<?php if (($jobsNote ?? null) !== null): ?>
  <p class="sa-desk-note" style="margin-bottom:12px">
    <?= $e((string) $jobsNote) ?>
    <a href="/sa-desk.php?view=jobs">Automation</a> has the detail and the button to run them now.
  </p>
<?php endif; ?>

<section aria-labelledby="desk-needs">
  <p class="sa-label" id="desk-needs">Needs you</p>
  <?php if ($shown === []): ?>
    <div class="sa-panel"><div class="sa-empty">
      Nothing is waiting on you. Every inquiry has been reviewed and no terms are sitting unsent.
    </div></div>
  <?php else: ?>
    <div class="sa-desk-cards">
      <?php foreach ($shown as $card): ?>
        <div class="sa-desk-card<?= $card['urgent'] ? ' is-urgent' : '' ?>">
          <div class="sa-desk-card-t">
            <b><?= $e($card['title']) ?></b>
            <span><?= $e($card['line']) ?></span>
          </div>
          <?php if ($card['allowed']): ?>
            <div class="sa-desk-card-a">
              <a class="sa-btn is-action is-sm" href="<?= $e($card['action'][1]) ?>"><?= $e($card['action'][0]) ?></a>
              <?php if (($card['dismiss'] ?? null) !== null): ?>
                <form method="post" action="/sa-desk.php">
                  <?= $csrf->field('attention.dismiss') ?>
                  <input type="hidden" name="action" value="attention.dismiss">
                  <input type="hidden" name="item" value="<?= $e((string) $card['dismiss']) ?>">
                  <input type="hidden" name="back" value="home">
                  <button type="submit" class="sa-btn is-quiet is-sm">Seen</button>
                </form>
              <?php else: ?>
                <a class="sa-btn is-quiet is-sm" href="<?= $e($card['view'][1]) ?>"><?= $e($card['view'][0]) ?></a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($hidden > 0): ?>
        <p class="sa-desk-note"><?= (int) $hidden ?> more waiting. The full queue is under Inquiries.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<?php if (($fitReplies ?? []) !== []): ?>
<section aria-labelledby="desk-replies">
  <p class="sa-label" id="desk-replies">Same-day replies, drafted for you</p>
  <p class="sa-desk-note" style="margin-bottom:12px">
    One per new inquiry, written from their own answers. Open the one that is
    true, change any word, press Send. Sending answers the person; it does not
    record the fit decision, which still happens under Inquiries.
  </p>
  <?php foreach ($fitReplies as $pair): ?>
    <?php $intake = $pair['intake']; ?>
    <div class="sa-panel" style="margin-bottom:14px">
      <div class="sa-panel-h">
        <span>
          <b><?= $e((string) $intake['organization_name']) ?></b>
          &middot; <?= $e((string) $intake['contact_name']) ?>
          &middot; <?= $e(\SoftAppeals\Domain\IntakeForms::ownerLabel((string) $intake['source'])) ?>
          &middot; <?= $e(Desk::ago($clock, (string) $intake['submitted_at'])) ?>
        </span>
        <a class="sa-btn is-quiet is-sm"
           href="/sa-desk.php?view=inquiries#<?= $e((string) $intake['public_ref']) ?>">The inquiry</a>
      </div>
      <div class="sa-panel-b" style="padding:6px 18px 14px">
        <?php foreach ($pair['drafts'] as $kind => $draft): ?>
          <details style="margin-top:8px">
            <summary style="cursor:pointer">
              <?= $e(\SoftAppeals\Services\FitReplyService::kindLabel((string) $kind)) ?>
            </summary>
            <form method="post" action="/sa-desk.php" style="margin-top:10px">
              <?= $csrf->field('intake.reply') ?>
              <input type="hidden" name="action" value="intake.reply">
              <input type="hidden" name="intake" value="<?= $e((string) $intake['id']) ?>">
              <input type="hidden" name="kind" value="<?= $e((string) $kind) ?>">
              <p style="margin:0 0 6px">
                <label>Subject
                  <input type="text" name="subject" value="<?= $e($draft['subject']) ?>"
                         maxlength="200" style="width:100%">
                </label>
              </p>
              <p style="margin:0 0 6px">
                <label>To <?= $e((string) $intake['contact_email']) ?>, as you wrote it
                  <textarea name="body" rows="12" style="width:100%"><?= $e($draft['body']) ?></textarea>
                </label>
              </p>
              <button type="submit" class="sa-btn is-action is-sm">Send this reply</button>
            </form>
          </details>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section aria-labelledby="desk-pipeline">
  <p class="sa-label" id="desk-pipeline">Pipeline</p>
  <div class="sa-metrics">
    <?php foreach (Stage::deskBuckets() as $key => $label): ?>
      <div class="sa-metric<?= $key === 'inquiry' && $pipeline[$key] > 0 ? ' is-lead' : '' ?>">
        <p class="sa-metric-k"><?= $e($label) ?></p>
        <p class="sa-metric-v"><?= (int) $pipeline[$key] ?></p>
        <?php if ($key === 'inquiry'): ?>
          <p class="sa-metric-c">Inquiries not yet accepted, plus engagements whose terms are not out.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section aria-labelledby="desk-deadlines">
  <p class="sa-label" id="desk-deadlines">Deadlines</p>
  <?php $batchDeadlines = $batchDeadlines ?? []; ?>
  <?php if ($deadlines === [] && $batchDeadlines === []): ?>
    <div class="sa-panel"><div class="sa-empty">
      No dated commitment on any engagement yet.
    </div></div>
    <p class="sa-desk-note">
      Payer and appeal deadlines are counted per work batch. Nothing here is
      calculated from an assumption: a date is either entered and confirmed, or
      it is shown as unconfirmed, and until a real one is entered this board
      stays empty rather than guessing at one.
    </p>
  <?php else: ?>
    <div class="sa-panel"><div class="sa-tablewrap">
      <table class="sa-table">
        <thead><tr>
          <th>Organization</th><th>What is due</th><th>When</th><th>Stage</th>
        </tr></thead>
        <tbody>
        <?php foreach ($batchDeadlines as $row): ?>
          <?php
            $due = (string) $row['earliest_deadline_at'];
            $days = $clock->daysUntil($due);
            $confirmed = (int) $row['deadline_confirmed'] === 1;
          ?>
          <tr>
            <td class="sa-strong"><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></td>
            <td>
              Batch <?= $e((string) $row['label']) ?>
              <div class="sa-desk-mono"><?= $e((string) $row['public_ref']) ?></div>
            </td>
            <td>
              <span class="<?= $e(Desk::deadlinePill($days, $confirmed)) ?>">
                <?= $e(Desk::deadlineWords($days, $confirmed)) ?>
              </span>
              <div class="sa-desk-mono"><?= $e($clock->displayDate($due)) ?></div>
            </td>
            <td>
              <?= $e(\SoftAppeals\Domain\BatchStage::staffLabel((string) $row['stage'])) ?>
              <div><a class="sa-btn is-quiet is-sm" href="/sa-desk.php?view=assessments&amp;e=<?= $e(urlencode((string) $row['engagement_ref'])) ?>">Open</a></div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($deadlines as $row): ?>
          <?php
            $due = (string) $row['client_decision_due_at'];
            $days = $clock->daysUntil($due);
            // Nothing in Phase 2 marks a date confirmed, so every one of these
            // is shown in the outlined warning state. That is the truth, and
            // the plan requires it rather than a colour that implies certainty.
            $confirmed = false;
          ?>
          <tr>
            <td class="sa-strong"><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></td>
            <td>Client decision on the assessment</td>
            <td>
              <span class="<?= $e(Desk::deadlinePill($days, $confirmed)) ?>">
                <?= $e(Desk::deadlineWords($days, $confirmed)) ?>
              </span>
              <div class="sa-desk-mono"><?= $e($clock->displayDate($due)) ?></div>
            </td>
            <td><?= $e(Stage::staffLabel((string) $row['stage'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  <?php endif; ?>
</section>

<section aria-labelledby="desk-recent">
  <p class="sa-label" id="desk-recent">Recent inquiries</p>
  <div class="sa-panel">
    <div class="sa-panel-h">
      <span>Newest first</span>
      <a class="sa-btn is-quiet is-sm" href="/sa-desk.php?view=inquiries">Open the queue</a>
    </div>
    <?php if ($recentIntakes === []): ?>
      <div class="sa-empty">
        No inquiry has been stored yet. If she has old leads on the server,
        <a href="/sa-desk.php?view=import">the importer</a> is where they come in.
      </div>
    <?php else: ?>
      <div class="sa-tablewrap">
        <table class="sa-table">
          <thead><tr>
            <th>Reference</th><th>Organization</th><th>Contact</th><th>Submitted</th>
            <th>Denials</th><th>Value</th><th>Time</th><th>Status</th><th>Next action</th>
          </tr></thead>
          <tbody>
          <?php foreach ($recentIntakes as $row): ?>
            <?php $status = (string) $row['status']; ?>
            <tr<?= (int) $row['time_sensitive'] === 1 ? ' class="is-urgent"' : '' ?>>
              <td class="sa-desk-mono"><?= $e((string) $row['public_ref']) ?></td>
              <td class="sa-strong"><?= $e((string) $row['organization_name']) ?></td>
              <td>
                <?= $e((string) $row['contact_name']) ?>
                <div class="sa-desk-mono"><?= $e((string) $row['contact_email']) ?></div>
              </td>
              <td title="<?= $e($clock->displayDateTime((string) $row['submitted_at'])) ?>">
                <?= $e(Desk::ago($clock, (string) $row['submitted_at'])) ?>
              </td>
              <td><?= Desk::orNotAsked(
                    $row['denial_volume_band'] === null ? null : (string) $row['denial_volume_band'],
                    $row['source'] === 'soft-appeals-start'
                  ) ?></td>
              <td><?= Desk::orNotAsked(
                    $row['denied_value_band'] === null ? null : (string) $row['denied_value_band'],
                    $row['source'] === 'soft-appeals-start'
                  ) ?></td>
              <td>
                <?php if ((int) $row['time_sensitive'] === 1): ?>
                  <span class="sa-pill is-urgent"><i></i>Deadlines</span>
                <?php else: ?>
                  <span class="sa-desk-quiet">Not flagged</span>
                <?php endif; ?>
              </td>
              <td><span class="sa-pill <?= $e(IntakeStatus::pill($status)) ?>"><?= $e(IntakeStatus::label($status)) ?></span></td>
              <td>
                <?php if (IntakeStatus::isOpen($status) && $canReview): ?>
                  <a class="sa-btn is-action is-sm"
                     href="/sa-desk.php?view=inquiries#<?= $e((string) $row['public_ref']) ?>">Review fit</a>
                <?php elseif ($status === IntakeStatus::ACCEPTED): ?>
                  <span class="sa-desk-quiet">Accepted</span>
                <?php else: ?>
                  <span class="sa-desk-quiet"><?= $e(IntakeStatus::label($status)) ?></span>
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

<section aria-labelledby="desk-orgs">
  <p class="sa-label" id="desk-orgs">Active organizations</p>
  <div class="sa-panel">
    <?php if ($activeEngagements === []): ?>
      <div class="sa-empty">No engagement is open. Accepting an inquiry opens one.</div>
    <?php else: ?>
      <div class="sa-tablewrap">
        <table class="sa-table">
          <thead><tr>
            <th>Organization</th><th>Stage</th><th>Next owner</th><th>Next action</th>
            <th>Next due</th><th>Last message</th><th>Recovery Room</th>
          </tr></thead>
          <tbody>
          <?php foreach ($activeEngagements as $row): ?>
            <?php
              $stage = (string) $row['stage'];
              $last = $row['last_communication'];
            ?>
            <tr>
              <td class="sa-strong">
                <?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?>
                <div class="sa-desk-mono"><?= $e((string) $row['public_ref']) ?></div>
              </td>
              <td><?= $e(Stage::staffLabel($stage)) ?></td>
              <td><?= $e(Stage::nextOwner($stage)) ?></td>
              <td><?= $e(Stage::nextAction($stage)) ?></td>
              <td>
                <?php if ($row['client_decision_due_at'] !== null): ?>
                  <?= $e($clock->displayDate((string) $row['client_decision_due_at'])) ?>
                  <div class="sa-desk-mono">Unconfirmed</div>
                <?php else: ?>
                  <span class="sa-desk-quiet">No date</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($last === null): ?>
                  <span class="sa-desk-quiet">Nothing sent</span>
                <?php else: ?>
                  <?= $e(Desk::ago($clock, (string) $last['created_at'])) ?>
                  <div class="sa-desk-mono"><?= $e(\SoftAppeals\Repositories\CommunicationRepository::stateLabel((string) $last['state'])) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if (Stage::phiGatePassed($stage)): ?>
                  <a class="sa-btn is-quiet is-sm" href="/sa-desk.php?view=assessments&amp;e=<?= $e(urlencode((string) $row['public_ref'])) ?>">Assessment</a>
                <?php else: ?>
                  <span class="sa-pill">Onboarding</span>
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

<?php if ($recentTimeline !== []): ?>
<section aria-labelledby="desk-timeline">
  <p class="sa-label" id="desk-timeline">What has happened, in the words a client would read</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <ul class="sa-desk-list">
      <?php foreach ($recentTimeline as $event): ?>
        <li>
          <strong><?= $e((string) ($event['display_name'] ?? $event['legal_name'])) ?></strong>
          &middot; <?= $e((string) $event['public_label']) ?>
          <span class="sa-desk-quiet"><?= $e(Desk::ago($clock, (string) $event['created_at'])) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div></div>
</section>
<?php endif; ?>

<?php if (($pendingApprovals ?? []) !== []): ?>
<section aria-labelledby="desk-approvals">
  <p class="sa-label" id="desk-approvals">With a practice for approval</p>
  <div class="sa-panel"><div class="sa-tablewrap">
    <table class="sa-table">
      <thead><tr><th>Organization</th><th>Batch</th><th>Asked</th><th>By</th></tr></thead>
      <tbody>
      <?php foreach ($pendingApprovals as $row): ?>
        <?php $days = $row['due_at'] === null ? null : $clock->daysUntil((string) $row['due_at']); ?>
        <tr>
          <td class="sa-strong"><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></td>
          <td><?= $e((string) $row['batch_label']) ?> <div class="sa-desk-mono"><?= $e((string) $row['public_ref']) ?></div></td>
          <td><?= $e(Desk::ago($clock, (string) $row['created_at'])) ?></td>
          <td>
            <?php if ($days === null): ?>
              <span class="sa-desk-quiet">No date</span>
            <?php else: ?>
              <span class="<?= $e(Desk::deadlinePill($days, true)) ?>"><?= $e(Desk::deadlineWords($days, true)) ?></span>
            <?php endif; ?>
            <div><a class="sa-btn is-quiet is-sm" href="/sa-desk.php?view=recovery&amp;e=<?= $e(urlencode((string) $row['engagement_ref'])) ?>">Open</a></div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</section>
<?php endif; ?>

<?php if (isset($recoverySummary)): ?>
<section aria-labelledby="desk-home-money">
  <p class="sa-label" id="desk-home-money">Recovery summary</p>
  <div class="sa-metrics">
    <div class="sa-metric"><p class="sa-metric-k">Denied dollars accepted</p><p class="sa-metric-v"><?= $e((string) $recoverySummary['denied_accepted']) ?></p></div>
    <div class="sa-metric"><p class="sa-metric-k">Submitted to payers</p><p class="sa-metric-v"><?= $e((string) $recoverySummary['submitted']) ?></p></div>
    <div class="sa-metric"><p class="sa-metric-k">Favorable, awaiting payment</p><p class="sa-metric-v"><?= $e((string) $recoverySummary['awaiting']) ?></p><p class="sa-metric-c">no fee yet</p></div>
    <div class="sa-metric"><p class="sa-metric-k">Verified reimbursement</p><p class="sa-metric-v"><?= $e((string) $recoverySummary['verified']) ?></p></div>
    <div class="sa-metric"><p class="sa-metric-k">Calculated fees</p><p class="sa-metric-v"><?= $e((string) $recoverySummary['fee']) ?></p></div>
    <div class="sa-metric"><p class="sa-metric-k">Invoiced</p><p class="sa-metric-v"><?= $e((string) $recoverySummary['invoiced']) ?></p></div>
    <div class="sa-metric"><p class="sa-metric-k">Paid</p><p class="sa-metric-v"><?= $e((string) $recoverySummary['paid']) ?></p></div>
    <div class="sa-metric"><p class="sa-metric-k">Adjusted or reversed</p><p class="sa-metric-v"><?= $e((string) $recoverySummary['taken_back']) ?></p></div>
  </div>
  <p class="sa-desk-note" style="margin-top:10px">
    The fee card is calculated only from verified qualifying reimbursement
    recorded under the agreement. A favorable decision alone never creates a
    fee. Per practice: <a href="/sa-desk.php?view=money">Money</a>.
  </p>
</section>
<?php endif; ?>
