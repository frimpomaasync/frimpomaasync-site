<?php
declare(strict_types=1);

/**
 * Migration 0007 · The recovery agreement, approvals and submissions. Phase 6.
 *
 * Four tables, and the rule that runs through all of them is the same as
 * 0006: section 5, the data boundary. A scope names batches, not claims. An
 * approval carries a safe summary, a count and a dollar figure. A submission
 * event carries a count, a dollar figure and a date. Nothing here can hold a
 * patient, a member, a claim number or a date of service.
 *
 * `sa_recovery_scopes` is one row per engagement (UNIQUE on engagement_id):
 * the fee basis the recovery runs on, the aggregate summary of what is in
 * scope, and the submission approver the practice named. It is the source the
 * Recovery Services Agreement and the Approved Recovery Scope are generated
 * from, which is why it has to exist before either document can.
 *
 * `sa_recovery_scope_batches` says which batches the scope covers. A batch
 * outside it cannot be put up for approval, in code and by the join.
 *
 * `sa_approval_requests` is section 11.1's table and Gate C of section 6. The
 * CHECK on the decision pair is what makes "double submission does not create
 * duplicate approval events" structural: a request is decided exactly once,
 * because the UPDATE that decides it is guarded on state = pending, and the
 * idempotency key is UNIQUE.
 *
 * `sa_submission_events` records what went to a payer and what came back, in
 * aggregate. There is no fee column in it and no fee column anywhere in this
 * migration. Section 19: a submission does not create a fee, a favorable
 * decision does not create a fee. The money phase adds its own table for the
 * one thing that does.
 */

