<?php
declare(strict_types=1);

/**
 * Migration 0005 · Documents and signatures.
 *
 * Section 11.1 asks for two tables and section 14 says what they have to make
 * impossible. Both are written here as constraints rather than as intentions,
 * because the application is not the only thing that will ever touch this
 * database and a rule that lives only in PHP is a rule for one code path.
 *
 * `sa_documents` is versioned, never edited. The unique constraint on
 * (engagement_id, kind, version) is the whole of "a corrected document creates
 * a new version": there is no way to write a second version 1 of a BAA for the
 * same engagement, so a correction has nowhere to go except version 2. The old
 * row is marked void with a reason and stays exactly as it was.
 *
 * content_sha256 is the hash of the document body as generated, before anybody
 * signs. executed_sha256 is the hash of the final rendered record, written once
 * the last required signature is on it. Both are CHAR(64) and both are checked
 * for length, because a truncated hash that still looks like a hash is the kind
 * of thing that is only ever found later.
 *
 * `sa_signatures` holds evidence, never a secret. There is no raw IP address
 * and no raw user agent in this table: both arrive already HMAC'd, which is
 * enough to say "the same device signed both of these" and not enough to
 * re-identify anybody from a copy of the table. The typed name is the legal act
 * and it is stored as typed.
 *
 * The PHI boundary, again by absence. Nothing here has a column for a patient,
 * a member, a claim or a date of service. A document body is business text.
 */

