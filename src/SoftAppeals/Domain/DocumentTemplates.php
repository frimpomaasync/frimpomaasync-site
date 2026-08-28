<?php
declare(strict_types=1);

namespace SoftAppeals\Domain;

/**
 * The document text, and the version stamp that says exactly which text was
 * used.
 *
 * Section 14.2 asks every generated document to carry its exact template
 * version. That is the reason this class exists rather than the text living
 * inside the service that renders it: a template version is only worth writing
 * down if the thing it names cannot drift. Change a word in here and
 * TEMPLATE_VERSION changes with it, or the stamp is a lie and every document
 * ever generated under the old stamp becomes unverifiable.
 *
 * Two things this class deliberately does not do.
 *
 * It does not know today's date. A document body that included the moment it
 * was rendered would hash differently every time it was rendered, and the whole
 * of section 14 rests on the body hashing the same way twice. The effective
 * date arrives as a value, from the row.
 *
 * It does not claim to be approved. Section 14.5 lists approval of the BAA
 * text, the review-authorization text and the recovery-agreement text as three
 * separate blockers on production signing, and none of the three has been
 * cleared. So the text below is a working draft, it says so on its own face,
 * and DocumentService will not generate against an unapproved template on
 * production. Approving one is: read it, change what it should say, bump
 * TEMPLATE_VERSION, and move its kind into APPROVED_KINDS.
 */
final class DocumentTemplates
{
    /**
     * The stamp that goes on every document generated from this file.
     *
     * Bump it whenever any text below changes. It is a date rather than a
     * number so that a document found in three years says when its wording was
     * written without anybody having to look up a changelog.
     */
    public const TEMPLATE_VERSION = '2026-08-28';

    /** The electronic-record consent, versioned separately from the documents. */
    public const CONSENT_VERSION = '2026-08-28';

    /**
     * The kinds whose text she has read and approved.
     *
     * Empty, and correctly so. Section 14.5 says production signing stays off
     * until the wording is approved, and an empty list is that fact written
     * where the code can act on it.
     *
     * @return list<string>
     */
    public static function approvedKinds(): array
    {
        return [];
    }

    public static function isApproved(string $kind): bool
    {
        return in_array($kind, self::approvedKinds(), true);
    }

    /**
     * The consent a signer accepts before the Sign action does anything.
     *
     * Stored by hash on the signature row, so the exact words somebody agreed
     * to can be proved later even if this text is rewritten afterwards.
     */
    public static function consentText(): string
    {
        return 'I agree to sign this document electronically. I understand that '
            . 'typing my name below is my signature, that it has the same effect '
            . 'as signing on paper, and that Soft Appeals will keep a record of '
            . 'this signature, the exact document it was applied to, and the time '
            . 'it was made. I can ask for a paper copy at any time by writing to '
            . 'softappeals@frimpomaasync.com.';
    }

    public static function consentSha256(): string
    {
        return hash('sha256', self::consentText());
    }

    public static function title(string $kind): string
    {
        return DocumentKind::label($kind);
    }

    /**
     * The full document body, as plain text.
     *
     * Deterministic: the same context in, the same bytes out, every time. That
     * is what makes content_sha256 mean something, and it is what lets the
     * executed record be re-rendered years later and checked against the hash
     * it was stored with.
     *
     * @param array<string,string> $context every value already resolved by the
     *        caller, so nothing here reaches for the clock, the session or the
     *        database
     */
    public static function body(string $kind, array $context): string
    {
        if (!DocumentKind::isValid($kind)) {
            throw new \RuntimeException('Unknown document kind: ' . $kind);
        }

        $lines = [];
        $lines[] = strtoupper(DocumentKind::label($kind));
        $lines[] = '';

        if (!self::isApproved($kind)) {
            $lines[] = 'DRAFT FOR REVIEW. This wording has not been approved yet, so '
                . 'this document is not an offer and is not binding on either party. '
                . 'It is here so the process can be walked end to end before any real '
                . 'agreement is issued.';
            $lines[] = '';
        }

        // -------------------------------------------------------------------
        // The identity block. Section 14.2 lists what every generated document
        // has to carry, and this is all of it in one place, at the top, where a
        // person checking one against another can read both in a glance.
        // -------------------------------------------------------------------
        $lines[] = 'Document reference: ' . self::value($context, 'document_ref');
        $lines[] = 'Version: ' . self::value($context, 'version');
        $lines[] = 'Template version: ' . self::TEMPLATE_VERSION;
        $lines[] = 'Effective date: ' . self::value($context, 'effective_date');
        $lines[] = '';
        $lines[] = 'Between';
        $lines[] = '  ' . self::value($context, 'provider_legal_name')
            . ', operating as ' . self::value($context, 'provider_trade_name')
            . ' ("Soft Appeals")';
        $lines[] = 'and';
        $lines[] = '  ' . self::value($context, 'client_legal_name') . ' ("the Practice")';
        $lines[] = '';
        $lines[] = 'Signing for the Practice: ' . self::value($context, 'signer_name')
            . ', ' . self::value($context, 'signer_title')
            . ', ' . self::value($context, 'signer_email');
        $lines[] = '';

        foreach (self::clauses($kind, $context) as $number => $clause) {
            $lines[] = ($number + 1) . '. ' . $clause['heading'];
            foreach ($clause['paragraphs'] as $paragraph) {
                $lines[] = '';
                $lines[] = wordwrap($paragraph, 78, "\n", false);
            }
            $lines[] = '';
        }

        $lines[] = 'Signatures follow on the record page.';

        return implode("\n", $lines) . "\n";
    }

