<?php
/**
 * Bringing her old leads in. Section 21.2.
 *
 * This screen is the dry run. Loading it reads fs-metrics and writes nothing:
 * every count below, and every row in the table, comes from looking. The one
 * button that writes is at the bottom and it is a POST with its own CSRF token.
 *
 * The originals are never touched. Nothing here moves, renames or deletes a
 * single file under fs-metrics, before the import or after it, which is what
 * makes running it a decision she can take back by emptying a table rather than
 * a decision that eats the only copy.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var array<string,mixed> $report
 * @var string $importerPath
 * @var array<string,array{path:string,label:string,note:string}> $sources
 * @var array<string,array<string,mixed>> $reports
 * @var string $chosen
 */

use SoftAppeals\Domain\IntakeForms;
use SoftAppeals\Views\Desk;

$e = static fn (?string $value): string => Desk::e($value);
$rows = array_slice($report['candidates'], 0, 120);
$hidden = count($report['candidates']) - count($rows);
?>

<?php if (count($sources) > 1): ?>
<section aria-labelledby="desk-source">
  <p class="sa-label" id="desk-source">Which folder</p>
  <div class="sa-desk-cards">
    <?php foreach ($sources as $key => $source): ?>
      <?php $count = (int) $reports[$key]['source_total']; ?>
      <div class="sa-desk-card"<?= $key === $chosen ? '' : ' style="border-left-color:var(--sa-line-strong)"' ?>>
        <div class="sa-desk-card-t">
          <b><?= $e($source['label']) ?><?= $key === $chosen ? ' &middot; reading this one' : '' ?></b>
          <span><?= $count ?> <?= $e(Desk::plural($count, 'lead')) ?> found &middot; <?= $e($source['note']) ?></span>
          <span class="sa-desk-mono"><?= $e($source['path']) ?></span>
        </div>
        <?php if ($key !== $chosen): ?>
          <div class="sa-desk-card-a">
            <a class="sa-btn is-quiet is-sm" href="/sa-desk.php?view=import&amp;source=<?= $e(urlencode($key)) ?>">
              Read this one instead
            </a>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="sa-desk-note">
    Staging sits inside the live site, so from here the live site's lead folder
    is one directory up and readable. That is where every real lead has landed
    since the form went up, and it is the one to import. Nothing is written to
    it: the import opens those files and never touches them.
  </p>
</section>
<?php endif; ?>

<section aria-labelledby="desk-import">
  <p class="sa-label" id="desk-import">Old leads, in <?= $e($sources[$chosen]['label']) ?></p>

  <div class="sa-metrics">
    <div class="sa-metric">
      <p class="sa-metric-k">Archive files</p>
      <p class="sa-metric-v"><?= (int) $report['archive_files'] ?></p>
      <p class="sa-metric-c">Full submissions in fs-metrics/sa-leads</p>
    </div>
    <div class="sa-metric">
      <p class="sa-metric-k">Log lines</p>
      <p class="sa-metric-v"><?= (int) $report['log_lines'] ?></p>
      <p class="sa-metric-c"><?= (int) $report['log_unmatched'] ?> of them have no archive file left</p>
    </div>
    <div class="sa-metric is-lead">
      <p class="sa-metric-k">New</p>
      <p class="sa-metric-v"><?= (int) $report['new'] ?></p>
      <p class="sa-metric-c">Not in the database yet</p>
    </div>
    <div class="sa-metric">
      <p class="sa-metric-k">Already there</p>
      <p class="sa-metric-v"><?= (int) $report['duplicates'] ?></p>
      <p class="sa-metric-c">Same submission, recognised by its hash</p>
    </div>
    <div class="sa-metric<?= (int) $report['invalid'] > 0 ? ' is-urgent' : '' ?>">
      <p class="sa-metric-k">Unusable</p>
      <p class="sa-metric-v"><?= (int) $report['invalid'] ?></p>
      <p class="sa-metric-c">No name or no usable email address</p>
    </div>
  </div>
</section>

<?php foreach ($report['notes'] as $note): ?>
  <p class="sa-desk-note"><?= $e((string) $note) ?></p>
