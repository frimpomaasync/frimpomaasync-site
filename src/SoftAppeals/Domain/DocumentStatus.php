<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * The life of one document version, from section 11.1.
 *
 * Six states and one rule that runs through all of them: the body never
 * changes. A status move rewrites the status column and the stamps beside it,
 * and it never rewrites the document, which is why "a signed document cannot be
 * edited" is a property of the shape rather than a promise in a comment.
 *
 * Void is reachable from everywhere except void itself, including from
 * executed. Section 14.2 says a correction voids the previous version with an
 * audit reason, and a correction can be needed after execution as easily as
 * before it. Voiding an executed document does not erase what was executed:
 * the hash, the stamp and every signature stay exactly where they are, and the
 * row that replaces it is a new version pointing back at this one.
 */
final class DocumentStatus
{
    public const DRAFT         = 'draft';
    public const SENT          = 'sent';
    public const CLIENT_SIGNED = 'client_signed';
    public const COUNTERSIGNED = 'countersigned';
    public const EXECUTED      = 'executed';
    public const VOID          = 'void';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::DRAFT,
            self::SENT,
            self::CLIENT_SIGNED,
            self::COUNTERSIGNED,
            self::EXECUTED,
            self::VOID,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /**
     * from => the statuses it may move to.
     *
     * client_signed reaches executed directly as well as through
     * countersigned, because a one-party document has nobody to countersign it
     * and waiting for a signature that is never coming would leave it stuck.
     *
     * draft reaches executed directly for exactly one reason: a record kind
     * (DocumentKind::records()) is sealed by Soft Appeals with no signature
     * on it. DocumentService::execute() refuses that edge for every kind that
     * a practice signs, so an unsigned agreement cannot take it whatever the
     * table says. The edge is here; the guard is there.
     *
     * @return array<string,list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::DRAFT         => [self::SENT, self::EXECUTED, self::VOID],
            self::SENT          => [self::CLIENT_SIGNED, self::VOID],
            self::CLIENT_SIGNED => [self::COUNTERSIGNED, self::EXECUTED, self::VOID],
            self::COUNTERSIGNED => [self::EXECUTED, self::VOID],
            self::EXECUTED      => [self::VOID],
            self::VOID          => [],
        ];
    }

    public static function canMove(string $from, string $to): bool
    {
        if (!self::isValid($from) || !self::isValid($to)) {
            return false;
        }
        return in_array($to, self::transitions()[$from], true);
    }

    /** True once no further signature is accepted on this version. */
    public static function isClosed(string $status): bool
    {
        return in_array($status, [self::EXECUTED, self::VOID], true);
    }

    /** True while this version is the one a practice is expected to act on. */
    public static function isOutForSignature(string $status): bool
    {
        return $status === self::SENT;
    }

    public static function staffLabel(string $status): string
    {
        return match ($status) {
            self::DRAFT         => 'Draft, not sent',
            self::SENT          => 'Out for signature',
            self::CLIENT_SIGNED => 'Client signed, waiting on your countersignature',
            self::COUNTERSIGNED => 'Countersigned, finishing',
            self::EXECUTED      => 'Executed',
            self::VOID          => 'Void',
            default             => $status,
        };
    }

    /**
     * What the practice reads. Never the internal token, and never her half of
     * the sentence: a document she has not countersigned yet is "with us", not
     * "waiting on the owner's countersignature", because the second one hands a
     * practice an internal queue it can do nothing about.
     */
    public static function clientLabel(string $status): string
    {
        return match ($status) {
            self::DRAFT         => 'Being prepared',
            self::SENT          => 'Waiting for your signature',
            self::CLIENT_SIGNED => 'Signed by you, with us to finish',
            self::COUNTERSIGNED => 'With us to finish',
            self::EXECUTED      => 'Signed by both of us',
            self::VOID          => 'Replaced',
            default             => 'In progress',
        };
    }

    /**
     * The same, for a document nobody signs. A sealed record is not "signed
     * by both of us", and telling a practice it was would be a small lie on
     * the one screen that must never carry one.
     */
    public static function clientLabelFor(string $kind, string $status): string
    {
        if (!DocumentKind::requiresSignature($kind)) {
            return match ($status) {
                self::EXECUTED => 'Sealed record',
                self::VOID     => 'Replaced',
                default        => 'Being prepared',
            };
        }
        return self::clientLabel($status);
    }
}