    /**
     * The clauses of one document kind.
     *
     * @param array<string,string> $context
     * @return list<array{heading:string,paragraphs:list<string>}>
     */
    private static function clauses(string $kind, array $context): array
    {
        return match ($kind) {
            DocumentKind::BAA                  => self::baaClauses($context),
            DocumentKind::REVIEW_AUTHORIZATION => self::reviewAuthorizationClauses($context),
            default => [[
                'heading'    => 'Not yet written',
                'paragraphs' => [
                    'The wording for this document type has not been written. Nothing '
                    . 'should be generated against it and nothing should be signed on it.',
                ],
            ]],
        };
    }

    /**
     * The Business Associate Agreement.
     *
     * Gate A of section 6: this is the document that has to be executed before
     * a single claim-level file moves. Its clauses are written around what this
     * application actually does, which is a narrower thing than most agreements
     * of this kind describe, and saying so plainly is worth more than borrowed
     * breadth.
     *
     * @param array<string,string> $context
     * @return list<array{heading:string,paragraphs:list<string>}>
     */
    private static function baaClauses(array $context): array
    {
        return [
            [
                'heading'    => 'What this agreement covers',
                'paragraphs' => [
                    'The Practice is a covered entity. Soft Appeals works on denied '
                    . 'claims on the Practice\'s behalf, which makes Soft Appeals a '
                    . 'business associate. This agreement sets out how protected '
                    . 'health information is handled between the two, and it applies '
                    . 'to every piece of that information Soft Appeals receives, '
                    . 'creates, keeps or sends on behalf of the Practice.',
                ],
            ],
            [
                'heading'    => 'What Soft Appeals may use the information for',
                'paragraphs' => [
                    'Soft Appeals uses protected health information only to do the '
                    . 'work the Practice has asked for: reviewing denied claims, '
                    . 'preparing appeals, and reporting back on what happened. It is '
                    . 'not used for anything else, it is not sold, and it is not '
                    . 'shared with anyone outside the people doing that work.',

                    'Where the law requires a disclosure, Soft Appeals makes it and '
                    . 'tells the Practice, unless telling the Practice is itself '
                    . 'forbidden.',
                ],
            ],
            [
                'heading'    => 'The minimum necessary',
                'paragraphs' => [
                    'Soft Appeals asks for the smallest set of records that will '
                    . 'answer the question in front of it. Where a redacted or '
                    . 'limited record is enough, that is what is requested.',
                ],
            ],
            [
                'heading'    => 'How the information is protected',
                'paragraphs' => [
                    'Records travel through the route the Practice chose during '
                    . 'onboarding, which for this engagement is: '
                    . self::value($context, 'secure_channel') . '. They are held '
                    . 'encrypted, access is limited to the people doing the work, and '
                    . 'every access is logged.',

                    'This portal holds no patient-level information at any point. It '
                    . 'holds counts, totals, deadlines and business contacts. That is '
                    . 'a deliberate boundary and it does not change over the life of '
                    . 'this agreement.',
                ],
            ],
            [
                'heading'    => 'If something goes wrong',
                'paragraphs' => [
                    'If Soft Appeals discovers a use or disclosure that this agreement '
                    . 'does not allow, or a breach of unsecured protected health '
                    . 'information, the Practice is told without unreasonable delay '
                    . 'and in no case later than five business days after discovery. '
                    . 'The notice says what happened, whose information was involved '
                    . 'so far as it is known, and what is being done about it.',
                ],
            ],
            [
                'heading'    => 'Subcontractors',
                'paragraphs' => [
                    'Anyone Soft Appeals brings in who will touch protected health '
                    . 'information agrees in writing to the same terms that appear '
                    . 'here, before they touch any of it. The Practice can ask at any '
                    . 'time who those parties are and the answer comes back in '
                    . 'writing.',
                ],
            ],
            [
                'heading'    => 'The Practice\'s rights over its own records',
                'paragraphs' => [
                    'On request, Soft Appeals makes available the information it holds '
                    . 'so the Practice can meet a patient\'s right of access, make an '
                    . 'amendment, or answer a request for an accounting of '
                    . 'disclosures. Requests are answered within fifteen business days '
                    . 'unless the Practice agrees to longer.',
                ],
            ],
            [
                'heading'    => 'Ending this agreement, and what happens to the records',
                'paragraphs' => [
                    'Either party may end this agreement with thirty days\' written '
                    . 'notice. The Practice may end it immediately if Soft Appeals '
                    . 'breaches it and does not fix the breach within ten business '
                    . 'days of being told.',

                    'When it ends, Soft Appeals returns or destroys the protected '
                    . 'health information it holds and confirms in writing which was '
                    . 'done. Where returning or destroying a record is not feasible, '
                    . 'the protections in this agreement carry on applying to that '
                    . 'record for as long as it is held.',
                ],
            ],
            [
                'heading'    => 'What this document is not',
                'paragraphs' => [
                    'This agreement is about the handling of information. It is not an '
                    . 'agreement to perform recovery work, it commits neither party to '
                    . 'any fee, and it promises no result on any claim.',
                ],
            ],
        ];
    }

