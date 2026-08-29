<?php
/**
 * Signing back in, section 10.2.
 *
 * Two steps on one template, because they are two states of the same screen and
 * a person who mistypes an address should not lose their place.
 *
 *   Step one asks for the work email and says the same sentence whatever
 *   happens next. An address we do not know gets the identical page, because a
 *   sign-in form that answers "no such account" is a way to find out which
 *   practices she works with.
 *
 *   Step two takes the six digits. It never says whether the address was known,
 *   only whether the code matched.
 *
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var string $step        'email' or 'code'
 * @var string $email
 * @var ?string $notice
 *
 * A refusal is deliberately NOT printed here. The shell prints it once, above
 * the card, from the same value the page hands it. Printing it in both places
 * is how a person reads the same sentence twice and wonders which one is live.
 */

use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);
?>

<div class="sa-screen">
  <p class="sa-qnum">Soft Appeals</p>

  <?php if ($step === 'code'): ?>

    <h1 class="sa-q">Enter the six digits.</h1>
    <p class="sa-qhelp">
      If <?= $e($email) ?> is on this engagement, a code is on its way to it.
      It works once and it stops working ten minutes after it was sent.
    </p>

    <?php if ($notice !== null): ?>
      <p class="sa-note"><?= $e($notice) ?></p>
    <?php endif; ?>

    <form method="post" action="/soft-appeals-room.php">
      <?= $csrf->field('client.code.verify') ?>
      <input type="hidden" name="action" value="client.code.verify">
      <input type="hidden" name="email" value="<?= $e($email) ?>">

      <label class="sa-field">
        <span class="sa-fieldlabel">Sign-in code</span>
        <input class="sa-input sa-client-code" type="text" name="code"
               inputmode="numeric" pattern="[0-9]*" maxlength="6" required
               autocomplete="one-time-code" spellcheck="false">
      </label>

      <div class="sa-client-actions">
        <button type="submit" class="sa-btn is-primary">Sign in</button>
      </div>
    </form>

    <form method="post" action="/soft-appeals-room.php" style="margin-top:14px">
      <?= $csrf->field('client.code.request') ?>
      <input type="hidden" name="action" value="client.code.request">
      <input type="hidden" name="email" value="<?= $e($email) ?>">
      <button type="submit" class="sa-btn">Send another code</button>
    </form>

  <?php else: ?>

    <h1 class="sa-q">Sign in to your Recovery Room.</h1>
    <p class="sa-qhelp">
      There is no password. Give the work email this engagement runs on and a
      six-digit code comes to it.
    </p>

    <?php if ($notice !== null): ?>
      <p class="sa-note"><?= $e($notice) ?></p>
    <?php endif; ?>

    <form method="post" action="/soft-appeals-room.php">
      <?= $csrf->field('client.code.request') ?>
      <input type="hidden" name="action" value="client.code.request">

      <label class="sa-field">
        <span class="sa-fieldlabel">Work email</span>
        <input class="sa-input" type="email" name="email" required
               autocomplete="username" autocapitalize="none" autocorrect="off"
               spellcheck="false" value="<?= $e($email) ?>">
      </label>

      <div class="sa-client-actions">
        <button type="submit" class="sa-btn is-primary">Send me a code</button>
      </div>
    </form>

  <?php endif; ?>

  <p class="sa-note">
    Do not send patient, member, claim or clinical information by email at any point.
  </p>
</div>
