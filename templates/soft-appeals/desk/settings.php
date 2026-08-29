<?php
/**
 * Settings. Section 12.3 lists it last; Phase 5 opens it with the two names
 * that go on the face of every agreement.
 *
 * Why this exists: SA_LEGAL_ENTITY lives in the private config file on the
 * server, which on this host can only be edited through a file manager, and
 * it sat blank for a day while every staging document named a placeholder.
 * A value set here wins over the file. A blank here falls back to the file,
 * and a blank in both is still the refusal it has always been on production.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var \SoftAppeals\Repositories\SettingsRepository $settingsRepository
 * @var string $legalEntitySource desk, config or none
 * @var string $effectiveLegalEntity
 * @var string $effectiveTradeName
 * @var string $configLegalEntity
 */

use SoftAppeals\Repositories\SettingsRepository;
use SoftAppeals\Views\Desk;

$e = static fn (?string $value): string => Desk::e($value);

$legalRow = $settingsRepository->row(SettingsRepository::LEGAL_ENTITY);
$tradeRow = $settingsRepository->row(SettingsRepository::TRADE_NAME);
$legalValue = $settingsRepository->get(SettingsRepository::LEGAL_ENTITY) ?? '';
$tradeValue = $settingsRepository->get(SettingsRepository::TRADE_NAME) ?? '';

$sourceWords = match ($legalEntitySource) {
    'desk'   => 'Set here on the Desk.',
    'config' => 'Coming from SA_LEGAL_ENTITY in the server config file.',
    default  => 'Not set anywhere. Off production, documents carry the placeholder below. On production, generating refuses.',
};
?>

<section aria-labelledby="desk-set-now">
  <p class="sa-label" id="desk-set-now">On every agreement right now</p>
  <div class="sa-metrics">
    <div class="sa-metric<?= $legalEntitySource === 'none' ? ' is-lead' : '' ?>">
      <p class="sa-metric-k">Legal entity</p>
      <p class="sa-metric-v"><?= $e($effectiveLegalEntity === '' ? 'Not set' : $effectiveLegalEntity) ?></p>
      <p class="sa-metric-c"><?= $e($sourceWords) ?></p>
    </div>
    <div class="sa-metric">
      <p class="sa-metric-k">Trade name</p>
      <p class="sa-metric-v"><?= $e($effectiveTradeName) ?></p>
      <p class="sa-metric-c">"operating as" on the face of each document.</p>
    </div>
  </div>
</section>

<section aria-labelledby="desk-set-form">
  <p class="sa-label" id="desk-set-form">The two party names</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:16px 18px">
    <form method="post" action="/sa-desk.php" style="margin:0">
      <?= $csrf->field('settings.save') ?>
      <input type="hidden" name="action" value="settings.save">

      <label class="sa-field">
        <span class="sa-fieldlabel">Legal entity name, exactly as registered</span>
        <input class="sa-input" type="text" name="<?= $e(SettingsRepository::LEGAL_ENTITY) ?>" maxlength="200"
               value="<?= $e($legalValue) ?>"
               placeholder="<?= $e($configLegalEntity === '' ? 'The registered company name' : $configLegalEntity) ?>">
      </label>
      <p class="sa-note" style="margin:6px 0 14px">
        This is the party a practice signs with. Leave it blank to fall back to the
        server config. Nothing here is invented for you: an empty name refuses to
        generate on production rather than guessing.
      </p>

      <label class="sa-field">
        <span class="sa-fieldlabel">Trade name</span>
        <input class="sa-input" type="text" name="<?= $e(SettingsRepository::TRADE_NAME) ?>" maxlength="120"
               value="<?= $e($tradeValue) ?>" placeholder="Soft Appeals">
      </label>

      <button type="submit" class="sa-btn is-primary" style="margin-top:14px">Save</button>
    </form>
  </div></div>

  <?php if ($legalRow !== null || $tradeRow !== null): ?>
    <p class="sa-desk-note" style="margin-top:12px">
      <?php if ($legalRow !== null): ?>
        Legal entity last saved <?= $e($clock->displayDateTime((string) $legalRow['updated_at'])) ?>.
      <?php endif; ?>
      <?php if ($tradeRow !== null): ?>
        Trade name last saved <?= $e($clock->displayDateTime((string) $tradeRow['updated_at'])) ?>.
      <?php endif; ?>
    </p>
  <?php endif; ?>
</section>

<section aria-labelledby="desk-set-scope">
  <p class="sa-label" id="desk-set-scope">What this does and does not change</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <ul class="sa-desk-list">
      <li>Every agreement generated after you save carries the new names.</li>
      <li>Nothing already generated changes. A signed document is never rewritten; if a name on one is wrong, void it and generate a replacement from the Agreements screen.</li>
      <li>Production signing stays shut until the section 14.5 blockers are cleared in code. Setting the name here is one of those six, not all of them.</li>
      <li>Secrets never go here. The database password and the three application secrets stay in the server config file and nowhere else.</li>
    </ul>
  </div></div>
</section>
