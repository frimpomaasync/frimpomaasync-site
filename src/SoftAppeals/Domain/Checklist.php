<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * The checklist a practice is shown, section 15.6.
 *
 * Two lists: the initial one every engagement gets, and the recovery one that
 * appears only if the organization continues. Each item has a stable key, so
 * the service that completes them can name one without matching on a label
 * that somebody may later reword.
 *
 * Completion is derived from facts, never ticked by hand. "BAA executed" is
 * done when an executed BAA exists, and it is done at the moment that record
 * says it was executed. A checklist that could be ticked without the thing
 * having happened would be a list of claims, and the room makes no claims.
 */
final class Checklist
{
    public const CATEGORY_SETUP      = 'SETUP';
    public const CATEGORY_DOCUMENT   = 'DOCUMENT';
    public const CATEGORY_ACCESS     = 'ACCESS';
    public const CATEGORY_INTAKE     = 'INTAKE';
    public const CATEGORY_ASSESSMENT = 'ASSESSMENT';
    public const CATEGORY_DECISION   = 'DECISION';
    public const CATEGORY_SCOPE      = 'SCOPE';
    public const CATEGORY_APPROVAL   = 'APPROVAL';
    public const CATEGORY_RECOVERY   = 'RECOVERY';

    // Initial list.
    public const PREFERENCES_CONFIRMED = 'preferences_confirmed';
    public const BAA_EXECUTED          = 'baa_executed';
    public const REVIEW_AUTH_EXECUTED  = 'review_auth_executed';
    public const SECURE_OPENED         = 'secure_opened';
    public const INITIAL_SET_RECEIVED  = 'initial_set_received';
    public const ASSESSMENT_DELIVERED  = 'assessment_delivered';
    public const DECISION_RECORDED     = 'decision_recorded';

    // Recovery list.
    public const SCOPE_SELECTED        = 'scope_selected';
    public const RECOVERY_AGREEMENT    = 'recovery_agreement_executed';
    public const APPROVER_CONFIRMED    = 'approver_confirmed';
    public const FIRST_APPROVAL        = 'first_approval';
    public const FIRST_SUBMISSION      = 'first_submission';

    /** @return list<string> */
    public static function categories(): array
    {
        return [
            self::CATEGORY_SETUP,
            self::CATEGORY_DOCUMENT,
            self::CATEGORY_ACCESS,
            self::CATEGORY_INTAKE,
            self::CATEGORY_ASSESSMENT,
            self::CATEGORY_DECISION,
            self::CATEGORY_SCOPE,
            self::CATEGORY_APPROVAL,
            self::CATEGORY_RECOVERY,
        ];
    }

    /**
     * The initial checklist, in display order. The wording is the plan's.
     *
     * @return list<array{key:string,label:string,category:string}>
     */
    public static function initial(): array
    {
        return [
            ['key' => self::PREFERENCES_CONFIRMED, 'label' => 'Onboarding preferences confirmed',           'category' => self::CATEGORY_SETUP],
            ['key' => self::BAA_EXECUTED,          'label' => 'Business Associate Agreement executed',      'category' => self::CATEGORY_DOCUMENT],
            ['key' => self::REVIEW_AUTH_EXECUTED,  'label' => 'Complimentary Review Authorization executed', 'category' => self::CATEGORY_DOCUMENT],
            ['key' => self::SECURE_OPENED,         'label' => 'Secure workflow opened',                     'category' => self::CATEGORY_ACCESS],
            ['key' => self::INITIAL_SET_RECEIVED,  'label' => 'Initial 20-denial set received',             'category' => self::CATEGORY_INTAKE],
            ['key' => self::ASSESSMENT_DELIVERED,  'label' => 'Assessment delivered',                       'category' => self::CATEGORY_ASSESSMENT],
            ['key' => self::DECISION_RECORDED,     'label' => 'Recovery decision recorded',                 'category' => self::CATEGORY_DECISION],
        ];
    }

    /**
     * The recovery checklist. Appears only once the practice chooses recovery.
     *
     * @return list<array{key:string,label:string,category:string}>
     */
    public static function recovery(): array
    {
        return [
            ['key' => self::SCOPE_SELECTED,     'label' => 'Recovery scope selected',            'category' => self::CATEGORY_SCOPE],
            ['key' => self::RECOVERY_AGREEMENT, 'label' => 'Recovery Services Agreement executed', 'category' => self::CATEGORY_DOCUMENT],
            ['key' => self::APPROVER_CONFIRMED, 'label' => 'Submission approver confirmed',      'category' => self::CATEGORY_ACCESS],
            ['key' => self::FIRST_APPROVAL,     'label' => 'First submission approval completed', 'category' => self::CATEGORY_APPROVAL],
            ['key' => self::FIRST_SUBMISSION,   'label' => 'First payer submission recorded',    'category' => self::CATEGORY_RECOVERY],
        ];
    }

    /** @return list<string> every key, both lists */
    public static function keys(): array
    {
        $out = [];
        foreach ([...self::initial(), ...self::recovery()] as $item) {
            $out[] = $item['key'];
        }
        return $out;
    }

    public static function isValidKey(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    public static function isValidCategory(string $category): bool
    {
        return in_array($category, self::categories(), true);
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            self::CATEGORY_SETUP      => 'Setup',
            self::CATEGORY_DOCUMENT   => 'Agreement',
            self::CATEGORY_ACCESS     => 'Access',
            self::CATEGORY_INTAKE     => 'Intake',
            self::CATEGORY_ASSESSMENT => 'Assessment',
            self::CATEGORY_DECISION   => 'Decision',
            self::CATEGORY_SCOPE      => 'Scope',
            self::CATEGORY_APPROVAL   => 'Approval',
            self::CATEGORY_RECOVERY   => 'Recovery',
            default                   => $category,
        };
    }
}
