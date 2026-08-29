<?php
declare(strict_types=1);

/**
 * Migration 0006 · The assessment and the Recovery Room proper. Phase 5.
 *
 * Five tables, and the rule that runs through all of them is section 5: the
 * data boundary. Not one column here can hold a patient, a member, a claim
 * number or a date of service. Counts are integers, money is integer cents,
 * and every free-text column is short and screened before it is written.
 *
 * `sa_settings` is a key/value table for the handful of things she sets from
 * the Desk rather than from a config file on a host with no shell. The first
 * is the legal entity name that goes on the face of every agreement.
 *
 * `sa_assessments` is one row per engagement (UNIQUE on engagement_id) and
 * carries the milestones of section 7.2 as timestamps: receipt confirmed,
 * started, quality review, delivered, and the decision the practice gave.
 *
 * `sa_work_batches` is section 11.1's replacement for claim-level storage.
 * A deadline is either confirmed or it is not, and the CHECK below refuses a
 * batch that claims a confirmed deadline without a date to confirm.
 *
 * `sa_checklist_items` is section 15.6, keyed so a completion names an item
 * rather than matching a label. UNIQUE on (engagement_id, key).
 *
 * `sa_action_requests` is section 15.8. The kind is constrained to the plan's
 * list, so a request nobody designed cannot be opened.
 */

