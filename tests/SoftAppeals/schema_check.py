#!/usr/bin/env python3
"""
Prove the Soft Appeals migrations run up and down on an empty database.

Why this exists
---------------
Phase 1's acceptance list says "migrations run up and down on an empty test
database". There is no PHP on this Mac, no Homebrew, no Docker and no MySQL
client, and Phase 0 recorded that as blocker B-03. Staging is the answer, and
staging does not exist yet.

What this Mac does have is /usr/bin/sqlite3.

So this script reads the real migration file, extracts the SQL exactly as the
SQLite branch of the migration would execute it, and runs the whole cycle
against a throwaway database file. It proves three things that are worth
proving before a single byte reaches a server:

  1. every CREATE TABLE, CREATE INDEX and CHECK constraint parses
  2. the statements are in an order the foreign keys accept
  3. the down migration drops everything, in an order the foreign keys accept,
     leaving an empty database

It does not prove the PHP runs. Nothing on this machine can. The MySQL branch
is checked structurally only. Both gaps are stated in the output rather than
glossed over, and staging is where they close.

Usage:
    python3 tests/SoftAppeals/schema_check.py
    python3 tests/SoftAppeals/schema_check.py --keep   # leave the db file
"""

from __future__ import annotations

import argparse
import pathlib
import re
import sqlite3
import sys
import tempfile

REPO = pathlib.Path(__file__).resolve().parents[2]
MIGRATIONS = REPO / "database" / "migrations"

# What each migration must create, keyed by file name.
#
# The migrations are applied CUMULATIVELY into one database, in file order,
# which is how they run on a real server. Running each one alone in a fresh
# database was fine while there was one of them; the moment 0002 arrived with
# foreign keys pointing at 0001's tables, a per-file database was proving the
# wrong thing. SQLite will happily create a table whose foreign key names a
# table that does not exist, and only complains at insert time, so a per-file
# run would have passed while the real ordering was never checked at all.
EXPECTED_TABLES = {
    "0001_foundation.php": [
        "sa_organizations",
        "sa_contacts",
        "sa_users",
        "sa_memberships",
        "sa_audit_events",
        "sa_rate_limits",
        "sa_idempotency_keys",
    ],
    "0002_intake_and_engagement.php": [
        "sa_intakes",
        "sa_engagements",
        "sa_invitations",
        "sa_communications",
        "sa_status_events",
    ],
    "0003_preferences_and_client_access.php": [
        "sa_engagement_preferences",
        "sa_login_codes",
    ],
    # 0004 creates no table. It adds one column to sa_status_events, so the
    # list is empty on purpose rather than missing: a missing entry is a
    # migration nobody checked, an empty one is a migration that was checked
    # and creates nothing.
    "0004_status_event_sequence.php": [],
    "0005_documents_and_signatures.php": [
        "sa_documents",
        "sa_signatures",
    ],
    "0006_assessment_and_recovery_room.php": [
        "sa_settings",
        "sa_assessments",
        "sa_work_batches",
        "sa_checklist_items",
        "sa_action_requests",
    ],
    "0007_recovery_agreement_and_approvals.php": [
        "sa_recovery_scopes",
        "sa_recovery_scope_batches",
        "sa_approval_requests",
        "sa_submission_events",
    ],
    "0008_reconciliation_and_closeout.php": [
        "sa_invoices",
        "sa_recoveries",
        "sa_closeouts",
        "sa_closeout_steps",
        "sa_access_reviews",
    ],
}


class Failure(Exception):
    pass


def php_single_quoted(raw: str) -> str:
    """Unescape a PHP single-quoted string body: \\' and \\\\ only."""
    return raw.replace("\\'", "'").replace("\\\\", "\\")


def extract_statements(source: str, sqlite_branch: bool) -> list[str]:
    """
    Pull the SQL out of a migration in execution order.

    The migration branches on $db->isSqlite() for the two places MySQL and
    SQLite genuinely differ: the table suffix, and the partial unique index on
    sa_memberships. Both branches are handled here so the SQLite run is a
    faithful replay rather than an approximation.
    """
    # Split off the if/else block so only the chosen branch is read.
    if_start = source.find("if ($sqlite) {")
    if if_start == -1:
        chosen = source
    else:
        else_start = source.index("} else {", if_start)
        block_end = source.index("\n        }", else_start)
        head = source[:if_start]
        if_body = source[if_start:else_start]
        else_body = source[else_start:block_end]
        tail = source[block_end:]
        chosen = head + (if_body if sqlite_branch else else_body) + tail

    statements: list[str] = []
    # Every executed statement in these migrations is $db->run('...').
    for match in re.finditer(r"\$db->run\(\s*'((?:[^'\\]|\\.)*)'", chosen, re.S):
        sql = php_single_quoted(match.group(1))
        sql = sql.replace("' . $suffix", "").strip()
        # The MySQL branch also uses double-quoted strings; those are picked up
        # separately below so the structural check sees them too.
        statements.append(sql)

    if not sqlite_branch:
        for match in re.finditer(r'\$db->run\(\s*"((?:[^"\\]|\\.)*)"', chosen, re.S):
            statements.append(match.group(1).replace('\\"', '"').strip())

    return statements


def strip_suffix_concat(sql: str) -> str:
    """Remove a trailing PHP concatenation artefact if one survived."""
    return re.sub(r"\)\s*'\s*\.\s*\$suffix\s*$", ")", sql).strip().rstrip("'")


def split_halves(migration_path: pathlib.Path) -> tuple[list[str], list[str], list[str]]:
    """
    One migration, read into its up statements and its down half.

    There are two shapes of down half and they need reading differently.

    A migration that CREATES tables drops them in a foreach over a list of
    names. Its $db->run() call concatenates the table name, so it carries no
    complete statement and must never be swept into a statement list; the names
    are read from the list the migration itself loops over.

    A migration that ALTERS an existing table has no such list. 0004 adds one
    column and drops it again, and its down half is a complete statement. Those
    are executed as written, which is also the only way this check means
    anything for that kind of migration: dropping a table 0004 never created
    would prove nothing about whether 0004 can be undone.

    Returns (up statements, table names to drop, explicit down statements).
    Exactly one of the last two is populated.
    """
    source = migration_path.read_text(encoding="utf-8")
    split_at = source.index("'down' =>")
    up_source = source[:split_at]
    down_block = source[split_at:]

    up_sql = [strip_suffix_concat(one) for one in extract_statements(up_source, sqlite_branch=True)]
    if not up_sql:
        raise Failure(f"No SQL found in {migration_path.name}")

    if "foreach" in down_block:
        down_tables = re.findall(r"'(sa_[a-z_]+)'", down_block)
        if not down_tables:
            raise Failure(f"No tables listed in the down half of {migration_path.name}")
        return up_sql, down_tables, []

    down_sql = [strip_suffix_concat(one) for one in extract_statements(down_block, sqlite_branch=True)]
    if not down_sql:
        raise Failure(
            f"The down half of {migration_path.name} neither lists tables to drop "
            "nor carries a statement to run. It cannot be undone."
        )
    return up_sql, [], down_sql


def tables_now(connection: sqlite3.Connection) -> list[str]:
    return sorted(
        row[0]
        for row in connection.execute(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'sa_%'"
        )
    )


_probe = 0


def refuses(connection: sqlite3.Connection, sql: str, what: str) -> None:
    """
    The database must reject this statement. If it takes it, that is a bug.

    The attempt runs inside its own savepoint, so a refusal undoes the one bad
    statement and leaves every fixture row placed before it alone. A plain
    rollback here would take the whole setup with it, and the next assertion
    would then fail on a foreign key for a row that had quietly vanished.
    """
    global _probe
    _probe += 1
    name = f"probe{_probe}"
    connection.execute(f"SAVEPOINT {name}")
    accepted = False
    try:
        connection.execute(sql)
        accepted = True
    except sqlite3.IntegrityError:
        pass
    finally:
        connection.execute(f"ROLLBACK TO {name}")
        connection.execute(f"RELEASE {name}")
    if accepted:
        raise Failure(what)


