<?php
/**
 * The success page, section 13.2.
 *
 * The plan gives the two sentences that must appear and they appear, word for
 * word: preferences confirmed, the next step is the Business Associate
 * Agreement and the complimentary-review authorization, do not send claim
 * information yet.
 *
 * What the practice chose is printed back underneath. A confirmation that does
 * not show what was confirmed is a page nobody trusts.
 *
 * @var array<string,mixed> $engagement
 * @var string $organization
 * @var list<array{label:string,value:string}> $chosen
 * @var bool $roomOpen
 */

use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);
?>

<div class="sa-screen">
  <p class="sa-qnum">Onboarding &middot; <?= $e($organization) ?></p>
  <h1 class="sa-q">Preferences confirmed.</h1>
  <p class="sa-qhelp">
    The next step is the Business Associate Agreement and the complimentary-review
    authorization. Do not send claim information yet.
  </p>

  <?php if ($chosen !== []): ?>
    <dl class="sa-dl" style="margin-top:clamp(20px,3vw,28px)">
      <?php foreach ($chosen as $row): ?>
        <dt><?= $e($row['label']) ?></dt>
        <dd><?= $e($row['value']) ?></dd>
      <?php endforeach; ?>
    </dl>
  <?php endif; ?>

  <p class="sa-note">
    A copy of this is on its way to the address the terms were sent to. Nothing is
    charged for the assessment, and you keep it whatever you decide afterwards.
  </p>

  <div class="sa-client-actions">
    <?php if ($roomOpen): ?>
      <a class="sa-btn is-primary" href="/soft-appeals-room.php">Open your Recovery Room</a>
      <span class="sa-client-quiet">Signing in again sends a code to your work email.</span>
    <?php else: ?>
      <a class="sa-btn" href="/soft-appeals">Back to Soft Appeals</a>
    <?php endif; ?>
  </div>
</div>