return [
    'name' => '0006_assessment_and_recovery_room',

    'up' => static function (\SoftAppeals\Database $db): void {
        $sqlite = $db->isSqlite();
        $suffix = $sqlite
            ? ''
            : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // ------------------------------------------------------------------
        // Settings. Written from the Desk by the owner, read at generate time.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_settings (
                setting_key   VARCHAR(60)  NOT NULL,
                setting_value VARCHAR(500)     NULL,
                updated_by    CHAR(36)         NULL,
                updated_at    DATETIME     NOT NULL,
                PRIMARY KEY (setting_key),
                CONSTRAINT sa_setting_key_check CHECK (length(setting_key) >= 3)
            )' . $suffix
        );

        // ------------------------------------------------------------------
        // Assessments. One per engagement. Section 7.2 and section 15.
        //
        // expected_count is the size of the initial set the terms promised,
        // twenty by default. received_count is what actually arrived, at
        // aggregate level. Neither is a claim.
        //
        // summary is the aggregate finding the practice reads. It is capped
        // and screened in code; here it is merely short.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_assessments (
                id                        CHAR(36)     NOT NULL,
                engagement_id             CHAR(36)     NOT NULL,
                organization_id           CHAR(36)     NOT NULL,
                expected_count            INTEGER          NULL,
                received_count            INTEGER          NULL,
                receipt_confirmed_at      DATETIME         NULL,
                receipt_confirmed_by      CHAR(36)         NULL,
                client_confirmed_at       DATETIME         NULL,
                client_confirmed_contact  CHAR(36)         NULL,
                started_at                DATETIME         NULL,
                quality_review_at         DATETIME         NULL,
                delivered_at              DATETIME         NULL,
                delivered_by              CHAR(36)         NULL,
                read_at                   DATETIME         NULL,
                summary                   VARCHAR(2000)    NULL,
                recommended_count         INTEGER          NULL,
                recommended_amount_cents  BIGINT           NULL,
                decision_due_at           DATETIME         NULL,
                decision                  VARCHAR(30)      NULL,
                decision_at               DATETIME         NULL,
                decision_contact_id       CHAR(36)         NULL,
                decision_note             VARCHAR(500)     NULL,
                created_at                DATETIME     NOT NULL,
                updated_at                DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_assess_eng_unique UNIQUE (engagement_id),
                CONSTRAINT sa_assess_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_assess_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_assess_decision_contact_fk FOREIGN KEY (decision_contact_id)
                    REFERENCES sa_contacts (id) ON DELETE SET NULL,
                CONSTRAINT sa_assess_expected_check CHECK (
                    expected_count IS NULL OR expected_count >= 0
                ),
                CONSTRAINT sa_assess_received_check CHECK (
                    received_count IS NULL OR received_count >= 0
                ),
                CONSTRAINT sa_assess_recommended_check CHECK (
                    recommended_count IS NULL OR recommended_count >= 0
                ),
                CONSTRAINT sa_assess_amount_check CHECK (
                    recommended_amount_cents IS NULL OR recommended_amount_cents >= 0
                ),
                CONSTRAINT sa_assess_decision_check CHECK (
                    decision IS NULL OR decision IN (
                        \'internal_use\', \'more_information\', \'recovery_scope\', \'no_further_action\'
                    )
                ),

                -- A decision carries its stamp. One without the other is a
                -- record that says a choice was made and cannot say when.
                CONSTRAINT sa_assess_decision_pair CHECK (
                    (decision IS NULL AND decision_at IS NULL)
                    OR (decision IS NOT NULL AND decision_at IS NOT NULL)
                )
            )' . $suffix
        );

        $db->run('CREATE INDEX sa_assess_org_idx ON sa_assessments (organization_id)');

        // ------------------------------------------------------------------
        // Work batches. Section 11.1 and section 15.7.
        //
        // The card the practice sees is this row and nothing more. Every
        // count is aggregate and every dollar figure is integer cents.
        // deadline_confirmed is the boolean section 12.4 asks for: a date is
        // either entered and confirmed, or visibly labelled unconfirmed.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_work_batches (
                id                   CHAR(36)     NOT NULL,
                public_ref           VARCHAR(24)  NOT NULL,
                engagement_id        CHAR(36)     NOT NULL,
                organization_id      CHAR(36)     NOT NULL,
                label                VARCHAR(80)  NOT NULL,
                payer_label          VARCHAR(80)      NULL,
                payer_label_approved TINYINT      NOT NULL,
                claim_count          INTEGER      NOT NULL,
                denied_amount_cents  BIGINT       NOT NULL,
                received_count       INTEGER      NOT NULL,
                in_review_count      INTEGER      NOT NULL,
                submitted_count      INTEGER      NOT NULL,
                overturned_count     INTEGER      NOT NULL,
                upheld_count         INTEGER      NOT NULL,
                closed_count         INTEGER      NOT NULL,
                stage                VARCHAR(30)  NOT NULL,
                earliest_deadline_at DATETIME         NULL,
                deadline_confirmed   TINYINT      NOT NULL,
                next_owner           VARCHAR(20)  NOT NULL,
                next_action          VARCHAR(160)     NULL,
                created_by           CHAR(36)         NULL,
                created_at           DATETIME     NOT NULL,
                updated_at           DATETIME     NOT NULL,
                row_version          INTEGER      NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_batch_ref_unique UNIQUE (public_ref),
                CONSTRAINT sa_batch_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_batch_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_batch_stage_check CHECK (stage IN (
                    \'received\', \'in_review\', \'recommended\', \'not_recommended\',
                    \'approval_pending\', \'submitted\', \'payer_review\',
                    \'overturned\', \'upheld\', \'closed\'
                )),
                CONSTRAINT sa_batch_owner_check CHECK (next_owner IN (
                    \'soft_appeals\', \'client\', \'billing_partner\', \'payer\', \'other\'
                )),
                CONSTRAINT sa_batch_approved_check CHECK (payer_label_approved IN (0, 1)),
                CONSTRAINT sa_batch_confirmed_check CHECK (deadline_confirmed IN (0, 1)),
                CONSTRAINT sa_batch_counts_check CHECK (
                    claim_count >= 0 AND received_count >= 0 AND in_review_count >= 0
                    AND submitted_count >= 0 AND overturned_count >= 0
                    AND upheld_count >= 0 AND closed_count >= 0
                ),
                CONSTRAINT sa_batch_amount_check CHECK (denied_amount_cents >= 0),

                -- A confirmed deadline needs a date to confirm.
                CONSTRAINT sa_batch_deadline_pair CHECK (
                    deadline_confirmed = 0 OR earliest_deadline_at IS NOT NULL
                )
            )' . $suffix
        );

        $db->run('CREATE INDEX sa_batch_engagement_idx ON sa_work_batches (engagement_id)');
        $db->run('CREATE INDEX sa_batch_deadline_idx ON sa_work_batches (earliest_deadline_at)');

        // ------------------------------------------------------------------
        // Checklist items. Section 15.6.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_checklist_items (
                id              CHAR(36)     NOT NULL,
                engagement_id   CHAR(36)     NOT NULL,
                item_key        VARCHAR(60)  NOT NULL,
                label           VARCHAR(120) NOT NULL,
                category        VARCHAR(20)  NOT NULL,
                display_order   INTEGER      NOT NULL,
                completed_at    DATETIME         NULL,
                completed_by    CHAR(36)         NULL,
                source_event_id CHAR(36)         NULL,
                created_at      DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_check_key_unique UNIQUE (engagement_id, item_key),
                CONSTRAINT sa_check_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_check_category_check CHECK (category IN (
                    \'SETUP\', \'DOCUMENT\', \'ACCESS\', \'INTAKE\', \'ASSESSMENT\',
                    \'DECISION\', \'SCOPE\', \'APPROVAL\', \'RECOVERY\'
                ))
            )' . $suffix
        );

        // ------------------------------------------------------------------
        // Action requests. Section 15.8.
        //
        // note is the one per-request free-text field, for her to add a line
        // to the standing instructions or to answer a question. Capped here,
        // screened in code.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_action_requests (
                id                   CHAR(36)     NOT NULL,
                public_ref           VARCHAR(24)  NOT NULL,
                engagement_id        CHAR(36)     NOT NULL,
                organization_id      CHAR(36)     NOT NULL,
                kind                 VARCHAR(40)  NOT NULL,
                owner                VARCHAR(20)  NOT NULL,
                requested_from       CHAR(36)         NULL,
                note                 VARCHAR(1000)    NULL,
                response             VARCHAR(1000)    NULL,
                due_at               DATETIME         NULL,
                status               VARCHAR(20)  NOT NULL,
                completed_at         DATETIME         NULL,
                completed_by         CHAR(36)         NULL,
                created_by           CHAR(36)         NULL,
                created_at           DATETIME     NOT NULL,
                updated_at           DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_req_ref_unique UNIQUE (public_ref),
                CONSTRAINT sa_req_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_req_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_req_contact_fk FOREIGN KEY (requested_from)
                    REFERENCES sa_contacts (id) ON DELETE SET NULL,
                CONSTRAINT sa_req_kind_check CHECK (kind IN (
                    \'confirm_signer\', \'complete_baa\', \'open_secure_channel\',
                    \'confirm_receipt_count\', \'review_assessment\', \'choose_scope\',
                    \'approve_submission\', \'provide_information\',
                    \'verify_reimbursement\', \'review_closeout\', \'answer_question\'
                )),
                CONSTRAINT sa_req_owner_check CHECK (owner IN (\'client\', \'soft_appeals\')),
                CONSTRAINT sa_req_status_check CHECK (status IN (
                    \'open\', \'done\', \'cancelled\', \'expired\'
                )),

                -- A finished request says when. An open one does not.
                CONSTRAINT sa_req_completed_pair CHECK (
                    (status = \'open\' AND completed_at IS NULL)
                    OR (status <> \'open\' AND completed_at IS NOT NULL)
                )
            )' . $suffix
        );

        $db->run('CREATE INDEX sa_req_engagement_idx ON sa_action_requests (engagement_id, status)');
        $db->run('CREATE INDEX sa_req_owner_idx ON sa_action_requests (owner, status)');
    },

    'down' => static function (\SoftAppeals\Database $db): void {
        foreach ([
            'sa_action_requests',
            'sa_checklist_items',
            'sa_work_batches',
            'sa_assessments',
            'sa_settings',
        ] as $table) {
            $db->run('DROP TABLE IF EXISTS ' . $table);
        }
    },
];
