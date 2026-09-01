<?php
/**
 * Automation. Section 17.2 on a screen: each job, its last run, what it
 * surfaced, the newest backup, today's digest, and the one line the host's
 * cron screen needs.
 *
 * "Job failures surface on The Desk" is a Phase 8 acceptance line and this
 * is where. A failed run is a red row here and an urgent card on Home.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Config $config
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var array<string,mixed> $jobHealth
 * @var list<array<string,mixed>> $jobRuns
 * @var list<array<string,mixed>> $attentionOpen
 * @var list<array<string,mixed>> $attentionResolved
 * @var array<string,mixed> $digestPreview
 * @var string $digestText
 * @var list<array<string,mixed>> $backupFiles
 * @var array<string,mixed> $backupCheck
 * @var string $cronCommand
 * @var bool $cronEnabled
 * @var list<string> $jobLog
 * @var bool $canRunJobs
 */

use SoftAppeals\Repositories\AttentionRepository;
use SoftAppeals\Repositories\JobRepository;
use SoftAppeals\Views\Desk;

$e = static fn (?string $value): string => Desk::e($value);
$outcomePill = static fn (?string $outcome): string => match ($outcome) {
    JobRepository::OUTCOME_OK      => 'sa-pill is-ok',
    JobRepository::OUTCOME_FAILED  => 'sa-pill is-urgent',
    JobRepository::OUTCOME_SKIPPED => 'sa-pill is-wait',
    default                        => 'sa-pill',
};
$severityPill = static fn (string $severity): string => match ($severity) {
    AttentionRepository::SEVERITY_URGENT => 'sa-pill is-urgent',
    AttentionRepository::SEVERITY_ACTION => 'sa-pill is-action',
    default                              => 'sa-pill',
};
$lastAny = $jobHealth['last_any'];
?>

<section aria-labelledby="desk-jobs-state">
  <p class="sa-label" id="desk-jobs-state">Where automation stands</p>
  <div class="sa-metrics">
    <div class="sa-metric<?= $cronEnabled ? '' : ' is-lead' ?>">
      <p class="sa-metric-k">Schedule</p>
      <p class="sa-metric-v"><?= $cronEnabled ? 'On' : 'Off' ?></p>
      <p class="sa-metric-c"><?= $cronEnabled
        ? 'The host\'s cron may run the jobs. Section 25 step 11.'
        : 'SA_DEADLINE_CRON_ENABLED is off. Only the button below runs them.' ?></p>
    </div>
    <div class="sa-metric<?= $jobHealth['stale_any'] ? ' is-lead' : '' ?>">
      <p class="sa-metric-k">Last run</p>
      <p class="sa-metric-v"><?= $lastAny === null ? 'Never' : $e(Desk::ago($clock, (string) $lastAny['finished_at'])) ?></p>
      <p class="sa-metric-c"><?= $jobHealth['stale_any'] ? 'At least one job has not succeeded in the last 26 hours.' : 'Every job has succeeded within the last 26 hours.' ?></p>
    </div>
    <div class="sa-metric<?= (int) $jobHealth['failures_7d'] > 0 ? ' is-lead' : '' ?>">
      <p class="sa-metric-k">Failures, 7 days</p>
      <p class="sa-metric-v"><?= (int) $jobHealth['failures_7d'] ?></p>
    </div>
    <div class="sa-metric<?= $backupCheck['ok'] ? '' : ' is-lead' ?>">
      <p class="sa-metric-k">Newest backup</p>
      <p class="sa-metric-v"><?= $backupCheck['ok'] ? 'Verified' : 'Not verified' ?></p>
      <p class="sa-metric-c"><?= $e(ucfirst((string) $backupCheck['reason'])) ?><?= $backupCheck['age_hours'] === null ? '' : ', ' . $e((string) $backupCheck['age_hours']) . 'h old' ?>.</p>
    </div>
    <div class="sa-metric">
      <p class="sa-metric-k">Open items</p>
      <p class="sa-metric-v"><?= count($attentionOpen) ?></p>
      <p class="sa-metric-c">What the jobs are holding up for you right now.</p>
    </div>
  </div>

  <?php if ($canRunJobs): ?>
    <div class="sa-desk-card" style="margin-top:12px">
      <div class="sa-desk-card-t">
        <b>Run the jobs now</b>
        <span>Every job, in order, under its lock. Safe to press twice: a second run finds its work done.<?= $cronEnabled ? '' : ' This works while the schedule is off.' ?></span>
      </div>
      <form method="post" action="/sa-desk.php" style="margin:0">
        <?= $csrf->field('jobs.run') ?>
        <input type="hidden" name="action" value="jobs.run">
        <button type="submit" class="sa-btn is-primary is-sm">Run every job</button>
      </form>
    </div>
  <?php endif; ?>
