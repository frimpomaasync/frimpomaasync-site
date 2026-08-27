<?php
declare(strict_types=1);

namespace SoftAppeals\Security;

use SoftAppeals\Config;
use Throwable;

/**
 * Safe error handling, from section 18.5.
 *
 * A person sees a sentence and a reference. The log gets the reference, the
 * exception class, the route, and the line. Neither gets the form payload, a
 * token, a signature, or a document.
 *
 * The reference is the tie between the two. When she forwards
 * "SA-ERR-7QK2M4T9" the log line for it is findable, and nothing about the
 * reference itself reveals anything.
 */
final class ErrorHandler
{
    private ?Config $config;
    private string $logPath;
    private string $correlation;

    /**
     * $config is optional so this can be installed BEFORE configuration is
     * loaded.
     *
     * That ordering is not a nicety. Configuration is the first thing that can
     * fail: a config file with a stray character, or one that returns something
     * other than an array, throws while loading. With the handler registered
     * afterwards, that threw past every catch and the response was an empty 500
     * with nothing in the body and nothing in any log. It happened on staging
     * on 2026-08-27 and cost an hour of guessing.
     *
     * Installed first, the same failure renders a page with a class name and a
     * correlation reference.
     */
    public function __construct(?Config $config = null, ?string $logPath = null)
    {
        $this->config = $config;
        $this->logPath = $logPath
            ?? $config?->privateStoragePath('audit-exports', 'errors.log')
            ?? dirname(__DIR__, 3) . '/storage-private/soft-appeals/audit-exports/errors.log';
        $this->correlation = strtoupper(bin2hex(random_bytes(4)));
    }

    /** Hand it the configuration once loading has succeeded. */
    public function withConfig(Config $config): void
    {
        $this->config = $config;
        $this->logPath = $config->privateStoragePath('audit-exports', 'errors.log');
    }

    public function correlationReference(): string
    {
        return 'SA-ERR-' . $this->correlation;
    }

    /** Install as the handler for uncaught throwables and fatal errors. */
    public function register(): void
    {
        // Nothing is ever displayed by PHP itself. A notice printed above the
        // doctype would break the page and could carry a path.
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        error_reporting(E_ALL);

        set_exception_handler(function (Throwable $e): void {
            $this->handle($e);
        });

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        register_shutdown_function(function (): void {
            $last = error_get_last();
            if ($last !== null && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->handle(new \ErrorException(
                    $last['message'],
                    0,
                    $last['type'],
                    $last['file'],
                    $last['line']
                ));
            }
        });
    }

    /**
     * Log and answer. Answers HTML by default and JSON when the caller asked
     * for it, so a fetch() from the Desk does not receive a page.
     */
    public function handle(Throwable $e, bool $wantsJson = false): void
    {
        $status = $this->statusFor($e);
        $this->log($e, $status);

        if ($e instanceof RateLimitException && !headers_sent()) {
            header('Retry-After: ' . $e->retryAfterSeconds);
        }

        $wantsJson = $wantsJson
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

        if (!headers_sent()) {
            http_response_code($status);
        }

        if ($wantsJson) {
            Headers::sendJson();
            echo json_encode([
                'error'     => $this->publicMessage($e),
                'reference' => $this->correlationReference(),
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        Headers::send();
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $this->page($e, $status);
        exit;
    }

    /**
     * A 404 for anything the caller was not allowed to reach.
     *
     * Section 10.1 allows a generic 404 for unauthorized access to internal
     * pages, and that is what is used: a 403 confirms the page exists.
     */
    private function statusFor(Throwable $e): int
    {
        return match (true) {
            $e instanceof AuthorizationException => 404,
            $e instanceof CsrfException          => 400,
            $e instanceof RateLimitException     => 429,
            default                              => 500,
        };
    }

    private function publicMessage(Throwable $e): string
    {
        return match (true) {
            $e instanceof AuthorizationException =>
                'Not here.',
            $e instanceof CsrfException =>
                'That form had expired. Open the page again and resubmit. Nothing was saved.',
            $e instanceof RateLimitException =>
                'Too many attempts. Wait a few minutes and try once more.',
            default =>
                'We could not complete that action. Nothing was submitted twice.',
        };
    }

    /**
     * One line per error. The reference, the class, the route, the file and
     * line. No message body from a user, no payload, no token.
     *
     * The exception message is included only for internal classes, because an
     * exception thrown by the application is written by the application. A
     * driver message can carry a value from a query, so those are recorded by
     * class alone.
     */
    private function log(Throwable $e, int $status): void
    {
        $safeMessage = str_starts_with($e::class, 'SoftAppeals\\')
            ? preg_replace('/[\x00-\x1F\x7F]/', ' ', $e->getMessage())
            : '';

        $line = implode("\t", [
            gmdate('Y-m-d H:i:s'),
            $this->correlationReference(),
            (string) $status,
            $e::class,
            $this->route(),
            basename($e->getFile()) . ':' . $e->getLine(),
            (string) $safeMessage,
        ]) . "\n";

        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        // Capped, like every other log on this site, so it cannot fill the disk.
        if (!is_file($this->logPath) || filesize($this->logPath) < 2_000_000) {
            @file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
        }
    }

    /** The path only. A query string can carry a token. */
    private function route(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) ? substr($path, 0, 120) : '';
    }

    private function page(Throwable $e, int $status): string
    {
        $heading = $status === 404 ? 'Not here.' : 'That did not go through.';
        $message = htmlspecialchars($this->publicMessage($e), ENT_QUOTES, 'UTF-8');
        $reference = htmlspecialchars($this->correlationReference(), ENT_QUOTES, 'UTF-8');

        // A 404 shows no reference. There is nothing to look up, and printing
        // one would confirm that something existed to be refused.
        $referenceBlock = $status === 404
            ? ''
            : '<p class="ref">Reference: <code>' . $reference . '</code></p>';

        return <<<HTML
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,nofollow">
        <title>Soft Appeals</title>
        <style>
          body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;
               background:#F8F8F9;color:#101426;margin:0;padding:15vh 20px;line-height:1.6}
          .w{max-width:38rem;margin:0 auto}
          h1{font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;
             font-size:2rem;font-weight:600;margin:0 0 .5rem}
          h1 span{color:#C2501C}
          p{margin:0 0 1rem}
          .ref{font-size:.85rem;color:#6E7280}
          code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.9em}
          a{color:#C2501C}
        </style></head><body><div class="w">
        <h1>{$heading}<span>.</span></h1>
        <p>{$message}</p>
        {$referenceBlock}
        <p><a href="/soft-appeals">Back to Soft Appeals</a></p>
        </div></body></html>
        HTML;
    }
}
