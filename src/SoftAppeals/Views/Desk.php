<?php
declare(strict_types=1);

namespace SoftAppeals\Views;

use SoftAppeals\Support\Clock;

/**
 * Rendering the Desk.
 *
 * The Desk stands on .sa-console, the operational shell already live on the
 * Recovery Lab: navy rail, pale canvas, metric row, worklist, slide-out record.
 * ADR-005 says reuse it rather than invent second chrome, and the whole reason
 * assets/soft-appeals.css exists is so a working tool looks like a working tool
 * and not like another marketing page. Nothing here restyles it. What this
 * class adds is the handful of helpers a server-rendered page needs: escaping,
 * pill classes, the deadline colour rule, and a template include that cannot
 * leak variables between views.
 *
 * Templates live under templates/soft-appeals/desk, which is deny-all on the
 * server, so no view file can be fetched directly.
 */
final class Desk
{
    /** Escape. Every single value that reaches the page goes through this. */
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Render one view.
     *
     * The include happens inside a static method with only $data in scope, so a
     * template cannot reach a variable the controller happened to leave lying
     * around. Anything a view needs, it is handed.
     *
     * The output is buffered, and a failure inside a view is caught rather than
     * left to become an empty response. Since PHP 7 a syntax error in a file
     * brought in by require throws ParseError instead of fataling, so this
     * catches the one failure the application otherwise cannot report on
     * itself. That matters more here than usual: there is no PHP on the machine
     * these views are written on, so the first time one of them is parsed is on
     * a server, and a blank page with nothing in it is the worst possible way
     * to find that out.
     *
     * $showDetail is false in production. There, the block names the view and
     * nothing else; the correlation reference in the error log carries the rest.
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $view, array $data = [], bool $showDetail = false): void
    {
        if (preg_match('/^[a-z0-9_]+$/', $view) !== 1) {
            throw new \RuntimeException('Refusing to render "' . $view . '" as a view.');
        }
        $path = dirname(__DIR__, 3) . '/templates/soft-appeals/desk/' . $view . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException('No such Desk view: ' . $view);
        }

        ob_start();
        try {
            (static function (string $__path, array $data): void {
                extract($data, EXTR_SKIP);
                require $__path;
            })($path, $data);
            echo ob_get_clean();
        } catch (\Throwable $e) {
            // Throw away whatever the view managed to print before it broke.
            // Half a table followed by an error block is harder to read than
            // the error block alone.
            ob_end_clean();
            echo self::failureBlock($view, $e, $showDetail);
        }
    }

    /** A readable block, in place of a screen that would otherwise be blank. */
    private static function failureBlock(string $view, \Throwable $e, bool $showDetail): string
    {
        $out = '<div class="sa-panel" style="border-color:#e4222c">'
            . '<div style="padding:16px 18px">'
            . '<p class="sa-label" style="color:#b4141c">This section did not render</p>'
            . '<p>The rest of the Desk is fine. The <strong>' . self::e($view)
            . '</strong> section is the part that broke.</p>';

        if ($showDetail) {
            $out .= '<p class="sa-desk-mono">' . self::e($e::class) . ': ' . self::e($e->getMessage()) . '</p>'
                . '<p class="sa-desk-mono">' . self::e(basename($e->getFile())) . ':' . (int) $e->getLine() . '</p>';
        }

        return $out . '</div></div>';
    }

    /**
     * The deadline colour rule, section 12.4, exactly as written:
     * overdue red, 0 to 13 days red, 14 to 29 copper, 30 or more ink.
     *
     * An unconfirmed date never gets a colour. It gets the outlined warning
     * state, because a date nobody has confirmed is not a deadline yet and
     * painting it like one is how a practice ends up trusting a guess.
     */
    public static function deadlinePill(?int $daysAway, bool $confirmed): string
    {
        if (!$confirmed || $daysAway === null) {
            return 'sa-pill is-wait';
        }
        if ($daysAway < 14) {
            return 'sa-pill is-urgent';
        }
        if ($daysAway < 30) {
            return 'sa-pill is-action';
        }
        return 'sa-pill';
    }

    /** The words that go with that pill. */
    public static function deadlineWords(?int $daysAway, bool $confirmed): string
    {
        if ($daysAway === null) {
            return 'No date';
        }
        if (!$confirmed) {
            return $daysAway < 0
                ? abs($daysAway) . ' days ago, unconfirmed'
                : 'In ' . $daysAway . ' days, unconfirmed';
        }
        if ($daysAway < 0) {
            return 'Overdue by ' . abs($daysAway) . ' ' . self::plural(abs($daysAway), 'day');
        }
        if ($daysAway === 0) {
            return 'Today';
        }
        return 'In ' . $daysAway . ' ' . self::plural($daysAway, 'day');
    }

    public static function plural(int $n, string $word): string
    {
        return $n === 1 ? $word : $word . 's';
    }

    /**
     * "3 days ago", for a submitted date. Relative time is what makes a queue
     * readable; the exact stamp is one hover away in the title attribute.
     */
    public static function ago(Clock $clock, ?string $storedUtc): string
    {
        if ($storedUtc === null || $storedUtc === '') {
            return '';
        }
        $days = $clock->daysUntil($storedUtc);
        if ($days === null) {
            return '';
        }
        $days = -$days;
        if ($days <= 0) {
            return 'Today';
        }
        if ($days === 1) {
            return 'Yesterday';
        }
        if ($days < 31) {
            return $days . ' days ago';
        }
        return $clock->displayDate($storedUtc);
    }

    /**
     * A value that may not have been asked for.
     *
     * "Not asked" and "left blank" are different facts and the Desk says which,
     * because one of them is a gap in her form and the other is a gap in the
     * answer, and only one of those is worth fixing.
     */
    public static function orNotAsked(?string $value, bool $formAsks = true): string
    {
        $value = trim((string) $value);
        if ($value !== '') {
            return self::e($value);
        }
        return '<span class="sa-desk-quiet">' . ($formAsks ? 'Left blank' : 'Not asked') . '</span>';
    }
}