def accepts(connection: sqlite3.Connection, sql: str, what: str) -> None:
    """The database must take this statement. A refusal means a rule is too tight."""
    try:
        connection.execute(sql)
    except sqlite3.Error as error:
        raise Failure(f"{what}: {error}")


def in_savepoint(connection: sqlite3.Connection, work) -> None:
    """
    Run one assertion block and leave the database exactly as it was found.

    The fixtures each block plants (a practice, an intake, an engagement) exist
    only for that block. Committing them would leave the next migration's
    assertions running against somebody else's leftovers, which is the same
    mistake the PHP test runner avoids by rebuilding the schema per test.
    """
    connection.execute("SAVEPOINT block")
    try:
        work(connection)
    finally:
        connection.execute("ROLLBACK TO block")
        connection.execute("RELEASE block")


STAMP = "'2026-01-01 00:00:00'"


def assert_foundation(connection: sqlite3.Connection) -> None:
    """The constraints 0001 exists to enforce."""
    refuses(
        connection,
        "INSERT INTO sa_contacts (id, organization_id, name, work_email, active, created_at)"
        f" VALUES ('x', 'does-not-exist', 'n', 'e@example.org', 1, {STAMP})",
        "a contact with an unknown organization was accepted",
    )
    refuses(
        connection,
        "INSERT INTO sa_organizations (id, public_ref, legal_name, status, created_at, updated_at)"
        f" VALUES ('y', 'SA-ORG-TEST01', 'Test', 'not_a_status', {STAMP}, {STAMP})",
        "an organization with an invalid status was accepted",
    )

    accepts(
        connection,
        f"INSERT INTO sa_users (id, email, active, created_at) VALUES ('u1', 'a@example.org', 1, {STAMP})",
        "a plain user row was refused",
    )
    accepts(
        connection,
        "INSERT INTO sa_memberships (user_id, organization_id, organization_scope, role, created_at)"
        f" VALUES ('u1', NULL, 'GLOBAL', 'owner_admin', {STAMP})",
        "a global staff membership was refused",
    )
    refuses(
        connection,
        "INSERT INTO sa_memberships (user_id, organization_id, organization_scope, role, created_at)"
        f" VALUES ('u1', NULL, 'GLOBAL', 'owner_admin', {STAMP})",
        "a duplicate global staff membership was accepted",
    )

    # The sentinel must not block a legitimate second row: the same user in the
    # same role at two different organizations is normal.
    for ident, ref, name in (("o1", "SA-ORG-AAAAAA", "One"), ("o2", "SA-ORG-BBBBBB", "Two")):
        connection.execute(
            "INSERT INTO sa_organizations (id, public_ref, legal_name, status, created_at, updated_at)"
            f" VALUES ('{ident}', '{ref}', '{name}', 'prospect', {STAMP}, {STAMP})"
        )
    for org in ("o1", "o2"):
        connection.execute(
            "INSERT INTO sa_memberships (user_id, organization_id, organization_scope, role, created_at)"
            f" VALUES ('u1', '{org}', '{org}', 'viewer', {STAMP})"
        )


def assert_intake_and_engagement(connection: sqlite3.Connection) -> None:
    """
    The constraints 0002 exists to enforce.

    Every one of these is a rule the application also states in PHP. The point
    of asserting them here is that the database is the layer that cannot be
    bypassed: a second code path, a future page, or a hand-written statement all
    still meet these.
    """
    # A practice to hang the rest on.
    connection.execute(
        "INSERT INTO sa_organizations (id, public_ref, legal_name, status, created_at, updated_at)"
        f" VALUES ('org1', 'SA-ORG-CCCCCC', 'Fictional Behavioral Health', 'prospect', {STAMP}, {STAMP})"
    )

    def intake(ident: str, ref: str, digest: str, status: str = "received", org: str = "'org1'") -> str:
        return (
            "INSERT INTO sa_intakes (id, public_ref, organization_id, source, organization_name,"
            " contact_name, contact_email, time_sensitive, payload_sha256, status, submitted_at, created_at)"
            f" VALUES ('{ident}', '{ref}', {org}, 'soft-appeals-start', 'Fictional Behavioral Health',"
            f" 'A Person', 'person@example.org', 0, '{digest}', '{status}', {STAMP}, {STAMP})"
        )

    accepts(connection, intake("i1", "SA-INQ-AAAAAA", "a" * 64), "a plain intake was refused")

    refuses(
        connection,
        intake("i2", "SA-INQ-BBBBBB", "a" * 64),
        "two intakes with the same payload hash were accepted, so the import is not idempotent",
    )
    refuses(
        connection,
        intake("i3", "SA-INQ-CCCCCC", "c" * 64, status="nonsense"),
        "an intake with an invented status was accepted",
    )
    refuses(
        connection,
        intake("i4", "SA-INQ-DDDDDD", "d" * 64, org="'no-such-org'"),
        "an intake pointing at an unknown organization was accepted",
    )
    refuses(
        connection,
        "INSERT INTO sa_intakes (id, public_ref, organization_id, source, organization_name,"
        " contact_name, contact_email, time_sensitive, payload_sha256, status, submitted_at, created_at)"
        f" VALUES ('i5', 'SA-INQ-EEEEEE', 'org1', 'soft-appeals-start', 'X', 'A', 'a@example.org',"
        f" 7, '{'e' * 64}', 'received', {STAMP}, {STAMP})",
        "an intake with a time-sensitive flag that is neither 0 nor 1 was accepted",
    )

    def engagement(ident: str, ref: str, fee: str = "not_set") -> str:
        return (
            "INSERT INTO sa_engagements (id, organization_id, intake_id, public_ref, stage,"
            " fee_basis, opened_at, row_version)"
            f" VALUES ('{ident}', 'org1', 'i1', '{ref}', 'terms_ready', '{fee}', {STAMP}, 1)"
        )

    accepts(connection, engagement("e1", "SA-ENG-AAAAAA"), "a plain engagement was refused")
    refuses(
        connection,
        engagement("e2", "SA-ENG-BBBBBB", fee="whatever_she_likes"),
        "an engagement with an invented fee basis was accepted",
    )

    # Invitations: the digest is unique, so one token cannot be minted twice.
    accepts(
        connection,
        "INSERT INTO sa_invitations (id, organization_id, engagement_id, contact_email, purpose,"
        " token_digest, expires_at, created_at)"
        f" VALUES ('v1', 'org1', 'e1', 'person@example.org', 'preferences', '{'f' * 64}',"
        f" '2027-01-01 00:00:00', {STAMP})",
        "a plain invitation was refused",
    )
    refuses(
        connection,
        "INSERT INTO sa_invitations (id, organization_id, engagement_id, contact_email, purpose,"
        " token_digest, expires_at, created_at)"
        f" VALUES ('v2', 'org1', 'e1', 'person@example.org', 'preferences', '{'f' * 64}',"
        f" '2027-01-01 00:00:00', {STAMP})",
        "the same invitation digest was accepted twice",
    )
    refuses(
        connection,
        "INSERT INTO sa_invitations (id, organization_id, engagement_id, contact_email, purpose,"
        " token_digest, expires_at, created_at)"
        f" VALUES ('v3', 'org1', 'e1', 'person@example.org', 'whatever', '{'0' * 64}',"
        f" '2027-01-01 00:00:00', {STAMP})",
        "an invitation with an invented purpose was accepted",
    )

    def comm(ident: str, state: str, key: str) -> str:
        return (
            "INSERT INTO sa_communications (id, engagement_id, organization_id, recipient_email,"
            " template_key, template_version, subject, channel, state, idempotency_key, created_at)"
            f" VALUES ('{ident}', 'e1', 'org1', 'person@example.org', 'assessment_terms', '1',"
            f" 'Subject', 'email', '{state}', {key}, {STAMP})"
        )

    accepts(connection, comm("c1", "accepted", f"'{'1' * 64}'"), "a plain communication was refused")
    refuses(
        connection,
        comm("c2", "accepted", f"'{'1' * 64}'"),
        "the same idempotency key was accepted twice, so a double click can send twice",
    )
    refuses(
        connection,
        comm("c3", "delivered", f"'{'2' * 64}'"),
        "a communication was allowed to claim it was delivered",
    )
    # Two rows with no key at all are fine: NULL never equals NULL, and a
    # manually recorded message legitimately has no key.
    accepts(connection, comm("c4", "manually_confirmed", "NULL"), "a keyless communication was refused")
    accepts(connection, comm("c5", "manually_confirmed", "NULL"), "a second keyless communication was refused")

    def event(ident: str, actor: str) -> str:
        return (
            "INSERT INTO sa_status_events (id, engagement_id, event_type, public_label, actor_type, created_at)"
            f" VALUES ('{ident}', 'e1', 'terms.sent', 'Your terms were sent.', '{actor}', {STAMP})"
        )

    accepts(connection, event("s1", "staff"), "a plain status event was refused")
    refuses(connection, event("s2", "robot"), "a status event with an invented actor type was accepted")

    # Deleting an engagement must take its timeline with it. A status event
    # pointing at an engagement that is gone is a row nothing can ever render.
    connection.execute("DELETE FROM sa_engagements WHERE id = 'e1'")
    left = connection.execute(
        "SELECT COUNT(*) FROM sa_status_events WHERE engagement_id = 'e1'"
    ).fetchone()[0]
    if left != 0:
        raise Failure("deleting an engagement left its status events behind")