return [
    'name' => '0007_recovery_agreement_and_approvals',

    'up' => static function (\SoftAppeals\Database $db): void {
        $sqlite = $db->isSqlite();
        $suffix = $sqlite
            ? ''
            : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // ------------------------------------------------------------------
        // The recovery scope. One per engagement.
        //
        // fee_rate_bps is basis points, section 19. It is NULL for a fee basis
        // that is not a percentage, and it is bounded, because a rate above
        // ten thousand basis points is a typo that would become a contract.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_recovery_scopes (
                id                    CHAR(36)      NOT NULL,
                engagement_id         CHAR(36)      NOT NULL,
                organization_id       CHAR(36)      NOT NULL,
                fee_basis             VARCHAR(30)   NOT NULL,
                fee_rate_bps          INTEGER           NULL,
                summary               VARCHAR(1000) NOT NULL,
                approver_contact_id   CHAR(36)          NULL,
                approver_confirmed_at DATETIME          NULL,
                recorded_by           CHAR(36)          NULL,
                created_at            DATETIME      NOT NULL,
                updated_at            DATETIME      NOT NULL,
                row_version           INTEGER       NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_scope_eng_unique UNIQUE (engagement_id),
                CONSTRAINT sa_scope_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_scope_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_scope_approver_fk FOREIGN KEY (approver_contact_id)
                    REFERENCES sa_contacts (id) ON DELETE SET NULL,
                CONSTRAINT sa_scope_fee_check CHECK (fee_basis IN (
                    \'contingency_25\', \'fixed\', \'custom\', \'scoped\'
                )),
                CONSTRAINT sa_scope_rate_check CHECK (
                    fee_rate_bps IS NULL OR (fee_rate_bps >= 0 AND fee_rate_bps <= 10000)
                ),
                CONSTRAINT sa_scope_summary_check CHECK (length(summary) >= 10),

                -- An approver is confirmed by naming one. A confirmation stamp
                -- with nobody behind it is a tick with no person.
                CONSTRAINT sa_scope_approver_pair CHECK (
                    approver_confirmed_at IS NULL OR approver_contact_id IS NOT NULL
                )
            )' . $suffix
        );

        $db->run('CREATE INDEX sa_scope_org_idx ON sa_recovery_scopes (organization_id)');

        // ------------------------------------------------------------------
        // Which batches the scope covers.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_recovery_scope_batches (
                scope_id      CHAR(36) NOT NULL,
                work_batch_id CHAR(36) NOT NULL,
                created_at    DATETIME NOT NULL,
                PRIMARY KEY (scope_id, work_batch_id),
                CONSTRAINT sa_scopeb_scope_fk FOREIGN KEY (scope_id)
                    REFERENCES sa_recovery_scopes (id) ON DELETE CASCADE,
                CONSTRAINT sa_scopeb_batch_fk FOREIGN KEY (work_batch_id)
                    REFERENCES sa_work_batches (id) ON DELETE CASCADE
            )' . $suffix
        );

        // ------------------------------------------------------------------
        // Approval requests. Section 11.1 and section 6, Gate C.
        //
        // safe_summary is what the approver reads in the portal: which batch,
        // how many, how much, what is being sent and to whom at business
        // level. Screened in code before it is written. The patient-level
        // materials are reviewed in the approved secure channel and never
        // come near this row.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_approval_requests (
                id                  CHAR(36)     NOT NULL,
                public_ref          VARCHAR(24)  NOT NULL,
                engagement_id       CHAR(36)     NOT NULL,
                organization_id     CHAR(36)     NOT NULL,
                work_batch_id       CHAR(36)     NOT NULL,
                kind                VARCHAR(30)  NOT NULL,
                safe_summary        VARCHAR(500) NOT NULL,
                claim_count         INTEGER      NOT NULL,
                amount_cents        BIGINT       NOT NULL,
                requested_from      CHAR(36)         NULL,
                due_at              DATETIME         NULL,
                state               VARCHAR(20)  NOT NULL,
                decision_at         DATETIME         NULL,
                decision_by         CHAR(36)         NULL,
                decision_contact_id CHAR(36)         NULL,
                decision_note       VARCHAR(500)     NULL,
                idempotency_key     CHAR(64)         NULL,
                requested_by        CHAR(36)         NULL,
                created_at          DATETIME     NOT NULL,
                updated_at          DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_appr_ref_unique UNIQUE (public_ref),
                CONSTRAINT sa_appr_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_appr_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_appr_batch_fk FOREIGN KEY (work_batch_id)
                    REFERENCES sa_work_batches (id) ON DELETE CASCADE,
                CONSTRAINT sa_appr_from_fk FOREIGN KEY (requested_from)
                    REFERENCES sa_contacts (id) ON DELETE SET NULL,
                CONSTRAINT sa_appr_decider_fk FOREIGN KEY (decision_contact_id)
                    REFERENCES sa_contacts (id) ON DELETE SET NULL,
                CONSTRAINT sa_appr_kind_check CHECK (kind IN (\'submission\')),
                CONSTRAINT sa_appr_state_check CHECK (state IN (
                    \'pending\', \'approved\', \'returned\', \'expired\', \'cancelled\'
                )),
                CONSTRAINT sa_appr_count_check CHECK (claim_count >= 0),
                CONSTRAINT sa_appr_amount_check CHECK (amount_cents >= 0),
                CONSTRAINT sa_appr_summary_check CHECK (length(safe_summary) >= 10),

                -- A decided request says when. A pending one does not.
                CONSTRAINT sa_appr_decided_pair CHECK (
                    (state = \'pending\' AND decision_at IS NULL)
                    OR (state <> \'pending\' AND decision_at IS NOT NULL)
                )
            )' . $suffix
        );

        $db->run('CREATE INDEX sa_appr_engagement_idx ON sa_approval_requests (engagement_id, state)');
        $db->run('CREATE INDEX sa_appr_batch_idx ON sa_approval_requests (work_batch_id, state)');
        $db->run('CREATE UNIQUE INDEX sa_appr_idempotency_idx ON sa_approval_requests (idempotency_key)');

        // ------------------------------------------------------------------
        // Submission events. What went to the payer and what came back.
        //
        // A submitted event has to point at the approval that allowed it.
        // That is Gate C as a CHECK: nothing is recorded as sent to a payer
        // without an approval row behind it.
        //
        // approval_request_id carries no foreign key on purpose. Both tables
        // cascade from the engagement, and a SET NULL on the approval would
        // collide with the Gate C check the moment the engagement was closed.
        // Nothing in the application deletes an approval on its own: the
        // rows are append-only, and the pointer is written once by the one
        // method that records a submission.
        //
        // There is no fee column here and none will be added. Section 19.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_submission_events (
                id                  CHAR(36)     NOT NULL,
                public_ref          VARCHAR(24)  NOT NULL,
                engagement_id       CHAR(36)     NOT NULL,
                organization_id     CHAR(36)     NOT NULL,
                work_batch_id       CHAR(36)     NOT NULL,
                approval_request_id CHAR(36)         NULL,
                event_type          VARCHAR(30)  NOT NULL,
                claim_count         INTEGER      NOT NULL,
                amount_cents        BIGINT       NOT NULL,
                occurred_at         DATETIME     NOT NULL,
                note                VARCHAR(500)     NULL,
                follow_up_due_at    DATETIME         NULL,
                follow_up_done_at   DATETIME         NULL,
                recorded_by         CHAR(36)         NULL,
                created_at          DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_sub_ref_unique UNIQUE (public_ref),
                CONSTRAINT sa_sub_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_sub_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_sub_batch_fk FOREIGN KEY (work_batch_id)
                    REFERENCES sa_work_batches (id) ON DELETE CASCADE,
                CONSTRAINT sa_sub_type_check CHECK (event_type IN (
                    \'submitted\', \'payer_acknowledged\', \'information_requested\',
                    \'decision_favorable\', \'decision_partial\', \'decision_unfavorable\',
                    \'withdrawn\'
                )),
                CONSTRAINT sa_sub_count_check CHECK (claim_count >= 0),
                CONSTRAINT sa_sub_amount_check CHECK (amount_cents >= 0),

                -- Gate C. Nothing is recorded as submitted without the approval
                -- that allowed it.
                CONSTRAINT sa_sub_gate_c CHECK (
                    event_type <> \'submitted\' OR approval_request_id IS NOT NULL
                ),

                -- A follow-up is done only if one was set.
                CONSTRAINT sa_sub_followup_pair CHECK (
                    follow_up_done_at IS NULL OR follow_up_due_at IS NOT NULL
                )
            )' . $suffix
        );

        $db->run('CREATE INDEX sa_sub_engagement_idx ON sa_submission_events (engagement_id, occurred_at)');
        $db->run('CREATE INDEX sa_sub_batch_idx ON sa_submission_events (work_batch_id, occurred_at)');
        $db->run('CREATE INDEX sa_sub_followup_idx ON sa_submission_events (follow_up_due_at)');
        $db->run('CREATE INDEX sa_sub_approval_idx ON sa_submission_events (approval_request_id)');
    },

    'down' => static function (\SoftAppeals\Database $db): void {
        foreach ([
            'sa_submission_events',
            'sa_approval_requests',
            'sa_recovery_scope_batches',
            'sa_recovery_scopes',
        ] as $table) {
            $db->run('DROP TABLE IF EXISTS ' . $table);
        }
    },
];