<?php endforeach; ?>

<?php if ($report['archive_unreadable'] !== []): ?>
  <section>
    <p class="sa-label">Could not be read as a lead</p>
    <div class="sa-panel"><div style="padding:14px 18px">
      <ul class="sa-desk-list">
        <?php foreach ($report['archive_unreadable'] as $name): ?>
          <li class="sa-desk-mono"><?= $e((string) $name) ?></li>
        <?php endforeach; ?>
      </ul>
    </div></div>
  </section>
<?php endif; ?>

<section>
  <p class="sa-label">What the import would do</p>
  <?php if ($rows === []): ?>
    <div class="sa-panel"><div class="sa-empty">
      Nothing found at <span class="sa-desk-mono"><?= $e($importerPath) ?></span>.
    </div></div>
  <?php else: ?>
    <div class="sa-panel"><div class="sa-tablewrap">
      <table class="sa-table">
        <thead><tr>
          <th>Verdict</th><th>Form</th><th>Organization</th><th>Contact</th>
          <th>Submitted</th><th>Source record</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <?php
            $verdict = (string) ($row['verdict'] ?? '');
            $answers = is_array($row['answers'] ?? null) ? $row['answers'] : [];
            $pill = match ($verdict) {
                'new'             => 'sa-pill is-action',
                'already imported' => 'sa-pill is-ok',
                default            => 'sa-pill is-urgent',
            };
          ?>
          <tr>
            <td><span class="<?= $e($pill) ?>"><?= $e($verdict) ?></span></td>
            <td><?= $e(IntakeForms::ownerLabel((string) $row['source'])) ?></td>
            <td class="sa-strong"><?= Desk::orNotAsked((string) ($answers['organization'] ?? '')) ?></td>
            <td>
              <?= Desk::orNotAsked((string) ($answers['name'] ?? '')) ?>
              <div class="sa-desk-mono"><?= $e((string) ($answers['email'] ?? '')) ?></div>
            </td>
            <td><?= $e($clock->displayDate((string) $row['submitted_at'])) ?></td>
            <td class="sa-desk-mono"><?= $e((string) $row['path']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
    <?php if ($hidden > 0): ?>
      <p class="sa-desk-note">
        <?= (int) $hidden ?> more source records are not listed here. The import
        handles all of them; only the table is capped.
      </p>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section>
  <p class="sa-label">Reconciliation</p>
  <div class="sa-panel"><div style="padding:14px 18px">
    <dl class="sa-dl">
      <dt>Source records</dt>
      <dd><?= (int) $report['source_total'] ?> found, of which <?= (int) $report['invalid'] ?> unusable</dd>
      <dt>Already imported</dt>
      <dd><?= (int) $report['already_imported'] ?> inquiries in the database carry a source record</dd>
      <dt>Reading from</dt>
      <dd class="sa-desk-mono"><?= $e($importerPath) ?></dd>
    </dl>
  </div></div>
</section>

<section>
  <p class="sa-label">Run it</p>
  <div class="sa-panel"><div style="padding:16px 18px">
    <p style="margin:0 0 14px">
      Reading <span class="sa-desk-mono"><?= $e($importerPath) ?></span>.
      Every imported lead lands as an inquiry at <strong>Received</strong>, waiting
      for a fit review like any new one. Nothing is emailed. Running this twice
      imports nothing twice: each record carries a hash of its own original, and
      the second run recognises it.
    </p>
    <form method="post" action="/sa-desk.php">
      <?= $csrf->field('leads.import') ?>
      <input type="hidden" name="action" value="leads.import">
      <input type="hidden" name="source" value="<?= $e($chosen) ?>">
      <button type="submit" class="sa-btn is-primary"<?= (int) $report['new'] === 0 ? ' disabled' : '' ?>>
        <?= (int) $report['new'] === 0
          ? 'Nothing new to import'
          : 'Import ' . (int) $report['new'] . ' ' . Desk::plural((int) $report['new'], 'lead') ?>
      </button>
    </form>
  </div></div>
</section>