def assert_preferences_and_client_access(connection: sqlite3.Connection) -> None:
    """
    The constraints 0003 exists to enforce.

    The one that matters most is the uniqueness on engagement_id. "Preferences
    update the engagement state once" is a Phase 3 acceptance line, and it is
    only actually true if a second row for the same engagement is impossible at
    the layer no code path can go around.
    """
    connection.execute(
        "INSERT INTO sa_organizations (id, public_ref, legal_name, status, created_at, updated_at)"
        f" VALUES ('org1', 'SA-ORG-DDDDDD', 'Fictional Family Practice', 'prospect', {STAMP}, {STAMP})"
    )
    connection.execute(
        "INSERT INTO sa_engagements (id, organization_id, public_ref, stage, fee_basis,"
        " opened_at, row_version)"
        f" VALUES ('e1', 'org1', 'SA-ENG-AAAAAA', 'terms_sent', 'contingency_25', {STAMP}, 1)"
    )
    connection.execute(
        "INSERT INTO sa_contacts (id, organization_id, name, work_email, active, created_at)"
        f" VALUES ('c1', 'org1', 'A Person', 'person@example.org', 1, {STAMP})"
    )

    def preferences(
        ident: str,
        engagement: str = "'e1'",
        cadence: str = "weekly",
        channel: str = "client_system",
        partner: str = "yes",
        signer: str = "'c1'",
    ) -> str:
        return (
            "INSERT INTO sa_engagement_preferences (id, engagement_id, organization_id,"
            " communication_cadence, secure_channel, billing_partner, signer_contact_id,"
            " created_at, updated_at)"
            f" VALUES ('{ident}', {engagement}, 'org1', '{cadence}', '{channel}',"
            f" '{partner}', {signer}, {STAMP}, {STAMP})"
        )

    accepts(connection, preferences("p1"), "a plain preferences row was refused")

    refuses(
        connection,
        preferences("p2"),
        "two preference rows for one engagement were accepted, so confirming twice"
        " could confirm twice",
    )
    refuses(
        connection,
        preferences("p3", cadence="fortnightly"),
        "a cadence nobody offers was accepted",
    )
    refuses(
        connection,
        preferences("p4", channel="email"),
        "a secure channel nobody offers was accepted",
    )
    refuses(
        connection,
        preferences("p5", partner="maybe"),
        "a billing-partner answer nobody offers was accepted",
    )
    refuses(
        connection,
        preferences("p6", signer="'nobody'"),
        "a signer pointing at a contact that does not exist was accepted",
    )

    # A contact who leaves must not take the practice's recorded choice with
    # them. The pointer clears; the row stays.
    connection.execute("DELETE FROM sa_contacts WHERE id = 'c1'")
    left = connection.execute(
        "SELECT signer_contact_id FROM sa_engagement_preferences WHERE id = 'p1'"
    ).fetchone()
    if left is None:
        raise Failure("removing a contact deleted the preferences row with them")
    if left[0] is not None:
        raise Failure("removing a contact left a signer pointer behind")

    def code(ident: str, purpose: str = "client_login", attempts: int = 0, org: str = "'org1'") -> str:
        return (
            "INSERT INTO sa_login_codes (id, organization_id, email, code_digest, purpose,"
            " expires_at, attempts, created_at)"
            f" VALUES ('{ident}', {org}, 'person@example.org', '{'a' * 64}', '{purpose}',"
            f" {STAMP}, {attempts}, {STAMP})"
        )

    accepts(connection, code("k1"), "a plain login code was refused")

    # Two codes may carry the same digest. Six digits is a million values and a
    # unique constraint here would eventually refuse a legitimate code.
    accepts(connection, code("k2"), "two codes with the same digest were refused")

    refuses(connection, code("k3", purpose="magic_link"), "a code purpose nobody offers was accepted")
    refuses(connection, code("k4", attempts=-1), "a code with negative attempts was accepted")
    refuses(connection, code("k5", org="'does-not-exist'"), "a code for an unknown organization was accepted")

    # Closing a practice must take its live codes with it.
    connection.execute("DELETE FROM sa_organizations WHERE id = 'org1'")
    remaining = connection.execute("SELECT COUNT(*) FROM sa_login_codes").fetchone()[0]
    if remaining != 0:
        raise Failure("deleting an organization left its login codes behind")


def assert_status_event_sequence(connection: sqlite3.Connection) -> None:
    """
    What 0004 exists to guarantee: two events written in the same second come
    back in the order they were recorded, not in the order of a random UUID.

    This is the bug that was on screen on 2026-08-28. A practice's own history
    read "terms prepared, reviewed for fit, enquiry received", which is
    backwards, because those three are written inside one transaction and the
    timestamp cannot tell them apart.
    """
    connection.execute(
        "INSERT INTO sa_organizations (id, public_ref, legal_name, status, created_at, updated_at)"
        f" VALUES ('org9', 'SA-ORG-EEEEEE', 'Fictional Primary Care', 'prospect', {STAMP}, {STAMP})"
    )
    connection.execute(
        "INSERT INTO sa_engagements (id, organization_id, public_ref, stage, fee_basis,"
        " opened_at, row_version)"
        f" VALUES ('e9', 'org9', 'SA-ENG-BBBBBB', 'terms_ready', 'not_set', {STAMP}, 1)"
    )

    # Written in one second, and deliberately given ids that sort the wrong way
    # round, which is exactly what a random UUID does one time in six.
    for ident, seq, label in (
        ("zzz", 1, "Your enquiry was received and opened for review."),
        ("mmm", 2, "Your enquiry was reviewed for fit."),
        ("aaa", 3, "Your assessment terms are being prepared."),
    ):
        connection.execute(
            "INSERT INTO sa_status_events (id, engagement_id, event_type, public_label,"
            f" actor_type, seq, created_at) VALUES ('{ident}', 'e9', 'engagement.step',"
            f" '{label}', 'staff', {seq}, {STAMP})"
        )

    ordered = [
        row[0]
        for row in connection.execute(
            "SELECT id FROM sa_status_events WHERE engagement_id = 'e9'"
            " ORDER BY created_at ASC, seq ASC, id ASC"
        )
    ]
    if ordered != ["zzz", "mmm", "aaa"]:
        raise Failure(
            "same-second timeline events did not come back in the order they were "
            "recorded: got " + ", ".join(ordered)
        )

    # And the old ordering really was broken, so the fix is not decorative.
    by_id = [
        row[0]
        for row in connection.execute(
            "SELECT id FROM sa_status_events WHERE engagement_id = 'e9'"
            " ORDER BY created_at ASC, id ASC"
        )
    ]
    if by_id == ["zzz", "mmm", "aaa"]:
        raise Failure("this fixture cannot prove anything: id order already matched")

    # A row written without naming seq must still be accepted, because the
    # column carries a default and older rows predate it.
    accepts(
        connection,
        "INSERT INTO sa_status_events (id, engagement_id, event_type, public_label,"
        f" actor_type, created_at) VALUES ('nnn', 'e9', 'x', 'y', 'system', {STAMP})",
        "a status event written without a sequence was refused",
    )


