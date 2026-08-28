<?php
declare(strict_types=1);

/**
 * Migration 0008 · Reconciliation and closeout. Phase 7. The money.
 *
 * Five tables, and section 19 runs through every one of them.
 *
 * `sa_invoices` comes first only because `sa_recoveries` points at it. An
 * invoice is a sum of recovery rows, never a figure typed in by hand: its
 * fee, credit and total are written from the rows it carries and nothing
 * else, and the rows it carries point back at it.
 *
 * `sa_recoveries` is section 11.1's table, and it is APPEND-ONLY. Three
 * kinds of row: a verified reimbursement, an adjustment, a reversal. Rule 8:
 * a reversal or recoupment is a new row that names the row it takes from,
 * and the original keeps every value it was written with. The CHECK on the
 * kind pair makes that structural: a verified row names no parent and an
 * adjustment or reversal always does. Rule 6: `qualifies` says whether the
 * money counts under the agreement, and a row that does not qualify carries
 * a fee of zero, by CHECK. The fee rate is a snapshot in basis points, so a
 * row calculated under one agreement version says so forever, and the
 * agreement that produced it is named on the row (rule 10).
 *
 * `sa_closeouts` is one per engagement (UNIQUE) and holds the closeout
 * record of section 15.10: the final summary, the access outcome, the data
 * disposition, when it closed and who closed it. `sa_closeout_steps` is one
 * row per section 7.4 step with who confirmed it and when, which is the
 * Phase 7 acceptance line "final records show who confirmed each closeout
 * step" written as a table. `sa_access_reviews` is one row per person who
 * held access at the practice when closeout began, and the decision taken on
 * each: removed or retained. Closing refuses while any of them is undecided.
 *
 * Four MariaDB rules, three paid for on staging in 0007 and the fourth paid
 * for by this migration on its first staging deploy (errno 121, "Duplicate key
 * on write or update", on CREATE TABLE sa_invoices): a foreign-key name is
 * unique across the whole DATABASE, not the table, and `sa_invitations` in
 * 0002 already owns the `sa_inv_` prefix. Invoice constraints are `sa_invc_`.
 *   1. No CHECK may reference a column a foreign key can SET NULL. So the
 *      user columns (verified_by, confirmed_by, decided_by, closed_by) carry
 *      no foreign key, exactly like recorded_by in 0007, and the pointer
 *      columns that a CHECK does reference (adjusts_recovery_id) carry none.
 *   2. The down half is a foreach over tables, never a mix.
 *   3. Nothing here holds a patient, a member, a claim number or a date of
 *      service. Notes are short and screened before they are written.
 */

