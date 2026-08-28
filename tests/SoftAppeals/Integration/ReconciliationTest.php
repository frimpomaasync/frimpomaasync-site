<?php
declare(strict_types=1);

/**
 * Phase 7 acceptance, section 22, the money half:
 *
 *   all currency tests use integer cents
 *   fees calculate only from verified reimbursement
 *   a reversal creates an adjustment without deleting history
 *
 * and the financial matrix of section 23: zero recovery, full recovery,
 * partial recovery, one-cent recovery, large recovery, fractional fee result
 * and rounding, recovery adjustment, full reversal, fee rate change only
 * through a new agreement version.
 *
 * Every case walks the real path to a payer decision through
 * Support/walk.php, then verifies, adjusts and invoices through the service.
 * Nothing here writes a recovery row by hand.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Config;
use SoftAppeals\Database;
use SoftAppeals\Domain\DocumentKind;
use SoftAppeals\Domain\InvoiceStatus;
use SoftAppeals\Domain\Permission;
use SoftAppeals\Domain\RecoveryRecord;
use SoftAppeals\Domain\Role;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Support\Money;

$walk = require __DIR__ . '/../Support/walk.php';
$boot = $walk['boot'];
$overturned = $walk['overturned'];
$batchNamed = $walk['batchNamed'];
$labelsOf = $walk['labelsOf'];

$ownerId = static fn (Bootstrap $app): string => (string) $app->users()->findByEmail('owner@example.org')['id'];

/** Verify a figure on the commercial set. */
$verify = static function (Bootstrap $app, array $engagement, string $amount, array $extra = []) use ($batchNamed, $ownerId): array {
    $batch = $batchNamed($app, (string) $engagement['id'], 'Commercial set');
    return $app->reconciliationService()->verify($engagement, $batch, array_merge([
        'amount' => $amount,
        'source' => RecoveryRecord::SOURCE_REMITTANCE,
    ], $extra), $ownerId($app));
};

