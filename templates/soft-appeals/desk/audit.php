<?php
/**
 * The audit trail, and the message record beside it.
 *
 * Two different histories on one screen, and the difference matters. The audit
 * trail is internal security history: it holds refusals as well as successes,
 * and it stores a digest of an address rather than the address, which is why
 * nothing on it is ever shown to a practice. The message record is what
 * actually went out, in the state it actually reached.
 *
 * Neither can be edited through this application. There is no update and no
 * delete on either path.
 *
 * @var \SoftAppeals\Support\Clock $clock
 * @var list<array<string,mixed>> $auditRows
 * @var list<array<string,mixed>> $communicationRows
 */

use SoftAppeals\Repositories\CommunicationRepository;
use SoftAppeals\Views\Desk;

$e = static fn (?string $value): string => Desk::e($value);

$outcomePill = static fn (string $outcome): string => match ($outcome) {
    'success' => 'sa-pill is-ok',
    'denied'  => 'sa-pill is-urgent',
    'failure', 'error' => 'sa-pill is-urgent',
    default   => 'sa-pill',
};
?>

<section aria-labelledby="desk-audit">
  <p class="sa-label" id="desk-audit">Audit trail &middot; newest first</p>
  <div class="sa-panel"><div class="sa-tablewrap">
    <table class="sa-table">
      <thead><tr>
        <th>When</th><th>Action</th><th>Outcome</th><th>Object</th><th>Detail</th>
      </tr></thead>
      <tbody>
      <?php if ($auditRows === []): ?>
        <tr><td colspan="5">Nothing recorded yet.</td></tr>
      <?php else: ?>
        <?php foreach ($auditRows as $row): ?>
          <tr>
            <td class="sa-desk-mono"><?= $e($clock->displayDateTime((string) $row['created_at'])) ?></td>
            <td class="sa-desk-mono"><?= $e((string) $row['action']) ?></td>
            <td><span class="<?= $e($outcomePill((string) $row['outcome'])) ?>"><?= $e((string) $row['outcome']) ?></span></td>
            <td class="sa-desk-mono"><?= $e((string) ($row['object_type'] ?? '')) ?></td>
            <td class="sa-desk-mono"><?= $e((string) ($row['metadata'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div></div>
</section>

<section aria-labelledby="desk-messages">
  <p class="sa-label" id="desk-messages">Messages &middot; what actually went out</p>
  <div class="sa-panel"><div class="sa-tablewrap">
    <table class="sa-table">
      <thead><tr>
        <th>When</th><th>Organization</th><th>To</th><th>Subject</th><th>State</th>
      </tr></thead>
      <tbody>
      <?php if ($communicationRows === []): ?>
        <tr><td colspan="5">Nothing has been sent.</td></tr>
      <?php else: ?>
        <?php foreach ($communicationRows as $row): ?>
          <tr>
            <td class="sa-desk-mono"><?= $e($clock->displayDateTime((string) $row['created_at'])) ?></td>
            <td><?= Desk::orNotAsked($row['legal_name'] === null ? null : (string) $row['legal_name']) ?></td>
            <td class="sa-desk-mono"><?= $e((string) $row['recipient_email']) ?></td>
            <td><?= $e((string) $row['subject']) ?></td>
            <td><?= $e(CommunicationRepository::stateLabel((string) $row['state'])) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div></div>
  <p class="sa-desk-note">
    No message is ever marked delivered. This mail path can tell you the server
    accepted a message and nothing at all about whether a person read it, so
    accepted is where the record stops.
  </p>
</section>