def assert_documents_and_signatures(connection: sqlite3.Connection) -> None:
    """
    The constraints 0005 exists to enforce.

    Three of section 14's acceptance lines are database rules rather than code
    rules, and this is where that is proved:

      a corrected document creates a new version   the unique constraint on
                                                   (engagement_id, kind, version)
      a signed document cannot be edited           there is no second version 1
                                                   to write the correction into
      only one signature per party per document    the unique constraint on
                                                   (document_id, party)

    The hash-length checks are here for a duller reason. A truncated SHA-256
    still looks like a hash, and a column that accepted one would let a
    half-written value sit in the record for years looking correct.
    """
    connection.execute(
        "INSERT INTO sa_organizations (id, public_ref, legal_name, status, created_at, updated_at)"
        f" VALUES ('orgD', 'SA-ORG-FFFFFF', 'Fictional Behavioral Health', 'active', {STAMP}, {STAMP})"
    )
    connection.execute(
        "INSERT INTO sa_engagements (id, organization_id, public_ref, stage, fee_basis,"
        " opened_at, row_version)"
        f" VALUES ('eD', 'orgD', 'SA-ENG-CCCCCC', 'baa_pending', 'contingency_25', {STAMP}, 1)"
    )
    connection.execute(
        "INSERT INTO sa_contacts (id, organization_id, name, work_email, active, created_at)"
        f" VALUES ('cD', 'orgD', 'A Signer', 'signer@example.org', 1, {STAMP})"
    )

    hash64 = "a" * 64

    def document(
        ident: str,
        ref: str,
        version: int = 1,
        kind: str = "baa",
        status: str = "draft",
        content: str = hash64,
        executed_hash: str = "NULL",
        executed_at: str = "NULL",
        void_reason: str = "NULL",
        engagement: str = "'eD'",
    ) -> str:
        return (
            "INSERT INTO sa_documents (id, public_ref, engagement_id, organization_id, kind,"
            " version, status, title, template_version, consent_version, content_sha256,"
            " executed_sha256, executed_at, void_reason, private_path, created_at, updated_at)"
            f" VALUES ('{ident}', '{ref}', {engagement}, 'orgD', '{kind}', {version},"
            f" '{status}', 'Business Associate Agreement', '2026-08-28', '2026-08-28',"
            f" '{content}', {executed_hash}, {executed_at}, {void_reason},"
            f" 'agreements/SA-ENG-CCCCCC/{ref}-v{version}.txt', {STAMP}, {STAMP})"
        )

    accepts(connection, document("d1", "SA-DOC-AAAAAA"), "a plain draft document was refused")

    refuses(
        connection,
        document("d2", "SA-DOC-BBBBBB", version=1),
        "two version 1 documents of the same kind on one engagement were accepted,"
        " so a correction could overwrite the original",
    )

    accepts(
        connection,
        document("d3", "SA-DOC-CCCCCC", version=2),
        "a second VERSION of the same document was refused, so a correction has"
        " nowhere to go",
    )

    accepts(
        connection,
        document("d4", "SA-DOC-DDDDDD", kind="review_authorization"),
        "version 1 of a DIFFERENT kind on the same engagement was refused",
    )

    refuses(
        connection,
        document("d5", "SA-DOC-EEEEEE", kind="a_kind_nobody_named"),
        "a document of an invented kind was accepted",
    )
    refuses(
        connection,
        document("d6", "SA-DOC-FFFFFF", version=3, status="nearly_signed"),
        "a document with an invented status was accepted",
    )
    refuses(
        connection,
        document("d7", "SA-DOC-GGGGGG", version=4, content="a" * 63),
        "a document with a 63-character content hash was accepted",
    )
    refuses(
        connection,
        document("d8", "SA-DOC-HHHHHH", version=5, status="executed"),
        "a document marked executed with no executed hash and no stamp was accepted",
    )
    refuses(
        connection,
        document("d9", "SA-DOC-JJJJJJ", version=6, status="void"),
        "a voided document with no reason was accepted",
    )
    refuses(
        connection,
        document("d10", "SA-DOC-AAAAAA", version=7),
        "two documents with the same public reference were accepted",
    )
    refuses(
        connection,
        document("d11", "SA-DOC-KKKKKK", version=0),
        "a document at version 0 was accepted",
    )
    refuses(
        connection,
        document("d12", "SA-DOC-LLLLLL", engagement="'no-such-engagement'"),
        "a document pointing at an unknown engagement was accepted",
    )

    def signature(
        ident: str,
        party: str = "client",
        document_id: str = "'d1'",
        doc_hash: str = hash64,
        key: str = "NULL",
    ) -> str:
        return (
            "INSERT INTO sa_signatures (id, document_id, organization_id, party, signer_contact_id,"
            " signer_role, typed_name, consent_version, consent_text_sha256, consent_accepted_at,"
            " document_sha256, payload_path, payload_sha256, idempotency_key, signed_at, created_at)"
            f" VALUES ('{ident}', {document_id}, 'orgD', '{party}', 'cD', 'authorized_signer',"
            f" 'A Signer', '2026-08-28', '{'b' * 64}', {STAMP}, '{doc_hash}',"
            f" 'signatures/SA-DOC-AAAAAA-{party}.json', '{'c' * 64}', {key}, {STAMP}, {STAMP})"
        )

    accepts(connection, signature("s1"), "a plain client signature was refused")

    refuses(
        connection,
        signature("s2"),
        "a second client signature on the same document was accepted, so a replayed"
        " form could sign twice",
    )

    accepts(
        connection,
        signature("s3", party="soft_appeals"),
        "a countersignature on a document the client had signed was refused",
    )

    refuses(
        connection,
        signature("s4", party="a_third_party"),
        "a signature from a party nobody defined was accepted",
    )
    refuses(
        connection,
        signature("s5", document_id="'d3'", doc_hash="a" * 63),
        "a signature carrying a 63-character document hash was accepted",
    )
    refuses(
        connection,
        signature("s6", document_id="'no-such-document'"),
        "a signature pointing at an unknown document was accepted",
    )

    # The idempotency index has to refuse a repeat and still allow many NULLs,
    # because a signature written by hand carries no key.
    accepts(
        connection,
        signature("s7", document_id="'d3'", key=f"'{'d' * 64}'"),
        "a signature carrying an idempotency key was refused",
    )
    refuses(
        connection,
        signature("s8", document_id="'d4'", key=f"'{'d' * 64}'"),
        "two signatures with the same idempotency key were accepted, so a replayed"
        " request could sign twice",
    )
    accepts(
        connection,
        signature("s9", document_id="'d4'"),
        "a second signature with no idempotency key was refused, but NULL is not a"
        " duplicate of NULL",
    )


