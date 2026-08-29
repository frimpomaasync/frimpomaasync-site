<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

/**
 * The branded HTML half of every Soft Appeals email.
 *
 * Every message this application sends is written once, as plain text, in
 * the service that owns it. That text stays the source of truth: it is what
 * the tests read, what the audit trail describes, and what a practice on a
 * text-only client receives. This class turns that same text into an HTML
 * alternative, so a phone shows a wordmark, readable paragraphs, small
 * copper section labels, numbered steps and one button, the way the mail
 * from a company she would trust looks, instead of a wall of hard-wrapped
 * monospace lines.
 *
 * The rules are the ones the text already follows, read back:
 *
 *   a blank line          ends a paragraph
 *   a line in CAPITALS    is a section label
 *   lines starting 1. 2.  are numbered steps
 *   a line that is a URL  becomes the button, with the address printed under
 *                         it for anyone whose client strips buttons
 *   the closing lines     (a name, then Soft Appeals) are the signature
 *
 * Nothing is loaded from anywhere: no image, no remote font, no tracking
 * pixel. System fonts only, because a remote font request crashes her own
 * iPhone, and because a practice's mail scanner treats a remote load as a
 * reason to hold the message. The whole thing is inline styles inside one
 * centred block, which every major client renders the same.
 *
 * Every piece of text is escaped on the way in. The text was already
 * screened for patient information by the service that wrote it; this
 * class adds nothing to it.
 */
final class MailHtml
{
    private const INK    = '#101426';
    private const COPPER = '#C2501C';
    private const MUTE   = '#6E7280';
    private const PAPER  = '#F8F8F9';
    private const LINE   = '#E1E2E6';

    private const SANS  = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";
    private const SERIF = "'Iowan Old Style', 'Palatino Linotype', Palatino, Georgia, serif";
    private const MONO  = "ui-monospace, 'SF Mono', Menlo, Consolas, monospace";

