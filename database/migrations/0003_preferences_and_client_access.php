<?php
declare(strict_types=1);

/**
 * Migration 0003 · Preferences and client access.
 *
 * What Phase 3 needs: the answers a practice gives on the preferences page,
 * and the six-digit codes that let the same people back in afterwards without
 * a password.
 *
 * Two tables, and the reason there are two rather than one.
 *
 * `sa_engagement_preferences` is one row per engagement, not one row per
 * answer. The eight questions in section 13.2 are a fixed set that is answered
 * once and then read on every screen the client sees, so a column each is the
 * shape that makes the read cheap and the constraint real: a cadence nobody
 * offered cannot be stored, because the CHECK refuses it.
 *
 * `sa_login_codes` is separate from `sa_invitations` on purpose. An invitation
 * token is 32 random bytes and its digest can carry a unique constraint,
 * because two of them colliding is not a thing that happens. A six-digit code
 * has a million values and a handful of live ones, so a unique digest would
 * eventually refuse a legitimate code for no reason a person could understand.
 * The code is therefore looked up by address AND digest, it carries its own
 * attempt counter, and the digest is keyed with the application secret rather
 * than being a bare hash, so a copy of this table is not a million-guess
 * offline exercise.
 *
 * The PHI boundary, again by absence. The free-text columns here are the two
 * the plan allows, both described to the person as business level, both capped
 * by the application, and both screened before they are written. There is no
 * column for a patient, a member number, a claim, or a date of service.
 */

return [
    'name' => '0003_preferences_and_client_access',

    'up' => static function (\SoftAppeals\Database $db): void {
        $suffix = $db->isSqlite()
            ? ''
            : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // ------------------------------------------------------------------
        // Engagement preferences. Section 11.1 and section 13.2.
        //
        // engagement_id is UNIQUE. One engagement has one set of preferences,
        // and a second submit updates the row it finds rather than opening a
        // second answer to the same eight questions. That uniqueness is what
        // makes "preferences update the engagement state once" enforceable at
        // the layer nothing can go around.
        //
        // The four contact columns are SET NULL rather than CASCADE. Removing
        // a person from a practice must not delete the record of what that
        // practice chose; the choice stays, the pointer to the person goes.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_engagement_preferences (
                id                      CHAR(36)    NOT NULL,
                engagement_id           CHAR(36)    NOT NULL,
                organization_id         CHAR(36)    NOT NULL,
                communication_cadence   VARCHAR(30) NOT NULL,
                secure_channel          VARCHAR(40) NOT NULL,
                billing_partner         VARCHAR(10) NOT NULL,
                signer_contact_id       CHAR(36)        NULL,
                approver_contact_id     CHAR(36)        NULL,
                billing_contact_id      CHAR(36)        NULL,
                compliance_contact_id   CHAR(36)        NULL,
                initial_payer_group     TEXT            NULL,
                procurement_notes       TEXT            NULL,
                confirmed_at            DATETIME        NULL,
                confirmed_by_contact_id CHAR(36)        NULL,
                created_at              DATETIME    NOT NULL,
                updated_at              DATETIME    NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_pref_eng_unique UNIQUE (engagement_id),
                CONSTRAINT sa_pref_eng_fk FOREIGN KEY (engagement_id)
                    REFERENCES sa_engagements (id) ON DELETE CASCADE,
                CONSTRAINT sa_pref_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_pref_signer_fk FOREIGN KEY (signer_contact_id)
                    REFERENCES sa_contacts (id) ON DELETE SET NULL,
                CONSTRAINT sa_pref_approver_fk FOREIGN KEY (approver_contact_id)
                    REFERENCES sa_contacts (id) ON DELETE SET NULL,
                CONSTRAINT sa_pref_billing_fk FOREIGN KEY (billing_contact_id)
                    REFERENCES sa_contacts (id) ON DELETE SET NULL,
                CONSTRAINT sa_pref_compliance_fk FOREIGN KEY (compliance_contact_id)
                    REFERENCES sa_contacts (id) ON DELETE SET NULL,
                CONSTRAINT sa_pref_confirmed_by_fk FOREIGN KEY (confirmed_by_contact_id)
                    REFERENCES sa_contacts (id) ON DELETE SET NULL,
                CONSTRAINT sa_pref_cadence_check CHECK (communication_cadence IN (
                    \'weekly\', \'biweekly\', \'monthly\', \'milestones\'
                )),
                CONSTRAINT sa_pref_channel_check CHECK (secure_channel IN (
                    \'client_system\', \'soft_appeals_link\', \'decide_later\'
                )),
                CONSTRAINT sa_pref_partner_check CHECK (billing_partner IN (
                    \'yes\', \'no\', \'unsure\'
                ))
            )' . $suffix
        );
        $db->run('CREATE INDEX sa_pref_org_idx ON sa_engagement_preferences (organization_id)');

        // ------------------------------------------------------------------
        // Login codes. Section 10.2.
        //
        // The code lives in the email and nowhere else. What is stored is a
        // keyed digest of the address and the code together, so the same code
        // issued to two practices produces two different digests and neither
        // digest can be reversed without the application secret.
        //
        // attempts is on the row rather than only in the rate limiter, because
        // the limiter counts by caller and this counts by code. A code that has
        // been guessed at five times is burnt regardless of who was guessing.
        // ------------------------------------------------------------------
        $db->run(
            'CREATE TABLE sa_login_codes (
                id              CHAR(36)     NOT NULL,
                organization_id CHAR(36)     NOT NULL,
                user_id         CHAR(36)         NULL,
                email           VARCHAR(200) NOT NULL,
                code_digest     CHAR(64)     NOT NULL,
                purpose         VARCHAR(30)  NOT NULL,
                expires_at      DATETIME     NOT NULL,
                used_at         DATETIME         NULL,
                revoked_at      DATETIME         NULL,
                attempts        INTEGER      NOT NULL,
                created_at      DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT sa_code_org_fk FOREIGN KEY (organization_id)
                    REFERENCES sa_organizations (id) ON DELETE CASCADE,
                CONSTRAINT sa_code_user_fk FOREIGN KEY (user_id)
                    REFERENCES sa_users (id) ON DELETE CASCADE,
                CONSTRAINT sa_code_attempts_check CHECK (attempts >= 0),
                CONSTRAINT sa_code_purpose_check CHECK (purpose IN (
                    \'client_login\'
                ))
            )' . $suffix
        );
        $db->run('CREATE INDEX sa_code_email_idx ON sa_login_codes (email, created_at)');
        $db->run('CREATE INDEX sa_code_expires_idx ON sa_login_codes (expires_at)');
    },

    'down' => static function (\SoftAppeals\Database $db): void {
        foreach ([
            'sa_login_codes',
            'sa_engagement_preferences',
        ] as $table) {
            $db->run('DROP TABLE IF EXISTS ' . $db->quoteIdentifier($table));
        }
    },
];
