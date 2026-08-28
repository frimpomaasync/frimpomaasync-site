<?php
/**
 * The dead end, said plainly.
 *
 * Three things land here and they are deliberately not distinguished from each
 * other in the wording of the first line: a link that was already used, a link
 * that expired, and a link that was never real. Section 10.3 makes them one
 * answer, because telling them apart is how somebody works out whether a guess
 * was close.
 *
 * What is different is the way out, and that is the part a practice needs. A
 * person holding a used link can sign in with their email. A person holding an
 * expired one needs a new link, and asking for it is one sentence.
 *
 * @var string $headline
 * @var string $explanation
 * @var bool $offerSignIn
 */

use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);
?>

<div class="sa-screen">
  <p class="sa-qnum">Soft Appeals</p>
  <h1 class="sa-q"><?= $e($headline) ?></h1>
  <p class="sa-qhelp"><?= $e($explanation) ?></p>

  <div class="sa-client-actions">
    <?php if ($offerSignIn): ?>
      <a class="sa-btn is-primary" href="/soft-appeals-room.php">Sign in with your work email</a>
    <?php endif; ?>
    <a class="sa-btn" href="/soft-appeals-contact">Ask for a new link</a>
  </div>

  <p class="sa-note">
    Write to hello@frimpomaasync.com and a new link goes out the same day.
    Do not send patient, member, claim or clinical information by email.
  </p>
</div>