    /**
     * @param string $text     the plain-text body, as sent
     * @param string $subject  used as the page title only
     * @param string $footer   one sentence under the card, already plain
     */
    public static function render(string $text, string $subject, string $footer = ''): string
    {
        $blocks = preg_split("/\n[ \t]*\n/", trim(str_replace("\r\n", "\n", $text))) ?: [];
        $signature = self::detachSignature($blocks);

        $body = '';
        foreach ($blocks as $block) {
            $body .= self::block($block);
        }
        if ($signature !== []) {
            $body .= self::signature($signature);
        }

        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        return '<!doctype html>'
            . '<html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="color-scheme" content="light">'
            . '<title>' . $e($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . self::PAPER . ';">'
            . '<div style="background:' . self::PAPER . ';padding:28px 14px;">'
            . '<div style="max-width:600px;margin:0 auto;">'

            // The wordmark. Text, not an image, so it is there before anything
            // loads and there whether or not images are allowed.
            . '<div style="padding:6px 8px 18px;">'
            . '<span style="font-family:' . self::SERIF . ';font-size:22px;letter-spacing:0.16em;color:' . self::INK . ';">SOFT APPEALS</span>'
            . '<span style="font-family:' . self::MONO . ';font-size:10px;letter-spacing:0.18em;text-transform:uppercase;color:' . self::MUTE . ';margin-left:12px;">by frimpomaasync</span>'
            . '</div>'

            // The card.
            . '<div style="background:#FFFFFF;border:1px solid ' . self::LINE . ';border-radius:20px;padding:30px 28px 26px;">'
            . $body
            . '</div>'

            // Under the card.
            . '<div style="padding:18px 8px 0;font-family:' . self::SANS . ';font-size:12px;line-height:1.6;color:' . self::MUTE . ';">'
            . ($footer === '' ? '' : '<p style="margin:0 0 6px;">' . $e($footer) . '</p>')
            . '<p style="margin:0;">Soft Appeals is a service of FrimpomaaSync &middot; '
            . '<a href="https://frimpomaasync.com/soft-appeals" style="color:' . self::MUTE . ';">frimpomaasync.com/soft-appeals</a></p>'
            . '</div>'

            . '</div></div></body></html>';
    }

    /**
     * One block of the text, as HTML.
     */
    private static function block(string $block): string
    {
        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $block)), static fn (string $l): bool => $l !== ''));
        if ($lines === []) {
            return '';
        }

        // A single URL on its own: the button.
        if (count($lines) === 1 && self::isUrl($lines[0])) {
            return self::button($lines[0]);
        }

        // A section label: a short line in capitals, on its own or as the
        // first line of its block. In the HTML it is the serif headline of
        // the section, in sentence case, the way the reference email she
        // chose sets its sections. Whatever follows it is read as its own
        // block.
        if (self::isLabel($lines[0])) {
            $heading = '<p style="margin:30px 0 10px;font-family:' . self::SERIF . ';font-size:24px;line-height:1.25;'
                . 'letter-spacing:-0.01em;color:' . self::INK . ';">' . self::e(self::sentenceCase($lines[0])) . '</p>';
            $rest = array_slice($lines, 1);
            return $heading . ($rest === [] ? '' : self::block(implode("\n", $rest)));
        }

        // Numbered steps: every line starts "1. ".
        $numbered = true;
        foreach ($lines as $line) {
            if (preg_match('/^\d+\.\s+/', $line) !== 1) {
                $numbered = false;
                break;
            }
        }
        if ($numbered) {
            $out = '<ol style="margin:0 0 16px;padding-left:22px;font-family:' . self::SANS . ';font-size:16px;line-height:1.6;color:' . self::INK . ';">';
            foreach ($lines as $line) {
                $out .= '<li style="margin:0 0 10px;padding-left:4px;">' . self::e(preg_replace('/^\d+\.\s+/', '', $line) ?? $line) . '</li>';
            }
            return $out . '</ol>';
        }

        // The greeting: a little larger, no lead-in.
        if (count($lines) === 1 && preg_match('/^Hello\b.*,$/', $lines[0]) === 1) {
            return '<p style="margin:0 0 18px;font-family:' . self::SERIF . ';font-size:24px;line-height:1.3;color:' . self::INK . ';">'
                . self::e($lines[0]) . '</p>';
        }

        // A paragraph. Lines inside it join with a space; a line that was
        // deliberately short in the text (a URL under a sentence, a date on
        // its own) keeps its break.
        $html = '';
        foreach ($lines as $i => $line) {
            $html .= ($i === 0 ? '' : (self::isUrl($line) || self::isUrl($lines[$i - 1]) ? '<br>' : ' '))
                . self::linkify($line);
        }
        return '<p style="margin:0 0 16px;font-family:' . self::SANS . ';font-size:16px;line-height:1.6;color:' . self::INK . ';">'
            . $html . '</p>';
    }

    private static function button(string $url): string
    {
        return '<div style="margin:22px 0 8px;">'
            . '<a href="' . self::e($url) . '" style="display:inline-block;background:' . self::INK . ';color:#FFFFFF;'
            . 'font-family:' . self::SANS . ';font-size:16px;font-weight:600;text-decoration:none;'
            . 'padding:14px 26px;border-radius:999px;">Open</a>'
            . '</div>'
            . '<p style="margin:0 0 16px;font-family:' . self::MONO . ';font-size:12px;line-height:1.5;word-break:break-all;color:' . self::MUTE . ';">'
            . 'If the button does not work, copy this into your browser:<br>' . self::link($url) . '</p>';
    }

    /**
     * Escape a line and turn any address inside it into a link. A trailing
     * full stop or comma belongs to the sentence, not the address.
     */
    private static function linkify(string $line): string
    {
        $parts = preg_split('~(https?://[^\s<>"]+)~', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$line];
        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $out .= self::e($part);
                continue;
            }
            $trail = '';
            while ($part !== '' && str_contains('.,;:)', substr($part, -1))) {
                $trail = substr($part, -1) . $trail;
                $part = substr($part, 0, -1);
            }
            $out .= self::link($part) . self::e($trail);
        }
        return $out;
    }

    private static function link(string $url): string
    {
        return '<a href="' . self::e($url) . '" style="color:' . self::COPPER . ';">' . self::e($url) . '</a>';
    }

    /**
     * The closing lines: the last block when it is two or three short lines
     * with no full stop, a name and a company. Pulled off the list so it can
     * be set as a signature rather than a paragraph.
     *
     * @param list<string> $blocks
     * @return list<string>
     */
    private static function detachSignature(array &$blocks): array
    {
        if ($blocks === []) {
            return [];
        }
        $last = array_values(array_filter(array_map('trim', explode("\n", (string) end($blocks)))));
        if (count($last) < 2 || count($last) > 4) {
            return [];
        }
        foreach ($last as $line) {
            if (strlen($line) > 60 || str_ends_with($line, '.') || self::isUrl($line)) {
                return [];
            }
        }
        array_pop($blocks);
        return $last;
    }

    /** @param list<string> $lines */
    private static function signature(array $lines): string
    {
        $out = '<div style="margin:26px 0 0;padding-top:18px;border-top:1px solid ' . self::LINE . ';'
            . 'font-family:' . self::SANS . ';font-size:15px;line-height:1.5;color:' . self::INK . ';">';
        foreach ($lines as $i => $line) {
            $out .= $i === 0
                ? '<div style="font-weight:600;">' . self::e($line) . '</div>'
                : '<div style="color:' . self::MUTE . ';">' . self::e($line) . '</div>';
        }
        return $out . '</div>';
    }

    /** "WHAT HAPPENS NEXT" reads as "What happens next" in the HTML. */
    private static function sentenceCase(string $label): string
    {
        $lower = mb_strtolower(trim($label));
        return mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
    }

    private static function isUrl(string $line): bool
    {
        return preg_match('~^https?://\S+$~', trim($line)) === 1;
    }

    private static function isLabel(string $line): bool
    {
        $line = trim($line);
        return strlen($line) >= 4 && strlen($line) <= 60
            && preg_match('/[A-Z]/', $line) === 1
            && preg_match('/[a-z]/', $line) !== 1
            && !self::isUrl($line);
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
