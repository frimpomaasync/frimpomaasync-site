<?php
declare(strict_types=1);

/**
 * Migration 0001 · Foundation.
 *
 * The tables Phase 1 needs to stand up: identity, authorization, the audit
 * trail, rate limiting, and the organizations everything else will hang from.
 * The engagement, document, signature and money tables arrive in later phases
 * against later migrations, so that a rollback here is small and total.
 *
 * Portability rules this file follows, so the same SQL runs on MySQL 8 and on
 * the SQLite used to prove it locally:
 *
 *   no ENUM             a VARCHAR plus a CHECK, validated again in PHP
 *   no JSON functions   metadata is TEXT holding JSON, read in PHP
 *   no AUTO_INCREMENT   every key is a CHAR(36) UUIDv4
 *   no DEFAULT on TEXT  MySQL will not take one
 *   DATETIME as text    'Y-m-d H:i:s', always UTC, never a native timestamp
 *
 * Money is not in this migration at all. When it arrives it is BIGINT cents and
 * basis points, never a float, per section 19.
 */

return [
    'name' => '0001_foundation',

    'up' => static function (\SoftAppeals\Database $db): void {
        $sqlite = $db->isSqlite();

        // MySQL wants an engine and a charset. SQLite wants neither and will
        // refuse the clause, so it is appended only where it belongs.
        $suffix = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // ------------------------------------------------------------------
        // Organizations. Practices. Business-level only, by design: there is
        // no column here that could hold a patient, a claim, or a date of
        // service. ADR-009 enforced by the schema.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_organizations (
                id                CHAR(36)     NOT NULL,
                public_ref        VARCHAR(24)  NOT NULL,
                legal_name        VARCHAR(200) NOT NULL,
                display_name      VARCHAR(200)     NULL,
                organization_type VARCHAR(40)      NULL,
                state             CHAR(2)          NULL,
                status            VARCHAR(20)  NOT NULL,
                created_at        DATETIME     NOT NULL,
                updated_at        DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_org_ref_unique UNIQUE (public_ref),
                CONSTRAINT sa_org_status_check
                    CHECK (status IN (\'prospect\', \'active\', \'closed\'))
            )' . $suffix
        );
        $db->run('CREATE INDEX sa_org_status_idx ON sa_organizations (status)');

        // ------------------------------------------------------------------
        // Contacts. A named person at a practice. work_email is normalised to
        // lowercase by the application before it is written, so the index is a
        // real uniqueness guarantee and not a case-sensitive near-miss.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_contacts (
                id              CHAR(36)     NOT NULL,
                organization_id CHAR(36)     NOT NULL,
                name            VARCHAR(160) NOT NULL,
                work_email      VARCHAR(200) NOT NULL,
                role_title      VARCHAR(120)     NULL,
                phone           VARCHAR(40)      NULL,
                active          TINYINT      NOT NULL,
                created_at      DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_contact_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_contact_active_check CHECK (active IN (0, 1))
            )' . $suffix
        );
        $db->run('CREATE INDEX sa_contact_email_idx ON sa_contacts (work_email)');
        $db->run('CREATE INDEX sa_contact_org_idx ON sa_contacts (organization_id)');

        // ------------------------------------------------------------------
        // Users. Her, plus any staff account. password_hash is nullable
        // because a client contact never has one: client access is
        // passwordless by design, per section 10.2.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_users (
                id            CHAR(36)     NOT NULL,
                contact_id    CHAR(36)         NULL,
                email         VARCHAR(200) NOT NULL,
                password_hash VARCHAR(255)     NULL,
                active        TINYINT      NOT NULL,
                last_login_at DATETIME         NULL,
                created_at    DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_user_email_unique UNIQUE (email),
                CONSTRAINT sa_user_contact_fk FOREIGN KEY (contact_id)
                    REFERENCES sa_contacts (id) ON DELETE SET NULL,
                CONSTRAINT sa_user_active_check CHECK (active IN (0, 1))
            )' . $suffix
        );

        // ------------------------------------------------------------------
        // Memberships. A staff row has organization_id NULL and applies
        // everywhere; a client row names one organization and applies only
        // there.
        //
        // organization_scope is the uniqueness key. It holds the same value as
        // organization_id, or the literal GLOBAL for a staff row, so one plain
        // unique index covers both cases on both engines. See the index below.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_memberships (
                user_id            CHAR(36)    NOT NULL,
                organization_id    CHAR(36)        NULL,
                organization_scope CHAR(36)    NOT NULL,
                role               VARCHAR(40) NOT NULL,
                created_at         DATETIME    NOT NULL,
                CONSTRAINT sa_membership_user_fk FOREIGN KEY (user_id)
                    REFERENCES sa_users (id) ON DELETE CASCADE,
                CONSTRAINT sa_membership_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE
            )' . $suffix
        );
        $db->run('CREATE INDEX sa_membership_user_idx ON sa_memberships (user_id)');
        $db->run('CREATE INDEX sa_membership_org_idx ON sa_memberships (organization_id)');

        // One unique index, on both engines, with no generated column and no
        // partial index.
        //
        // The problem: a staff membership has organization_id NULL and must be
        // unique per (user, role), while a client membership names an
        // organization and must be unique per (user, organization, role). NULL
        // never equals NULL in SQL, so a plain unique index on the triple would
        // happily accept the same staff role twice.
        //
        // SQLite solves that with a partial index and MySQL with a generated
        // column, and the first version of this migration used one of each.
        // That is two schemas wearing one name, and only one of them was ever
        // executed before it reached a server.
        //
        // Instead the application writes an explicit sentinel. organization_id
        // stays nullable and keeps the foreign key; organization_scope is NOT
        // NULL and carries either the same id or the literal GLOBAL. A UUIDv4
        // always carries hyphens in fixed positions, so the sentinel can never
        // collide with one.
        $db->run(
            'CREATE UNIQUE INDEX sa_membership_unique ON sa_memberships (user_id, organization_scope, role)'
        );

        // ------------------------------------------------------------------
        // The audit trail. Append only. No update path and no delete path
        // exists in the application, and AuditService offers neither.
        //
        // ip_digest and user_agent_digest are keyed digests, never the values.
        // metadata is TEXT holding JSON filtered against an allowlist, so a
        // caller cannot smuggle a patient name in under a plausible key.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_audit_events (
                id                CHAR(36)    NOT NULL,
                actor_user_id     CHAR(36)        NULL,
                organization_id   CHAR(36)        NULL,
                action            VARCHAR(80) NOT NULL,
                object_type       VARCHAR(40)     NULL,
                object_id         CHAR(36)        NULL,
                outcome           VARCHAR(20) NOT NULL,
                correlation_id    VARCHAR(32)     NULL,
                ip_digest         CHAR(64)        NULL,
                user_agent_digest CHAR(64)        NULL,
                metadata          TEXT            NULL,
                created_at        DATETIME    NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_audit_outcome_check
                    CHECK (outcome IN (\'success\', \'failure\', \'denied\', \'error\'))
            )' . $suffix
        );
        // No foreign key from actor_user_id to sa_users on purpose. The trail
        // must survive the deletion of the account it describes, which is
        // exactly the case an audit exists for.
        $db->run('CREATE INDEX sa_audit_created_idx ON sa_audit_events (created_at)');
        $db->run('CREATE INDEX sa_audit_action_idx ON sa_audit_events (action)');
        $db->run('CREATE INDEX sa_audit_object_idx ON sa_audit_events (object_type, object_id)');
        $db->run('CREATE INDEX sa_audit_org_idx ON sa_audit_events (organization_id)');

        // ------------------------------------------------------------------
        // Rate limiting. Fixed windows, subject stored only as a digest, so
        // the table cannot be read as a list of who tried to log in.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_rate_limits (
                action         VARCHAR(40) NOT NULL,
                subject_digest CHAR(64)    NOT NULL,
                window_start   DATETIME    NOT NULL,
                attempts       INTEGER     NOT NULL,
                updated_at     DATETIME    NOT NULL,
                PRIMARY KEY (action, subject_digest, window_start)
            )' . $suffix
        );
        $db->run('CREATE INDEX sa_rate_updated_idx ON sa_rate_limits (updated_at)');

        // ------------------------------------------------------------------
        // Idempotency. One row per externally-triggered effect, so a double
        // click or a network retry cannot send two emails or record two
        // signatures. Section 18 and the plan's idempotency_keys table.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_idempotency_keys (
                idempotency_key CHAR(64)    NOT NULL,
                scope           VARCHAR(40) NOT NULL,
                object_type     VARCHAR(40)     NULL,
                object_id       CHAR(36)        NULL,
                outcome         VARCHAR(20)     NULL,
                created_at      DATETIME    NOT NULL,
                PRIMARY KEY (idempotency_key)
            )' . $suffix
        );
        $db->run('CREATE INDEX sa_idem_scope_idx ON sa_idempotency_keys (scope, created_at)');
    },

    'down' => static function (\SoftAppeals\Database $db): void {
        // Reverse creation order so the foreign keys never block a drop.
        foreach ([
            'sa_idempotency_keys',
            'sa_rate_limits',
            'sa_audit_events',
            'sa_memberships',
            'sa_users',
            'sa_contacts',
            'sa_organizations',
        ] as $table) {
            $db->run('DROP TABLE IF EXISTS ' . $db->quoteIdentifier($table));
        }
    },
];
