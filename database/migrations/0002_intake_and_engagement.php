<?php
declare(strict_types=1);

/**
 * Migration 0002 · Intake and engagement.
 *
 * What Phase 2 needs: the enquiries that arrive from the live form, the
 * engagement each accepted one becomes, the one-time invitations that carry a
 * practice to its preferences page, the record of every message sent, and the
 * business timeline a client is allowed to see.
 *
 * Same portability rules as 0001, and this time they are the only rules: one
 * schema, executed identically on MySQL and SQLite. 0001 shipped with two, and
 * only the one that was never going to production had been run.
 *
 * The PHI boundary again, enforced by absence. There is no column here for a
 * patient, a claim number, a date of service, a denial code tied to a person,
 * or a document. Denial volume and denied value are stored as BANDS, not
 * figures, because that is all the intake form asks for and a band cannot
 * identify anybody.
 */

return [
    'name' => '0002_intake_and_engagement',

    'up' => static function (\SoftAppeals\Database $db): void {
        $suffix = $db->isSqlite()
            ? ''
            : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // ------------------------------------------------------------------
        // Intakes. One row per submission of the live Soft Appeals form.
        //
        // payload_json holds the answers exactly as sa-lead.php already
        // filters them: an allowlist of named fields, control characters
        // stripped, nothing the form did not ask for. It is TEXT holding JSON
        // rather than a column per answer because the form's questions change
        // and a schema migration per question is a bad trade.
        //
        // payload_sha256 is the idempotency key. The same submission arriving
        // twice, from a double click or a browser retry, produces the same
        // hash and the second one is recognised rather than stored again.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_intakes (
                id                  CHAR(36)     NOT NULL,
                public_ref          VARCHAR(24)  NOT NULL,
                organization_id     CHAR(36)         NULL,
                source              VARCHAR(60)  NOT NULL,
                organization_name   VARCHAR(200) NOT NULL,
                contact_name        VARCHAR(160) NOT NULL,
                contact_email       VARCHAR(200) NOT NULL,
                contact_role        VARCHAR(120)     NULL,
                state               CHAR(2)          NULL,
                organization_type   VARCHAR(60)      NULL,
                denial_volume_band  VARCHAR(60)      NULL,
                denied_value_band   VARCHAR(60)      NULL,
                time_sensitive      TINYINT      NOT NULL,
                payload_json        TEXT             NULL,
                payload_sha256      CHAR(64)     NOT NULL,
                status              VARCHAR(30)  NOT NULL,
                fit_decision        VARCHAR(30)      NULL,
                fit_note            TEXT             NULL,
                reviewed_at         DATETIME         NULL,
                reviewed_by         CHAR(36)         NULL,
                legacy_record_path  VARCHAR(255)     NULL,
                submitted_at        DATETIME     NOT NULL,
                created_at          DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_intake_ref_unique UNIQUE (public_ref),
                CONSTRAINT sa_intake_payload_unique UNIQUE (payload_sha256),
                CONSTRAINT sa_intake_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE SET NULL,
                CONSTRAINT sa_intake_ts_check CHECK (time_sensitive IN (0, 1)),
                CONSTRAINT sa_intake_status_check CHECK (status IN (
                    \'received\', \'in_review\', \'accepted\', \'declined\',
                    \'clarification\', \'hold\', \'duplicate\'
                ))
            )' . $suffix
        );
        $db->run('CREATE INDEX sa_intake_status_idx ON sa_intakes (status)');
        $db->run('CREATE INDEX sa_intake_submitted_idx ON sa_intakes (submitted_at)');
        $db->run('CREATE INDEX sa_intake_email_idx ON sa_intakes (contact_email)');
        $db->run('CREATE INDEX sa_intake_org_idx ON sa_intakes (organization_id)');

        // ------------------------------------------------------------------
        // Engagements. One per accepted intake. Stage is validated against
        // Domain\Stage before any write, so a browser cannot skip a gate by
        // calling a later endpoint.
        //
        // row_version is optimistic concurrency: a transition reads it, writes
        // it back incremented, and fails if somebody else moved first. Two
        // tabs open on the same practice must not both advance it.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_engagements (
                id                    CHAR(36)     NOT NULL,
                organization_id       CHAR(36)     NOT NULL,
                intake_id             CHAR(36)         NULL,
                public_ref            VARCHAR(24)  NOT NULL,
                stage                 VARCHAR(40)  NOT NULL,
                fee_basis             VARCHAR(30)  NOT NULL,
                fee_rate_bps          INTEGER          NULL,
                secure_channel_type   VARCHAR(40)      NULL,
                communication_cadence VARCHAR(30)      NULL,
                assessment_window     VARCHAR(60)      NULL,
                client_decision_due_at DATETIME        NULL,
                opened_at             DATETIME     NOT NULL,
                closed_at             DATETIME         NULL,
                row_version           INTEGER      NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_eng_ref_unique UNIQUE (public_ref),
                CONSTRAINT sa_eng_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_eng_intake_fk FOREIGN KEY (intake_id)
                    REFERENCES sa_intakes (id) ON DELETE SET NULL,
                CONSTRAINT sa_eng_fee_check CHECK (fee_basis IN (
                    \'not_set\', \'contingency_25\', \'fixed\', \'custom\', \'scoped\'
                ))
            )' . $suffix
        );
        $db->run('CREATE INDEX sa_eng_stage_idx ON sa_engagements (stage)');
        $db->run('CREATE INDEX sa_eng_org2_idx ON sa_engagements (organization_id)');

        // ------------------------------------------------------------------
        // Invitations. A one-time link, stored only as a digest.
        //
        // Section 10.3: 32 random bytes minimum, digest only, purpose-bound,
        // organization-bound, one-time, explicit expiry, server-side
        // revocation. The token itself exists in the email and nowhere else,
        // so a copy of this table cannot be replayed against a practice.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_invitations (
                id              CHAR(36)     NOT NULL,
                organization_id CHAR(36)     NOT NULL,
                engagement_id   CHAR(36)         NULL,
                contact_id      CHAR(36)         NULL,
                contact_email   VARCHAR(200) NOT NULL,
                purpose         VARCHAR(30)  NOT NULL,
                token_digest    CHAR(64)     NOT NULL,
                expires_at      DATETIME     NOT NULL,
                used_at         DATETIME         NULL,
                revoked_at      DATETIME         NULL,
                created_by      CHAR(36)         NULL,
                created_at      DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_inv_digest_unique UNIQUE (token_digest),
                CONSTRAINT sa_inv_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_inv_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_inv_purpose_check CHECK (purpose IN (
                    \'preferences\', \'sign\', \'invite\', \'passwordless_login\'
                ))
            )' . $suffix
        );
        $db->run('CREATE INDEX sa_inv_org_idx ON sa_invitations (organization_id)');
        $db->run('CREATE INDEX sa_inv_expires_idx ON sa_invitations (expires_at)');

        // ------------------------------------------------------------------
        // Communications. One row per message the system sent.
        //
        // state is never "delivered". Section 16.1: do not label an email
        // delivered unless the mail system actually provides and verifies
        // delivery events, and this one does not. "accepted" means the SMTP
        // server took it, which is the honest ceiling.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_communications (
                id               CHAR(36)     NOT NULL,
                engagement_id    CHAR(36)         NULL,
                organization_id  CHAR(36)         NULL,
                recipient_email  VARCHAR(200) NOT NULL,
                template_key     VARCHAR(60)  NOT NULL,
                template_version VARCHAR(20)  NOT NULL,
                subject          VARCHAR(200) NOT NULL,
                channel          VARCHAR(20)  NOT NULL,
                state            VARCHAR(20)  NOT NULL,
                error_category   VARCHAR(60)      NULL,
                idempotency_key  CHAR(64)         NULL,
                sent_at          DATETIME         NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                -- Section 16.1: every send has an idempotency key. The unique
                -- constraint is what turns that from an intention into a rule.
                -- NULL never equals NULL on either driver, so a row that
                -- genuinely has no key (a manual record) is still allowed.
                CONSTRAINT sa_comm_idem_unique UNIQUE (idempotency_key),
                CONSTRAINT sa_comm_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_comm_state_check CHECK (state IN (
                    \'queued\', \'accepted\', \'failed\', \'manually_confirmed\', \'refused\'
                ))
            )' . $suffix
        );
        $db->run('CREATE INDEX sa_comm_eng_idx ON sa_communications (engagement_id)');
        $db->run('CREATE INDEX sa_comm_created_idx ON sa_communications (created_at)');

        // ------------------------------------------------------------------
        // Status events. The append-only business timeline, and the only
        // history a client is ever shown.
        //
        // Separate from sa_audit_events on purpose. The audit trail is
        // internal security history and holds refusals and digests; this is
        // what appears in the Recovery Room, so every label here is written to
        // be read by the practice.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_status_events (
                id            CHAR(36)     NOT NULL,
                engagement_id CHAR(36)     NOT NULL,
                event_type    VARCHAR(60)  NOT NULL,
                public_label  VARCHAR(160) NOT NULL,
                from_stage    VARCHAR(40)      NULL,
                to_stage      VARCHAR(40)      NULL,
                actor_type    VARCHAR(20)  NOT NULL,
                actor_id      CHAR(36)         NULL,
                metadata      TEXT             NULL,
                created_at    DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_status_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_status_actor_check CHECK (actor_type IN (
                    \'staff\', \'client\', \'system\'
                ))
            )' . $suffix
        );
        $db->run('CREATE INDEX sa_status_eng_idx ON sa_status_events (engagement_id, created_at)');
    },

    'down' => static function (\SoftAppeals\Database $db): void {
        foreach ([
            'sa_status_events',
            'sa_communications',
            'sa_invitations',
            'sa_engagements',
            'sa_intakes',
        ] as $table) {
            $db->run('DROP TABLE IF EXISTS ' . $db->quoteIdentifier($table));
        }
    },
];
