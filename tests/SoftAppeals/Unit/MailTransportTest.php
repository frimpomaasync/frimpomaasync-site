<?php
declare(strict_types=1);

/**
 * Where the mail credentials are looked for, and money arithmetic.
 *
 * The first half is the staging SMTP fix. Every send on staging failed as
 * "the mail server would not take it" from the day staging existed, and no
 * server was ever asked: the credentials file lives beside the LIVE site's
 * fs-mail.php, staging sits in a folder inside it with an empty fs-metrics of
 * its own, and the transport only ever looked in its own folder. The rule now
 * is the importer's rule: own folder first, one level up off production,
 * never one level up on production.
 *
 * The second half is section 19: integer cents, half-up rounding, no float.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Services\MailService;
use SoftAppeals\Support\Money;

/** A throwaway "public_html" with a staging folder inside it. */
$tree = static function (bool $liveHasCredentials, bool $stagingHasCredentials): array {
    $root = sys_get_temp_dir() . '/sa-mail-' . bin2hex(random_bytes(4));
    $staging = $root . '/staging';
    mkdir($staging . '/fs-metrics', 0750, true);
    mkdir($root . '/fs-metrics', 0750, true);
    if ($liveHasCredentials) {
        file_put_contents($root . '/fs-metrics/smtp.json', '{"user":"notify@example.org","pass":"x"}');
    }
    if ($stagingHasCredentials) {
        file_put_contents($staging . '/fs-metrics/smtp.json', '{"user":"staging@example.org","pass":"y"}');
    }
    register_shutdown_function(static function () use ($root): void {
        removeTree($root);
    });
    return [$root, $staging];
};

return [

    'staging with no credentials of its own reads the live site\'s, one level up' =>
        static function (Bootstrap $app) use ($tree): void {
            [$root, $staging] = $tree(true, false);
            Expect::same(
                $root . '/fs-metrics/smtp.json',
                MailService::smtpConfigPath(false, $staging),
                'off production the parent folder is searched'
            );
        },

    'an installation with its own credentials uses them first' =>
        static function (Bootstrap $app) use ($tree): void {
            [$root, $staging] = $tree(true, true);
            Expect::same(
                $staging . '/fs-metrics/smtp.json',
                MailService::smtpConfigPath(false, $staging),
                'own folder wins'
            );
        },

    'production never looks above its own folder' =>
        static function (Bootstrap $app) use ($tree): void {
            [$root, $staging] = $tree(true, false);
            Expect::null(
                MailService::smtpConfigPath(true, $staging),
                'production with no credentials of its own gets nothing, not the parent\'s'
            );
            Expect::same(
                $root . '/fs-metrics/smtp.json',
                MailService::smtpConfigPath(true, $root),
                'production reads its own folder as it always has'
            );
        },

    'nothing anywhere is null, and the send records the reason as no credentials' =>
        static function (Bootstrap $app) use ($tree): void {
            [$root, $staging] = $tree(false, false);
            Expect::null(MailService::smtpConfigPath(false, $staging), 'no file in either folder');

            // The real transport, against a tree with no credentials: the
            // send must come back failed with its own category, and it must
            // not have opened a socket to find that out. fs-mail.php is not
            // beside this throwaway tree, so the transport stops before it
            // reaches the path lookup, which is the same category either way.
            $mail = new MailService(
                $app->config(),
                $app->communications(),
                $app->audit(),
                static function (): bool {
                    throw new \SoftAppeals\Services\NoMailCredentials('none here');
                }
            );
            $result = $mail->send('nanafrimpgskc@gmail.com', 'Subject', 'Body', 'test_template');
            Expect::false($result['sent'], 'not sent');
            Expect::same('this environment has no mail credentials', $result['reason'], 'and it says why');
            $row = $app->communications()->find($result['communication_id']);
            Expect::same('no_mail_credentials', (string) $row['error_category'], 'the category is on the row');
        },

    'a refused socket is still recorded as the mail server refusing' =>
        static function (Bootstrap $app): void {
            $mail = new MailService(
                $app->config(),
                $app->communications(),
                $app->audit(),
                static fn (): bool => false
            );
            $result = $mail->send('nanafrimpgskc@gmail.com', 'Subject', 'Body', 'test_template');
            Expect::same('the mail server would not take it', $result['reason'], 'a false from the transport is a refusal');
            $row = $app->communications()->find($result['communication_id']);
            Expect::same('transport_refused', (string) $row['error_category'], 'with the old category');
        },

    'dollars parse to integer cents and refuse anything inexact' =>
        static function (Bootstrap $app): void {
            Expect::same(1234567, Money::parseCents('12,345.67'), 'commas and two decimals');
            Expect::same(1234567, Money::parseCents('$12,345.67'), 'a leading dollar sign');
            Expect::same(500, Money::parseCents('5'), 'whole dollars');
            Expect::same(550, Money::parseCents('5.5'), 'one decimal is tens of cents');
            Expect::same(0, Money::parseCents('0.00'), 'zero');
            Expect::null(Money::parseCents('12.345'), 'three decimals are refused, never rounded');
            Expect::null(Money::parseCents('-5'), 'a negative is refused');
            Expect::null(Money::parseCents('five'), 'words are refused');
            Expect::null(Money::parseCents(''), 'blank is null');
            Expect::null(Money::parseCents('1e5'), 'exponent notation is refused');
            Expect::same('$12,345.67', Money::format(1234567), 'and formats back');
            Expect::same('$0.05', Money::format(5), 'small amounts keep their zeros');
        },

    'the fee is integer arithmetic with half-up rounding, the plan\'s own example' =>
        static function (Bootstrap $app): void {
            Expect::same(60000, Money::feeCents(240000, 2500), 'section 19: 240000 at 2500 bps is 60000');
            Expect::same(0, Money::feeCents(0, 2500), 'nothing verified is no fee');
            Expect::same(1, Money::feeCents(2, 2500), '0.5 cents rounds up');
            Expect::same(0, Money::feeCents(1, 2500), '0.25 cents rounds down');
            Expect::same(3, Money::feeCents(10, 2500), '2.5 cents rounds up to 3');
            Expect::throws(
                RuntimeException::class,
                static fn () => Money::feeCents(-1, 2500),
                'a negative figure is refused'
            );
        },
];
