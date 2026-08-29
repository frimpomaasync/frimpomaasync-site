<?php
/**
 * The terms preview. Section 12.6.
 *
 * The rule this screen exists to enforce: preparing the terms does not send
 * them. Everything below is built and shown, and nothing has left the building.
 * The one button that sends is at the bottom, it says what it does, and it
 * carries its own CSRF token so a token minted anywhere else cannot trigger it.
 *
 * Resend rotates the link. The previous unused invitation is revoked in the
 * same transaction as the new one is minted, so a forwarded old email stops
 * working the moment she sends again, and both sends stay on the record.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var array<string,mixed>|null $engagement
 * @var array<string,mixed>|null $preview
 * @var list<array<string,mixed>> $communications
 * @var list<array<string,mixed>> $awaitingTerms
 * @var ?string $stagingLink  the just-minted one-time link, off production only
 * @var list<array{label:string,value:string}> $preferencesSummary
 * @var array<string,mixed>|null $preferencesRow
 * @var bool $canSendTerms
 */

use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\CommunicationRepository;
use SoftAppeals\Views\Desk;

$e = static fn (?string $value): string => Desk::e($value);
?>

<?php if ($preview === null): ?>
  <section aria-labelledby="desk-terms-list">
    <p class="sa-label" id="desk-terms-list">Terms waiting to go out</p>
    <?php if ($awaitingTerms === []): ?>
      <div class="sa-panel"><div class="sa-empty">
        Nothing is waiting. Accepting an inquiry is what puts an engagement here.
      </div></div>
    <?php else: ?>
      <div class="sa-desk-cards">
        <?php foreach ($awaitingTerms as $row): ?>
          <div class="sa-desk-card">
            <div class="sa-desk-card-t">
              <b><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></b>
              <span><?= $e((string) $row['public_ref']) ?>
                &middot; <?= $e(EngagementTerms::feeLabel((string) $row['fee_basis'])) ?></span>
            </div>
            <div class="sa-desk-card-a">
              <a class="sa-btn is-action is-sm"
                 href="/sa-desk.php?view=terms&amp;e=<?= $e(urlencode((string) $row['public_ref'])) ?>">
                Read the terms
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
<?php else: ?>

  <section aria-labelledby="desk-terms">
    <p class="sa-label" id="desk-terms">
      Assessment onboarding terms &middot; <?= $e((string) $preview['engagement_ref']) ?>
    </p>

    <div class="sa-panel">
      <div class="sa-panel-h">
        <span><?= $e((string) $preview['organization']) ?></span>
        <span class="sa-pill <?= (string) $preview['stage'] === Stage::TERMS_SENT ? 'is-ok' : 'is-action' ?>">
          <?= $e(Stage::staffLabel((string) $preview['stage'])) ?>
        </span>
      </div>
      <div style="padding:16px 18px">
        <dl class="sa-dl">
          <dt>Goes to</dt>
          <dd>
            <?= $e((string) $preview['recipient_name']) ?><br>
            <span class="sa-desk-mono"><?= $e((string) $preview['recipient_email']) ?></span>
          </dd>
          <dt>Subject</dt>
          <dd><?= $e((string) $preview['subject']) ?></dd>
          <dt>Fee basis</dt>
          <dd><?= $e((string) $preview['fee_label']) ?></dd>
          <dt>Assessment window</dt>
          <dd><?= Desk::orNotAsked(
                $preview['window'] === null ? null : (string) $preview['window']
              ) ?></dd>
          <dt>Link expires</dt>
          <dd><?= $e((string) $preview['expires_at_display']) ?>, fourteen days from the moment you send</dd>
          <dt>Sent before</dt>
          <dd>
            <?= (int) $preview['sent_before'] === 0
              ? 'Never'
              : (int) $preview['sent_before'] . ' ' . Desk::plural((int) $preview['sent_before'], 'time') ?>
          </dd>
        </dl>
      </div>
    </div>
  </section>

  <section class="sa-desk-grid2">
    <div class="sa-panel">
      <div class="sa-panel-h"><span>What they get</span></div>
      <div style="padding:14px 18px">
        <ul class="sa-desk-list">
          <?php foreach ($preview['scope'] as $line): ?>
            <li><?= $e((string) $line) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="sa-panel">
      <div class="sa-panel-h"><span>What this is not</span></div>
      <div style="padding:14px 18px">
        <ul class="sa-desk-list">
          <?php foreach ($preview['not_included'] as $line): ?>
            <li><?= $e((string) $line) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </section>

  <section>
    <p class="sa-label">Money</p>
    <div class="sa-panel"><div style="padding:14px 18px">
      <p style="margin:0 0 10px"><?= $e((string) $preview['fee_sentence']) ?></p>
      <p style="margin:0"><?= $e((string) $preview['no_payment']) ?></p>
    </div></div>
  </section>

  <section>
    <p class="sa-label">The email, exactly as it will arrive</p>
    <pre class="sa-desk-email"><?= $e((string) $preview['body']) ?></pre>
  </section>

  <?php if ($communications !== []): ?>
    <section>
      <p class="sa-label">Already sent</p>
      <div class="sa-panel"><div class="sa-tablewrap">
        <table class="sa-table">
          <thead><tr><th>When</th><th>To</th><th>Template</th><th>State</th></tr></thead>
          <tbody>
          <?php foreach ($communications as $row): ?>
            <tr>
              <td><?= $e($clock->displayDateTime((string) $row['created_at'])) ?></td>
              <td class="sa-desk-mono"><?= $e((string) $row['recipient_email']) ?></td>
              <td><?= $e((string) $row['template_key']) ?> <span class="sa-desk-mono"><?= $e((string) $row['template_version']) ?></span></td>
              <td><?= $e(CommunicationRepository::stateLabel((string) $row['state'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div></div>
    </section>
  <?php endif; ?>

  <?php /* Only once it is actually confirmed. A row that exists but carries no
           confirmation stamp is a state nothing in the application creates, and
           printing "Confirmed" against an empty date would be the one thing on
           this screen that was not true. */ ?>
  <?php if ($preferencesRow !== null && $preferencesRow['confirmed_at'] !== null): ?>
    <section aria-labelledby="desk-terms-prefs">
      <p class="sa-label" id="desk-terms-prefs">What they confirmed on their own form</p>
      <div class="sa-panel">
        <div class="sa-panel-h">
          <span>
            Confirmed <?= $e($clock->displayDateTime((string) $preferencesRow['confirmed_at'])) ?>
          </span>
          <span class="sa-pill is-ok">Preferences in</span>
        </div>
        <div style="padding:14px 18px">
          <dl class="sa-dl">
            <?php foreach ($preferencesSummary as $row): ?>
              <dt><?= $e($row['label']) ?></dt>
              <dd><?= $e($row['value']) ?></dd>
            <?php endforeach; ?>
          </dl>
          <p class="sa-desk-note">
            The people named here already hold their roles. The signer can be sent
            the Business Associate Agreement the moment document generation exists.
          </p>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <?php /* Staging only, and only on the one request that minted it.
           TermsService returns null for this on production, so there is no
           setting anybody can switch that would print a live token on the live
           site. It is here because staging refuses to email a real practice,
           which leaves the link inside a message the mail layer declined to
           send, and without it the client side cannot be walked at all. */ ?>
  <?php if ($stagingLink !== null): ?>
    <section aria-labelledby="desk-terms-testlink">
      <p class="sa-label" id="desk-terms-testlink">The link that just went out</p>
      <div class="sa-panel" style="border-color:var(--sa-action)">
        <div class="sa-panel-h">
          <span>Shown once, because this environment does not email real practices</span>
          <span class="sa-pill is-action"><?= $e($config->string('SA_APP_ENV')) ?></span>
        </div>
        <div style="padding:14px 18px">
          <p class="sa-desk-email" style="margin:0 0 12px"><?= $e($stagingLink) ?></p>
          <a class="sa-btn is-action" href="<?= $e($stagingLink) ?>">Open it as the practice</a>
          <p class="sa-desk-note" style="margin-top:12px">
            This is the real one-time link, not a copy of it. Opening it uses it
            up, exactly as it would for them, and there is no second chance:
            send again to mint a fresh one.
            <br><br>
            It also signs you out of the Desk and into that practice's side,
            because that is what the link does. Sign back in with your password
            when you have seen enough.
            <br><br>
            Reload this page and the link is gone from it. It was never stored.
          </p>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <section>
    <p class="sa-label">Send it</p>
    <div class="sa-panel"><div style="padding:16px 18px">
      <?php if (!$canSendTerms): ?>
        <p class="sa-desk-note">You can read this. Sending it is the owner's.</p>
      <?php else: ?>
        <?php if (!$preview['recipient_allowed']): ?>
          <p class="sa-desk-flash is-problem" role="alert">
            This environment may only email its own allowlist, and
            <?= $e((string) $preview['recipient_email']) ?> is not on it. Pressing
            send records the attempt and emails nobody. That is the staging
            guard working, not a fault.
          </p>
        <?php endif; ?>

        <form method="post" action="/sa-desk.php" style="display:grid;gap:14px">
          <?= $csrf->field('terms.send') ?>
          <input type="hidden" name="action" value="terms.send">
          <input type="hidden" name="engagement" value="<?= $e((string) $preview['engagement_ref']) ?>">
          <?php /* The number of sends that had already happened when this page
                   was drawn. It makes the idempotency key, so two submits of
                   this page send once, and a deliberate resend from a freshly
                   loaded page sends again. */ ?>
          <input type="hidden" name="send_sequence" value="<?= (int) $preview['send_sequence'] ?>">

          <label class="sa-field">
            <span>Cadence you are proposing, which they confirm on their own form</span>
            <select class="sa-select" name="cadence">
              <option value="">Leave it to them</option>
              <?php foreach ($preview['cadence_choices'] as $value => $label): ?>
                <option value="<?= $e((string) $value) ?>"<?= (string) $preview['cadence'] === (string) $value ? ' selected' : '' ?>>
                  <?= $e((string) $label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <div class="sa-desk-card-a">
            <button type="submit" class="sa-btn is-primary">
              <?= $preview['is_resend'] ? 'Send again with a new link' : 'Approve and send assessment terms' ?>
            </button>
            <a class="sa-btn is-quiet" href="/sa-desk.php">Not yet</a>
          </div>

          <?php if ($preview['is_resend']): ?>
            <p class="sa-desk-note">
              Sending again mints a new one-time link and kills the previous one.
              Anyone still holding the old email will find it does not work.
            </p>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </div></div>
  </section>

<?php endif; ?>
