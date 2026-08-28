<?php
/**
 * Agreements. Section 14, her side of it.
 *
 * The rule this screen exists to enforce, the same one the terms screen carries:
 * preparing a document does not send it. Generating writes a draft nobody has
 * seen but her. Sending is a second button that says so. Countersigning is a
 * third, and it is the one that executes.
 *
 * Every version ever generated is listed, including the void ones, with the
 * reason each was replaced. A screen that quietly hid a superseded agreement
 * would be the screen somebody later needed and could not find.
 *
 * The verification line under each executed document is not decoration. It is
 * the stored record reopened and hashed on this request, so what is printed is
 * what is true right now rather than what was true when it was written.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var \SoftAppeals\Security\Csrf $csrf
 * @var array<string,mixed>|null $engagement
 * @var list<array<string,mixed>> $documents
 * @var array<string,list<array<string,mixed>>> $signatures
 * @var array<string,array{body:array{found:bool,matches:bool,sha256:?string},executed:?array{found:bool,matches:bool,sha256:?string}}> $verifications
 * @var ?string $nextKind
 * @var array<string,array{ok:bool,reason:?string}> $generateChecks
 * @var array<string,mixed>|null $signer
 * @var list<string> $blockers
 * @var bool $eSignEnabled
 * @var bool $canCountersign
 * @var bool $canGenerate
 * @var list<array<string,mixed>> $awaitingCountersignature
 * @var list<array<string,mixed>> $outForSignature
 * @var list<array<string,mixed>> $engagementsWithDocuments
 * @var ?string $stagingLink
 * @var array<string,mixed> $user
 */

use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\DocumentStatus;
use SoftAppeals\Domain\DocumentTemplates;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\SignatureRepository;
use SoftAppeals\Views\Desk;

$e = static fn (?string $value): string => Desk::e($value);
?>

<?php if (!$eSignEnabled): ?>
  <section aria-labelledby="desk-doc-off">
    <p class="sa-label" id="desk-doc-off">Signing is off here</p>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
      <p style="margin:0 0 10px">
        Documents can be generated and read. Nothing can be sent for signature and
        nothing can be signed, because section 14.5 keeps production signing shut
        until every one of these is cleared:
      </p>
      <ul class="sa-desk-list">
        <?php foreach ($blockers as $blocker): ?>
          <li><?= $e($blocker) ?></li>
        <?php endforeach; ?>
      </ul>
    </div></div>
  </section>
<?php endif; ?>

<?php if ($stagingLink !== null): ?>
  <section aria-labelledby="desk-doc-link">
    <p class="sa-label" id="desk-doc-link">The signing link, this once</p>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
      <p style="margin:0 0 8px">
        This environment will not email a real practice, so the link is shown here
        instead. It is not stored anywhere and it is not shown again.
      </p>
      <p class="sa-desk-email" style="padding:12px"><?= $e($stagingLink) ?></p>
    </div></div>
  </section>
<?php endif; ?>

