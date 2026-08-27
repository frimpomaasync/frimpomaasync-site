<?php
declare(strict_types=1);

namespace SoftAppeals\Views;

/**
 * What a Soft Appeals page shows before the private config file exists.
 *
 * A freshly deployed site has no configuration: the file holding the database
 * password and the three secrets is written on the server and never committed,
 * so between the first deploy and that file being saved there is a real window
 * where nothing can work.
 *
 * That window used to render a 500 with "We could not complete that action",
 * which reads as something broken. It is not broken. It is waiting.
 *
 * The page names no path and no field. Someone who should be writing that file
 * already knows where it goes, and someone who should not does not learn the
 * layout of the private directory from a public page.
 */
final class NotConfigured
{
    /**
     * @param array{path?:string,file?:bool,database?:bool,secrets?:bool,connects?:bool,reason?:string} $readiness
     */
    public static function render(
        string $environment = '',
        array $readiness = [],
        bool $showDetail = false
    ): string {
        $env = $environment === ''
            ? ''
            : '<p class="env">' . htmlspecialchars($environment, ENT_QUOTES, 'UTF-8') . '</p>';

        // The detail is for a non-production site only. On production the page
        // stays a plain sentence: a public page does not teach the layout of a
        // private directory.
        $detail = '';
        if ($showDetail && $readiness !== []) {
            $mark = static fn (bool $ok): string => $ok
                ? '<span class="yes">found</span>'
                : '<span class="no">missing</span>';
            $path = htmlspecialchars((string) ($readiness['path'] ?? ''), ENT_QUOTES, 'UTF-8');
            $detail = '<div class="checks">'
                . '<p><b>Config file</b> ' . $mark((bool) ($readiness['file'] ?? false)) . '</p>'
                . '<p><b>Database setting</b> ' . $mark((bool) ($readiness['database'] ?? false)) . '</p>'
                . '<p><b>Three secrets</b> ' . $mark((bool) ($readiness['secrets'] ?? false)) . '</p>'
                . (isset($readiness['connects'])
                    ? '<p><b>Database opens</b> ' . $mark((bool) $readiness['connects'])
                        . (((bool) $readiness['connects']) === false && ($readiness['reason'] ?? '') !== ''
                            ? '<br><span class="why">'
                                . htmlspecialchars((string) $readiness['reason'], ENT_QUOTES, 'UTF-8')
                                . '</span>'
                            : '')
                        . '</p>'
                    : '')
                . '<p class="path">Looking for this exact file:<br><code>' . $path . '</code></p>'
                . '</div>';
        }

        return <<<HTML
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,nofollow">
        <title>Not set up yet &middot; Soft Appeals</title>
        <link rel="icon" href="/favicon.svg">
        <style>
          body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;
               background:#F8F8F9;color:#101426;margin:0;padding:24px;line-height:1.6;
               display:flex;align-items:center;justify-content:center;min-height:100vh}
          .card{background:#FFF;border:1px solid #E1E2E6;max-width:28rem;width:100%;
                padding:2.25rem 2rem 2rem}
          .eyebrow{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.65rem;
                   letter-spacing:.18em;text-transform:uppercase;color:#6E7280;margin:0 0 .5rem}
          h1{font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;
             font-size:1.75rem;font-weight:600;margin:0 0 .9rem}
          h1 span{color:#C2501C}
          p{margin:0 0 1rem}
          .muted{color:#6E7280;font-size:.95rem}
          .env{margin:1.5rem 0 0;padding:.7rem .9rem;background:#F8F8F9;
               font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.65rem;
               letter-spacing:.1em;text-transform:uppercase;color:#6E7280}
          a{color:#C2501C}
          .checks{margin:1.25rem 0 0;padding:1rem 1.1rem;background:#F8F8F9;
                  border-left:3px solid #C2501C;font-size:.9rem}
          .checks p{margin:0 0 .4rem}
          .checks p:last-child{margin-bottom:0}
          .yes{color:#1F6B45;font-weight:600}
          .no{color:#8A2B12;font-weight:600}
          .why{color:#8A2B12;font-size:.85rem}
          .path{margin-top:.9rem;padding-top:.7rem;border-top:1px solid #E1E2E6;
                font-size:.8rem;color:#6E7280}
          code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.78rem;
               word-break:break-all;color:#101426}
        </style></head><body>
        <main class="card">
          <p class="eyebrow">Soft Appeals</p>
          <h1>Not set up yet<span>.</span></h1>
          <p class="muted">
            The code is here and the pages are working. The private
            configuration file has not been written to this server yet, so there
            is no database to open and no account to sign in to.
          </p>
          <p class="muted">
            Nothing is broken and nothing is lost. This page becomes the sign-in
            form the moment that file exists.
          </p>
          {$detail}
          {$env}
          <p style="margin-top:1.25rem"><a href="/soft-appeals">Back to Soft Appeals</a></p>
        </main></body></html>
        HTML;
    }
}