def assert_assessment_and_recovery_room(connection: sqlite3.Connection) -> None:
    """
    The constraints 0006 exists to enforce.

    Phase 5's acceptance line "every aggregate deadline is marked confirmed
    or unconfirmed" is a CHECK here: a batch cannot claim a confirmed deadline
    without a date. One assessment per engagement, one checklist item per
    key, and a decision that always carries its stamp are the other three.
    """
    connection.execute(
        "INSERT INTO sa_organizations (id, public_ref, legal_name, status, created_at, updated_at)"
        f" VALUES ('orgP', 'SA-ORG-GGGGGG', 'Fictional Family Practice', 'active', {STAMP}, {STAMP})"
    )
    connection.execute(
        "INSERT INTO sa_engagements (id, organization_id, public_ref, stage, fee_basis,"
        " opened_at, row_version)"
        f" VALUES ('eP', 'orgP', 'SA-ENG-DDDDDD', 'secure_intake_ready', 'contingency_25', {STAMP}, 1)"
    )

    accepts(
        connection,
        "INSERT INTO sa_settings (setting_key, setting_value, updated_at)"
        f" VALUES ('legal_entity', 'A Fictional Legal Entity LLC', {STAMP})",
        "a plain setting was refused",
    )
    refuses(
        connection,
        "INSERT INTO sa_settings (setting_key, setting_value, updated_at)"
        f" VALUES ('legal_entity', 'Twice', {STAMP})",
        "the same setting key was accepted twice",
    )

    def assessment(ident: str, engagement: str = "'eP'", decision: str = "NULL", decision_at: str = "NULL",
                   received: str = "NULL") -> str:
        return (
            "INSERT INTO sa_assessments (id, engagement_id, organization_id, expected_count,"
            " received_count, decision, decision_at, created_at, updated_at)"
            f" VALUES ('{ident}', {engagement}, 'orgP', 20, {received}, {decision}, {decision_at}, {STAMP}, {STAMP})"
        )

    accepts(connection, assessment("a1"), "a plain assessment was refused")
    refuses(connection, assessment("a2"), "two assessments for one engagement were accepted")
    refuses(
        connection,
        assessment("a3", engagement="'no-such-engagement'"),
        "an assessment pointing at an unknown engagement was accepted",
    )
    connection.execute("DELETE FROM sa_assessments WHERE id = 'a1'")
    refuses(
        connection,
        assessment("a4", decision="'recovery_scope'"),
        "a decision with no stamp was accepted",
    )
    refuses(
        connection,
        assessment("a5", decision="'maybe_later'", decision_at=STAMP),
        "a decision nobody offers was accepted",
    )
    refuses(connection, assessment("a6", received="-1"), "a negative received count was accepted")
    accepts(
        connection,
        assessment("a7", decision="'no_further_action'", decision_at=STAMP, received="20"),
        "a stamped decision was refused",
    )

    def batch(ident: str, ref: str, stage: str = "received", owner: str = "soft_appeals",
              deadline: str = "NULL", confirmed: int = 0, count: int = 20, cents: int = 0) -> str:
        return (
            "INSERT INTO sa_work_batches (id, public_ref, engagement_id, organization_id, label,"
            " payer_label_approved, claim_count, denied_amount_cents, received_count, in_review_count,"
            " submitted_count, overturned_count, upheld_count, closed_count, stage,"
            " earliest_deadline_at, deadline_confirmed, next_owner, created_at, updated_at, row_version)"
            f" VALUES ('{ident}', '{ref}', 'eP', 'orgP', 'Initial set', 0, {count}, {cents}, {count}, 0,"
            f" 0, 0, 0, 0, '{stage}', {deadline}, {confirmed}, '{owner}', {STAMP}, {STAMP}, 1)"
        )

    accepts(connection, batch("b1", "SA-BAT-AAAAAA"), "a plain batch was refused")
    refuses(connection, batch("b2", "SA-BAT-AAAAAA"), "two batches with one reference were accepted")
    refuses(
        connection,
        batch("b3", "SA-BAT-BBBBBB", confirmed=1),
        "a batch claiming a confirmed deadline with no date was accepted",
    )
    accepts(
        connection,
        batch("b4", "SA-BAT-CCCCCC", deadline="'2026-09-30 12:00:00'", confirmed=1),
        "a batch with a dated, confirmed deadline was refused",
    )
    accepts(
        connection,
        batch("b5", "SA-BAT-DDDDDD", deadline="'2026-09-30 12:00:00'", confirmed=0),
        "a batch with a dated, unconfirmed deadline was refused",
    )
    refuses(connection, batch("b6", "SA-BAT-EEEEEE", stage="lost"), "a batch stage nobody named was accepted")
    refuses(connection, batch("b7", "SA-BAT-FFFFFF", owner="lawyer"), "a next owner nobody named was accepted")
    refuses(connection, batch("b8", "SA-BAT-GGGGGG", count=-1), "a negative claim count was accepted")
    refuses(connection, batch("b9", "SA-BAT-HHHHHH", cents=-100), "a negative denied amount was accepted")

    def item(ident: str, key: str = "baa_executed", category: str = "DOCUMENT") -> str:
        return (
            "INSERT INTO sa_checklist_items (id, engagement_id, item_key, label, category,"
            " display_order, created_at)"
            f" VALUES ('{ident}', 'eP', '{key}', 'Label', '{category}', 1, {STAMP})"
        )

    accepts(connection, item("c1"), "a plain checklist item was refused")
    refuses(connection, item("c2"), "the same checklist key was accepted twice on one engagement")
    refuses(connection, item("c3", key="other", category="WHIMSY"), "a checklist category nobody named was accepted")

    def request(ident: str, ref: str, kind: str = "confirm_receipt_count", status: str = "open",
                completed: str = "NULL", owner: str = "client") -> str:
        return (
            "INSERT INTO sa_action_requests (id, public_ref, engagement_id, organization_id, kind,"
            " owner, status, completed_at, created_at, updated_at)"
            f" VALUES ('{ident}', '{ref}', 'eP', 'orgP', '{kind}', '{owner}', '{status}', {completed},"
            f" {STAMP}, {STAMP})"
        )

    accepts(connection, request("r1", "SA-REQ-AAAAAA"), "a plain open request was refused")
    refuses(connection, request("r2", "SA-REQ-BBBBBB", kind="please_upload"), "a request kind nobody designed was accepted")
    refuses(connection, request("r3", "SA-REQ-CCCCCC", status="done"), "a done request with no completion stamp was accepted")
    refuses(connection, request("r4", "SA-REQ-DDDDDD", completed=STAMP), "an open request carrying a completion stamp was accepted")
    accepts(connection, request("r5", "SA-REQ-EEEEEE", status="done", completed=STAMP), "a stamped done request was refused")
    refuses(connection, request("r6", "SA-REQ-FFFFFF", owner="payer"), "a request owner nobody named was accepted")

    # Closing the engagement takes every Phase 5 row with it.
    connection.execute("DELETE FROM sa_engagements WHERE id = 'eP'")
    for table in ("sa_assessments", "sa_work_batches", "sa_checklist_items", "sa_action_requests"):
        left = connection.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0]
        if left != 0:
            raise Failure(f"deleting an engagement left {table} rows behind")