</section>

<section aria-labelledby="desk-jobs-list">
  <p class="sa-label" id="desk-jobs-list">The jobs</p>
  <div class="sa-panel"><div class="sa-tablewrap">
    <table class="sa-table">
      <thead><tr><th>Job</th><th>What it does</th><th>Last run</th><th>Result</th><?php if ($canRunJobs): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($jobHealth['jobs'] as $key => $job): ?>
        <?php $last = $job['last']; ?>
        <tr<?= $last !== null && (string) $last['outcome'] === JobRepository::OUTCOME_FAILED ? ' class="is-urgent"' : '' ?>>
          <td class="sa-strong"><?= $e((string) $job['label']) ?><div class="sa-desk-mono"><?= $e((string) $key) ?></div></td>
          <td style="max-width:28rem"><?= $e((string) $job['what']) ?></td>
          <td>
            <?php if ($last === null): ?>
              <span class="sa-desk-quiet">Never</span>
            <?php else: ?>
              <?= $e(Desk::ago($clock, (string) $last['finished_at'])) ?>
              <div class="sa-desk-mono"><?= $e($clock->displayDateTime((string) $last['finished_at'])) ?> · <?= $e((string) $last['trigger_by']) ?></div>
            <?php endif; ?>
            <?php if ($job['stale'] && $last !== null): ?><div class="sa-desk-mono">No success in 26h</div><?php endif; ?>
            <?php if ($job['locked']): ?><div class="sa-desk-mono">Running now</div><?php endif; ?>
          </td>
          <td>
            <?php if ($last !== null): ?>
              <span class="<?= $e($outcomePill((string) $last['outcome'])) ?>"><?= $e(ucfirst((string) $last['outcome'])) ?></span>
              <div class="sa-desk-mono"><?= $e((string) ($last['summary'] ?? '')) ?></div>
            <?php endif; ?>
          </td>
          <?php if ($canRunJobs): ?>
            <td>
              <form method="post" action="/sa-desk.php" style="margin:0">
                <?= $csrf->field('jobs.run') ?>
                <input type="hidden" name="action" value="jobs.run">
                <input type="hidden" name="job" value="<?= $e((string) $key) ?>">
                <button type="submit" class="sa-btn is-quiet is-sm">Run</button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</section>

