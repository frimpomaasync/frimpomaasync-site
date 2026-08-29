<?php
/**
 * Launch. Section 25's order and section 26's decision register, read off
 * the live installation rather than remembered.
 *
 * Every row is a fact the application can check for itself: a flag, a
 * setting, a file, a job run. A row it cannot check says so and names the
 * person whose decision it is. Nothing here flips anything: the flags live
 * in the server config file, and the whole point of section 25 is that they
 * go on one at a time, by hand, after approval.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Config $config
 * @var list<array{step:int,title:string,state:string,detail:string,how:string}> $launchSteps
 * @var list<array{decision:string,state:string,detail:string}> $launchRegister
 * @var list<string> $blockers
 */

use SoftAppeals\Views\Desk;

$e = static fn (?string $value): string => Desk::e($value);
$pill = static fn (string $state): string => match ($state) {
    'done'    => 'sa-pill is-ok',
    'open'    => 'sa-pill is-action',
    'blocked' => 'sa-pill is-urgent',
    default   => 'sa-pill is-wait',
};
$word = static fn (string $state): string => match ($state) {
    'done'    => 'Done',
    'open'    => 'Next',
    'blocked' => 'Blocked',
    'manual'  => 'Your call',
    default   => 'Later',
};
$doneCount = count(array_filter($launchSteps, static fn (array $s): bool => $s['state'] === 'done'));
?>

<section aria-labelledby="desk-launch-state">
  <p class="sa-label" id="desk-launch-state">Where launch stands</p>
  <div class="sa-metrics">
    <div class="sa-metric">
      <p class="sa-metric-k">Environment</p>
      <p class="sa-metric-v"><?= $e($config->string('SA_APP_ENV')) ?></p>
      <p class="sa-metric-c"><?= $config->isProduction() ? 'This is the live installation.' : 'Not production. Flags here follow the "on unless production" rule.' ?></p>
    </div>
    <div class="sa-metric">
      <p class="sa-metric-k">Section 25 steps</p>
      <p class="sa-metric-v"><?= $doneCount ?> of <?= count($launchSteps) ?></p>
    </div>
    <div class="sa-metric<?= $blockers === [] ? '' : ' is-lead' ?>">
      <p class="sa-metric-k">Signing blockers</p>
      <p class="sa-metric-v"><?= count($blockers) ?></p>
      <p class="sa-metric-c">Section 14.5. Cleared in code, reviewed, deployed.</p>
    </div>
  </div>
</section>

<section aria-labelledby="desk-launch-steps">
  <p class="sa-label" id="desk-launch-steps">Section 25, in order. One flag at a time, after approval.</p>
  <div class="sa-panel"><div class="sa-tablewrap">
    <table class="sa-table">
      <thead><tr><th>Step</th><th>State</th><th>What the installation says</th><th>How</th></tr></thead>
      <tbody>
      <?php foreach ($launchSteps as $step): ?>
        <tr<?= $step['state'] === 'blocked' ? ' class="is-urgent"' : '' ?>>
          <td class="sa-strong"><?= (int) $step['step'] ?>. <?= $e($step['title']) ?></td>
          <td><span class="<?= $e($pill($step['state'])) ?>"><?= $e($word($step['state'])) ?></span></td>
          <td style="max-width:24rem"><?= $e($step['detail']) ?></td>
          <td style="max-width:24rem;font-size:13px;color:var(--sa-mute)"><?= $e($step['how']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <p class="sa-desk-note" style="margin-top:10px">
    Nothing on this screen changes a flag. Flags are edited in the private
    config file on the server, one at a time, and this screen reads the
    result. Rollback is the same list in reverse: disable every new flag,
    restore the prior code release, keep the database, and record it. The
    runbook in docs/ has the exact steps.
  </p>
</section>

<section aria-labelledby="desk-launch-register">
  <p class="sa-label" id="desk-launch-register">Section 26, the decision register</p>
  <div class="sa-panel"><div class="sa-tablewrap">
    <table class="sa-table">
      <thead><tr><th>Decision</th><th>State</th><th>Where it stands</th></tr></thead>
      <tbody>
      <?php foreach ($launchRegister as $row): ?>
        <tr>
          <td class="sa-strong"><?= $e($row['decision']) ?></td>
          <td><span class="<?= $e($pill($row['state'])) ?>"><?= $e($word($row['state'])) ?></span></td>
          <td style="max-width:32rem"><?= $e($row['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</section>

<?php if ($blockers !== []): ?>
<section aria-labelledby="desk-launch-blockers">
  <p class="sa-label" id="desk-launch-blockers">Still standing on production signing</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <ul class="sa-desk-list">
      <?php foreach ($blockers as $blocker): ?>
        <li><?= $e(ucfirst($blocker)) ?>.</li>
      <?php endforeach; ?>
    </ul>
  </div></div>
</section>
<?php endif; ?>