    /**
     * The Complimentary Review Authorization.
     *
     * The second half of Gate A. The BAA says how records are handled; this
     * says what Soft Appeals is allowed to do with them and, just as
     * importantly, what it is not allowed to do yet. Nothing goes to a payer
     * under this document. That is Gate C, and it needs its own approval on
     * every single submission.
     *
     * @param array<string,string> $context
     * @return list<array{heading:string,paragraphs:list<string>}>
     */
    private static function reviewAuthorizationClauses(array $context): array
    {
        return [
            [
                'heading'    => 'What the Practice is authorizing',
                'paragraphs' => [
                    'The Practice authorizes Soft Appeals to review the denied claims '
                    . 'it sends and to report back on what it finds. The review covers '
                    . 'the denial reasons, the deadlines attached to them, and what '
                    . 'looks recoverable.',
                ],
            ],
            [
                'heading'    => 'Nothing goes to a payer under this document',
                'paragraphs' => [
                    'This authorization covers the review and nothing beyond it. No '
                    . 'appeal, no reconsideration and no correspondence of any kind is '
                    . 'sent to a payer, a clearinghouse or anybody else on the '
                    . 'Practice\'s behalf under this document.',

                    'Submitting anything needs a separate approval from the Practice, '
                    . 'given per submission, naming what is being sent and to whom.',
                ],
            ],
            [
                'heading'    => 'What it costs',
                'paragraphs' => [
                    'The review is complimentary. No fee is owed for it, whatever it '
                    . 'finds, and signing this document does not commit the Practice '
                    . 'to any paid work afterwards.',
                ],
            ],
            [
                'heading'    => 'What comes back, and when',
                'paragraphs' => [
                    'The Practice receives a written assessment: what was reviewed, '
                    . 'what the denials have in common, which deadlines are close, and '
                    . 'what recovery would involve. The working window for this '
                    . 'engagement is ' . self::value($context, 'assessment_window')
                    . '. Deadlines drive that window, and any deadline inside it is '
                    . 'flagged as soon as it is seen rather than held until the end.',

                    'After the assessment, the Practice decides what happens next. '
                    . 'Using it internally and doing nothing else is a complete and '
                    . 'expected answer.',
                ],
            ],
            [
                'heading'    => 'The records used for the review',
                'paragraphs' => [
                    'Records are handled under the Business Associate Agreement the '
                    . 'two parties have already executed. They arrive through the '
                    . 'route the Practice chose, which for this engagement is: '
                    . self::value($context, 'secure_channel') . '.',
                ],
            ],
            [
                'heading'    => 'Ending it',
                'paragraphs' => [
                    'The Practice can withdraw this authorization at any time by '
                    . 'writing to softappeals@frimpomaasync.com. Work stops when the '
                    . 'notice arrives, and the records are returned or destroyed under '
                    . 'the terms of the Business Associate Agreement.',
                ],
            ],
            [
                'heading'    => 'No promise of a result',
                'paragraphs' => [
                    'Soft Appeals does not promise that any claim will be paid, or '
                    . 'that any particular amount will be recovered. What is promised '
                    . 'is the review itself and a straight answer about what it found.',
                ],
            ],
        ];
    }

    /**
     * One context value, or a refusal.
     *
     * A blank in a document is worse than a missing document. A generated
     * agreement that says "Between , operating as" is a thing somebody could
     * sign, so the generator stops here instead, naming the field, and the Desk
     * shows her which one is missing.
     *
     * @param array<string,string> $context
     */
    private static function value(array $context, string $key): string
    {
        $value = trim($context[$key] ?? '');
        if ($value === '') {
            throw new \RuntimeException(
                'This document cannot be generated: "' . $key . '" is empty.'
            );
        }
        return $value;
    }
}
