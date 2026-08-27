<?php
declare(strict_types=1);

namespace SoftAppeals\Security;

/**
 * The security headers for every Soft Appeals application page, from section
 * 18.2 of the plan.
 *
 * These are stricter than the site-wide headers in .htaccess, and deliberately
 * so. The marketing pages allow framing from the same origin and send a
 * referrer to same-site destinations. The Desk, the Recovery Room and the
 * signing screen allow neither.
 *
 * X-Powered-By is unset here as well as in .htaccess, because a header set by
 * PHP itself is not always removable at the Apache layer on a shared host.
 */
final class Headers
{
    /**
     * @param bool $allowInlineStyle The existing soft-appeals.css is a linked
     *        stylesheet, but the four shells carry a handful of inline style
     *        attributes on generated rows. Keeping 'unsafe-inline' for styles
     *        only, never for scripts, is the compromise the plan names.
     */
    public static function send(bool $allowInlineStyle = true): void
    {
        if (headers_sent()) {
            return;
        }

        $style = $allowInlineStyle ? "'self' 'unsafe-inline'" : "'self'";

        $csp = implode('; ', [
            "default-src 'self'",
            "img-src 'self' data:",
            'style-src ' . $style,
            "script-src 'self'",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "form-action 'self'",
        ]);

        header('Content-Security-Policy: ' . $csp);
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');

        // No page in this application is cacheable. A Desk page held in a
        // browser cache is a client list on a shared machine, and a signing
        // page held in a cache is an agreement someone can page back to.
        header('Cache-Control: no-store, private, max-age=0');
        header('Pragma: no-cache');

        header_remove('X-Powered-By');
    }

    /**
     * For an endpoint that answers with JSON. Same protections, plus the
     * content type, and no CSP relaxation of any kind.
     */
    public static function sendJson(): void
    {
        self::send(false);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
    }
}
