<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * The four public Soft Appeals forms, as one definition.
 *
 * sa-lead.php has carried this list inline since the day it was written. It is
 * still the live endpoint and it is deliberately untouched here: it takes real
 * submissions from real practices every week and it works. What this class adds
 * is a second reader of the same list, for the Desk and for the importer, so
 * neither has to re-type a field name and get it subtly wrong.
 *
 * The two copies are held together by a test, not by good intentions.
 * tests/SoftAppeals/Unit/IntakeFormsTest.php parses sa-lead.php and fails if a
 * form, a field or a label has drifted apart from this file.
 *
 * The PHI boundary. Every field below is business level: how many denials, what
 * they are worth in a band, which payer at the level of a company name. There
 * is no field here for a patient, a member number, a claim number, a date of
 * service or a diagnosis, because the forms do not ask for any of those and the
 * confirmation email tells the person outright not to send them.
 */
final class IntakeForms
{
    /**
     * source key => the form, exactly as sa-lead.php holds it.
     *
     * `owner` is the label that goes in the Subject line she sees, and it is
     * also the line written at the top of every archived lead file, which is
     * how the importer works out which form an old file came from.
     *
     * `fields` is ordered. The confirmation email echoes answers in this order
     * and so does the Desk, so a person reading the drawer sees the same shape
     * they filled in.
     *
     * @return array<string,array{owner:string,subject:string,thanks:string,fields:array<string,string>}>
     */
    public static function all(): array
    {
        return [
            'soft-appeals-maryland' => [
                'owner'   => 'Maryland denial review request',
                'subject' => 'Your denial review request is in',
                'thanks'  => '/soft-appeals-start-thanks',
                'fields'  => [
                    'organization'     => 'Practice or organization',
                    'name'             => 'Your name',
                    'email'            => 'Work email',
                    'role'             => 'Your role',
                    'state'            => 'State',
                    'practice_type'    => 'Practice type',
                    'clinicians'       => 'Clinicians',
                    'denial_age'       => 'Age of the denials',
                    'current_handling' => 'Handled today',
                    'carelon_audit'    => 'Carelon interest check',
                ],
            ],
            'soft-appeals-start' => [
                'owner'   => 'Denial review request',
                'subject' => 'Your denial review request is in',
                'thanks'  => '/soft-appeals-start-thanks',
                'fields'  => [
                    'organization'      => 'Organization',
                    'name'              => 'Your name',
                    'email'             => 'Work email',
                    'role'              => 'Your role',
                    'organization_type' => 'Organization type',
                    'state'             => 'State',
                    'denial_volume'     => 'Denied claims unresolved',
                    'denied_value'      => 'Value involved',
                    'current_handling'  => 'Handled today',
                    'billing_company'   => 'Billing company',
                    'payers'            => 'Payers involved',
                    'denial_types'      => 'Types of denials',
                    'denial_outcomes'   => 'What happens to them',
                    'denial_age'        => 'Age of the denials',
                    'time_sensitive'    => 'Time-sensitive',
                    'goals'             => 'What would make this useful',
                    'context'           => 'Anything else',
                ],
            ],
            'soft-appeals-contact' => [
                'owner'   => 'Question',
                'subject' => 'Your question is in',
                'thanks'  => '/soft-appeals-contact-thanks',
                'fields'  => [
                    'inquiry_type' => 'What you asked about',
                    'name'         => 'Your name',
                    'email'        => 'Work email',
                    'organization' => 'Organization',
                    'role'         => 'Your role',
                    'topics'       => 'Topics',
                    'question'     => 'Your question',
                ],
            ],
            'soft-appeals-due-diligence' => [
                'owner'   => 'Vendor due-diligence request',
                'subject' => 'Your due diligence request is in',
                'thanks'  => '/soft-appeals-contact-thanks',
                'fields'  => [
                    'name'         => 'Your name',
                    'email'        => 'Work email',
                    'organization' => 'Organization',
                    'requester'    => 'Your role in the review',
                    'requested'    => 'Requested',
                    'requirements' => 'Your requirements',
                ],
            ],
        ];
    }

    /**
     * A lead that predates the archive files, reconstructed from the one-line
     * log. It carries only what the log line holds, and it is marked as its own
     * source so the Desk never implies more was captured than actually was.
     */
    public const SOURCE_LEGACY_LOG = 'legacy-log';

    /**
     * An inquiry that arrived as a forwarded email rather than through a
     * form. The intake.mailbox job creates these: sender, subject, the
     * message text, and the names of any attachments. The raw email itself
     * is kept beside the private storage, so nothing the parser did not
     * understand is gone.
     */
    public const SOURCE_EMAIL = 'email-forward';

    /** @return list<string> */
    public static function sources(): array
    {
        return array_keys(self::all());
    }

    public static function isKnown(string $source): bool
    {
        return array_key_exists($source, self::all());
    }

    /** @return array{owner:string,subject:string,thanks:string,fields:array<string,string>}|null */
    public static function form(string $source): ?array
    {
        return self::all()[$source] ?? null;
    }