return [

    'fees are integer cents, half up, at basis points, and never a float' =>
        static function (Bootstrap $app, Database $db): void {
            Expect::same(0, Money::feeCents(0, 2500), 'zero recovery is a zero fee');
            Expect::same(0, Money::feeCents(1, 2500), 'one cent at 25 percent is a quarter cent, which rounds down to nothing');
            Expect::same(1, Money::feeCents(2, 2500), 'two cents at 25 percent is half a cent, which rounds up to one');
            Expect::same(60000, Money::feeCents(240000, 2500), 'the plan\'s own example: $2,400.00 at 25 percent is $600.00');
            Expect::same(175000, Money::feeCents(700000, 2500), 'the fixture: $7,000.00 at 25 percent is $1,750.00');
            Expect::same(2500000000, Money::feeCents(10000000000, 2500), 'a hundred million dollars at 25 percent, in cents, with no float in sight');
            Expect::same(111, Money::feeCents(333, 3333), '$3.33 at 33.33 percent is 110.99 cents, which is 111');
            Expect::same(23, Money::feeCents(100, 2250), '$1.00 at 22.5 percent is 22.5 cents, half up to 23');
            Expect::same(22, Money::feeCents(99, 2250), '$0.99 at 22.5 percent is 22.275 cents, which is 22');
            Expect::same(0, Money::feeCents(700000, 0), 'a zero rate is a zero fee');
            Expect::same(700000, Money::feeCents(700000, 10000), 'ten thousand basis points is all of it');
            Expect::throws(RuntimeException::class, static fn () => Money::feeCents(-1, 2500), 'a negative figure is refused');
            Expect::same('$1,750.00', Money::format(175000), 'formatted for reading');
            Expect::same('-$250.00', Money::format(-25000), 'a credit reads as a credit');
        },

    'a payer decision creates no fee; verifying what arrived does, at the rate on the executed agreement' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $verify, $labelsOf, $batchNamed): void {
            [$app, $sent] = $boot($db);
            $engagement = $overturned($app, $sent);
            $money = $app->reconciliationService();

            $before = $money->summary($engagement);
            Expect::same('$7,000.00', (string) $before['overturned'], 'the payer said seven thousand');
            Expect::same('$0.00', (string) $before['verified'], 'nothing is verified');
            Expect::same('$0.00', (string) $before['fee_net'], 'so there is no fee');
            Expect::same('$7,000.00', (string) $before['awaiting'], 'and all of it awaits verification');
            Expect::same(1, count($money->awaitingVerification()), 'the Desk sees one batch awaiting');
            $block = $app->recoveryService()->feeBlock($engagement);
            Expect::same('$0.00', (string) $block['fee'], 'the room block reads zero too');

            $agreement = $app->documents()->current((string) $engagement['id'], DocumentKind::RECOVERY_AGREEMENT);
            $sentBefore = count($sent);
            $row = $verify($app, $engagement, '7,000.00', ['verified_on' => '2026-08-20', 'note' => 'Remittance received in full.']);

            Expect::same(RecoveryRecord::KIND_VERIFIED, (string) $row['kind'], 'a verified row');
            Expect::same(700000, (int) $row['amount_cents'], 'in integer cents');
            Expect::same(175000, (int) $row['fee_cents'], 'the fee is $1,750.00');
            Expect::same(2500, (int) $row['fee_rate_bps'], 'at the scope\'s basis points, snapshotted on the row');
            Expect::same((string) $agreement['id'], (string) $row['agreement_document_id'], 'naming the executed agreement that set the rate');
            Expect::same(1, (int) $row['qualifies'], 'and it qualifies');
            Expect::same('2026-08-20 12:00:00', (string) $row['verified_at'], 'on the day the money arrived');
            Expect::null($row['invoice_id'], 'not on an invoice yet, invoice-ready');

            $after = $money->summary($engagement);
            Expect::same('$7,000.00', (string) $after['verified'], 'verified now');
            Expect::same('$1,750.00', (string) $after['fee_net'], 'with the fee');
            Expect::same('$1,750.00', (string) $after['uninvoiced'], 'waiting to be invoiced');
            Expect::same('$0.00', (string) $after['awaiting'], 'nothing awaits verification');
            Expect::same(0, count($money->awaitingVerification()), 'and the Desk agrees');
            Expect::same(1, count($money->invoiceReady()), 'one engagement is invoice-ready');

            $block = $app->recoveryService()->feeBlock($engagement);
            Expect::same('$7,000.00', (string) $block['verified'], 'the room block shows the verified figure');
            Expect::same('$1,750.00', (string) $block['fee'], 'and the fee');
            Expect::same('Not created', (string) $block['invoice'], 'and no invoice yet');
            Expect::same((string) $agreement['public_ref'], (string) $block['agreement_ref'], 'and the agreement behind it');

            Expect::true(in_array('recovery.verified', $labelsOf($app, (string) $engagement['id']), true), 'the timeline says so');
            Expect::same($sentBefore + 1, count($sent), 'the practice was told');
            Expect::same('dana@example.org', $sent[count($sent) - 1]['to'], 'at the signer');
            Expect::false(str_contains($sent[count($sent) - 1]['body'], '7,000'), 'without the figure');
            Expect::false(str_contains($sent[count($sent) - 1]['body'], 'Remittance received'), 'and without the note');

            // The second batch was never overturned, so nothing can be
            // verified against it, and nothing more fits on the first.
            $second = $batchNamed($app, (string) $engagement['id'], 'Second set');
            Expect::throws(
                RuntimeException::class,
                static fn () => $money->verify($engagement, $second, ['amount' => '1.00', 'source' => RecoveryRecord::SOURCE_REMITTANCE]),
                'a batch the payer did not overturn takes no verification'
            );
            Expect::throws(
                RuntimeException::class,
                static fn () => $verify($app, $engagement, '0.01'),
                'one cent more than the payer overturned is refused'
            );
            Expect::same(1, count($app->recoveries()->forEngagement((string) $engagement['id'])), 'and nothing was written by a refusal');
        },

    'verification refuses more than the payer overturned, a future date, a PHI note, and a non-qualifying figure with no reason' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $verify): void {
            [$app, $sent] = $boot($db);
            $engagement = $overturned($app, $sent);

            Expect::throws(RuntimeException::class, static fn () => $verify($app, $engagement, '7,000.01'), 'over the overturn');
            Expect::throws(RuntimeException::class, static fn () => $verify($app, $engagement, '7,000.00', ['verified_on' => '2099-01-01']), 'in the future');
            Expect::throws(RuntimeException::class, static fn () => $verify($app, $engagement, '7,000.00', ['note' => 'Patient SSN 123-45-6789 paid']), 'a note that carries a person');
            Expect::throws(RuntimeException::class, static fn () => $verify($app, $engagement, '7,000.00', ['qualifies' => 'no']), 'does not qualify needs a reason');
            Expect::throws(RuntimeException::class, static fn () => $verify($app, $engagement, 'seven thousand'), 'words are not a figure');
            Expect::throws(RuntimeException::class, static fn () => $verify($app, $engagement, '7,000.005'), 'a third decimal is refused, never rounded');
            Expect::throws(RuntimeException::class, static fn () => $verify($app, $engagement, '7,000.00', ['source' => 'a screenshot']), 'the source is one of the categories');
            Expect::same(0, count($app->recoveries()->forEngagement((string) $engagement['id'])), 'nothing was written');

            // Partial, then the rest: two rows, both under the cap together.
            $first = $verify($app, $engagement, '4,000.00');
            $rest = $verify($app, $engagement, '3,000.00');
            Expect::same(100000, (int) $first['fee_cents'], '$1,000.00 on the first');
            Expect::same(75000, (int) $rest['fee_cents'], '$750.00 on the rest');
            $summary = $app->reconciliationService()->summary($engagement);
            Expect::same('$7,000.00', (string) $summary['verified'], 'the two add to the overturn');
            Expect::same('$1,750.00', (string) $summary['fee_net'], 'and so do the fees, cent for cent');
        },

    'zero recovery is a record, and money that does not qualify creates no fee' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $verify): void {
            [$app, $sent] = $boot($db);
            $engagement = $overturned($app, $sent);
            $money = $app->reconciliationService();

            $zero = $verify($app, $engagement, '0.00', ['source' => RecoveryRecord::SOURCE_PRACTICE, 'note' => 'The practice confirms nothing has arrived after ninety days.']);
            Expect::same(0, (int) $zero['amount_cents'], 'zero');
            Expect::same(0, (int) $zero['fee_cents'], 'zero fee');
            $rows = $money->verifiable($engagement);
            Expect::true($rows[0]['has_verified'], 'the batch counts as verified, at nothing');
            Expect::same(0, count($money->awaitingVerification()), 'and no longer awaits');

            $none = $verify($app, $engagement, '7,000.00', ['qualifies' => 'no', 'note' => 'Paid under a government program the agreement excludes.']);
            Expect::same(700000, (int) $none['amount_cents'], 'the money is recorded');
            Expect::same(0, (int) $none['qualifies'], 'as not qualifying');
            Expect::same(0, (int) $none['fee_cents'], 'and no fee exists on it, by rule 6');
            $summary = $money->summary($engagement);
            Expect::same('$7,000.00', (string) $summary['verified'], 'verified is what arrived');
            Expect::same('$0.00', (string) $summary['fee_net'], 'the fee is nothing');
            Expect::throws(RuntimeException::class, static fn () => $money->createInvoice($engagement), 'and there is nothing to invoice');
        },

    'a reversal creates an adjustment without deleting history' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $verify, $labelsOf): void {
            [$app, $sent] = $boot($db);
            $engagement = $overturned($app, $sent);
            $money = $app->reconciliationService();
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];

            $original = $verify($app, $engagement, '7,000.00');

            Expect::throws(RuntimeException::class, static fn () => $money->adjust($engagement, $original, ['kind' => RecoveryRecord::KIND_ADJUSTMENT, 'amount' => '1,000.00', 'note' => ''], $ownerId), 'an adjustment needs a reason');
            Expect::throws(RuntimeException::class, static fn () => $money->adjust($engagement, $original, ['kind' => RecoveryRecord::KIND_ADJUSTMENT, 'amount' => '7,000.01', 'note' => 'Recouped on the next remittance.'], $ownerId), 'more than stands is refused');
            Expect::throws(RuntimeException::class, static fn () => $money->adjust($engagement, $original, ['kind' => RecoveryRecord::KIND_VERIFIED, 'amount' => '1.00', 'note' => 'Recouped.'], $ownerId), 'only an adjustment or a reversal');

            $adjustment = $money->adjust($engagement, $original, [
                'kind' => RecoveryRecord::KIND_ADJUSTMENT, 'amount' => '1,000.00', 'occurred_on' => '2026-09-05',
                'note' => 'Recouped on the next remittance, one claim reprocessed.',
            ], $ownerId);
            Expect::same(RecoveryRecord::KIND_ADJUSTMENT, (string) $adjustment['kind'], 'a new row');
            Expect::same((string) $original['id'], (string) $adjustment['adjusts_recovery_id'], 'naming the row it takes from');
            Expect::same(100000, (int) $adjustment['amount_cents'], 'for the amount taken back');
            Expect::same(25000, (int) $adjustment['fee_cents'], 'with the fee credit at the rate the fee was charged at');
            Expect::same(2500, (int) $adjustment['fee_rate_bps'], 'the original row\'s rate');

            $untouched = $app->recoveries()->find((string) $original['id']);
            Expect::same(700000, (int) $untouched['amount_cents'], 'the original amount is untouched');
            Expect::same(175000, (int) $untouched['fee_cents'], 'and so is its fee');
            Expect::same(RecoveryRecord::KIND_VERIFIED, (string) $untouched['kind'], 'and its kind');

            $ledger = $money->ledger($engagement);
            Expect::same(2, count($ledger), 'two rows on the ledger');
            Expect::same(600000, (int) $ledger[0]['remaining_cents'], 'six thousand still stands on the original');
            Expect::true($ledger[0]['can_adjust'], 'and more can come off it');

            Expect::throws(RuntimeException::class, static fn () => $money->adjust($engagement, $adjustment, ['kind' => RecoveryRecord::KIND_REVERSAL, 'note' => 'Reversed.'], $ownerId), 'an adjustment is not adjusted');

            $reversal = $money->adjust($engagement, $original, ['kind' => RecoveryRecord::KIND_REVERSAL, 'note' => 'The payer reversed the whole decision on review.'], $ownerId);
            Expect::same(600000, (int) $reversal['amount_cents'], 'a reversal takes all of what still stands');
            Expect::same(150000, (int) $reversal['fee_cents'], 'and credits the fee on it');
            Expect::throws(RuntimeException::class, static fn () => $money->adjust($engagement, $original, ['kind' => RecoveryRecord::KIND_ADJUSTMENT, 'amount' => '1.00', 'note' => 'Once more.'], $ownerId), 'nothing is left to take');

            $summary = $money->summary($engagement);
            Expect::same('$7,000.00', (string) $summary['verified'], 'verified is still what was verified');
            Expect::same('$7,000.00', (string) $summary['taken_back'], 'all of it taken back');
            Expect::same('$0.00', (string) $summary['net'], 'net nothing');
            Expect::same('$0.00', (string) $summary['fee_net'], 'fee nothing');
            Expect::same(3, count($app->recoveries()->forEngagement((string) $engagement['id'])), 'three rows, nothing deleted');
            $labels = $labelsOf($app, (string) $engagement['id']);
            Expect::true(in_array('recovery.adjustment', $labels, true) && in_array('recovery.reversal', $labels, true), 'both on the timeline');
        },

    'an invoice gathers invoice-ready rows only, issues as a notice without the figure, is paid, and void hands the rows back' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $verify): void {
            [$app, $sent] = $boot($db);
            $engagement = $overturned($app, $sent);
            $money = $app->reconciliationService();
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];
            $agreement = $app->documents()->current((string) $engagement['id'], DocumentKind::RECOVERY_AGREEMENT);

            Expect::throws(RuntimeException::class, static fn () => $money->createInvoice($engagement, $ownerId), 'nothing verified, nothing to invoice');
            $row = $verify($app, $engagement, '7,000.00');

            $draft = $money->createInvoice($engagement, $ownerId);
            Expect::same(InvoiceStatus::DRAFT, (string) $draft['status'], 'a draft');
            Expect::same(175000, (int) $draft['fee_cents'], 'the fee');
            Expect::same(0, (int) $draft['credit_cents'], 'no credit');
            Expect::same(175000, (int) $draft['total_cents'], 'the total');
            Expect::same((string) $agreement['id'], (string) $draft['agreement_document_id'], 'under the agreement');
            Expect::same((string) $draft['id'], (string) $app->recoveries()->find((string) $row['id'])['invoice_id'], 'the row is on it');
            Expect::null($money->invoiceText($draft), 'a draft has no file');
            Expect::throws(RuntimeException::class, static fn () => $money->createInvoice($engagement, $ownerId), 'one draft at a time');
            Expect::same('Draft, not issued', (string) $money->summary($engagement)['invoice'], 'the block says draft');

            $before = count($sent);
            $issued = $money->issueInvoice($engagement, $draft, ['due_on' => '2026-10-15'], $ownerId);
            Expect::same(InvoiceStatus::ISSUED, (string) $issued['status'], 'issued');
            Expect::same('2026-10-15 12:00:00', (string) $issued['due_at'], 'due on the date given');
            Expect::notNull($issued['private_path'], 'rendered into the vault');
            Expect::true($money->verifyInvoice($issued)['matches'], 'and it reopens and matches its hash');
            $text = (string) $money->invoiceText($issued);
            Expect::true(str_contains($text, (string) $issued['public_ref']), 'the invoice carries its number');
            Expect::true(str_contains($text, (string) $agreement['public_ref']), 'names the agreement');
            Expect::true(str_contains($text, (string) $row['public_ref']), 'names the recovery record');
            Expect::true(str_contains($text, '$1,750.00'), 'and the fee');
            Expect::true(str_contains($text, '25 percent of verified reimbursement'), 'and the rate');
            Expect::true(str_contains($text, 'never receives or holds payer funds'), 'and section 19.9 on its face');
            Expect::same($before + 1, count($sent), 'one notice went out');
            Expect::same('dana@example.org', $sent[count($sent) - 1]['to'], 'to the signer, since no billing contact was named');
            Expect::true(str_contains(strtolower($sent[count($sent) - 1]['subject']), 'invoice'), 'saying an invoice is ready');
            Expect::false(str_contains($sent[count($sent) - 1]['body'], '1,750'), 'without the figure');
            Expect::same(1, count($money->outstandingInvoices()), 'the Desk sees it outstanding');

            // A credit arrives after the invoice went out: it goes on the
            // next one, never over the last.
            $money->adjust($engagement, $row, ['kind' => RecoveryRecord::KIND_ADJUSTMENT, 'amount' => '1,000.00', 'note' => 'Recouped after the invoice.'], $ownerId);
            $credit = $money->createInvoice($engagement, $ownerId);
            Expect::same(0, (int) $credit['fee_cents'], 'no fee on the credit note');
            Expect::same(25000, (int) $credit['credit_cents'], 'the credit');
            Expect::same(-25000, (int) $credit['total_cents'], 'a negative total, a credit note');
            Expect::same(175000, (int) $app->invoices()->find((string) $issued['id'])['total_cents'], 'the first invoice is unchanged');

            $money->voidInvoice($engagement, $credit, 'Issued in error; the credit goes on the final invoice.', $ownerId);
            Expect::same(InvoiceStatus::VOID, (string) $app->invoices()->find((string) $credit['id'])['status'], 'void');
            Expect::same(1, $money->summary($engagement)['uninvoiced_count'], 'the credit row is invoice-ready again');
            Expect::throws(RuntimeException::class, static fn () => $money->issueInvoice($engagement, $app->invoices()->find((string) $credit['id']), [], $ownerId), 'a void invoice is not issued');

            Expect::throws(RuntimeException::class, static fn () => $money->markPaid($engagement, $draft, [], $ownerId), 'paid needs the current row version');
            $money->markPaid($engagement, $app->invoices()->find((string) $issued['id']), ['paid_on' => '2026-10-01', 'note' => 'Paid by check.'], $ownerId);
            $paid = $app->invoices()->find((string) $issued['id']);
            Expect::same(InvoiceStatus::PAID, (string) $paid['status'], 'paid');
            Expect::same('2026-10-01 12:00:00', (string) $paid['paid_at'], 'on the day');
            $summary = $money->summary($engagement);
            Expect::same('$1,750.00', (string) $summary['paid'], 'the summary counts it');
            Expect::same(0, count($money->outstandingInvoices()), 'nothing outstanding');
            Expect::throws(RuntimeException::class, static fn () => $money->voidInvoice($engagement, $paid, 'Too late.', $ownerId), 'a paid invoice is not voided');
        },

    'the fee rate changes only through a new agreement version, and money is the owner\'s alone' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $verify, $walk): void {
            [$app, $sent] = $boot($db);
            $engagement = $overturned($app, $sent);
            $ownerId = (string) $app->users()->findByEmail('owner@example.org')['id'];

            // The scope cannot be rewritten once recovery is active, so the
            // rate on the executed agreement is the rate, full stop.
            Expect::throws(
                RuntimeException::class,
                static fn () => $walk['recordScope']($app, $engagement, $ownerId, ['fee_basis' => 'custom', 'fee_rate' => '40']),
                'the scope is closed once recovery is active'
            );
            $row = $verify($app, $engagement, '7,000.00');
            Expect::same(2500, (int) $row['fee_rate_bps'], 'the rate on the row is the agreement\'s');
            $agreement = $app->documents()->find((string) $row['agreement_document_id']);
            Expect::same(DocumentKind::RECOVERY_AGREEMENT, (string) $agreement['kind'], 'and the row names that agreement');
            Expect::same('executed', (string) $agreement['status'], 'which is executed');

            Expect::same([Role::OWNER_ADMIN], Permission::map()[Permission::RECOVERY_VERIFY], 'only the owner verifies money');
            Expect::same([Role::OWNER_ADMIN], Permission::map()[Permission::CLOSEOUT_MANAGE], 'only the owner closes');
            Expect::false(in_array(Role::VIEWER, Permission::map()[Permission::FINANCE_VIEW], true), 'a viewer does not see invoices');
            Expect::true(in_array(Role::BILLING, Permission::map()[Permission::FINANCE_VIEW], true), 'billing does');
        },

    'recovery finance is a flag, shut on production, and a shut flag refuses every write' =>
        static function (Bootstrap $app, Database $db) use ($boot, $overturned, $verify): void {
            [$app, $sent] = $boot($db, ['SA_RECOVERY_FINANCE_ENABLED' => false]);
            $engagement = $overturned($app, $sent);
            Expect::false($app->config()->recoveryFinanceEnabled(), 'off by the file');
            Expect::throws(RuntimeException::class, static fn () => $verify($app, $engagement, '7,000.00'), 'nothing is verified with the flag off');
            Expect::same(0, count($app->recoveries()->forEngagement((string) $engagement['id'])), 'and nothing was written');

            $path = sys_get_temp_dir() . '/sa-fin-prod-' . bin2hex(random_bytes(4)) . '.php';
            file_put_contents($path, '<?php return ' . var_export([
                'SA_APP_ENV'           => 'production',
                'SA_APP_URL'           => 'https://frimpomaasync.com',
                'SA_BUSINESS_TIMEZONE' => 'America/New_York',
                'SA_SESSION_SECRET'    => str_repeat('test-session-secret-', 3),
                'SA_TOKEN_SECRET'      => str_repeat('test-token-secret-', 3),
                'SA_IP_HMAC_SECRET'    => str_repeat('test-ip-hmac-secret-', 3),
                'SA_DEMO_MODE'         => false,
                'SA_MAIL_ALLOWLIST'    => '',
            ], true) . ";\n");
            $production = Config::load($path);
            @unlink($path);
            Expect::false($production->recoveryFinanceEnabled(), 'unset on production is off');
        },
];
