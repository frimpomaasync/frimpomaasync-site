<?php
declare(strict_types=1);

namespace SoftAppeals\Views;

/**
 * Rendering the client side.
 *
 * The same shape as Views\Desk and for the same reasons: escaping in one
 * place, a template include that cannot leak variables between views, and a
 * failure that prints a readable block instead of a blank page. There is no PHP
 * on the machine these templates are written on, so the first time one of them
 * is parsed is on a server, and a white screen is the worst way to find out.
 *
 * It is a separate class rather than a second method on Desk because the two
 * render from different directories and are read by different people. A staff
 * screen may name a section that broke. A client screen must not: a practice
 * reading "the preferences section did not render" learns something about the
 * inside of the application and can do nothing with it. So the block here says
 * the page could not be shown and gives her address to write to, and the detail
 * goes to the error log under the correlation reference.
 *
 * Templates live under templates/soft-appeals/client, which is deny-all on the
 * server, so no view file can be fetched directly.
 */
final class Client
{
    /** Escape. Every single value that reaches the page goes through this. */
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Render one client view.
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $view, array $data = [], bool $showDetail = false): void
    {
        if (preg_match('/^[a-z0-9_-]+$/', $view) !== 1) {
            throw new \RuntimeException('Refusing to render "' . $view . '" as a view.');
        }
        $path = dirname(__DIR__, 3) . '/templates/soft-appeals/client/' . $view . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException('No such client view: ' . $view);
        }

        ob_start();
        try {
            (static function (string $__path, array $data): void {
                extract($data, EXTR_SKIP);
                require $__path;
            })($path, $data);
            echo ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            echo self::failureBlock($e, $showDetail);
        }
    }

    /** A readable block, in place of a screen that would otherwise be blank. */
    private static function failureBlock(\Throwable $e, bool $showDetail): string
    {
        $out = '<div class="sa-screen">'
            . '<p class="sa-qnum">Soft Appeals</p>'
            . '<h1 class="sa-q">This page could not be shown.</h1>'
            . '<p class="sa-note">Nothing you did caused it and nothing was lost. '
            . 'Write to softappeals@frimpomaasync.com and it gets sorted the same day.</p>';

        if ($showDetail) {
            $out .= '<p class="sa-note"><code>' . self::e($e::class) . ': '
                . self::e($e->getMessage()) . '</code></p>'
                . '<p class="sa-note"><code>' . self::e(basename($e->getFile()))
                . ':' . (int) $e->getLine() . '</code></p>';
        }

        return $out . '</div>';
    }

    /**
     * A value the practice left blank, said out loud rather than left empty.
     * A row of blank cells reads as a page that failed to load.
     */
    public static function orBlank(?string $value, string $whenEmpty = 'Not answered'): string
    {
        $value = trim((string) $value);
        return $value === ''
            ? '<span class="sa-client-quiet">' . self::e($whenEmpty) . '</span>'
            : self::e($value);
    }
}
