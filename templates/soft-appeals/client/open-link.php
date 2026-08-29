<?php
/**
 * The one click between an emailed link and the thing it opens.
 *
 * A one-time link used to be redeemed by the GET that carried it. Mail
 * systems follow links before a person does: a spam filter, a safe-links
 * scanner, a preview. Each of those is a GET, and each one burned the link
 * before the practice ever saw the page. Seen on her own phone 2026-08-29,
 * the first production terms email, landing in spam and arriving dead.
 *
 * So the GET now shows this screen and changes nothing. The button posts the
 * token, and the POST is what redeems it. A scanner does not press buttons.
 * The token still never lands in a stored URL: it goes from this form to the
 * server once, and the answer is a 303 to the bare path.
 *
 * @var string $headline
 * @var string $explanation
 * @var string $action    the page to post back to
 * @var string $token
 * @var string $button
 */

use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);
?>

<div class="sa-screen">
  <p class="sa-qnum">Soft Appeals</p>
  <h1 class="sa-q"><?= $e($headline) ?></h1>
  <p class="sa-qhelp"><?= $e($explanation) ?></p>

  <form method="post" action="<?= $e($action) ?>" class="sa-client-actions">
    <input type="hidden" name="t" value="<?= $e($token) ?>">
    <button type="submit" class="sa-btn is-primary"><?= $e($button) ?></button>
  </form>

  <p class="sa-note">
    This link works once. If it has already been used, sign in with your work
    email instead, or write to softappeals@frimpomaasync.com for a new one.
  </p>
</div>
