<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * Free text that may reach a practice's screen, cleaned and screened.
 *
 * One door for every free-text field Phase 5 adds: the assessment summary, a
 * batch's payer label and next action, the note on an action request, the
 * practice's own question. Each is capped to what its column holds and
 * refused if it looks like it carries a person, using the same screen the
 * preferences page has used since Phase 3, so the rule is one rule.
 */
final class SafeText
{
    /** Multi-line text: control characters out, whitespace tidied, capped. */
    public static function clean(string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = str_replace("\r\n", "\n", $value);
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? '';
        $value = preg_replace('/\n{3,}/', "\n\n", $value) ?? '';
        return mb_substr(trim($value), 0, $max);
    }

    /** A single line: a label, a title, a short action. */
    public static function line(string $value, int $max): string
    {
        return PreferenceForm::cleanLine($value, $max);
    }

    /**
     * The phrase to refuse with, or null when the text is fine.
     * See PreferenceForm::phiObjection for what is looked for and why.
     */
    public static function objection(string $value): ?string
    {
        return PreferenceForm::phiObjection($value);
    }

    /**
     * Clean and screen in one call. Throws with the shape it found, so the
     * refusal names what to take out rather than saying no.
     */
    public static function require(string $value, int $max, string $what): string
    {
        $clean = self::clean($value, $max);
        $objection = self::objection($clean);
        if ($objection !== null) {
            throw new \RuntimeException(
                'Not saved: ' . $what . ' ' . $objection . '. Nothing at patient level goes in here.'
            );
        }
        return $clean;
    }

    /** The single-line form of require(). */
    public static function requireLine(string $value, int $max, string $what): string
    {
        $clean = self::line($value, $max);
        $objection = self::objection($clean);
        if ($objection !== null) {
            throw new \RuntimeException(
                'Not saved: ' . $what . ' ' . $objection . '. Nothing at patient level goes in here.'
            );
        }
        return $clean;
    }
}