def assert_recovery_agreement_and_approvals(connection: sqlite3.Connection) -> None:
    """
    The constraints 0007 exists to enforce.

    Gate C as a CHECK: a submitted event needs an approval behind it. A
    decided approval carries its stamp and a pending one does not. One scope
    per engagement, with a bounded rate. And there is no fee column anywhere
    in these four tables, which is section 19 stated as an absence.
    """
    connection.execute(
        "INSERT INTO sa_organizations (id, public_ref, legal_name, status, created_at, updated_at)"
        f" VALUES ('orgR', 'SA-ORG-RRRRRR', 'Fictional Recovery Practice', 'active', {STAMP}, {STAMP})"
    )
    connection.execute(
        "INSERT INTO sa_engagements (id, organization_id, public_ref, stage, fee_basis,"
        " opened_at, row_version)"
        f" VALUES ('eR', 'orgR', 'SA-ENG-RRRRRR', 'recovery_active', 'contingency_25', {STAMP}, 1)"
    )
    connection.execute(
        "INSERT INTO sa_contacts (id, organization_id, name, work_email, active, created_at)"
        f" VALUES ('cR', 'orgR', 'Kofi Mensah', 'kofi@example.org', 1, {STAMP})"
    )
    connection.execute(
        "INSERT INTO sa_work_batches (id, public_ref, engagement_id, organization_id, label,"
        " payer_label_approved, claim_count, denied_amount_cents, received_count, in_review_count,"
        " submitted_count, overturned_count, upheld_count, closed_count, stage,"
        " earliest_deadline_at, deadline_confirmed, next_owner, created_at, updated_at, row_version)"
        f" VALUES ('bR', 'SA-BAT-RRRRRR', 'eR', 'orgR', 'Initial set', 0, 20, 1840000, 20, 0,"
        f" 0, 0, 0, 0, 'recommended', NULL, 0, 'soft_appeals', {STAMP}, {STAMP}, 1)"
    )

    for table in ("sa_recovery_scopes", "sa_recovery_scope_batches", "sa_approval_requests", "sa_submission_events"):
        columns = [row[1] for row in connection.execute(f"PRAGMA table_info({table})").fetchall()]
        for column in columns:
            if "fee" in column and column != "fee_basis" and column != "fee_rate_bps":
                raise Failure(f"{table}.{column}: a fee column in a Phase 6 table, section 19")
            for word in ("patient", "member", "claim_number", "claim_id", "mrn", "dob", "date_of_service"):
                if word in column:
                    raise Failure(f"{table}.{column}: a patient-level column, section 5")

    def scope(ident: str, engagement: str = "'eR'", fee: str = "contingency_25", rate: str = "2500",
              summary: str = "The commercial denials in the initial set.", approver: str = "NULL",
              confirmed: str = "NULL") -> str:
        return (
            "INSERT INTO sa_recovery_scopes (id, engagement_id, organization_id, fee_basis, fee_rate_bps,"
            " summary, approver_contact_id, approver_confirmed_at, created_at, updated_at, row_version)"
            f" VALUES ('{ident}', {engagement}, 'orgR', '{fee}', {rate}, '{summary}', {approver}, {confirmed},"
            f" {STAMP}, {STAMP}, 1)"
        )

    accepts(connection, scope("s1", approver="'cR'", confirmed=STAMP), "a plain scope was refused")
    refuses(connection, scope("s2"), "two scopes for one engagement were accepted")
    connection.execute("DELETE FROM sa_recovery_scopes WHERE id = 's1'")
    refuses(connection, scope("s3", fee="not_set"), "a scope with no fee basis was accepted")
    refuses(connection, scope("s4", rate="12000"), "a rate above 100 percent was accepted")
    refuses(connection, scope("s5", rate="-1"), "a negative rate was accepted")
    refuses(connection, scope("s6", summary="short"), "an empty scope summary was accepted")
    accepts(connection, scope("s8", rate="NULL", fee="custom"), "a custom basis with no rate was refused")

    accepts(
        connection,
        f"INSERT INTO sa_recovery_scope_batches (scope_id, work_batch_id, created_at) VALUES ('s8', 'bR', {STAMP})",
        "a batch in scope was refused",
    )
    refuses(
        connection,
        f"INSERT INTO sa_recovery_scope_batches (scope_id, work_batch_id, created_at) VALUES ('s8', 'bR', {STAMP})",
        "the same batch was accepted in one scope twice",
    )
    refuses(
        connection,
        f"INSERT INTO sa_recovery_scope_batches (scope_id, work_batch_id, created_at) VALUES ('s8', 'no-batch', {STAMP})",
        "a scope pointing at an unknown batch was accepted",
    )

    def approval(ident: str, ref: str, state: str = "pending", decided: str = "NULL", kind: str = "submission",
                 summary: str = "First-level appeals to the commercial payer.", count: int = 12,
                 cents: int = 1120000, key: str = "NULL") -> str:
        return (
            "INSERT INTO sa_approval_requests (id, public_ref, engagement_id, organization_id, work_batch_id,"
            " kind, safe_summary, claim_count, amount_cents, state, decision_at, idempotency_key,"
            " created_at, updated_at)"
            f" VALUES ('{ident}', '{ref}', 'eR', 'orgR', 'bR', '{kind}', '{summary}', {count}, {cents},"
            f" '{state}', {decided}, {key}, {STAMP}, {STAMP})"
        )

    accepts(connection, approval("a1", "SA-APR-AAAAAA"), "a plain pending approval was refused")
    refuses(connection, approval("a2", "SA-APR-AAAAAA"), "two approvals with one reference were accepted")
    refuses(connection, approval("a3", "SA-APR-BBBBBB", state="approved"), "an approved request with no stamp was accepted")
    refuses(connection, approval("a4", "SA-APR-CCCCCC", decided=STAMP), "a pending request carrying a decision stamp was accepted")
    refuses(connection, approval("a5", "SA-APR-DDDDDD", state="maybe", decided=STAMP), "a state nobody named was accepted")
    refuses(connection, approval("a6", "SA-APR-EEEEEE", kind="upload"), "an approval kind nobody designed was accepted")
    refuses(connection, approval("a7", "SA-APR-FFFFFF", count=-1), "a negative count was accepted")
    refuses(connection, approval("a8", "SA-APR-GGGGGG", summary="tiny"), "an empty safe summary was accepted")
    accepts(connection, approval("a9", "SA-APR-HHHHHH", state="approved", decided=STAMP, key="'k1'"), "a stamped approval was refused")
    refuses(connection, approval("a10", "SA-APR-IIIIII", state="approved", decided=STAMP, key="'k1'"), "the same idempotency key was accepted twice")

    def event(ident: str, ref: str, kind: str = "submitted", approval_id: str = "'a9'", count: int = 12,
              cents: int = 1120000, due: str = "NULL", done: str = "NULL") -> str:
        return (
            "INSERT INTO sa_submission_events (id, public_ref, engagement_id, organization_id, work_batch_id,"
            " approval_request_id, event_type, claim_count, amount_cents, occurred_at, follow_up_due_at,"
            " follow_up_done_at, created_at)"
            f" VALUES ('{ident}', '{ref}', 'eR', 'orgR', 'bR', {approval_id}, '{kind}', {count}, {cents},"
            f" {STAMP}, {due}, {done}, {STAMP})"
        )

    accepts(connection, event("v1", "SA-SUB-AAAAAA"), "a submitted event with an approval behind it was refused")
    refuses(connection, event("v2", "SA-SUB-BBBBBB", approval_id="NULL"), "Gate C: a submitted event with no approval was accepted")
    accepts(connection, event("v3", "SA-SUB-CCCCCC", kind="decision_favorable", approval_id="NULL"), "a payer decision with no approval id was refused")
    refuses(connection, event("v4", "SA-SUB-DDDDDD", kind="paid_in_full"), "an event type nobody named was accepted")
    refuses(connection, event("v5", "SA-SUB-EEEEEE", cents=-1), "a negative amount was accepted")
    refuses(connection, event("v6", "SA-SUB-FFFFFF", done=STAMP), "a follow-up marked done with no due date was accepted")
    accepts(connection, event("v7", "SA-SUB-GGGGGG", due=STAMP, done=STAMP), "a dated, done follow-up was refused")

    # Closing the engagement takes every Phase 6 row with it.
    connection.execute("DELETE FROM sa_engagements WHERE id = 'eR'")
    for table in ("sa_recovery_scopes", "sa_recovery_scope_batches", "sa_approval_requests", "sa_submission_events"):
        left = connection.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0]
        if left != 0:
            raise Failure(f"deleting an engagement left {table} rows behind")