<?php if ($engagement === null): ?>

  <section aria-labelledby="desk-doc-needs">
    <p class="sa-label" id="desk-doc-needs">Waiting on you</p>
    <?php if ($awaitingCountersignature === []): ?>
      <div class="sa-panel"><div class="sa-empty">
        Nothing is waiting on your countersignature.
      </div></div>
    <?php else: ?>
      <div class="sa-desk-cards">
        <?php foreach ($awaitingCountersignature as $row): ?>
          <div class="sa-desk-card is-urgent">
            <div class="sa-desk-card-t">
              <b><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></b>
              <span>
                <?= $e(DocumentKind::label((string) $row['kind'])) ?>
                &middot; signed <?= $e($clock->displayDate((string) $row['client_signed_at'])) ?>
              </span>
            </div>
            <div class="sa-desk-card-a">
              <a class="sa-btn is-action is-sm"
                 href="/sa-desk.php?view=documents&amp;e=<?= $e(urlencode((string) $row['engagement_ref'])) ?>">
                Countersign
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section aria-labelledby="desk-doc-out">
    <p class="sa-label" id="desk-doc-out">Out for signature</p>
    <?php if ($outForSignature === []): ?>
      <div class="sa-panel"><div class="sa-empty">
        Nothing is out for signature.
      </div></div>
    <?php else: ?>
      <div class="sa-desk-cards">
        <?php foreach ($outForSignature as $row): ?>
          <div class="sa-desk-card">
            <div class="sa-desk-card-t">
              <b><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></b>
              <span>
                <?= $e(DocumentKind::label((string) $row['kind'])) ?>
                &middot; sent <?= $e($clock->displayDate((string) $row['sent_at'])) ?>
              </span>
            </div>
            <div class="sa-desk-card-a">
              <a class="sa-btn is-sm"
                 href="/sa-desk.php?view=documents&amp;e=<?= $e(urlencode((string) $row['engagement_ref'])) ?>">
                Open
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section aria-labelledby="desk-doc-pick">
    <p class="sa-label" id="desk-doc-pick">Every open engagement</p>
    <div class="sa-panel">
      <?php if ($engagementsWithDocuments === []): ?>
        <div class="sa-empty">No engagement is open.</div>
      <?php else: ?>
        <div class="sa-tablewrap">
          <table class="sa-table">
            <thead><tr><th>Practice</th><th>Reference</th><th>Stage</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($engagementsWithDocuments as $row): ?>
                <tr>
                  <td><?= $e((string) ($row['display_name'] ?? $row['legal_name'])) ?></td>
                  <td class="sa-desk-mono"><?= $e((string) $row['public_ref']) ?></td>
                  <td><?= $e(Stage::staffLabel((string) $row['stage'])) ?></td>
                  <td>
                    <a class="sa-btn is-sm"
                       href="/sa-desk.php?view=documents&amp;e=<?= $e(urlencode((string) $row['public_ref'])) ?>">
                      Agreements
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </section>