<section aria-labelledby="desk-jobs-open">
  <p class="sa-label" id="desk-jobs-open">What the jobs are holding up</p>
  <?php if ($attentionOpen === []): ?>
    <div class="sa-panel"><div class="sa-empty">Nothing right now. Every deadline group is beyond 30 days, no favorable decision is waiting on payment, and no closeout has open access.</div></div>
  <?php else: ?>
    <div class="sa-panel"><div class="sa-tablewrap">
      <table class="sa-table">
        <thead><tr><th>Item</th><th>Kind</th><th>Since</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($attentionOpen as $item): ?>
          <tr<?= (string) $item['severity'] === AttentionRepository::SEVERITY_URGENT ? ' class="is-urgent"' : '' ?>>
            <td>
              <span class="<?= $e($severityPill((string) $item['severity'])) ?>"><?= $e(ucfirst((string) $item['severity'])) ?></span>
              <div class="sa-strong" style="margin-top:4px"><?= $e((string) $item['label']) ?></div>
              <?php if ($item['detail'] !== null): ?><div style="font-size:13px;color:var(--sa-mute)"><?= $e((string) $item['detail']) ?></div><?php endif; ?>
            </td>
            <td><?= $e(AttentionRepository::kindLabel((string) $item['kind'])) ?></td>
            <td><?= $e(Desk::ago($clock, (string) $item['first_seen_at'])) ?><div class="sa-desk-mono">last seen <?= $e(Desk::ago($clock, (string) $item['last_seen_at'])) ?></div></td>
            <td>
              <div class="sa-desk-card-a">
                <?php if ($item['link'] !== null): ?>
                  <a class="sa-btn is-action is-sm" href="<?= $e((string) $item['link']) ?>">Open</a>
                <?php endif; ?>
                <?php if ($canRunJobs): ?>
                  <form method="post" action="/sa-desk.php" style="margin:0">
                    <?= $csrf->field('attention.dismiss') ?>
                    <input type="hidden" name="action" value="attention.dismiss">
                    <input type="hidden" name="item" value="<?= $e((string) $item['id']) ?>">
                    <button type="submit" class="sa-btn is-quiet is-sm">Seen</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
    <p class="sa-desk-note" style="margin-top:10px">
      "Seen" hides the card and keeps the row. The job keeps checking; the card
      comes back only if the condition ends and then arises again.
    </p>
  <?php endif; ?>
</section>

<section aria-labelledby="desk-jobs-digest">
  <p class="sa-label" id="desk-jobs-digest">Today's digest, as it would be emailed</p>
  <div class="sa-desk-grid2">
    <div>
      <pre class="sa-desk-email"><?= $e($digestText) ?></pre>
    </div>
    <div class="sa-desk-note">
      Sent once a day to <?= $e($config->string('SA_OWNER_EMAIL')) ?> after
      <?= (int) $config->digestHour() ?>:00 in <?= $e($config->string('SA_BUSINESS_TIMEZONE')) ?>,
      by the "Morning digest" job. Counts only, by design: names, batches and
      figures stay on the Desk. Change the hour with SA_DIGEST_HOUR in the
      server config.
      <?php if ($digestPreview['quiet']): ?><br><br>Nothing needs you today, so today's would say so.<?php endif; ?>
    </div>
  </div>
</section>

<section aria-labelledby="desk-jobs-backups">
  <p class="sa-label" id="desk-jobs-backups">Backups</p>
  <div class="sa-desk-grid2">
    <div class="sa-panel">
      <?php if ($backupFiles === []): ?>
        <div class="sa-empty">No backup has been written yet. The daily job writes one; "Run every job" writes one now.</div>
      <?php else: ?>
        <div class="sa-tablewrap">
          <table class="sa-table">
            <thead><tr><th>File</th><th>Written</th><th>Size</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($backupFiles, 0, 10) as $file): ?>
              <tr>
                <td class="sa-desk-mono"><?= $e((string) $file['name']) ?></td>
                <td><?= $e($clock->displayDateTime((string) $file['modified_at'])) ?></td>
                <td><?= number_format((int) $file['bytes']) ?> bytes</td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <div class="sa-desk-note">
      Every sa_ table, every row, as one gzipped JSON file with its SHA-256
      beside it, in the private backup folder the web server denies. Kept
      <?= \SoftAppeals\Services\BackupService::KEEP_DAYS ?> days and never fewer than
      <?= \SoftAppeals\Services\BackupService::KEEP_AT_LEAST ?> files.
      The vault files (agreements, executed records, signatures, invoices) are
      not inside it: each is named by its hash on the row it belongs to, and
      the host's own file backup covers them.
      <br><br>
      The restore is proved on every CI run: a backup is written, put into a
      fresh database, and compared row for row. On the host it is one command
      line, in the runbook, and it refuses any database that is not empty.
    </div>
  </div>