def assert_reconciliation_and_closeout(connection: sqlite3.Connection) -> None:
    """
    The constraints 0008 exists to enforce. Section 19 as CHECKs.

    A verified row names no parent and an adjustment always does. Money that
    does not qualify carries a fee of zero. An invoice's total is its fee less
    its credit, and its status pairs with its stamps. One closeout per
    engagement, closed only with a disposition. An access decision carries its
    stamp. And no column in any of the five tables names a patient.
    """
    connection.execute(
        "INSERT INTO sa_organizations (id, public_ref, legal_name, status, created_at, updated_at)"
        f" VALUES ('orgM', 'SA-ORG-MMMMMM', 'Fictional Money Practice', 'active', {STAMP}, {STAMP})"
    )
    connection.execute(
        "INSERT INTO sa_engagements (id, organization_id, public_ref, stage, fee_basis,"
        " opened_at, row_version)"
        f" VALUES ('eM', 'orgM', 'SA-ENG-MMMMMM', 'recovery_active', 'contingency_25', {STAMP}, 1)"
    )
    connection.execute(
        "INSERT INTO sa_work_batches (id, public_ref, engagement_id, organization_id, label,"
        " payer_label_approved, claim_count, denied_amount_cents, received_count, in_review_count,"
        " submitted_count, overturned_count, upheld_count, closed_count, stage,"
        " earliest_deadline_at, deadline_confirmed, next_owner, created_at, updated_at, row_version)"
        f" VALUES ('bM', 'SA-BAT-MMMMMM', 'eM', 'orgM', 'Initial set', 0, 20, 1840000, 20, 0,"
        f" 12, 8, 4, 0, 'overturned', NULL, 0, 'soft_appeals', {STAMP}, {STAMP}, 1)"
    )

    for table in ("sa_invoices", "sa_recoveries", "sa_closeouts", "sa_closeout_steps", "sa_access_reviews"):
        columns = [row[1] for row in connection.execute(f"PRAGMA table_info({table})").fetchall()]
        for column in columns:
            for word in ("patient", "member", "claim_number", "claim_id", "mrn", "dob", "date_of_service"):
                if word in column:
                    raise Failure(f"{table}.{column}: a patient-level column, section 5")
    for column in [row[1] for row in connection.execute("PRAGMA table_info(sa_recoveries)").fetchall()]:
        if column.endswith("_float") or column.endswith("_decimal"):
            raise Failure(f"sa_recoveries.{column}: money that is not integer cents, section 19")

    def invoice(ident: str, ref: str, status: str = "draft", fee: int = 175000, credit: int = 0, total: int = 175000,
                issued: str = "NULL", paid: str = "NULL", voided: str = "NULL") -> str:
        return (
            "INSERT INTO sa_invoices (id, public_ref, engagement_id, organization_id, status, fee_cents,"
            " credit_cents, total_cents, issued_at, paid_at, voided_at, created_at, updated_at, row_version)"
            f" VALUES ('{ident}', '{ref}', 'eM', 'orgM', '{status}', {fee}, {credit}, {total}, {issued}, {paid},"
            f" {voided}, {STAMP}, {STAMP}, 1)"
        )

    accepts(connection, invoice("i1", "SA-INV-AAAAAA"), "a plain draft invoice was refused")
    refuses(connection, invoice("i2", "SA-INV-AAAAAA"), "two invoices with one number were accepted")
    refuses(connection, invoice("i3", "SA-INV-BBBBBB", total=175001), "a total that is not fee less credit was accepted")
    accepts(connection, invoice("i4", "SA-INV-CCCCCC", fee=0, credit=25000, total=-25000), "a credit note was refused")
    refuses(connection, invoice("i5", "SA-INV-DDDDDD", status="issued"), "an issued invoice with no issue stamp was accepted")
    refuses(connection, invoice("i6", "SA-INV-EEEEEE", status="paid", paid=STAMP), "a paid invoice never issued was accepted")
    accepts(connection, invoice("i7", "SA-INV-FFFFFF", status="paid", issued=STAMP, paid=STAMP), "a paid, issued invoice was refused")
    refuses(connection, invoice("i8", "SA-INV-GGGGGG", status="void"), "a void invoice with no void stamp was accepted")
    refuses(connection, invoice("i9", "SA-INV-HHHHHH", status="draft", issued=STAMP), "a draft carrying an issue stamp was accepted")
    refuses(connection, invoice("i10", "SA-INV-IIIIII", status="sent", issued=STAMP), "a status nobody named was accepted")
    refuses(connection, invoice("i11", "SA-INV-JJJJJJ", fee=-1, total=-1), "a negative fee was accepted")

    def recovery(ident: str, ref: str, kind: str = "verified", parent: str = "NULL", amount: int = 700000,
                 qualifies: int = 1, rate: str = "2500", fee: int = 175000, source: str = "remittance",
                 invoice_id: str = "NULL") -> str:
        return (
            "INSERT INTO sa_recoveries (id, public_ref, engagement_id, organization_id, work_batch_id, kind,"
            " adjusts_recovery_id, original_denied_cents, amount_cents, qualifies, fee_basis, fee_rate_bps,"
            " fee_cents, verification_source, verified_at, invoice_id, created_at)"
            f" VALUES ('{ident}', '{ref}', 'eM', 'orgM', 'bM', '{kind}', {parent}, 1840000, {amount}, {qualifies},"
            f" 'contingency_25', {rate}, {fee}, '{source}', {STAMP}, {invoice_id}, {STAMP})"
        )

    accepts(connection, recovery("r1", "SA-REC-AAAAAA"), "a plain verified row was refused")
    refuses(connection, recovery("r2", "SA-REC-AAAAAA"), "two rows with one reference were accepted")
    refuses(connection, recovery("r3", "SA-REC-BBBBBB", kind="verified", parent="'r1'"), "rule 8: a verified row naming a parent was accepted")
    refuses(connection, recovery("r4", "SA-REC-CCCCCC", kind="adjustment"), "rule 8: an adjustment with no parent was accepted")
    accepts(connection, recovery("r5", "SA-REC-DDDDDD", kind="adjustment", parent="'r1'", amount=100000, fee=25000), "an adjustment naming its parent was refused")
    accepts(connection, recovery("r6", "SA-REC-EEEEEE", kind="reversal", parent="'r1'", amount=600000, fee=150000), "a reversal naming its parent was refused")
    refuses(connection, recovery("r7", "SA-REC-FFFFFF", kind="refund", parent="'r1'"), "a kind nobody named was accepted")
    refuses(connection, recovery("r8", "SA-REC-GGGGGG", qualifies=0), "rule 6: money that does not qualify carrying a fee was accepted")
    accepts(connection, recovery("r9", "SA-REC-HHHHHH", qualifies=0, fee=0), "money that does not qualify with no fee was refused")
    refuses(connection, recovery("r10", "SA-REC-IIIIII", amount=-1), "a negative amount was accepted")
    refuses(connection, recovery("r11", "SA-REC-JJJJJJ", fee=-1), "a negative fee was accepted")
    refuses(connection, recovery("r12", "SA-REC-KKKKKK", rate="12000"), "a rate above ten thousand basis points was accepted")
    refuses(connection, recovery("r13", "SA-REC-LLLLLL", source="screenshot"), "a source nobody named was accepted")
    accepts(connection, recovery("r14", "SA-REC-MMMMMM", rate="NULL", fee=0), "a fixed basis with no rate was refused")
    accepts(connection, recovery("r15", "SA-REC-NNNNNN", invoice_id="'i1'"), "a row on an invoice was refused")
    refuses(connection, recovery("r16", "SA-REC-OOOOOO", invoice_id="'no-invoice'"), "a row pointing at an unknown invoice was accepted")

    # Voiding is done in code, but deleting an invoice must never delete money.
    connection.execute("DELETE FROM sa_invoices WHERE id = 'i1'")
    left = connection.execute("SELECT invoice_id FROM sa_recoveries WHERE id = 'r15'").fetchone()[0]
    if left is not None:
        raise Failure("deleting an invoice did not hand its rows back")
    if connection.execute("SELECT COUNT(*) FROM sa_recoveries WHERE id = 'r15'").fetchone()[0] != 1:
        raise Failure("deleting an invoice deleted a recovery row")

    def closeout(ident: str, engagement: str = "'eM'", disposition: str = "NULL", closed: str = "NULL",
                 access: str = "NULL") -> str:
        return (
            "INSERT INTO sa_closeouts (id, engagement_id, organization_id, started_at, data_disposition,"
            " access_outcome, closed_at, created_at, updated_at, row_version)"
            f" VALUES ('{ident}', {engagement}, 'orgM', {STAMP}, {disposition}, {access}, {closed}, {STAMP}, {STAMP}, 1)"
        )

    accepts(connection, closeout("c1"), "a plain closeout was refused")
    refuses(connection, closeout("c2"), "two closeouts for one engagement were accepted")
    connection.execute("DELETE FROM sa_closeouts WHERE id = 'c1'")
    refuses(connection, closeout("c3", closed=STAMP), "a closeout closed with no disposition was accepted")
    refuses(connection, closeout("c4", disposition="'shredded'"), "a disposition nobody named was accepted")
    refuses(connection, closeout("c5", access="'some'"), "an access outcome nobody named was accepted")
    accepts(connection, closeout("c6", disposition="'returned'", closed=STAMP, access="'mixed'"), "a closed closeout with a disposition was refused")

    def step(key: str, order: int = 1, confirmed: str = "NULL") -> str:
        return (
            "INSERT INTO sa_closeout_steps (closeout_id, step_key, display_order, confirmed_at, created_at)"
            f" VALUES ('c6', '{key}', {order}, {confirmed}, {STAMP})"
        )

    accepts(connection, step("reconciliation"), "a step was refused")
    refuses(connection, step("reconciliation"), "the same step twice was accepted")
    refuses(connection, step("celebration", 5), "a step nobody named was accepted")
    refuses(connection, step("final_report", 0), "a display order of zero was accepted")

    def access(ident: str, user: str, decision: str = "NULL", decided: str = "NULL") -> str:
        return (
            "INSERT INTO sa_access_reviews (id, closeout_id, user_id, email, roles, decision, decided_at, created_at)"
            f" VALUES ('{ident}', 'c6', '{user}', '{user}@example.org', 'viewer', {decision}, {decided}, {STAMP})"
        )

    accepts(connection, access("x1", "u1"), "an undecided access row was refused")
    refuses(connection, access("x2", "u1"), "the same person twice on one review was accepted")
    refuses(connection, access("x3", "u2", decision="'removed'"), "a decision with no stamp was accepted")
    refuses(connection, access("x4", "u3", decided=STAMP), "a stamp with no decision was accepted")
    refuses(connection, access("x5", "u4", decision="'maybe'", decided=STAMP), "a decision nobody named was accepted")
    accepts(connection, access("x6", "u5", decision="'retained'", decided=STAMP), "a stamped decision was refused")

    # Closing the engagement takes every Phase 7 row with it.
    connection.execute("DELETE FROM sa_engagements WHERE id = 'eM'")
    for table in ("sa_invoices", "sa_recoveries", "sa_closeouts", "sa_closeout_steps", "sa_access_reviews"):
        left = connection.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0]
        if left != 0:
            raise Failure(f"deleting an engagement left {table} rows behind")


