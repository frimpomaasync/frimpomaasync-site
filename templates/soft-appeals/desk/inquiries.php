<?php
/**
 * The inquiry queue, and the review drawer. Sections 12.4 and 12.5.
 *
 * One drawer per row, rendered by the server. The alternative is one drawer
 * filled in by JavaScript from a blob of data in the page, and that means every
 * answer a practice typed sits in the HTML as a string waiting to be written
 * back into the DOM. Server-rendered, every value goes through one escape on
 * the way out and there is nothing to write back. At the volume she works at,
 * a handful of extra markup blocks costs nothing.
 *
 * The drawer opens with data-sa-open, which assets/soft-appeals.js already
 * carries: scrim, focus move, Escape to close.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var list<array<string,mixed>> $inquiries
 * @var \SoftAppeals\Repositories\IntakeRepository $intakeRepository
 * @var \SoftAppeals\Repositories\EngagementRepository $engagementRepository
 * @var bool $canReview
 */

use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\FitDecision;
use SoftAppeals\Domain\IntakeForms;
use SoftAppeals\Domain\IntakeStatus;
use SoftAppeals\Views\Desk;

$e = static fn (?string $value): string => Desk::e($value);
?>

<section aria-labelledby="desk-queue">
  <p class="sa-label" id="desk-queue">Inquiries &middot; newest first</p>

  <?php if ($inquiries === []): ?>
    <div class="sa-panel"><div class="sa-empty">
      Nothing here yet. Her old leads live on the server in fs-metrics, and
      <a href="/sa-desk.php?view=import">the importer</a> brings them in.
    </div></div>
  <?php else: ?>
    <div class="sa-panel"><div class="sa-tablewrap">
      <table class="sa-table">
        <thead><tr>
          <th>Reference</th><th>Organization</th><th>Contact</th><th>Form</th>
          <th>Submitted</th><th>Denials</th><th>Value</th><th>Time</th>
          <th>Status</th><th>Next action</th>
        </tr></thead>
        <tbody>
        <?php foreach ($inquiries as $row): ?>
          <?php
            $ref = (string) $row['public_ref'];
            $status = (string) $row['status'];
            $source = (string) $row['source'];
            $asksVolume = $source === 'soft-appeals-start';
          ?>
          <tr id="<?= $e($ref) ?>"<?= (int) $row['time_sensitive'] === 1 ? ' class="is-urgent"' : '' ?>>
            <td class="sa-desk-mono"><?= $e($ref) ?></td>
            <td class="sa-strong"><?= $e((string) $row['organization_name']) ?></td>
            <td>
              <?= $e((string) $row['contact_name']) ?>
              <div class="sa-desk-mono"><?= $e((string) $row['contact_email']) ?></div>
            </td>
            <td><?= $e(IntakeForms::ownerLabel($source)) ?></td>
            <td title="<?= $e($clock->displayDateTime((string) $row['submitted_at'])) ?>">
              <?= $e(Desk::ago($clock, (string) $row['submitted_at'])) ?>
            </td>
            <td><?= Desk::orNotAsked(
                  $row['denial_volume_band'] === null ? null : (string) $row['denial_volume_band'],
                  $asksVolume
                ) ?></td>
            <td><?= Desk::orNotAsked(
                  $row['denied_value_band'] === null ? null : (string) $row['denied_value_band'],
                  $asksVolume
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
              <?php if ($canReview): ?>
                <button type="button" class="sa-btn is-action is-sm" data-sa-open="drawer-<?= $e($ref) ?>">
                  <?= IntakeStatus::isUnresolved($status) ? 'Review fit' : 'Open' ?>
                </button>
              <?php else: ?>
                <span class="sa-desk-quiet">Read only</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  <?php endif; ?>
</section>

<?php if ($canReview): ?>
  <?php foreach ($inquiries as $row): ?>
    <?php
      $ref = (string) $row['public_ref'];
      $status = (string) $row['status'];
      $source = (string) $row['source'];
      $answers = $intakeRepository->answers($row);
      $engagement = $engagementRepository->findByIntake((string) $row['id']);
      $settled = !IntakeStatus::isUnresolved($status);
    ?>
    <aside class="sa-drawer" id="drawer-<?= $e($ref) ?>" role="dialog" aria-modal="true"
           aria-labelledby="drawer-t-<?= $e($ref) ?>" aria-hidden="true" data-sa-drawer>
      <header class="sa-drawer-h">
        <div>
          <p class="sa-label"><?= $e($ref) ?> &middot; <?= $e(IntakeForms::ownerLabel($source)) ?></p>
          <h2 id="drawer-t-<?= $e($ref) ?>"><?= $e((string) $row['organization_name']) ?></h2>
        </div>
        <button type="button" class="sa-close" data-sa-close aria-label="Close">&times;</button>
      </header>

      <div class="sa-drawer-b">
        <dl class="sa-dl">
          <dt>Contact</dt>
          <dd><?= $e((string) $row['contact_name']) ?><br><?= $e((string) $row['contact_email']) ?></dd>
          <dt>Their role</dt>
          <dd><?= Desk::orNotAsked($row['contact_role'] === null ? null : (string) $row['contact_role']) ?></dd>
          <dt>State</dt>
          <dd><?= Desk::orNotAsked($row['state'] === null ? null : (string) $row['state']) ?></dd>
          <dt>Type</dt>
          <dd><?= Desk::orNotAsked($row['organization_type'] === null ? null : (string) $row['organization_type']) ?></dd>
          <dt>Submitted</dt>
          <dd><?= $e($clock->displayDateTime((string) $row['submitted_at'])) ?></dd>
          <dt>Status</dt>
          <dd><span class="sa-pill <?= $e(IntakeStatus::pill($status)) ?>"><?= $e(IntakeStatus::label($status)) ?></span></dd>
          <?php if ($row['legacy_record_path'] !== null): ?>
            <dt>Came from</dt>
            <dd class="sa-desk-mono"><?= $e((string) $row['legacy_record_path']) ?></dd>
          <?php endif; ?>
          <?php if ($engagement !== null): ?>
            <dt>Engagement</dt>
            <dd>
              <a href="/sa-desk.php?view=terms&amp;e=<?= $e(urlencode((string) $engagement['public_ref'])) ?>">
                <?= $e((string) $engagement['public_ref']) ?>
              </a>
            </dd>
          <?php endif; ?>
        </dl>

        <p class="sa-label" style="margin-top:18px">What they sent</p>
        <?php if ($answers === []): ?>
          <p class="sa-desk-note">
            Nothing beyond the header was stored for this one. That happens with
            a lead recovered from the log line rather than from a full archive
            file, and the file it would have had was pruned long ago.
          </p>
        <?php else: ?>
          <dl class="sa-dl">
            <?php foreach ($answers as $label => $answer): ?>
              <dt><?= $e((string) $label) ?></dt>
              <dd style="white-space:pre-wrap"><?= $e((string) $answer) ?></dd>
            <?php endforeach; ?>
          </dl>
        <?php endif; ?>

        <?php if ($row['fit_note'] !== null && trim((string) $row['fit_note']) !== ''): ?>
          <p class="sa-label" style="margin-top:18px">Her note from last time</p>
          <p class="sa-callout" style="white-space:pre-wrap"><?= $e((string) $row['fit_note']) ?></p>
        <?php endif; ?>

        <p class="sa-label" style="margin-top:18px">
          <?= $settled ? 'Review again' : 'Fit review' ?>
        </p>
        <p class="sa-desk-note">
          Nothing on this form emails anybody. Accepting opens the engagement and
          takes you to the terms preview, which is a separate button.
        </p>

        <form method="post" action="/sa-desk.php" class="sa-stack" style="display:grid;gap:14px;margin-top:14px">
          <?= $csrf->field('intake.review') ?>
          <input type="hidden" name="action" value="intake.review">
          <input type="hidden" name="intake" value="<?= $e((string) $row['id']) ?>">

          <label class="sa-field">
            <span>Fee basis, if you accept</span>
            <select class="sa-select" name="fee_basis">
              <?php
                $currentFee = $engagement === null
                    ? EngagementTerms::FEE_NOT_SET
                    : (string) $engagement['fee_basis'];
              ?>
              <?php foreach (EngagementTerms::feeBases() as $value => $label): ?>
                <option value="<?= $e($value) ?>"<?= $value === $currentFee ? ' selected' : '' ?>>
                  <?= $e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="sa-field">
            <span>Assessment window</span>
            <input class="sa-input" type="text" name="assessment_window" maxlength="60"
                   placeholder="within ten business days of the paperwork"
                   value="<?= $e($engagement === null || $engagement['assessment_window'] === null
                       ? '' : (string) $engagement['assessment_window']) ?>">
          </label>

          <label class="sa-field">
            <span>Secure route, when it comes to that</span>
            <select class="sa-select" name="secure_channel">
              <option value="">Not chosen yet</option>
              <?php
                $currentChannel = $engagement === null || $engagement['secure_channel_type'] === null
                    ? '' : (string) $engagement['secure_channel_type'];
              ?>
              <?php foreach (EngagementTerms::secureChannels() as $value => $label): ?>
                <option value="<?= $e($value) ?>"<?= $value === $currentChannel ? ' selected' : '' ?>>
                  <?= $e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="sa-field">
            <span>Internal note</span>
            <textarea class="sa-textarea" name="note" rows="4" maxlength="4000"
              placeholder="Why this is or is not a fit. Business level only, no patient or claim detail."></textarea>
          </label>

          <div class="sa-desk-card-a">
            <?php foreach (FitDecision::labels() as $value => $label): ?>
              <button type="submit" name="decision" value="<?= $e($value) ?>"
                      class="sa-btn <?= $value === FitDecision::ACCEPT ? 'is-action' : 'is-quiet' ?> is-sm"
                      title="<?= $e(FitDecision::notes()[$value]) ?>">
                <?= $e($label) ?>
              </button>
            <?php endforeach; ?>
          </div>
        </form>
      </div>

      <footer class="sa-drawer-f">
        <button type="button" class="sa-btn is-quiet is-sm" data-sa-close>Close without deciding</button>
      </footer>
    </aside>
  <?php endforeach; ?>
<?php endif; ?>