return [
    'name' => '0005_documents_and_signatures',

    'up' => static function (\SoftAppeals\Database $db): void {
        $sqlite = $db->isSqlite();
        $suffix = $sqlite
            ? ''
            : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // ------------------------------------------------------------------
        // Documents. Section 11.1 and section 14.2.
        //
        // private_path and executed_path are paths inside the private vault,
        // never URLs. Section 14.2 says a document is never a public link, and
        // storing a path rather than a URL is what makes that structural: there
        // is nothing in this row that a browser could follow.
        //
        // superseded_by points at the version that replaced this one. It is
        // SET NULL rather than CASCADE, because deleting a correction must
        // never delete the record it corrected.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_documents (
                id                CHAR(36)     NOT NULL,
                public_ref        VARCHAR(24)  NOT NULL,
                engagement_id     CHAR(36)     NOT NULL,
                organization_id   CHAR(36)     NOT NULL,
                kind              VARCHAR(40)  NOT NULL,
                version           INTEGER      NOT NULL,
                status            VARCHAR(20)  NOT NULL,
                title             VARCHAR(200) NOT NULL,
                template_version  VARCHAR(20)  NOT NULL,
                consent_version   VARCHAR(20)  NOT NULL,
                content_sha256    CHAR(64)     NOT NULL,
                executed_sha256   CHAR(64)         NULL,
                private_path      VARCHAR(255) NOT NULL,
                executed_path     VARCHAR(255)     NULL,
                signer_contact_id CHAR(36)         NULL,
                fee_basis         VARCHAR(30)      NULL,
                void_reason       VARCHAR(200)     NULL,
                superseded_by     CHAR(36)         NULL,
                created_by        CHAR(36)         NULL,
                sent_at           DATETIME         NULL,
                client_signed_at  DATETIME         NULL,
                countersigned_at  DATETIME         NULL,
                executed_at       DATETIME         NULL,
                voided_at         DATETIME         NULL,
                created_at        DATETIME     NOT NULL,
                updated_at        DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_doc_ref_unique UNIQUE (public_ref),
                CONSTRAINT sa_doc_version_unique UNIQUE (engagement_id, kind, version),
                CONSTRAINT sa_doc_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_doc_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_doc_signer_fk FOREIGN KEY (signer_contact_id)
                    REFERENCES sa_contacts (id) ON DELETE SET NULL,
                CONSTRAINT sa_doc_superseded_fk FOREIGN KEY (superseded_by)
                    REFERENCES sa_documents (id) ON DELETE SET NULL,
                CONSTRAINT sa_doc_kind_check CHECK (kind IN (
                    \'baa\', \'review_authorization\', \'recovery_agreement\',
                    \'approved_scope\', \'submission_approval\', \'closeout\'
                )),
                CONSTRAINT sa_doc_status_check CHECK (status IN (
                    \'draft\', \'sent\', \'client_signed\', \'countersigned\',
                    \'executed\', \'void\'
                )),
                CONSTRAINT sa_doc_version_positive CHECK (version >= 1),
                CONSTRAINT sa_doc_content_hash_len CHECK (length(content_sha256) = 64),
                CONSTRAINT sa_doc_executed_hash_len CHECK (
                    executed_sha256 IS NULL OR length(executed_sha256) = 64
                ),

                -- An executed document has a final hash and a stamp. Neither is
                -- allowed without the other, because a record that says it was
                -- executed and cannot say what was executed is worse than no
                -- record at all.
                CONSTRAINT sa_doc_executed_pair CHECK (
                    (status <> \'executed\')
                    OR (executed_sha256 IS NOT NULL AND executed_at IS NOT NULL)
                ),

                -- Void carries its reason. Section 14.2 asks for an audit
                -- reason on every correction, and this is the half of it the
                -- database can insist on.
                CONSTRAINT sa_doc_void_reason CHECK (
                    (status <> \'void\') OR (void_reason IS NOT NULL)
                )
            )' . $suffix
        );

        $db->run('CREATE INDEX sa_doc_engagement_idx ON sa_documents (engagement_id, kind, version)');
        $db->run('CREATE INDEX sa_doc_status_idx ON sa_documents (status)');
        $db->run('CREATE INDEX sa_doc_org_idx ON sa_documents (organization_id)');

        // ------------------------------------------------------------------
        // Signatures. Section 11.1 and section 14.4.
        //
        // document_sha256 is the hash of the document AS SIGNED, copied onto
        // the signature row rather than read back through the document later.
        // That is the acceptance criterion "signature event references the
        // exact document hash", and it only means anything if the value is
        // frozen here: a foreign key to a row somebody could rewrite would
        // prove nothing.
        //
        // One signature per party per document. The unique constraint on
        // (document_id, party) is what stops a second client signature landing
        // on a document that is already signed, whatever the application does.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_signatures (
                id                 CHAR(36)     NOT NULL,
                document_id        CHAR(36)     NOT NULL,
                organization_id    CHAR(36)         NULL,
                party              VARCHAR(20)  NOT NULL,
                signer_user_id     CHAR(36)         NULL,
                signer_contact_id  CHAR(36)         NULL,
                signer_role        VARCHAR(40)  NOT NULL,
                typed_name         VARCHAR(160) NOT NULL,
                typed_title        VARCHAR(120)     NULL,
                typed_organization VARCHAR(200)     NULL,
                consent_version    VARCHAR(20)  NOT NULL,
                consent_text_sha256 CHAR(64)    NOT NULL,
                consent_accepted_at DATETIME    NOT NULL,
                document_sha256    CHAR(64)     NOT NULL,
                payload_path       VARCHAR(255) NOT NULL,
                payload_sha256     CHAR(64)     NOT NULL,
                ip_digest          CHAR(64)         NULL,
                user_agent_digest  CHAR(64)         NULL,
                auth_event_id      CHAR(36)         NULL,
                idempotency_key    CHAR(64)         NULL,
                signed_at          DATETIME     NOT NULL,
                created_at         DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_sig_party_unique UNIQUE (document_id, party),
                CONSTRAINT sa_sig_doc_fk FOREIGN KEY (document_id)
                    REFERENCES sa_documents (id) ON DELETE CASCADE,
                CONSTRAINT sa_sig_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE SET NULL,
                CONSTRAINT sa_sig_user_fk FOREIGN KEY (signer_user_id)
                    REFERENCES sa_users (id) ON DELETE SET NULL,
                CONSTRAINT sa_sig_contact_fk FOREIGN KEY (signer_contact_id)
                    REFERENCES sa_contacts (id) ON DELETE SET NULL,
                CONSTRAINT sa_sig_party_check CHECK (party IN (\'client\', \'soft_appeals\')),
                CONSTRAINT sa_sig_doc_hash_len CHECK (length(document_sha256) = 64),
                CONSTRAINT sa_sig_payload_hash_len CHECK (length(payload_sha256) = 64),
                CONSTRAINT sa_sig_consent_hash_len CHECK (length(consent_text_sha256) = 64),
                CONSTRAINT sa_sig_typed_name_present CHECK (length(typed_name) >= 2)
            )' . $suffix
        );

        $db->run('CREATE INDEX sa_sig_document_idx ON sa_signatures (document_id)');
        $db->run('CREATE UNIQUE INDEX sa_sig_idempotency_idx ON sa_signatures (idempotency_key)');
    },

    'down' => static function (\SoftAppeals\Database $db): void {
        foreach (['sa_signatures', 'sa_documents'] as $table) {
            $db->run('DROP TABLE IF EXISTS ' . $table);
        }
    },
];
