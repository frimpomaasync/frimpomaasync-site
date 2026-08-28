<?php
/**
 * The signing screen. Section 14.3, in the order the plan lists it.
 *
 *   1 document identity and version
 *   2 signer identity
 *   3 the scrollable document
 *   4 electronic-record consent
 *   5 typed legal name
 *   6 title and organization
 *   7 the signature pad, if one is ever approved
 *   8 the UTC and local time
 *   9 the confirmation checkbox
 *   10 the Sign action
 *
 * Seven is not built. A drawn signature is an image, an image is a file, and a
 * file uploaded from a practice's browser is a new surface on the one page that
 * least needs one. The typed name is the legal act here, and a pad would add
 * decoration rather than evidence. If one is ever approved it arrives as
 * another field on this form and another key in the stored payload.
 *
 * Eight is printed, not ticked by a script. There is no JavaScript on any
 * client page, so the clock shown is the one the server had when the page was
 * built, and the stamp recorded is the one the server has when the form
 * arrives. Those are two different moments and the page says so rather than
 * pretending to a live clock.
 *
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var array<string,mixed> $document
 * @var string $kindLabel
 * @var ?string $body
 * @var bool $bodyIntact
 * @var string $contentSha
 * @var array<string,mixed>|null $signer
 * @var string $consentText
 * @var string $consentVersion
 * @var string $utcNow
 * @var string $localNow
 * @var string $organizationName
 * @var array{typed_name:string,typed_title:?string,typed_organization:?string,consent:bool,confirm:bool} $typed
 */

use SoftAppeals\Views\Client;

$e = static fn (?string $value): string => Client::e($value);
?>

<div class="sa-screen">

  <p class="sa-qnum">
    <?= $e((string) $document['public_ref']) ?> &middot; version <?= (int) $document['version'] ?>
  </p>
  <h1 class="sa-q"><?= $e($kindLabel) ?></h1>
  <p class="sa-qhelp">
    Read the whole thing first. Nothing is signed until you type your name at the
    bottom and press the button.
  </p>

  <?php if ($body === null || !$bodyIntact): ?>

    <div class="sa-client-block">
      <p class="sa-client-error">
        This document could not be opened safely, so it is not being shown and it
        cannot be signed. Nothing is wrong on your side. Write to
        softappeals@frimpomaasync.com and a fresh copy goes out the same day.
      </p>
    </div>

  <?php else: ?>

    <div class="sa-client-block">
      <p class="sa-fieldlabel">Signing as</p>
      <p style="margin:0">
        <b><?= $e($signer === null ? 'Not named' : (string) $signer['name']) ?></b>
        <?php if ($signer !== null && trim((string) ($signer['role_title'] ?? '')) !== ''): ?>
          <br><span class="sa-client-quiet"><?= $e((string) $signer['role_title']) ?></span>
        <?php endif; ?>
        <?php if ($signer !== null): ?>
          <br><span class="sa-client-quiet"><?= $e((string) $signer['work_email']) ?></span>
        <?php endif; ?>
      </p>
      <p class="sa-client-warn">
        This document names one signer and only that person can sign it. If
        somebody else should be, write to softappeals@frimpomaasync.com and it is
        reissued to them.
      </p>
    </div>

    <div class="sa-client-block">
      <p class="sa-fieldlabel">The document</p>
      <div class="sa-client-doc" tabindex="0" role="region"
           aria-label="The document you are being asked to sign"><?= $e($body) ?></div>
      <p class="sa-client-warn">
        Fingerprint <?= $e(substr($contentSha, 0, 16)) ?>. The same fingerprint is
        printed on your signed copy, which is how you can tell later that what you
        signed is what is on file.
      </p>
    </div>

    <form method="post" action="/soft-appeals-sign.php">
      <?= $csrf->field('document.sign') ?>
      <input type="hidden" name="action" value="document.sign">
      <input type="hidden" name="document" value="<?= $e((string) $document['public_ref']) ?>">
      <input type="hidden" name="document_sha256" value="<?= $e($contentSha) ?>">

      <fieldset class="sa-client-block" style="border:0;padding:0;margin-inline:0">
        <legend class="sa-qnum">Agreeing to sign electronically</legend>
        <p class="sa-qhelp"><?= $e($consentText) ?></p>
        <p class="sa-client-warn">Consent wording version <?= $e($consentVersion) ?>.</p>
        <div class="sa-choices">
          <label class="sa-choice">
            <input type="checkbox" name="consent" value="yes"
                   <?= $typed['consent'] ? 'checked' : '' ?>>
            <span class="sa-choice-t"><b>I agree to sign this document electronically.</b></span>
          </label>
        </div>
      </fieldset>

      <fieldset class="sa-client-block" style="border:0;padding:0;margin-inline:0">
        <legend class="sa-qnum">Your signature</legend>
        <p class="sa-qhelp">Typing your name here is your signature.</p>

        <label class="sa-field">
          <span class="sa-fieldlabel">Your full legal name</span>
          <input class="sa-input" type="text" name="typed_name" maxlength="160"
                 autocomplete="name" spellcheck="false"
                 value="<?= $e($typed['typed_name']) ?>"
                 placeholder="<?= $e($signer === null ? '' : (string) $signer['name']) ?>">
        </label>

        <label class="sa-field" style="margin-top:12px">
          <span class="sa-fieldlabel">Your title</span>
          <input class="sa-input" type="text" name="typed_title" maxlength="120"
                 value="<?= $e($typed['typed_title'] ?? ($signer === null ? '' : (string) ($signer['role_title'] ?? ''))) ?>">
        </label>

        <label class="sa-field" style="margin-top:12px">
          <span class="sa-fieldlabel">The organization you are signing for</span>
          <input class="sa-input" type="text" name="typed_organization" maxlength="200"
                 value="<?= $e($typed['typed_organization'] ?? $organizationName) ?>">
        </label>
      </fieldset>

      <div class="sa-client-block">
        <p class="sa-client-warn">
          This page was built at <?= $e($localNow) ?> in the business timezone,
          which is <?= $e($utcNow) ?> UTC. The time recorded against your
          signature is the moment this form reaches the server, in UTC.
        </p>
        <div class="sa-choices">
          <label class="sa-choice">
            <input type="checkbox" name="confirm" value="yes"
                   <?= $typed['confirm'] ? 'checked' : '' ?>>
            <span class="sa-choice-t"><b>I have read this document and I am signing it.</b></span>
          </label>
        </div>
      </div>

      <div class="sa-client-actions">
        <button type="submit" class="sa-btn is-primary">Sign this document</button>
        <a class="sa-btn" href="/soft-appeals-room.php">Not now</a>
      </div>
    </form>

  <?php endif; ?>

</div>