ASSERTIONS = {
    "0001_foundation.php": assert_foundation,
    "0002_intake_and_engagement.php": assert_intake_and_engagement,
    "0003_preferences_and_client_access.php": assert_preferences_and_client_access,
    "0004_status_event_sequence.php": assert_status_event_sequence,
    "0005_documents_and_signatures.php": assert_documents_and_signatures,
    "0006_assessment_and_recovery_room.php": assert_assessment_and_recovery_room,
    "0007_recovery_agreement_and_approvals.php": assert_recovery_agreement_and_approvals,
    "0008_reconciliation_and_closeout.php": assert_reconciliation_and_closeout,
}


def run_cycle(migration_paths: list[pathlib.Path], db_path: pathlib.Path) -> dict:
    """
    Every migration, up in order and down in reverse, against one database.

    That is how they run on a server, and it is the only arrangement that
    proves the ordering: a foreign key in 0002 pointing at a table 0001 creates
    is only meaningful if 0001 actually ran first.
    """
    # Autocommit, so the savepoints below are the only transaction control.
    # Python's sqlite3 otherwise opens an implicit transaction before the first
    # write and a rollback anywhere would take the whole cycle with it.
    connection = sqlite3.connect(db_path, isolation_level=None)
    connection.execute("PRAGMA foreign_keys = ON")

    per_file: list[dict] = []
    all_down_plans: list[tuple[list[str], list[str]]] = []

    for migration_path in migration_paths:
        up_sql, down_tables, down_sql = split_halves(migration_path)
        all_down_plans.append((down_tables, down_sql))

        before = set(tables_now(connection))
        applied = 0
        for sql in up_sql:
            try:
                connection.execute(sql)
            except sqlite3.Error as error:
                head = " ".join(sql.split())[:110]
                raise Failure(
                    f"{migration_path.name}: up failed on statement {applied + 1}: {error}"
                    f"\n         {head}"
                )
            applied += 1

        after = tables_now(connection)
        created = sorted(set(after) - before)

        expected = EXPECTED_TABLES.get(migration_path.name)
        if expected is None:
            raise Failure(
                f"{migration_path.name} has no entry in EXPECTED_TABLES. Add one, "
                "or this file's tables are never checked."
            )
        missing = [table for table in expected if table not in after]
        if missing:
            raise Failure(f"{migration_path.name}: up did not create " + ", ".join(missing))

        assertion = ASSERTIONS.get(migration_path.name)
        if assertion is None:
            raise Failure(
                f"{migration_path.name} has no constraint assertions. Its CHECK and "
                "foreign key clauses would be decorative and nothing would notice."
            )
        in_savepoint(connection, assertion)

        per_file.append(
            {
                "name": migration_path.name,
                "statements": applied,
                "created": created,
                "dropped": down_tables or down_sql,
            }
        )

    # Down, in reverse file order, which is the order a rollback runs in.
    for down_tables, down_sql in reversed(all_down_plans):
        for statement in down_sql:
            try:
                connection.execute(statement)
            except sqlite3.Error as error:
                raise Failure(f"a down statement failed: {statement}: {error}")
        for table in down_tables:
            connection.execute(f'DROP TABLE IF EXISTS "{table}"')

    leftovers = tables_now(connection)
    if leftovers:
        raise Failure("down left tables behind: " + ", ".join(leftovers))

    connection.close()
    return {"files": per_file}


def structural_mysql_check(migration_path: pathlib.Path) -> list[str]:
    """
    The MySQL branch cannot be executed here. Check what can be checked from
    the text: that it is present, that it uses no construct known to be absent
    from MySQL 8, and that it carries an engine and charset.
    """
    source = migration_path.read_text(encoding="utf-8")
    notes: list[str] = []

    # Only a migration that creates a table can carry an engine and a charset.
    # 0004 adds a column to a table 0002 already created with both, and asking
    # it for them again would be asking it to repeat something it does not own.
    #
    # The marker is CREATE TABLE with its opening quote, so it matches a real
    # SQL string and not the phrase in a comment. 0004 explains itself by saying
    # "the way CREATE TABLE IF NOT EXISTS is", and matching on the bare words
    # made this check fire on a sentence about the check.
    if "'CREATE TABLE" in source:
        if "ENGINE=InnoDB" not in source:
            notes.append("no ENGINE=InnoDB clause")
        if "utf8mb4" not in source:
            notes.append("no utf8mb4 charset")

    # Constructs SQLite takes and MySQL does not.
    for banned, why in [
        ("AUTOINCREMENT", "SQLite-only keyword"),
        ("WITHOUT ROWID", "SQLite-only clause"),
    ]:
        if banned in source.upper():
            notes.append(f"{banned}: {why}")

    # A partial index is SQLite-only, so it must sit inside the sqlite branch.
    for match in re.finditer(r"CREATE UNIQUE INDEX[^']*WHERE", source, re.S):
        before = source[: match.start()]
        if before.count("if ($sqlite) {") <= before.count("} else {"):
            notes.append("a partial index appears outside the SQLite branch")

    return notes


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--keep", action="store_true", help="keep the temporary database file")
    args = parser.parse_args()

    files = sorted(MIGRATIONS.glob("*.php"))
    if not files:
        print("  No migrations found in " + str(MIGRATIONS))
        return 1

    print()
    print("  Soft Appeals schema check")
    print("  " + "-" * 58)

    failures = 0
    directory = pathlib.Path(tempfile.mkdtemp(prefix="sa-schema-"))
    db_path = directory / "check.sqlite"

    try:
        result = run_cycle(files, db_path)
    except Failure as error:
        print("  FAIL   migrations")
        print(f"         {error}")
        result = None
        failures += 1
    finally:
        if args.keep:
            print(f"         kept {db_path}")
        else:
            if db_path.exists():
                db_path.unlink()
            for leftover in (db_path.with_name("check.sqlite-wal"), db_path.with_name("check.sqlite-shm")):
                if leftover.exists():
                    leftover.unlink()
            directory.rmdir()

    if result is not None:
        total = sum(one["statements"] for one in result["files"])
        print(f"  ok     {len(files)} migrations, {total} statements up, empty after down")
        for one in result["files"]:
            print(f"         {one['name']}  {one['statements']} statements")
            for table in one["created"]:
                print(f"           {table}")

    for migration in files:
        notes = structural_mysql_check(migration)
        if notes:
            print(f"  WARN   {migration.name}, MySQL branch:")
            for note in notes:
                print(f"           {note}")
            failures += 1
        else:
            print(f"         {migration.name}: MySQL engine, charset and portability checks pass")

    print("  " + "-" * 58)
    if failures:
        print(f"  {failures} problem(s).")
    else:
        print("  Up and down both clean on SQLite, in file order.")
    print()
    print("  NOT PROVED HERE, and it cannot be on this machine:")
    print("    the PHP itself never executes, because there is no PHP runtime")
    print("    the MySQL branch is checked by reading, never by running")
    print()
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
