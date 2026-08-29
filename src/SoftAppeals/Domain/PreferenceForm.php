<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * The eight onboarding questions, as one definition.
 *
 * Section 13.2 of the plan lists them and this is that list, in that order,
 * with the wording a practice actually reads. The page renders from here and
 * the server validates against here, so a choice that is not offered cannot be
 * stored by posting it, and a question cannot drift between the screen and the
 * check.
 *
 * Three of the questions ask for a person: who signs the Business Associate
 * Agreement, who may approve a payer submission, and who should see recovery
 * and invoice information. Each of those is a name, a role and a work email,
 * and each becomes a contact with exactly one client role. That is section 8.2
 * enforced at the point the practice tells us, rather than a mapping somebody
 * has to remember to apply afterwards.
 *
 * The free-text questions carry a cap and a screen. The cap is 500 characters,
 * which is long enough for "Blue Cross commercial, mostly outpatient behavioral
 * health" and short enough that nobody pastes a spreadsheet into it. The screen
 * is deliberately narrow: it looks for the shapes that identify a person, and
 * it says which shape it found, because a refusal that will not say what it
 * objected to is a refusal a person cannot act on.
 */
final class PreferenceForm
{
    /** The most a free-text answer may hold. */
    public const FREE_TEXT_MAX = 500;

    /** The most a name, role or address field may hold. */
    public const NAME_MAX  = 160;
    public const ROLE_MAX  = 120;
    public const EMAIL_MAX = 200;

    /** The warning that sits above every free-text field. Section 13.2. */
    public const PHI_WARNING = 'Do not enter patient, member, claim, clinical, '
        . 'or other protected health information.';

    // Question 6.
    public const PARTNER_YES    = 'yes';
    public const PARTNER_NO     = 'no';
    public const PARTNER_UNSURE = 'unsure';

    /**
     * Question 1, in the second person.
     *
     * EngagementTerms holds the same four keys with the labels she reads on the
     * Desk. These are the labels a practice reads, and they are separate because
     * "At major milestones only" is a setting and "Only when something actually
     * happens" is an answer to a question. The keys are the same constants, so
     * there is one vocabulary and two ways of saying it.
     *
     * @return array<string,string>
     */
    public static function cadenceChoices(): array
    {
        return [
            EngagementTerms::CADENCE_WEEKLY     => 'Weekly',
            EngagementTerms::CADENCE_BIWEEKLY   => 'Every two weeks',
            EngagementTerms::CADENCE_MONTHLY    => 'Monthly',
            EngagementTerms::CADENCE_MILESTONES => 'Only at major milestones',
        ];
    }

    /**
     * Question 5, in the second person.
     *
     * @return array<string,array{label:string,help:string}>
     */
    public static function channelChoices(): array
    {
        return [
            EngagementTerms::CHANNEL_CLIENT_SYSTEM => [
                'label' => 'Use our own approved environment',
                'help'  => 'Your portal, your secure file transfer, your system. '
                    . 'We work inside what your organization already approved.',
            ],
            EngagementTerms::CHANNEL_SOFT_APPEALS => [
                'label' => 'Use the Soft Appeals secure transfer option',
                'help'  => 'Set up for you, reviewed by your compliance people '
                    . 'before anything moves through it.',
            ],
            EngagementTerms::CHANNEL_DECIDE_LATER => [
                'label' => 'Decide with our compliance or IT people',
                'help'  => 'Nothing is chosen yet. This goes on the list to settle '
                    . 'before the agreement is signed.',
            ],
        ];
    }

    /**
     * The secure route as a noun, for reading back rather than choosing.
     *
     * channelChoices above is phrased as an instruction because it sits next to
     * a radio button. This is what the same answer is called afterwards, on the
     * confirmation page and in the Recovery Room. EngagementTerms has a third
     * wording, "Their own approved environment", which is hers and is correct on
     * the Desk. Three phrasings of one stored value, one for each person who
     * reads it, and none of them shown to the wrong one.
     */
    public static function channelClientLabel(?string $value): string
    {
        return match ($value) {
            EngagementTerms::CHANNEL_CLIENT_SYSTEM => 'Your own approved environment',
            EngagementTerms::CHANNEL_SOFT_APPEALS  => 'The Soft Appeals secure transfer option',
            EngagementTerms::CHANNEL_DECIDE_LATER  => 'Being decided with your compliance or IT',
            default                                => 'Not chosen yet',
        };
    }

    /** @return array<string,string> */
    public static function billingPartners(): array
    {
        return [
            self::PARTNER_YES    => 'Yes',
            self::PARTNER_NO     => 'No',
            self::PARTNER_UNSURE => 'Unsure',
        ];
    }

