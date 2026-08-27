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

# Tables the foundation migration must create, in creation order.
EXPECTED_TABLES = [
    "sa_organizations",
    "sa_contacts",
    "sa_users",
    "sa_memberships",
    "sa_audit_events",
    "sa_rate_limits",
    "sa_idempotency_keys",
]


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


def run_cycle(migration_path: pathlib.Path, db_path: pathlib.Path) -> dict:
    source = migration_path.read_text(encoding="utf-8")

    # Read the two halves separately. The down half builds its SQL by
    # concatenating a table name, so its $db->run() call carries no complete
    # statement and must never be swept into the up list.
    split_at = source.index("'down' =>")
    up_source = source[:split_at]
    down_block = source[split_at:]

    up_sql = [strip_suffix_concat(s) for s in extract_statements(up_source, sqlite_branch=True)]
    if not up_sql:
        raise Failure(f"No SQL found in {migration_path.name}")

    # The down half is a loop over table names, not literal SQL, so it is read
    # from the list the migration itself uses. That keeps this check honest: if
    # a table is added to `up` and forgotten in `down`, the leftover-table
    # assertion below is what catches it.
    down_tables = re.findall(r"'(sa_[a-z_]+)'", down_block)
    if not down_tables:
        raise Failure("No tables listed in the down migration")

    connection = sqlite3.connect(db_path)
    connection.execute("PRAGMA foreign_keys = ON")

    applied = 0
    for sql in up_sql:
        try:
            connection.execute(sql)
        except sqlite3.Error as error:
            head = " ".join(sql.split())[:110]
            raise Failure(f"up failed on statement {applied + 1}: {error}\n         {head}")
        applied += 1
    connection.commit()

    tables_after_up = sorted(
        row[0]
        for row in connection.execute(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'sa_%'"
        )
    )

    missing = [t for t in EXPECTED_TABLES if t not in tables_after_up]
    if missing:
        raise Failure("up did not create: " + ", ".join(missing))

    # Foreign keys must actually be enforced, or the constraints are decorative.
    try:
        connection.execute(
            "INSERT INTO sa_contacts (id, organization_id, name, work_email, active, created_at)"
            " VALUES ('x', 'does-not-exist', 'n', 'e@example.org', 1, '2026-01-01 00:00:00')"
        )
        connection.rollback()
        raise Failure("a contact with an unknown organization was accepted")
    except sqlite3.IntegrityError:
        pass  # correct

    # CHECK constraints must bite too.
    try:
        connection.execute(
            "INSERT INTO sa_organizations"
            " (id, public_ref, legal_name, status, created_at, updated_at)"
            " VALUES ('y', 'SA-ORG-TEST01', 'Test', 'not_a_status',"
            " '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        )
        connection.rollback()
        raise Failure("an organization with an invalid status was accepted")
    except sqlite3.IntegrityError:
        pass  # correct

    # The partial unique index on staff memberships must stop a duplicate.
    connection.execute(
        "INSERT INTO sa_users (id, email, active, created_at)"
        " VALUES ('u1', 'a@example.org', 1, '2026-01-01 00:00:00')"
    )
    connection.execute(
        "INSERT INTO sa_memberships (user_id, organization_id, role, created_at)"
        " VALUES ('u1', NULL, 'owner_admin', '2026-01-01 00:00:00')"
    )
    try:
        connection.execute(
            "INSERT INTO sa_memberships (user_id, organization_id, role, created_at)"
            " VALUES ('u1', NULL, 'owner_admin', '2026-01-01 00:00:00')"
        )
        raise Failure("a duplicate global staff membership was accepted")
    except sqlite3.IntegrityError:
        pass  # correct
    connection.rollback()

    for table in down_tables:
        connection.execute(f'DROP TABLE IF EXISTS "{table}"')
    connection.commit()

    leftovers = sorted(
        row[0]
        for row in connection.execute(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'sa_%'"
        )
    )
    if leftovers:
        raise Failure("down left tables behind: " + ", ".join(leftovers))

    connection.close()

    return {
        "statements": applied,
        "tables": tables_after_up,
        "dropped": down_tables,
    }


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
    for migration in files:
        directory = pathlib.Path(tempfile.mkdtemp(prefix="sa-schema-"))
        db_path = directory / "check.sqlite"
        try:
            result = run_cycle(migration, db_path)
        except Failure as error:
            print(f"  FAIL   {migration.name}")
            print(f"         {error}")
            failures += 1
            continue
        finally:
            if not args.keep and db_path.exists():
                db_path.unlink()
                directory.rmdir()
            elif args.keep:
                print(f"         kept {db_path}")

        print(f"  ok     {migration.name}")
        print(f"         {result['statements']} statements up, "
              f"{len(result['tables'])} tables, "
              f"{len(result['dropped'])} dropped, empty after down")
        for table in result["tables"]:
            print(f"           {table}")

        notes = structural_mysql_check(migration)
        if notes:
            print("  WARN   MySQL branch:")
            for note in notes:
                print(f"           {note}")
            failures += 1
        else:
            print("         MySQL branch: engine, charset and portability checks pass")

    print("  " + "-" * 58)
    if failures:
        print(f"  {failures} problem(s).")
    else:
        print("  Up and down both clean on SQLite.")
    print()
    print("  NOT PROVED HERE, and it cannot be on this machine:")
    print("    the PHP itself never executes, because there is no PHP runtime")
    print("    the MySQL branch is checked by reading, never by running")
    print("  Both close on staging, which is the first environment that has")
    print("  PHP 8.3 and MySQL together. Phase 0, blocker B-03.")
    print()

    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
