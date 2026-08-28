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


def split_halves(migration_path: pathlib.Path) -> tuple[list[str], list[str]]:
    """
    One migration, read into its up statements and its down table list.

    The down half builds its SQL by concatenating a table name, so its
    $db->run() call carries no complete statement and must never be swept into
    the up list. The table names are read from the list the migration itself
    loops over, which keeps this honest: a table added to `up` and forgotten in
    `down` is caught by the leftover assertion at the end of the cycle.
    """
    source = migration_path.read_text(encoding="utf-8")
    split_at = source.index("'down' =>")
    up_source = source[:split_at]
    down_block = source[split_at:]

    up_sql = [strip_suffix_concat(one) for one in extract_statements(up_source, sqlite_branch=True)]
    if not up_sql:
        raise Failure(f"No SQL found in {migration_path.name}")

    down_tables = re.findall(r"'(sa_[a-z_]+)'", down_block)
    if not down_tables:
        raise Failure(f"No tables listed in the down half of {migration_path.name}")

    return up_sql, down_tables


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


ASSERTIONS = {
    "0001_foundation.php": assert_foundation,
    "0002_intake_and_engagement.php": assert_intake_and_engagement,
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
    all_down_tables: list[list[str]] = []

    for migration_path in migration_paths:
        up_sql, down_tables = split_halves(migration_path)
        all_down_tables.append(down_tables)

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
                "dropped": down_tables,
            }
        )

    # Down, in reverse file order, which is the order a rollback runs in.
    for down_tables in reversed(all_down_tables):
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
