<?php
declare(strict_types=1);

/**
 * Phase 5 acceptance, the first line: "the portal contains no patient-level
 * fields or upload element".
 *
 * Proved by reading the client templates rather than by trusting them. Every
 * file under templates/soft-appeals/client is scanned for a file input, a
 * multipart form, and any form field whose name suggests a person or a claim.
 * A future template that broke the boundary would fail here before it
 * shipped, which is the only place a rule like this can be enforced without
 * a reviewer remembering it.
 *
 * The same scan runs over the two client page controllers, because a field
 * a template never draws can still be read off $_POST.
 */

use SoftAppeals\Bootstrap;

$root = dirname(__DIR__, 3);

$clientFiles = static function () use ($root): array {
    $files = glob($root . '/templates/soft-appeals/client/*.php') ?: [];
    foreach ([
        'soft-appeals-room.php',
        'soft-appeals-preferences.php',
        'soft-appeals-confirmed.php',
        'soft-appeals-sign.php',
    ] as $page) {
        if (is_file($root . '/' . $page)) {
            $files[] = $root . '/' . $page;
        }
    }
    sort($files);
    return $files;
};

/** The words a field name must not carry. Section 5. */
$forbiddenNames = [
    'patient', 'member', 'mrn', 'dob', 'birth', 'ssn', 'social',
    'claim_number', 'claim_id', 'claim_no', 'diagnosis', 'icd', 'cpt',
    'date_of_service', 'dos', 'subscriber', 'policy_number',
];

return [

    'no client template or page carries an upload element' =>
        static function (Bootstrap $app) use ($clientFiles): void {
            $files = $clientFiles();
            Expect::true(count($files) >= 8, 'the client templates should be found: ' . count($files));
            foreach ($files as $file) {
                $source = strtolower((string) file_get_contents($file));
                Expect::false(
                    str_contains($source, 'type="file"') || str_contains($source, "type='file'"),
                    basename($file) . ' carries a file input'
                );
                Expect::false(
                    str_contains($source, 'multipart/form-data'),
                    basename($file) . ' declares a multipart form'
                );
                Expect::false(
                    str_contains($source, '$_files'),
                    basename($file) . ' reads uploaded files'
                );
            }
        },

    'no client form field is named for a person or a claim' =>
        static function (Bootstrap $app) use ($clientFiles, $forbiddenNames): void {
            foreach ($clientFiles() as $file) {
                $source = (string) file_get_contents($file);
                preg_match_all('/\bname\s*=\s*["\']([^"\']+)["\']/i', $source, $matches);
                foreach ($matches[1] as $name) {
                    $lower = strtolower($name);
                    foreach ($forbiddenNames as $word) {
                        Expect::false(
                            str_contains($lower, $word),
                            basename($file) . ' has a field named "' . $name . '", which reads as ' . $word
                        );
                    }
                }
                preg_match_all('/\$_POST\[\s*["\']([^"\']+)["\']\s*\]/', $source, $posted);
                foreach ($posted[1] as $name) {
                    $lower = strtolower($name);
                    foreach ($forbiddenNames as $word) {
                        Expect::false(
                            str_contains($lower, $word),
                            basename($file) . ' reads $_POST["' . $name . '"], which reads as ' . $word
                        );
                    }
                }
            }
        },

    'the decision form offers exactly the four choices the plan names' =>
        static function (Bootstrap $app): void {
            $all = \SoftAppeals\Domain\ClientDecision::all();
            Expect::same(4, count($all), 'four choices');
            foreach ([
                \SoftAppeals\Domain\ClientDecision::INTERNAL_USE,
                \SoftAppeals\Domain\ClientDecision::MORE_INFORMATION,
                \SoftAppeals\Domain\ClientDecision::RECOVERY_SCOPE,
                \SoftAppeals\Domain\ClientDecision::NO_FURTHER_ACTION,
            ] as $choice) {
                Expect::true(in_array($choice, $all, true), $choice . ' should be offered');
            }
            Expect::same(\SoftAppeals\Domain\Stage::RECOVERY_SCOPE_SELECTED, \SoftAppeals\Domain\ClientDecision::stageAfter(\SoftAppeals\Domain\ClientDecision::RECOVERY_SCOPE), 'recovery goes to scope selected');
            Expect::null(\SoftAppeals\Domain\ClientDecision::stageAfter(\SoftAppeals\Domain\ClientDecision::MORE_INFORMATION), 'a question moves nothing');
        },

    'every action request kind has a title, an owner and instructions' =>
        static function (Bootstrap $app): void {
            foreach (\SoftAppeals\Domain\ActionRequestKind::all() as $kind) {
                Expect::true(\SoftAppeals\Domain\ActionRequestKind::title($kind) !== $kind, $kind . ' has a title');
                Expect::true(\SoftAppeals\Domain\ActionRequestKind::instructions($kind) !== '', $kind . ' has instructions');
                Expect::true(in_array(\SoftAppeals\Domain\ActionRequestKind::owner($kind), ['client', 'soft_appeals'], true), $kind . ' has an owner');
                Expect::null(
                    \SoftAppeals\Domain\PreferenceForm::phiObjection(\SoftAppeals\Domain\ActionRequestKind::instructions($kind)),
                    $kind . ' instructions pass the screen'
                );
            }
        },

    'the checklist keys and categories are the plan\'s' =>
        static function (Bootstrap $app): void {
            Expect::same(7, count(\SoftAppeals\Domain\Checklist::initial()), 'seven initial items');
            Expect::same(5, count(\SoftAppeals\Domain\Checklist::recovery()), 'five recovery items');
            foreach ([...\SoftAppeals\Domain\Checklist::initial(), ...\SoftAppeals\Domain\Checklist::recovery()] as $item) {
                Expect::true(\SoftAppeals\Domain\Checklist::isValidCategory($item['category']), $item['key'] . ' has a valid category');
            }
            Expect::same(12, count(array_unique(\SoftAppeals\Domain\Checklist::keys())), 'twelve distinct keys');
        },
];