    /** The label she sees in a Subject line. */
    public static function ownerLabel(string $source): string
    {
        if ($source === self::SOURCE_LEGACY_LOG) {
            return 'Lead line, archive file no longer on the server';
        }
        if ($source === self::SOURCE_EMAIL) {
            return 'Forwarded email';
        }
        return self::all()[$source]['owner'] ?? $source;
    }

    /**
     * The reverse of ownerLabel, which is how an archived lead file is matched
     * back to the form that produced it. Every archived file opens with
     * "Form:  <owner label>", and the four labels are distinct.
     */
    public static function sourceForOwnerLabel(string $label): ?string
    {
        $label = trim($label);
        foreach (self::all() as $source => $form) {
            if (strcasecmp($form['owner'], $label) === 0) {
                return $source;
            }
        }
        return null;
    }

    /**
     * Field key => label, for one form. Used by the drawer to print the
     * original submission with the wording the person actually saw on screen.
     *
     * @return array<string,string>
     */
    public static function labels(string $source): array
    {
        if ($source === self::SOURCE_LEGACY_LOG) {
            // All the log line holds. Three labels, and no pretence that a
            // fourth answer is sitting somewhere unread.
            return [
                'name'         => 'Their name',
                'email'        => 'Work email',
                'organization' => 'Organization',
            ];
        }
        if ($source === self::SOURCE_EMAIL) {
            // What an email actually carries. No band, no state, no role:
            // the drawer prints "not asked" for those, which is the truth.
            return [
                'name'        => 'Their name',
                'email'       => 'From',
                'subject'     => 'Subject',
                'message'     => 'Their message',
                'attachments' => 'Attachments',
            ];
        }
        return self::all()[$source]['fields'] ?? [];
    }

    /**
     * The reverse, for one form: label => field key. The archived lead files
     * store labels rather than keys, so this is how an old file is read back
     * into named answers.
     *
     * @return array<string,string>
     */
    public static function keysByLabel(string $source): array
    {
        $out = [];
        foreach (self::labels($source) as $key => $label) {
            $out[$label] = $key;
        }
        return $out;
    }

    /**
     * The long fields, which keep their paragraph breaks. Same list sa-lead.php
     * uses, and the reason a pasted question survives the round trip.
     *
     * @return list<string>
     */
    public static function longFields(): array
    {
        return ['question', 'requirements', 'context', 'current_handling'];
    }

    /**
     * Answers, reduced to the handful of business-level facts the Desk sorts
     * and filters on. Everything else stays in the payload and is shown in the
     * drawer rather than promoted to a column.
     *
     * Nothing is inferred. A form that does not ask how many denials there are
     * produces a null volume band, and the Desk prints "not asked" rather than
     * a guess, because a guessed band on a fit decision is worse than a blank.
     *
     * @param array<string,string> $answers
     * @return array{
     *   organization_name:string, contact_name:string, contact_email:string,
     *   contact_role:?string, state:?string, organization_type:?string,
     *   denial_volume_band:?string, denied_value_band:?string, time_sensitive:bool
     * }
     */
    public static function summarize(string $source, array $answers): array
    {
        $pick = static function (array $keys) use ($answers): ?string {
            foreach ($keys as $key) {
                $value = trim((string) ($answers[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
            return null;
        };

        $timeSensitive = strtolower((string) ($answers['time_sensitive'] ?? ''));

        return [
            'organization_name'  => $pick(['organization']) ?? 'Not given',
            'contact_name'       => $pick(['name']) ?? '',
            'contact_email'      => strtolower($pick(['email']) ?? ''),
            'contact_role'       => $pick(['role', 'requester']),
            'state'              => self::stateCode($pick(['state'])),
            'organization_type'  => $pick(['organization_type', 'practice_type']),
            // Only the field that actually asks. The Maryland form asks how
            // many clinicians there are, which is a headcount and not a denial
            // volume, and reading one as the other is the sort of quiet guess
            // that then decides a fit.
            'denial_volume_band' => $pick(['denial_volume']),
            'denied_value_band'  => $pick(['denied_value']),
            // The one derived value, and it is derived from an exact string the
            // form itself supplies rather than from a keyword hunt.
            'time_sensitive'     => str_contains($timeSensitive, 'approaching deadlines'),
        ];
    }

    /**
     * The two-letter code for a state the form names in full. An unknown value
     * returns null rather than a truncated guess: "Ma" for "Massachusetts" and
     * "Ma" for "Maryland" are the same two characters and a wrong state on a
     * Maryland-statute method is not a small error.
     */
    public static function stateCode(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z]{2}$/', $name) === 1) {
            return strtoupper($name);
        }
        $map = [
            'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
            'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE',
            'district of columbia' => 'DC', 'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI',
            'idaho' => 'ID', 'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA',
            'kansas' => 'KS', 'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME',
            'maryland' => 'MD', 'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN',
            'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE',
            'nevada' => 'NV', 'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM',
            'new york' => 'NY', 'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH',
            'oklahoma' => 'OK', 'oregon' => 'OR', 'pennsylvania' => 'PA', 'puerto rico' => 'PR',
            'rhode island' => 'RI', 'south carolina' => 'SC', 'south dakota' => 'SD',
            'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT',
            'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV',
            'wisconsin' => 'WI', 'wyoming' => 'WY',
        ];
        return $map[strtolower($name)] ?? null;
    }
}