</section>

<section aria-labelledby="desk-jobs-cron">
  <p class="sa-label" id="desk-jobs-cron">The host's cron line</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <p style="margin:0 0 8px">In hPanel, Advanced, Cron Jobs: once a day, at least 15 minutes after the configured digest hour, this exact command.</p>
    <pre class="sa-desk-email" style="margin:0"><?= $e($cronCommand) ?></pre>
    <p class="sa-desk-note" style="margin-top:10px">
      Every job is safe to run more often than daily; nothing sends twice and
      nothing is created twice. With the current <?= (int) $config->digestHour() ?>:00 digest hour,
      schedule this no earlier than <?= sprintf('%02d:15', min(23, (int) $config->digestHour())) ?>
      in <?= $e($config->string('SA_BUSINESS_TIMEZONE')) ?>. A run before that
      is recorded as <b>Skipped</b>, not successful, so the Desk cannot look
      healthy while the digest never sends. Until SA_DEADLINE_CRON_ENABLED is set to true
      in the server config, that line prints a refusal and exits, and this
      screen's button is the only thing that runs the jobs.
    </p>
  </div></div>
</section>

<?php if ($jobRuns !== []): ?>
<section aria-labelledby="desk-jobs-runs">
  <p class="sa-label" id="desk-jobs-runs">Recent runs</p>
  <div class="sa-panel"><div class="sa-tablewrap">
    <table class="sa-table">
      <thead><tr><th>When</th><th>Job</th><th>By</th><th>Result</th><th>Items</th><th>Summary</th></tr></thead>
      <tbody>
      <?php foreach ($jobRuns as $run): ?>
        <tr<?= (string) ($run['outcome'] ?? '') === JobRepository::OUTCOME_FAILED ? ' class="is-urgent"' : '' ?>>
          <td title="<?= $e($clock->displayDateTime((string) $run['started_at'])) ?>"><?= $e(Desk::ago($clock, (string) $run['started_at'])) ?><div class="sa-desk-mono"><?= $e($clock->displayDateTime((string) $run['started_at'])) ?></div></td>
          <td class="sa-desk-mono"><?= $e((string) $run['job_key']) ?></td>
          <td><?= $e((string) $run['trigger_by']) ?></td>
          <td><span class="<?= $e($outcomePill($run['outcome'] === null ? null : (string) $run['outcome'])) ?>"><?= $e($run['outcome'] === null ? 'Running' : ucfirst((string) $run['outcome'])) ?></span></td>
          <td><?= (int) $run['items'] ?></td>
          <td style="max-width:28rem"><?= $e((string) ($run['summary'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</section>
<?php endif; ?>

<?php if ($attentionResolved !== []): ?>
<section aria-labelledby="desk-jobs-resolved">
  <p class="sa-label" id="desk-jobs-resolved">Resolved in the last seven days</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <ul class="sa-desk-list">
      <?php foreach ($attentionResolved as $item): ?>
        <li><?= $e((string) $item['label']) ?> <span class="sa-desk-quiet">resolved <?= $e(Desk::ago($clock, (string) $item['resolved_at'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div></div>
</section>
<?php endif; ?>

<?php if ($jobLog !== []): ?>
<section aria-labelledby="desk-jobs-log">
  <p class="sa-label" id="desk-jobs-log">The health log, newest last</p>
  <pre class="sa-desk-email"><?= $e(implode("\n", $jobLog)) ?></pre>
  <p class="sa-desk-note" style="margin-top:8px">One line per run, tab-separated: time, trigger, job, outcome, count, summary. Counts and reasons only, never a name.</p>
</section>
<?php endif; ?>
