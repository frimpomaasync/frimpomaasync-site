<?php
/**
 * The preferences page. Section 13.2, all eight questions, one screen.
 *
 * One screen rather than a wizard, on purpose. A wizard needs script or a round
 * trip per question, and it hides how long the thing is, which is the single
 * complaint people have about onboarding forms. Eight questions on one page,
 * three of them optional, is about four minutes and the person can see that
 * before they start.
 *
 * Every answer comes back on a failed submit. Nobody retypes seven correct
 * answers because the eighth was wrong.
 *
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var array<string,mixed> $engagement
 * @var array<string,string> $errors
 * @var array<string,mixed> $values
 * @var array<string,array{name:string,role:string,email:string}> $people
 * @var string $organization
 * @var string $expiresNote
 */

use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\PreferenceForm;
use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);

/** One question's error, or nothing. */
$errorFor = static function (string $key) use ($errors, $e): string {
    return isset($errors[$key])
        ? '<p class="sa-client-error" role="alert">' . $e($errors[$key]) . '</p>'
        : '';
};

$number = 0;
?>

<div class="sa-screen">

  <p class="sa-qnum">Onboarding &middot; <?= $e($organization) ?></p>
  <h1 class="sa-q">Confirm how you want this run.</h1>
  <p class="sa-qhelp">
    Eight questions. Three of them are optional and you can change any answer later.
    Nothing here asks about a patient or a claim, and nothing you write on this page
    should name one.
  </p>

  <?php if ($errors !== []): ?>
    <p class="sa-client-flash" role="alert">
      <?= count($errors) === 1 ? 'One answer needs another look.' : count($errors) . ' answers need another look.' ?>
      Everything else is kept.
    </p>
  <?php endif; ?>

  <form method="post" action="/soft-appeals-preferences.php" novalidate>
    <?= $csrf->field('preferences.confirm') ?>
    <input type="hidden" name="action" value="preferences.confirm">

    <?php // 1. Cadence. ?>
    <fieldset class="sa-client-block" style="border:0;padding:0;margin-inline:0">
      <legend class="sa-qnum">Question <?= ++$number ?></legend>
      <p class="sa-q">How often should we send you a routine update?</p>
      <p class="sa-qhelp">Anything urgent reaches you the day it happens, whatever you pick here.</p>
      <?= $errorFor('communication_cadence') ?>
      <div class="sa-choices">
        <?php foreach (PreferenceForm::cadenceChoices() as $key => $label): ?>
          <label class="sa-choice">
            <input type="radio" name="communication_cadence" value="<?= $e($key) ?>"
                   <?= ($values['communication_cadence'] ?? '') === $key ? 'checked' : '' ?>>
            <span class="sa-choice-t"><b><?= $e($label) ?></b></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <?php // 2, 3, 4. The three people. ?>
    <?php foreach (PreferenceForm::contactQuestions() as $key => $question): ?>
      <fieldset class="sa-client-block" style="border:0;padding:0;margin-inline:0">
        <legend class="sa-qnum">
          Question <?= ++$number ?><?= $question['required'] ? '' : ' &middot; optional' ?>
        </legend>
        <p class="sa-q"><?= $e($question['label']) ?></p>
        <p class="sa-qhelp"><?= $e($question['help']) ?></p>
        <?= $errorFor($key) ?>
        <div class="sa-client-people">
          <label class="sa-field">
            <span class="sa-fieldlabel">Name</span>
            <input class="sa-input" type="text" name="<?= $e($key) ?>_name"
                   maxlength="<?= PreferenceForm::NAME_MAX ?>"
                   autocomplete="off" spellcheck="false"
                   value="<?= $e($people[$key]['name'] ?? '') ?>">
          </label>
          <label class="sa-field">
            <span class="sa-fieldlabel">Role</span>
            <input class="sa-input" type="text" name="<?= $e($key) ?>_role"
                   maxlength="<?= PreferenceForm::ROLE_MAX ?>"
                   autocomplete="off" spellcheck="false"
                   value="<?= $e($people[$key]['role'] ?? '') ?>">
          </label>
          <label class="sa-field">
            <span class="sa-fieldlabel">Work email</span>
            <input class="sa-input" type="email" name="<?= $e($key) ?>_email"
                   maxlength="<?= PreferenceForm::EMAIL_MAX ?>"
                   autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false"
                   value="<?= $e($people[$key]['email'] ?? '') ?>">
          </label>
        </div>
      </fieldset>
    <?php endforeach; ?>

    <?php // 5. Secure channel. ?>
    <fieldset class="sa-client-block" style="border:0;padding:0;margin-inline:0">
      <legend class="sa-qnum">Question <?= ++$number ?></legend>
      <p class="sa-q">Which secure route should we look at for the claim material?</p>
      <p class="sa-qhelp">
        Nothing at patient level moves until the Business Associate Agreement is
        signed and this route is open. Picking one now only says where to start.
      </p>
      <?= $errorFor('secure_channel') ?>
      <div class="sa-choices">
        <?php foreach (PreferenceForm::channelChoices() as $key => $choice): ?>
          <label class="sa-choice">
            <input type="radio" name="secure_channel" value="<?= $e($key) ?>"
                   <?= ($values['secure_channel'] ?? '') === $key ? 'checked' : '' ?>>
            <span class="sa-choice-t">
              <b><?= $e($choice['label']) ?></b>
              <span><?= $e($choice['help']) ?></span>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <?php // 6. Billing partner. ?>
    <fieldset class="sa-client-block" style="border:0;padding:0;margin-inline:0">
      <legend class="sa-qnum">Question <?= ++$number ?></legend>
      <p class="sa-q">Is a billing company or revenue-cycle partner involved?</p>
      <p class="sa-qhelp">
        If there is one, they usually hold the denial history, and knowing that
        now saves a week later.
      </p>
      <?= $errorFor('billing_partner') ?>
      <div class="sa-choices">
        <?php foreach (PreferenceForm::billingPartners() as $key => $label): ?>
          <label class="sa-choice">
            <input type="radio" name="billing_partner" value="<?= $e($key) ?>"
                   <?= ($values['billing_partner'] ?? '') === $key ? 'checked' : '' ?>>
            <span class="sa-choice-t"><b><?= $e($label) ?></b></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <?php // 7, 8. Free text, each with the warning above it. ?>
    <?php foreach (PreferenceForm::freeTextQuestions() as $key => $question): ?>
      <div class="sa-client-block">
        <p class="sa-qnum">Question <?= ++$number ?> &middot; optional</p>
        <p class="sa-q"><?= $e($question['label']) ?></p>
        <p class="sa-qhelp"><?= $e($question['help']) ?></p>
        <p class="sa-client-warn"><?= $e(PreferenceForm::PHI_WARNING) ?></p>
        <?= $errorFor($key) ?>
        <label class="sa-field">
          <span class="sa-fieldlabel">Business level only, <?= PreferenceForm::FREE_TEXT_MAX ?> characters</span>
          <textarea class="sa-textarea" name="<?= $e($key) ?>" rows="4"
                    maxlength="<?= PreferenceForm::FREE_TEXT_MAX ?>"
          ><?= $e((string) ($values[$key] ?? '')) ?></textarea>
        </label>
      </div>
    <?php endforeach; ?>

    <div class="sa-client-actions">
      <button type="submit" class="sa-btn is-primary">Confirm onboarding preferences</button>
      <span class="sa-client-quiet"><?= $e($expiresNote) ?></span>
    </div>
  </form>

</div>

<div class="sa-screen" style="margin-top:clamp(16px,2.4vw,24px)">
  <p class="sa-qnum">What happens next</p>
  <ol style="margin:0;padding-left:18px;line-height:1.7;font-size:14px">
    <li>You confirm these preferences. Nothing is charged and nothing is signed yet.</li>
    <li>
      The Business Associate Agreement and the review authorization come to
      <?= $e($people['signer']['name'] ?? 'the person you named') ?> to read and sign.
    </li>
    <li>The secure route opens. Only then does anything at patient level move.</li>
    <li>
      Twenty recent denied claims are reviewed and you get the written assessment,
      whether or not you go further.
    </li>
  </ol>
  <p class="sa-note">
    The fee: <?= $e(EngagementTerms::feeSentence((string) $engagement['fee_basis'])) ?>
  </p>
</div>