    public static function isValidPartner(string $value): bool
    {
        return array_key_exists($value, self::billingPartners());
    }

    public static function partnerLabel(?string $value): string
    {
        return $value === null ? 'Not answered' : (self::billingPartners()[$value] ?? $value);
    }

    /**
     * The three people the form asks for.
     *
     * key => the field prefix on the form, the question wording, the role the
     * person is granted, and whether an answer is required.
     *
     * The signer is required because the next step after preferences is the
     * Business Associate Agreement, and an agreement with nobody named to sign
     * it is a step that cannot begin. The other two are optional: a practice
     * that has not decided yet should be able to finish this page rather than
     * abandon it, and both can be added later.
     *
     * @return array<string,array{label:string,help:string,role:string,required:bool}>
     */
    public static function contactQuestions(): array
    {
        return [
            'signer' => [
                'label'    => 'Who is authorized to sign the Business Associate Agreement?',
                'help'     => 'This is the person who can bind the organization. '
                    . 'They receive the agreement to sign, and nothing else.',
                'role'     => Role::AUTHORIZED_SIGNER,
                'required' => true,
            ],
            'approver' => [
                'label'    => 'Who may approve payer submissions if recovery work begins?',
                'help'     => 'Only if you go ahead with recovery work after the assessment. '
                    . 'Leave it blank if that has not been decided.',
                'role'     => Role::SUBMISSION_APPROVER,
                'required' => false,
            ],
            'billing' => [
                'label'    => 'Who should receive recovery and invoice information?',
                'help'     => 'Often billing or finance. Leave it blank if it is the '
                    . 'same person who signs, and nobody new is created.',
                'role'     => Role::BILLING,
                'required' => false,
            ],
        ];
    }

    /**
     * The two free-text questions, in order, with their captions.
     *
     * @return array<string,array{label:string,help:string,required:bool}>
     */
    public static function freeTextQuestions(): array
    {
        return [
            'initial_payer_group' => [
                'label'    => 'Which payer or denial group should the first sample represent?',
                'help'     => 'A payer name and a kind of denial is enough. '
                    . 'No claim numbers and no patients.',
                'required' => false,
            ],
            'procurement_notes' => [
                'label'    => 'Does your organization have procurement, insurance, security '
                    . 'or contract requirements to review before onboarding?',
                'help'     => 'A vendor form, a certificate of insurance, a security '
                    . 'questionnaire, a purchase order. Say which and it gets handled first.',
                'required' => false,
            ],
        ];
    }

    /**
     * Whether a free-text answer looks like it carries a person in it.
     *
     * Narrow on purpose. Every pattern below identifies an individual and none
     * of them appears in a legitimate answer to either question:
     *
     *   a run of nine or more digits   a member, claim, or social security number
     *   NNN-NN-NNNN                    a social security number, formatted
     *   a date of birth, however written
     *   MRN, followed by anything      a medical record number
     *
     * A payer name, a denial type, a dollar amount and a date range all pass.
     * The return is the phrase to show the person, or null when the answer is
     * fine, because "that looks like a member number" is actionable and "that
     * was rejected" is not.
     */
    public static function phiObjection(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/\b\d{3}-\d{2}-\d{4}\b/', $text) === 1) {
            return 'that looks like a social security number';
        }
        if (preg_match('/\b(mrn|medical record (no|number)|patient id)\b/i', $text) === 1) {
            return 'that looks like a medical record number';
        }
        if (preg_match('/\b(date of birth|d\.?o\.?b\.?)\b/i', $text) === 1) {
            return 'that looks like a date of birth';
        }
        // Nine digits or more in a row, with spaces and hyphens ignored the way
        // a person types a long number. A year, a dollar figure and a phone
        // number are all shorter than this.
        if (preg_match('/\d[\d\s-]{7,}\d/', $text) === 1) {
            $digits = preg_replace('/\D/', '', $text) ?? '';
            if (strlen($digits) >= 9) {
                return 'that looks like a member or claim number';
            }
        }

        return null;
    }

    /**
     * Clean one free-text answer: control characters out, whitespace tidied,
     * capped at the length the form promises.
     */
    public static function cleanFreeText(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? '';
        $value = preg_replace('/\n{3,}/', "\n\n", $value) ?? '';
        return mb_substr(trim($value), 0, self::FREE_TEXT_MAX);
    }

    /** Clean a single-line answer: a name, a role title, an address. */
    public static function cleanLine(string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        return mb_substr(trim($value), 0, $max);
    }
}
