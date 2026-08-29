<?php
declare(strict_types=1);

/**
 * Migration 0009 · Automation. Phase 8.
 *
 * Three tables, and none of them holds a person.
 *
 * `sa_job_locks` is section 17.2's last line made structural: "jobs use a
 * database lock so overlapping cron executions cannot run the same job
 * twice". One row per job, holding who has it and until when. Acquiring is
 * an UPDATE guarded on the lock having lapsed, so two crons racing for the
 * same job leave exactly one holder and the other gets nothing back.
 *
 * `sa_job_runs` is the PHI-free job health log as rows, so the Desk can say
 * when each job last ran, whether it worked, and what it did, in counts.
 * The Phase 8 acceptance line "job failures surface on The Desk" reads from
 * here. A run that finished carries an outcome; an open run carries none.
 *
 * `sa_attention_items` is what the jobs surface: a deadline group crossing
 * thirty, fourteen, seven, three or one day; a favorable decision still
 * waiting on payment verification; open access at closeout; an internal
 * task past its date. `item_key` is UNIQUE, which is what makes a job safe
 * to rerun: the second run touches the row it made the first time rather
 * than making a second one. A label here is a practice name, a batch label
 * and a count. Never a patient, never a claim.
 *
 * The MariaDB rules paid for in 0007 and 0008 still apply: constraint names
 * are unique across the database (the prefixes here are sa_jobl_, sa_jobr_
 * and sa_att_, none of them taken), no CHECK references a column a foreign
 * key can SET NULL, and the down half is a foreach over tables.
 */

return [
    'name' => '0009_automation',

    'up' => static function (\SoftAppeals\Database $db): void {
        $sqlite = $db->isSqlite();
        $suffix = $sqlite
            ? ''
            : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // ------------------------------------------------------------------
        // Job locks. One row per job. The token says who holds it.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_job_locks (
                job_key      VARCHAR(40) NOT NULL,
                token        CHAR(36)    NOT NULL,
                locked_until DATETIME    NOT NULL,
                updated_at   DATETIME    NOT NULL,
                PRIMARY KEY (job_key),
                CONSTRAINT sa_jobl_key_check CHECK (length(job_key) >= 3)
            )' . $suffix
        );

        // ------------------------------------------------------------------
        // Job runs. Append-only from the application: a row is opened when
        // a job starts and closed once, with its outcome.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_job_runs (
                id          CHAR(36)     NOT NULL,
                job_key     VARCHAR(40)  NOT NULL,
                trigger_by  VARCHAR(20)  NOT NULL,
                started_at  DATETIME     NOT NULL,
                finished_at DATETIME         NULL,
                outcome     VARCHAR(20)      NULL,
                items       INTEGER      NOT NULL,
                summary     VARCHAR(500)     NULL,
                created_at  DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_jobr_trigger_check CHECK (trigger_by IN (
                    \'cron\', \'desk\', \'cli\', \'test\'
                )),
                CONSTRAINT sa_jobr_outcome_check CHECK (outcome IS NULL OR outcome IN (
                    \'ok\', \'failed\', \'skipped\'
                )),
                CONSTRAINT sa_jobr_items_check CHECK (items >= 0),

                -- A finished run says how it went. An open run says nothing yet.
                CONSTRAINT sa_jobr_finished_pair CHECK (
                    (finished_at IS NULL AND outcome IS NULL)
                    OR (finished_at IS NOT NULL AND outcome IS NOT NULL)
                )
            )' . $suffix
        );

        $db->run('CREATE INDEX sa_jobr_key_idx ON sa_job_runs (job_key, started_at)');
        $db->run('CREATE INDEX sa_jobr_started_idx ON sa_job_runs (started_at)');

        // ------------------------------------------------------------------
        // Attention items. What the jobs surface for her.
        //
        // engagement_id and organization_id cascade, so closing a practice
        // takes its items with it. Neither is referenced by a CHECK.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_attention_items (
                id              CHAR(36)     NOT NULL,
                item_key        VARCHAR(120) NOT NULL,
                kind            VARCHAR(40)  NOT NULL,
                severity        VARCHAR(10)  NOT NULL,
                engagement_id   CHAR(36)         NULL,
                organization_id CHAR(36)         NULL,
                label           VARCHAR(200) NOT NULL,
                detail          VARCHAR(300)     NULL,
                link            VARCHAR(200)     NULL,
                first_seen_at   DATETIME     NOT NULL,
                last_seen_at    DATETIME     NOT NULL,
                resolved_at     DATETIME         NULL,
                dismissed_at    DATETIME         NULL,
                dismissed_by    CHAR(36)         NULL,
                created_at      DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_att_key_unique UNIQUE (item_key),
                CONSTRAINT sa_att_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_att_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_att_kind_check CHECK (kind IN (
                    \'deadline\', \'payment_pending\', \'closeout_access\',
                    \'internal_task\', \'countersign\', \'submission\',
                    \'follow_up\', \'invoice_overdue\', \'backup\', \'job_failed\'
                )),
                CONSTRAINT sa_att_severity_check CHECK (severity IN (
                    \'urgent\', \'action\', \'note\'
                )),
                CONSTRAINT sa_att_label_check CHECK (length(label) >= 3),

                -- A dismissal says who and when, together or not at all.
                CONSTRAINT sa_att_dismissed_pair CHECK (
                    (dismissed_at IS NULL AND dismissed_by IS NULL)
                    OR (dismissed_at IS NOT NULL)
                )
            )' . $suffix
        );

        $db->run('CREATE INDEX sa_att_open_idx ON sa_attention_items (resolved_at, dismissed_at, severity)');
        $db->run('CREATE INDEX sa_att_kind_idx ON sa_attention_items (kind, resolved_at)');
        $db->run('CREATE INDEX sa_att_engagement_idx ON sa_attention_items (engagement_id)');
    },

    'down' => static function (\SoftAppeals\Database $db): void {
        foreach ([
            'sa_attention_items',
            'sa_job_runs',
            'sa_job_locks',
        ] as $table) {
            $db->run('DROP TABLE IF EXISTS ' . $table);
        }
    },
];
