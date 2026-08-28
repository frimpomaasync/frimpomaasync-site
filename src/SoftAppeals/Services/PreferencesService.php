<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Database;
use SoftAppeals\Domain\EngagementTerms;
use SoftAppeals\Domain\PreferenceForm;
use SoftAppeals\Domain\Stage;
use SoftAppeals\Repositories\ContactRepository;
use SoftAppeals\Repositories\EngagementRepository;
use SoftAppeals\Repositories\MembershipRepository;
use SoftAppeals\Repositories\PreferenceRepository;
use SoftAppeals\Repositories\StatusEventRepository;
use SoftAppeals\Repositories\UserRepository;

/**
 * The eight answers: checked, stored, and turned into the people who can act.
 *
 * Three things happen here and they happen in one transaction, because two of
 * them are useless without the third. The answers are stored. The people the
 * practice named become contacts with one client role each. The engagement
 * moves to "preferences confirmed", once.
 *
 * "Once" is the part worth stating plainly. A practice that comes back to the
 * page and changes its cadence updates the row and does not re-confirm: the
 * stage does not move a second time, no second timeline entry is written, and
 * no second email goes out. The confirmation stamp on the preferences row is
 * what carries that, and the UNIQUE constraint on engagement_id is what makes
 * it impossible to route around by submitting twice.
 *
 * Nothing here trusts the browser for who is asking. The engagement and the
 * organization are handed in by the page from the session, which got them from
 * a redeemed invitation. A posted organization id would be a way for one
 * practice to answer for another.
 */
final class PreferencesService
{
    public const TEMPLATE_KEY = 'preferences_confirmed';
    public const SUBJECT      = 'Your Soft Appeals onboarding preferences are confirmed';

    private Database $db;
    private PreferenceRepository $preferences;
    private ContactRepository $contacts;
    private UserRepository $users;
    private MembershipRepository $memberships;
    private EngagementRepository $engagements;
    private EngagementService $engagementService;
    private StatusEventRepository $timeline;
    private MailService $mail;
    private AuditService $audit;

    public function __construct(
        Database $db,
        PreferenceRepository $preferences,
        ContactRepository $contacts,
        UserRepository $users,
        MembershipRepository $memberships,
        EngagementRepository $engagements,
        EngagementService $engagementService,
        StatusEventRepository $timeline,
        MailService $mail,
        AuditService $audit
    ) {
        $this->db = $db;
        $this->preferences = $preferences;
        $this->contacts = $contacts;
        $this->users = $users;
        $this->memberships = $memberships;
        $this->engagements = $engagements;
        $this->engagementService = $engagementService;
        $this->timeline = $timeline;
        $this->mail = $mail;
        $this->audit = $audit;
    }