<?php else: ?>

  <?php
  $engagementRef = (string) $engagement['public_ref'];
  $stage = (string) $engagement['stage'];
  ?>

  <section aria-labelledby="desk-doc-who">
    <p class="sa-label" id="desk-doc-who">
      <?= $e((string) ($engagement['display_name'] ?? $engagement['legal_name'])) ?>
    </p>
    <div class="sa-metrics">
      <div class="sa-metric">
        <p class="sa-metric-k">Stage</p>
        <p class="sa-metric-v"><?= $e(Stage::staffLabel($stage)) ?></p>
        <p class="sa-metric-c"><?= $e(Stage::nextAction($stage)) ?></p>
      </div>
      <div class="sa-metric">
        <p class="sa-metric-k">Authorized signer</p>
        <p class="sa-metric-v"><?= $e($signer === null ? 'Nobody named' : (string) $signer['name']) ?></p>
        <p class="sa-metric-c">
          <?= $e($signer === null
            ? 'The practice names one on the preferences page.'
            : (string) $signer['work_email']) ?>
        </p>
      </div>
      <div class="sa-metric">
        <p class="sa-metric-k">Template version</p>
        <p class="sa-metric-v"><?= $e(DocumentTemplates::TEMPLATE_VERSION) ?></p>
        <p class="sa-metric-c">Consent wording <?= $e(DocumentTemplates::CONSENT_VERSION) ?></p>
      </div>
    </div>
  </section>

  <?php if ($canGenerate): ?>
  <section aria-labelledby="desk-doc-make">
    <p class="sa-label" id="desk-doc-make">Generate</p>
    <div class="sa-desk-cards">
      <?php foreach (DocumentKind::live() as $kind): ?>
        <?php $check = $generateChecks[$kind] ?? ['ok' => false, 'reason' => 'Not checked.']; ?>
        <div class="sa-desk-card<?= $nextKind === $kind ? ' is-urgent' : '' ?>">
          <div class="sa-desk-card-t">
            <b><?= $e(DocumentKind::label($kind)) ?></b>
            <span>
              <?= $check['ok']
                ? $e('Ready to generate. It is a draft until you send it.')
                : $e((string) $check['reason']) ?>
            </span>
          </div>
          <div class="sa-desk-card-a">
            <?php if ($check['ok']): ?>
              <form method="post" action="/sa-desk.php" style="margin:0">
                <?= $csrf->field('document.generate') ?>
                <input type="hidden" name="action" value="document.generate">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <input type="hidden" name="kind" value="<?= $e($kind) ?>">
                <button type="submit" class="sa-btn is-action is-sm">Generate</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($stage === Stage::REVIEW_AUTH_EXECUTED): ?>
    <section aria-labelledby="desk-doc-gate">
      <p class="sa-label" id="desk-doc-gate">The PHI gate</p>
      <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
        <p style="margin:0 0 12px">
          Both agreements are executed, so this practice may now send denials.
          Nothing at patient level has moved before this point and nothing may
          until this is pressed.
        </p>
        <form method="post" action="/sa-desk.php" style="margin:0">
          <?= $csrf->field('engagement.open_secure_route') ?>
          <input type="hidden" name="action" value="engagement.open_secure_route">
          <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
          <button type="submit" class="sa-btn is-primary">Open the secure route</button>
        </form>
      </div></div>
    </section>
  <?php endif; ?>

  <section aria-labelledby="desk-doc-all">
    <p class="sa-label" id="desk-doc-all">Every version</p>
    <?php if ($documents === []): ?>
      <div class="sa-panel"><div class="sa-empty">
        Nothing has been generated for this practice yet.
      </div></div>
    <?php else: ?>
      <?php foreach ($documents as $document): ?>
        <?php
        $documentId = (string) $document['id'];
        $status = (string) $document['status'];
        $rows = $signatures[$documentId] ?? [];
        $check = $verifications[$documentId] ?? null;
        ?>
        <div class="sa-panel" style="margin-bottom:14px">
          <div class="sa-panel-h">
            <div>
              <b><?= $e(DocumentKind::label((string) $document['kind'])) ?></b>
              <span class="sa-desk-quiet">version <?= (int) $document['version'] ?></span>
            </div>
            <span class="sa-pill"><?= $e(DocumentStatus::staffLabel($status)) ?></span>
          </div>
          <div class="sa-panel-b" style="padding:14px 18px">

            <dl class="sa-dl">
              <dt>Reference</dt><dd class="sa-desk-mono"><?= $e((string) $document['public_ref']) ?></dd>
              <dt>Generated</dt>
              <dd><?= $e($clock->displayDateTime((string) $document['created_at'])) ?></dd>
              <dt>Template</dt><dd><?= $e((string) $document['template_version']) ?></dd>
              <dt>Document hash</dt>
              <dd class="sa-desk-mono"><?= $e((string) $document['content_sha256']) ?></dd>
              <?php if ($document['executed_sha256'] !== null): ?>
                <dt>Executed hash</dt>
                <dd class="sa-desk-mono"><?= $e((string) $document['executed_sha256']) ?></dd>
              <?php endif; ?>
              <?php if ($document['void_reason'] !== null): ?>
                <dt>Voided because</dt><dd><?= $e((string) $document['void_reason']) ?></dd>
              <?php endif; ?>
            </dl>

            <?php if ($check !== null): ?>
              <p class="sa-desk-note" style="margin-top:12px">
                <?php if (!$check['body']['found']): ?>
                  The stored document is not in the vault. Nothing can be signed against it.
                <?php elseif (!$check['body']['matches']): ?>
                  The stored document does not match its recorded hash. Do not sign this.
                  Write it down and say so.
                <?php else: ?>
                  Reopened just now and it matches its recorded hash.
                  <?php if ($check['executed'] !== null): ?>
                    <?= $check['executed']['found'] && $check['executed']['matches']
                      ? 'The executed record matches too.'
                      : 'The executed record does NOT match, or is missing.' ?>
                  <?php endif; ?>
                <?php endif; ?>
              </p>
            <?php endif; ?>

            <?php if ($rows !== []): ?>
              <div class="sa-tablewrap" style="margin-top:12px">
                <table class="sa-table">
                  <thead><tr><th>Party</th><th>Signed by</th><th>When</th><th>Hash signed</th></tr></thead>
                  <tbody>
                    <?php foreach ($rows as $signature): ?>
                      <tr>
                        <td><?= $e(SignatureRepository::partyLabel((string) $signature['party'])) ?></td>
                        <td>
                          <?= $e((string) $signature['typed_name']) ?>
                          <?php if ($signature['typed_title'] !== null): ?>
                            <br><span class="sa-desk-quiet"><?= $e((string) $signature['typed_title']) ?></span>
                          <?php endif; ?>
                        </td>
                        <td><?= $e($clock->displaySigningStamp((string) $signature['signed_at'])) ?></td>
                        <td class="sa-desk-mono"><?= $e(substr((string) $signature['document_sha256'], 0, 16)) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <?php // Reading what is actually stored, out of the vault. ?>
            <div class="sa-desk-card-a" style="margin-top:14px">
              <a class="sa-btn is-sm"
                 href="/sa-desk.php?view=documents&amp;e=<?= $e(urlencode($engagementRef)) ?>&amp;open=<?= $e(urlencode((string) $document['public_ref'])) ?>&amp;part=body"
                 target="_blank" rel="noopener">Read the document</a>
              <?php if ($document['executed_path'] !== null): ?>
                <a class="sa-btn is-sm"
                   href="/sa-desk.php?view=documents&amp;e=<?= $e(urlencode($engagementRef)) ?>&amp;open=<?= $e(urlencode((string) $document['public_ref'])) ?>"
                   target="_blank" rel="noopener">Executed record and audit certificate</a>
              <?php endif; ?>
            </div>

            <?php // The actions this version allows, and only those. ?>
            <?php if ($status === DocumentStatus::DRAFT && $canGenerate && $eSignEnabled): ?>
              <form method="post" action="/sa-desk.php" style="margin-top:14px">
                <?= $csrf->field('document.send') ?>
                <input type="hidden" name="action" value="document.send">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <input type="hidden" name="document" value="<?= $e((string) $document['public_ref']) ?>">
                <button type="submit" class="sa-btn is-primary">Send it for signature</button>
              </form>
            <?php endif; ?>

            <?php if ($status === DocumentStatus::CLIENT_SIGNED && $canCountersign && $eSignEnabled): ?>
              <form method="post" action="/sa-desk.php" style="margin-top:14px">
                <?= $csrf->field('document.countersign') ?>
                <input type="hidden" name="action" value="document.countersign">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <input type="hidden" name="document" value="<?= $e((string) $document['public_ref']) ?>">

                <p class="sa-note" style="margin:0 0 10px"><?= $e(DocumentTemplates::consentText()) ?></p>

                <label class="sa-field">
                  <span class="sa-fieldlabel">Your full legal name</span>
                  <input class="sa-input" type="text" name="typed_name" maxlength="160"
                         value="Nana Frimpongmaa">
                </label>
                <label class="sa-field" style="margin-top:10px">
                  <span class="sa-fieldlabel">Your title</span>
                  <input class="sa-input" type="text" name="typed_title" maxlength="120" value="Owner">
                </label>
                <label class="sa-choice" style="margin-top:10px">
                  <input type="checkbox" name="consent" value="yes">
                  <span class="sa-choice-t"><b>I agree to sign this electronically.</b></span>
                </label>

                <button type="submit" class="sa-btn is-primary" style="margin-top:12px">
                  Countersign and execute
                </button>
              </form>
            <?php endif; ?>

            <?php if ($status !== DocumentStatus::VOID && $canGenerate): ?>
              <form method="post" action="/sa-desk.php" style="margin-top:14px">
                <?= $csrf->field('document.correct') ?>
                <input type="hidden" name="action" value="document.correct">
                <input type="hidden" name="engagement" value="<?= $e($engagementRef) ?>">
                <input type="hidden" name="document" value="<?= $e((string) $document['public_ref']) ?>">
                <label class="sa-field">
                  <span class="sa-fieldlabel">Replace this version because</span>
                  <input class="sa-input" type="text" name="reason" maxlength="200"
                         placeholder="The signer changed">
                </label>
                <button type="submit" class="sa-btn is-sm" style="margin-top:10px">
                  Void this and generate a replacement
                </button>
              </form>
            <?php endif; ?>

          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <section aria-labelledby="desk-doc-back">
    <p class="sa-label" id="desk-doc-back">Elsewhere</p>
    <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
      <a class="sa-btn is-sm" href="/sa-desk.php?view=documents">All agreements</a>
      <a class="sa-btn is-sm"
         href="/sa-desk.php?view=terms&amp;e=<?= $e(urlencode($engagementRef)) ?>">
        The terms for this practice
      </a>
    </div></div>
  </section>

<?php endif; ?>