return [
    'name' => '0008_reconciliation_and_closeout',

    'up' => static function (\SoftAppeals\Database $db): void {
        $sqlite = $db->isSqlite();
        $suffix = $sqlite
            ? ''
            : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // ------------------------------------------------------------------
        // Invoices. A sum of recovery rows with a status.
        //
        // total_cents may be negative: an invoice that carries only a
        // reversal is a credit note, and section 19.8 says a reversal is
        // taken off the next invoice rather than written over the last.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_invoices (
                id                    CHAR(36)     NOT NULL,
                public_ref            VARCHAR(24)  NOT NULL,
                engagement_id         CHAR(36)     NOT NULL,
                organization_id       CHAR(36)     NOT NULL,
                status                VARCHAR(20)  NOT NULL,
                fee_cents             BIGINT       NOT NULL,
                credit_cents          BIGINT       NOT NULL,
                total_cents           BIGINT       NOT NULL,
                agreement_document_id CHAR(36)         NULL,
                private_path          VARCHAR(255)     NULL,
                content_sha256        CHAR(64)         NULL,
                issued_at             DATETIME         NULL,
                due_at                DATETIME         NULL,
                paid_at               DATETIME         NULL,
                paid_note             VARCHAR(500)     NULL,
                voided_at             DATETIME         NULL,
                void_reason           VARCHAR(200)     NULL,
                created_by            CHAR(36)         NULL,
                created_at            DATETIME     NOT NULL,
                updated_at            DATETIME     NOT NULL,
                row_version           INTEGER      NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_invc_ref_unique UNIQUE (public_ref),
                CONSTRAINT sa_invc_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_invc_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_invc_status_check CHECK (status IN (
                    \'draft\', \'issued\', \'paid\', \'void\'
                )),
                CONSTRAINT sa_invc_fee_check CHECK (fee_cents >= 0),
                CONSTRAINT sa_invc_credit_check CHECK (credit_cents >= 0),
                CONSTRAINT sa_invc_total_check CHECK (total_cents = fee_cents - credit_cents),

                -- An issued invoice says when. A paid one was issued first.
                -- A void one says when it was voided.
                CONSTRAINT sa_invc_status_pairs CHECK (
                    (status <> \'issued\' OR issued_at IS NOT NULL)
                    AND (status <> \'paid\' OR (paid_at IS NOT NULL AND issued_at IS NOT NULL))
                    AND (status <> \'void\' OR voided_at IS NOT NULL)
                    AND (status <> \'draft\' OR (issued_at IS NULL AND paid_at IS NULL))
                )
            )' . $suffix
        );

        $db->run('CREATE INDEX sa_invc_engagement_idx ON sa_invoices (engagement_id, status)');
        $db->run('CREATE INDEX sa_invc_status_idx ON sa_invoices (status, issued_at)');

        // ------------------------------------------------------------------
        // Recoveries. Append-only. Section 19, every rule of it.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_recoveries (
                id                    CHAR(36)     NOT NULL,
                public_ref            VARCHAR(24)  NOT NULL,
                engagement_id         CHAR(36)     NOT NULL,
                organization_id       CHAR(36)     NOT NULL,
                work_batch_id         CHAR(36)     NOT NULL,
                kind                  VARCHAR(20)  NOT NULL,
                adjusts_recovery_id   CHAR(36)         NULL,
                agreement_document_id CHAR(36)         NULL,
                original_denied_cents BIGINT       NOT NULL,
                amount_cents          BIGINT       NOT NULL,
                qualifies             TINYINT      NOT NULL,
                fee_basis             VARCHAR(30)  NOT NULL,
                fee_rate_bps          INTEGER          NULL,
                fee_cents             BIGINT       NOT NULL,
                verification_source   VARCHAR(30)  NOT NULL,
                verified_at           DATETIME     NOT NULL,
                verified_by           CHAR(36)         NULL,
                note                  VARCHAR(500)     NULL,
                invoice_id            CHAR(36)         NULL,
                created_at            DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_rec_ref_unique UNIQUE (public_ref),
                CONSTRAINT sa_rec_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_rec_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_rec_batch_fk FOREIGN KEY (work_batch_id)
                    REFERENCES sa_work_batches (id) ON DELETE CASCADE,
                CONSTRAINT sa_rec_invoice_fk FOREIGN KEY (invoice_id)
                    REFERENCES sa_invoices (id) ON DELETE SET NULL,
                CONSTRAINT sa_rec_kind_check CHECK (kind IN (
                    \'verified\', \'adjustment\', \'reversal\'
                )),
                CONSTRAINT sa_rec_source_check CHECK (verification_source IN (
                    \'remittance\', \'bank_deposit\', \'practice_confirmation\', \'payer_portal\', \'other\'
                )),
                CONSTRAINT sa_rec_denied_check CHECK (original_denied_cents >= 0),
                CONSTRAINT sa_rec_amount_check CHECK (amount_cents >= 0),
                CONSTRAINT sa_rec_fee_check CHECK (fee_cents >= 0),
                CONSTRAINT sa_rec_rate_check CHECK (
                    fee_rate_bps IS NULL OR (fee_rate_bps >= 0 AND fee_rate_bps <= 10000)
                ),
                CONSTRAINT sa_rec_qualifies_check CHECK (qualifies IN (0, 1)),

                -- Rule 6. Money that does not qualify creates no fee.
                CONSTRAINT sa_rec_qualifying_fee CHECK (qualifies = 1 OR fee_cents = 0),

                -- Rule 8. A verified row stands alone. An adjustment or a
                -- reversal always names the row it takes from. No foreign key
                -- on adjusts_recovery_id, because a CHECK references it and
                -- MariaDB refuses that pairing; the rows are append-only and
                -- cascade from the engagement together.
                CONSTRAINT sa_rec_kind_pair CHECK (
                    (kind = \'verified\' AND adjusts_recovery_id IS NULL)
                    OR (kind <> \'verified\' AND adjusts_recovery_id IS NOT NULL)
                )
            )' . $suffix
        );

        $db->run('CREATE INDEX sa_rec_engagement_idx ON sa_recoveries (engagement_id, kind)');
        $db->run('CREATE INDEX sa_rec_batch_idx ON sa_recoveries (work_batch_id)');
        $db->run('CREATE INDEX sa_rec_invoice_idx ON sa_recoveries (invoice_id)');
        $db->run('CREATE INDEX sa_rec_parent_idx ON sa_recoveries (adjusts_recovery_id)');

        // ------------------------------------------------------------------
        // Closeouts. One per engagement. Section 15.10.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_closeouts (
                id                 CHAR(36)      NOT NULL,
                engagement_id      CHAR(36)      NOT NULL,
                organization_id    CHAR(36)      NOT NULL,
                started_at         DATETIME      NOT NULL,
                started_by         CHAR(36)          NULL,
                final_summary      VARCHAR(2000)     NULL,
                access_outcome     VARCHAR(20)       NULL,
                data_disposition   VARCHAR(30)       NULL,
                disposition_note   VARCHAR(500)      NULL,
                record_document_id CHAR(36)          NULL,
                closed_at          DATETIME          NULL,
                closed_by          CHAR(36)          NULL,
                created_at         DATETIME      NOT NULL,
                updated_at         DATETIME      NOT NULL,
                row_version        INTEGER       NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_clo_eng_unique UNIQUE (engagement_id),
                CONSTRAINT sa_clo_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_clo_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_clo_access_check CHECK (access_outcome IS NULL OR access_outcome IN (
                    \'removed\', \'retained\', \'mixed\'
                )),
                CONSTRAINT sa_clo_disposition_check CHECK (data_disposition IS NULL OR data_disposition IN (
                    \'returned\', \'destroyed\', \'retained_per_agreement\'
                )),

                -- A closed closeout has a disposition on it. Nothing closes
                -- with the data question unanswered.
                CONSTRAINT sa_clo_closed_pair CHECK (
                    closed_at IS NULL OR data_disposition IS NOT NULL
                )
            )' . $suffix
        );

        $db->run('CREATE INDEX sa_clo_org_idx ON sa_closeouts (organization_id)');

        // ------------------------------------------------------------------
        // The four steps of section 7.4, each with who confirmed it.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_closeout_steps (
                closeout_id   CHAR(36)      NOT NULL,
                step_key      VARCHAR(30)   NOT NULL,
                display_order INTEGER       NOT NULL,
                confirmed_at  DATETIME          NULL,
                confirmed_by  CHAR(36)          NULL,
                note          VARCHAR(2000)     NULL,
                created_at    DATETIME      NOT NULL,
                PRIMARY KEY (closeout_id, step_key),
                CONSTRAINT sa_cls_closeout_fk FOREIGN KEY (closeout_id)
                    REFERENCES sa_closeouts (id) ON DELETE CASCADE,
                CONSTRAINT sa_cls_key_check CHECK (step_key IN (
                    \'reconciliation\', \'final_report\', \'access_review\', \'data_disposition\'
                )),
                CONSTRAINT sa_cls_order_check CHECK (display_order >= 1)
            )' . $suffix
        );

        // ------------------------------------------------------------------
        // The access review. One row per person with access when closeout
        // began, and the decision on each. A snapshot of the email and the
        // roles, so the record still reads after the membership rows are
        // gone.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_access_reviews (
                id           CHAR(36)     NOT NULL,
                closeout_id  CHAR(36)     NOT NULL,
                user_id      CHAR(36)     NOT NULL,
                email        VARCHAR(200) NOT NULL,
                contact_name VARCHAR(160)     NULL,
                roles        VARCHAR(200) NOT NULL,
                decision     VARCHAR(20)      NULL,
                decided_at   DATETIME         NULL,
                decided_by   CHAR(36)         NULL,
                created_at   DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_acc_user_unique UNIQUE (closeout_id, user_id),
                CONSTRAINT sa_acc_closeout_fk FOREIGN KEY (closeout_id)
                    REFERENCES sa_closeouts (id) ON DELETE CASCADE,
                CONSTRAINT sa_acc_decision_check CHECK (decision IS NULL OR decision IN (
                    \'removed\', \'retained\'
                )),

                -- A decision says when. No decision, no stamp.
                CONSTRAINT sa_acc_decided_pair CHECK (
                    (decision IS NULL AND decided_at IS NULL)
                    OR (decision IS NOT NULL AND decided_at IS NOT NULL)
                )
            )' . $suffix
        );
    },

    'down' => static function (\SoftAppeals\Database $db): void {
        foreach ([
            'sa_access_reviews',
            'sa_closeout_steps',
            'sa_closeouts',
            'sa_recoveries',
            'sa_invoices',
        ] as $table) {
            $db->run('DROP TABLE IF EXISTS ' . $table);
        }
    },
];