    /**
     * Check one submission without writing anything.
     *
     * Returns the answers in the shape the repository stores, the three people
     * separately, and one message per field that is wrong. The page renders the
     * messages beside the fields and puts every answer back, so a practice that
     * gets one thing wrong does not retype the other seven.
     *
     * @param array<string,mixed> $posted
     * @return array{
     *     answers:array<string,mixed>,
     *     people:array<string,array{name:string,role:string,email:string}>,
     *     errors:array<string,string>
     * }
     */
    public function validate(array $posted): array
    {
        $errors = [];
        $answers = [];

        $cadence = self::text($posted, 'communication_cadence');
        if (!EngagementTerms::isValidCadence($cadence)) {
            $errors['communication_cadence'] = 'Choose how often you want to hear from us.';
        }
        $answers['communication_cadence'] = $cadence;

        $channel = self::text($posted, 'secure_channel');
        if (!EngagementTerms::isValidChannel($channel)) {
            $errors['secure_channel'] = 'Choose which secure route should be looked at.';
        }
        $answers['secure_channel'] = $channel;

        $partner = self::text($posted, 'billing_partner');
        if (!PreferenceForm::isValidPartner($partner)) {
            $errors['billing_partner'] = 'Say whether a billing or revenue-cycle partner is involved.';
        }
        $answers['billing_partner'] = $partner;

        $people = [];
        foreach (PreferenceForm::contactQuestions() as $key => $question) {
            $name  = PreferenceForm::cleanLine(self::text($posted, $key . '_name'), PreferenceForm::NAME_MAX);
            $role  = PreferenceForm::cleanLine(self::text($posted, $key . '_role'), PreferenceForm::ROLE_MAX);
            $email = PreferenceForm::cleanLine(self::text($posted, $key . '_email'), PreferenceForm::EMAIL_MAX);
            $email = strtolower($email);

            $people[$key] = ['name' => $name, 'role' => $role, 'email' => $email];

            $anything = $name !== '' || $role !== '' || $email !== '';

            if (!$anything) {
                if ($question['required']) {
                    $errors[$key] = 'Name the person who can sign, with their work email.';
                }
                continue;
            }

            // Part-filled is an error rather than a silent partial record. A
            // contact with no address cannot be sent an agreement, and one with
            // no name cannot be identified on a signature page.
            if ($name === '') {
                $errors[$key] = 'Add their name.';
                continue;
            }
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$key] = 'Add a work email that can receive the agreement.';
                continue;
            }
        }

        foreach (PreferenceForm::freeTextQuestions() as $key => $question) {
            $raw = self::text($posted, $key);
            $clean = PreferenceForm::cleanFreeText($raw);

            // The cap is enforced here, not only in the textarea's maxlength.
            // A browser attribute is a courtesy; the server is the rule.
            if (mb_strlen(trim($raw)) > PreferenceForm::FREE_TEXT_MAX) {
                $errors[$key] = 'Keep this under ' . PreferenceForm::FREE_TEXT_MAX
                    . ' characters. Yours is ' . mb_strlen(trim($raw)) . '.';
            }

            $objection = PreferenceForm::phiObjection($clean);
            if ($objection !== null) {
                $errors[$key] = 'Take that out: ' . $objection . '. '
                    . PreferenceForm::PHI_WARNING;
            }

            $answers[$key] = $clean === '' ? null : $clean;
        }

        return ['answers' => $answers, 'people' => $people, 'errors' => $errors];
    }

    /**
     * Store the answers, create the people, and move the engagement once.
     *
     * @param array<string,mixed> $engagement a row joined with its organization
     * @param array<string,mixed> $posted
     * @return array{
     *     saved:bool,
     *     errors:array<string,string>,
     *     first_confirmation:bool,
     *     people:array<string,array{name:string,role:string,email:string}>
     * }
     */
    public function confirm(
        array $engagement,
        array $posted,
        ?string $actorUserId,
        ?string $actorContactId
    ): array {
        $checked = $this->validate($posted);
        if ($checked['errors'] !== []) {
            $this->audit->record(
                'preferences.confirm',
                'failure',
                'engagement',
                (string) $engagement['id'],
                ['reason' => 'the answers did not pass', 'count' => count($checked['errors'])],
                (string) $engagement['organization_id']
            );
            return [
                'saved'              => false,
                'errors'             => $checked['errors'],
                'first_confirmation' => false,
                'people'             => $checked['people'],
            ];
        }

        $engagementId = (string) $engagement['id'];
        $organizationId = (string) $engagement['organization_id'];

        $outcome = $this->db->transaction(function () use (
            $engagement,
            $engagementId,
            $organizationId,
            $checked,
            $actorUserId,
            $actorContactId
        ): array {
            $answers = $checked['answers'];

            // The three named people. Each becomes a contact, a user with no
            // password, and exactly one client role in this organization.
            foreach (PreferenceForm::contactQuestions() as $key => $question) {
                $person = $checked['people'][$key];
                if ($person['email'] === '' || $person['name'] === '') {
                    $answers[$key . '_contact_id'] = null;
                    continue;
                }

                $contact = $this->contacts->upsert(
                    $organizationId,
                    $person['name'],
                    $person['email'],
                    $person['role'] === '' ? null : $person['role']
                );

                $user = $this->users->findByEmail($person['email']);
                $userId = $user === null
                    ? $this->users->create($person['email'], null, $contact['id'])
                    : (string) $user['id'];

                $this->memberships->grant($userId, $question['role'], $organizationId);

                $answers[$key . '_contact_id'] = $contact['id'];
            }

            // Question 5 is also the engagement's secure-channel choice, and it
            // is stored in both places on purpose: the preferences row is what
            // the practice said, and the engagement column is what the work runs
            // on. Writing only one of them would leave the Desk showing a route
            // nobody chose.
            $this->engagements->setTerms(
                $engagementId,
                (string) $engagement['fee_basis'],
                (string) $answers['secure_channel'],
                $engagement['assessment_window'] === null
                    ? null
                    : (string) $engagement['assessment_window'],
                (string) $answers['communication_cadence']
            );

            $saved = $this->preferences->save(
                $engagementId,
                $organizationId,
                $answers,
                $actorContactId
            );

            if ($saved['first_confirmation']) {
                $stage = (string) $engagement['stage'];
                if ($stage === Stage::TERMS_SENT) {
                    $this->engagementService->move(
                        $engagementId,
                        Stage::PREFERENCES_CONFIRMED,
                        'You confirmed your onboarding preferences.',
                        'preferences.confirmed',
                        $actorUserId,
                        [
                            'cadence'        => (string) $answers['communication_cadence'],
                            'secure_channel' => (string) $answers['secure_channel'],
                        ],
                        null,
                        StatusEventRepository::ACTOR_CLIENT
                    );
                } else {
                    // The stage had already moved past terms sent, so this is a
                    // late confirmation rather than the one that advances it.
                    // The timeline still gets the line, because the practice
                    // did the thing and a gap in their own history is worse
                    // than a stage that did not need to move.
                    $this->timeline->record(
                        $engagementId,
                        'preferences.confirmed',
                        'You confirmed your onboarding preferences.',
                        $stage,
                        $stage,
                        StatusEventRepository::ACTOR_CLIENT,
                        $actorUserId,
                        ['cadence' => (string) $answers['communication_cadence']]
                    );
                }
            }

            return $saved;
        });

        $this->audit->record(
            'preferences.confirm',
            'success',
            'engagement',
            $engagementId,
            ['reason' => $outcome['first_confirmation'] ? 'confirmed' : 'updated'],
            $organizationId
        );

        // The email is sent outside the transaction. A mail server that is slow
        // must not hold a database write open, and a mail server that refuses
        // must not undo a decision the practice actually made.
        if ($outcome['first_confirmation']) {
            $this->sendConfirmation($engagement, $checked['people']);
        }

        return [
            'saved'              => true,
            'errors'             => [],
            'first_confirmation' => $outcome['first_confirmation'],
            'people'             => $checked['people'],
        ];
    }

    /**
     * What the practice chose, in the words it chose them in.
     *
     * Built here rather than in each page, because the confirmation screen and
     * the Recovery Room both show it and two copies of this list would drift.
     * A question that was left blank is left out rather than printed empty: an
     * optional answer nobody gave is not a fact worth a row.
     *
     * @return list<array{label:string,value:string}>
     */
    public function summary(string $engagementId): array
    {
        $row = $this->preferences->forEngagement($engagementId);
        if ($row === null) {
            return [];
        }

        $out = [];
        $out[] = [
            'label' => 'Updates',
            'value' => EngagementTerms::cadenceLabel((string) $row['communication_cadence']),
        ];
        $out[] = [
            'label' => 'Secure route',
            'value' => EngagementTerms::channelLabel((string) $row['secure_channel']),
        ];

        foreach (PreferenceForm::contactQuestions() as $key => $question) {
            $contactId = $row[$key . '_contact_id'] ?? null;
            if ($contactId === null) {
                continue;
            }
            $contact = $this->contacts->find((string) $contactId);
            if ($contact === null) {
                continue;
            }
            $name = (string) $contact['name'];
            $title = (string) ($contact['role_title'] ?? '');
            $out[] = [
                'label' => match ($key) {
                    'signer'   => 'Signing the agreements',
                    'approver' => 'Approving submissions',
                    'billing'  => 'Recovery and invoices',
                    default    => $key,
                },
                'value' => $title === '' ? $name : $name . ' · ' . $title,
            ];
        }

        $out[] = [
            'label' => 'Billing or revenue-cycle partner',
            'value' => PreferenceForm::partnerLabel((string) $row['billing_partner']),
        ];

        if (($row['initial_payer_group'] ?? null) !== null && trim((string) $row['initial_payer_group']) !== '') {
            $out[] = [
                'label' => 'First sample',
                'value' => (string) $row['initial_payer_group'],
            ];
        }
        if (($row['procurement_notes'] ?? null) !== null && trim((string) $row['procurement_notes']) !== '') {
            $out[] = [
                'label' => 'To review before onboarding',
                'value' => (string) $row['procurement_notes'],
            ];
        }

        return $out;
    }

    /**
     * Section 16.2, template 4. Plain text, no link, no attachment.
     *
     * @param array<string,mixed> $engagement
     * @param array<string,array{name:string,role:string,email:string}> $people
     */
    private function sendConfirmation(array $engagement, array $people): void
    {
        $engagementId = (string) $engagement['id'];
        $preferences = $this->preferences->forEngagement($engagementId);
        $recipient = $this->recipientFor($engagement);
        if ($recipient === null) {
            return;
        }

        $organization = (string) ($engagement['display_name'] ?? $engagement['legal_name'] ?? '');
        $signer = $people['signer']['name'] ?? '';

        $lines = [];
        $lines[] = 'Thank you. Your onboarding preferences are confirmed for '
            . ($organization === '' ? 'your practice' : $organization) . '.';
        $lines[] = '';
        $lines[] = 'What you chose:';
        $lines[] = '';
        $lines[] = '  Updates: ' . EngagementTerms::cadenceLabel(
            $preferences === null ? null : (string) $preferences['communication_cadence']
        );
        $lines[] = '  Secure route: ' . EngagementTerms::channelLabel(
            $preferences === null ? null : (string) $preferences['secure_channel']
        );
        if ($signer !== '') {
            $lines[] = '  Signing the Business Associate Agreement: ' . $signer;
        }
        $lines[] = '';
        $lines[] = 'The next step is the Business Associate Agreement and the';
        $lines[] = 'complimentary-review authorization. Both come to you to read and';
        $lines[] = 'sign, and neither costs anything.';
        $lines[] = '';
        $lines[] = 'Do not send claim information yet. Nothing at patient level moves';
        $lines[] = 'until both of those are executed and the secure route is open.';
        $lines[] = '';
        $lines[] = 'Nana Frimpongmaa';
        $lines[] = 'Soft Appeals';

        $this->mail->send(
            $recipient,
            self::SUBJECT,
            implode("\n", $lines) . "\n",
            self::TEMPLATE_KEY,
            $engagementId,
            (string) $engagement['organization_id'],
            hash('sha256', $engagementId . '|' . self::TEMPLATE_KEY)
        );
    }

    /**
     * Who the confirmation goes to: the address the invitation was sent to,
     * which is the person who just filled the form in.
     *
     * @param array<string,mixed> $engagement
     */
    private function recipientFor(array $engagement): ?string
    {
        $row = $this->db->one(
            'SELECT contact_email FROM sa_invitations'
            . ' WHERE engagement_id = :e AND purpose = :p'
            . ' ORDER BY created_at DESC',
            ['e' => (string) $engagement['id'], 'p' => 'preferences']
        );
        if ($row !== null && (string) $row['contact_email'] !== '') {
            return (string) $row['contact_email'];
        }

        $intake = $engagement['intake_id'] === null ? null : $this->db->one(
            'SELECT contact_email FROM sa_intakes WHERE id = :i',
            ['i' => (string) $engagement['intake_id']]
        );
        return $intake === null ? null : (string) $intake['contact_email'];
    }

    /** @param array<string,mixed> $posted */
    private static function text(array $posted, string $key): string
    {
        $value = $posted[$key] ?? '';
        return is_string($value) ? $value : '';
    }
}
