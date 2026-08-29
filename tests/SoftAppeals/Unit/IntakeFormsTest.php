<?php
declare(strict_types=1);

/**
 * The public forms, and the one thing that can quietly break them.
 *
 * sa-lead.php holds the four forms inline and is the live endpoint taking real
 * submissions. Domain\IntakeForms holds the same list for the Desk and the
 * importer. Two copies of anything drift, and this drift would be invisible:
 * the form would keep working, the confirmation email would keep going out, and
 * the Desk would quietly stop reading one answer, or the importer would fail to
 * match an archive file to its form and skip a lead.
 *
 * So the first test parses sa-lead.php and compares. It is the reason the two
 * copies are allowed to exist.
 */

use SoftAppeals\Bootstrap;
use SoftAppeals\Domain\IntakeForms;

/**
 * Pull one form's field list out of sa-lead.php as it is actually written.
 *
 * @return array<string,string> field key => label
 */
$fieldsFromEndpoint = static function (string $source): array {
    static $endpoint = null;
    $endpoint ??= (string) file_get_contents(dirname(__DIR__, 3) . '/sa-lead.php');

    $start = strpos($endpoint, "'" . $source . "' => [");
    if ($start === false) {
        return [];
    }
    $fieldsAt = strpos($endpoint, "'fields'", $start);
    if ($fieldsAt === false) {
        return [];
    }
    // The field list ends at the first line that closes an array at the
    // indentation sa-lead.php uses for it. That file's shape has not changed
    // since it was written, and if it does this test fails loudly rather than
    // reading the wrong block.
    $end = strpos($endpoint, "\n    ],", $fieldsAt);
    $block = substr($endpoint, $fieldsAt, ($end === false ? strlen($endpoint) : $end) - $fieldsAt);

    $out = [];
    if (preg_match_all("/'([a-z_]+)'\s*=>\s*'([^']*)'/", $block, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            $out[$match[1]] = $match[2];
        }
    }
    return $out;
};

return [

    'the form definitions have not drifted apart from the live endpoint' =>
        static function (Bootstrap $app) use ($fieldsFromEndpoint): void {
            foreach (IntakeForms::sources() as $source) {
                $live = $fieldsFromEndpoint($source);
                Expect::false(
                    $live === [],
                    'sa-lead.php should still define ' . $source
                );

                $mine = IntakeForms::labels($source);
                Expect::same(
                    array_keys($live),
                    array_keys($mine),
                    $source . ': the field list and its order must match sa-lead.php'
                );
                foreach ($live as $key => $label) {
                    Expect::same(
                        $label,
                        $mine[$key] ?? null,
                        $source . '.' . $key . ': the label must match the one the person saw'
                    );
                }
            }
        },

    'the owner label of every form is unique, because the importer matches on it' =>
        static function (Bootstrap $app): void {
            $labels = [];
            foreach (IntakeForms::all() as $source => $form) {
                $label = $form['owner'];
                Expect::false(
                    isset($labels[$label]),
                    'two forms share the owner label "' . $label . '", so an archived lead cannot be traced back'
                );
                $labels[$label] = $source;
                Expect::same(
                    $source,
                    IntakeForms::sourceForOwnerLabel($label),
                    'the label must map back to its own source'
                );
            }
        },

    'a state name becomes a code, and an ambiguous one becomes nothing' =>
        static function (Bootstrap $app): void {
            Expect::same('MD', IntakeForms::stateCode('Maryland'), 'her own state');
            Expect::same('MA', IntakeForms::stateCode('Massachusetts'), 'the one that starts the same way');
            Expect::same('DC', IntakeForms::stateCode('District of Columbia'), 'not a state, still a place');
            Expect::same('PA', IntakeForms::stateCode('PA'), 'a code stays a code');
            Expect::null(IntakeForms::stateCode('Narnia'), 'an unknown place is null, never a guess');
            Expect::null(IntakeForms::stateCode(''), 'blank is null');
            Expect::null(IntakeForms::stateCode(null), 'null is null');
        },

    'the summary promotes only what the form actually asked' =>
        static function (Bootstrap $app): void {
            $answers = [
                'organization'  => 'Fictional Behavioral Health',
                'name'          => 'A Person',
                'email'         => 'A.Person@Example.ORG',
                'role'          => 'Billing or revenue cycle',
                'state'         => 'Maryland',
                'clinicians'    => '6 to 10',
                'practice_type' => 'Behavioral health',
            ];
            $summary = IntakeForms::summarize('soft-appeals-maryland', $answers);

            Expect::same('a.person@example.org', $summary['contact_email'], 'the address is normalised');
            Expect::same('MD', $summary['state'], 'the state becomes a code');
            Expect::same('Behavioral health', $summary['organization_type'], 'practice type is the type');
            Expect::null(
                $summary['denial_volume_band'],
                'the Maryland form never asks a denial volume, and a clinician headcount is not one'
            );
            Expect::null($summary['denied_value_band'], 'nor a value');
            Expect::false($summary['time_sensitive'], 'nothing flagged a deadline');
        },

    'the time-sensitive flag comes from the answer, not from a keyword hunt' =>
        static function (Bootstrap $app): void {
            $base = ['organization' => 'X', 'name' => 'A', 'email' => 'a@example.org'];

            $flagged = IntakeForms::summarize(
                'soft-appeals-start',
                $base + ['time_sensitive' => 'Yes, some have approaching deadlines']
            );
            Expect::true($flagged['time_sensitive'], 'the exact answer sets the flag');

            $notChecked = IntakeForms::summarize(
                'soft-appeals-start',
                $base + ['time_sensitive' => 'We have not checked']
            );
            Expect::false($notChecked['time_sensitive'], 'not checked is not the same as urgent');

            $absent = IntakeForms::summarize('soft-appeals-start', $base);
            Expect::false($absent['time_sensitive'], 'an unanswered question is not urgent');
        },

    'a lead recovered from the log says what it is' =>
        static function (Bootstrap $app): void {
            Expect::same(
                ['name' => 'Their name', 'email' => 'Work email', 'organization' => 'Organization'],
                IntakeForms::labels(IntakeForms::SOURCE_LEGACY_LOG),
                'three labels, and no pretence that a fourth answer exists'
            );
            Expect::false(
                IntakeForms::isKnown(IntakeForms::SOURCE_LEGACY_LOG),
                'it is not one of the four public forms and must not claim to be'
            );
        },
];
