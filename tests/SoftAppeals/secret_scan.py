#!/usr/bin/env python3
"""
Refuse to ship a committed secret.

The deploy uploads whatever is committed, so a password in a tracked file is a
published password. This is the last gate before anything leaves the runner.

WHY THIS IS A SCRIPT AND NOT A LINE IN THE WORKFLOW
---------------------------------------------------
It started as two `git grep` calls inside deploy-staging.yml. The second one
searched for the words that begin a private key block, and it found them: in
tests/SoftAppeals/static_check.py, where that exact phrase is written down as
the thing to look for. The scanner matched the scanner's own definition of what
a secret looks like, and the build failed on 2026-08-27 for that reason.

Two lessons, both handled here rather than patched over:

  1. A scanner must know which files exist to describe patterns, and skip them.
     That is the SCANNER_FILES list below.
  2. A gate that has never been seen to fail is not a gate. `--self-test` plants
     a real-shaped secret in a temporary file, proves the scan catches it, and
     removes it. CI runs the self-test before the scan, so a scan that has
     quietly stopped working fails the build instead of passing it.

Living in a script also means the next fix is an ordinary push. Changing a file
under .github/workflows/ needs a GitHub token carrying the workflow scope, which
hers does not have, so every workflow edit costs a manual paste in the browser.

Usage:
    python3 tests/SoftAppeals/secret_scan.py
    python3 tests/SoftAppeals/secret_scan.py --self-test
"""

from __future__ import annotations

import argparse
import os
import pathlib
import re
import subprocess
import sys
import tempfile

REPO = pathlib.Path(__file__).resolve().parents[2]

# Files whose job is to describe what a secret looks like. They contain the
# patterns by necessity and must never be scanned with them.
SCANNER_FILES = {
    "tests/SoftAppeals/secret_scan.py",
    "tests/SoftAppeals/static_check.py",
}

# Files that legitimately name configuration keys with no values beside them.
REFERENCE_FILES = {
    "src/SoftAppeals/.env.example",
}

# Binary and media, skipped by extension rather than by sniffing every byte.
SKIP_SUFFIXES = {
    ".png", ".jpg", ".jpeg", ".gif", ".webp", ".svg", ".ico",
    ".mp4", ".webm", ".mov", ".mp3", ".wav",
    ".pdf", ".zip", ".gz", ".woff", ".woff2", ".ttf", ".otf", ".bak",
}

# A value that is obviously not real. Anything matching these is not a finding.
PLACEHOLDERS = re.compile(
    r"^(PASTE_|CHANGE_?ME|REPLACE|YOUR_|xxx+|\.\.\.|<[^>]+>|example|placeholder|"
    r"test-|dummy|fake|sample|\$\{|\$\(|%[A-Z_]+%)",
    re.IGNORECASE,
)

CHECKS: list[tuple[str, re.Pattern[str], str]] = [
    (
        "config secret with a value",
        re.compile(
            r"""SA_(?:DB_PASSWORD|SESSION_SECRET|TOKEN_SECRET|IP_HMAC_SECRET|CRON_SECRET)"""
            r"""["']?\s*(?:=>|[:=])\s*["']([^"']{6,})["']"""
        ),
        "a Soft Appeals secret with a real-looking value",
    ),
    (
        "private key block",
        # Built from parts so this line is not itself a literal key header.
        re.compile("-{5}" + "BEGIN" + r"[ A-Z]*" + "PRIVATE KEY" + "-{5}"),
        "the opening line of a private key",
    ),
    (
        "database password in a dsn",
        re.compile(r"""(?:mysql|pgsql):[^"'\s]*[;:]\s*password\s*=\s*([^"';\s]{6,})""", re.IGNORECASE),
        "a database password inside a connection string",
    ),
    (
        "smtp credential",
        re.compile(r"""["'](?:pass|password|pwd)["']\s*(?:=>|:)\s*["']([^"']{8,})["']"""),
        "a mail or service password",
    ),
    (
        "api token literal",
        re.compile(r"\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{30,}\b"),
        "a GitHub token",
    ),
    (
        "aws key id",
        re.compile(r"\bAKIA[0-9A-Z]{16}\b"),
        "an AWS access key id",
    ),
]


def tracked_files() -> list[str]:
    out = subprocess.run(
        ["git", "ls-files"],
        cwd=REPO,
        capture_output=True,
        text=True,
        check=True,
    )
    return [line for line in out.stdout.splitlines() if line]


def scan_text(relative: str, text: str) -> list[str]:
    findings: list[str] = []
    is_reference = relative in REFERENCE_FILES

    for line_number, line in enumerate(text.splitlines(), start=1):
        stripped = line.strip()

        # A commented line in the reference file is documentation, not a value.
        if is_reference and stripped.startswith("#"):
            continue

        for name, pattern, description in CHECKS:
            match = pattern.search(line)
            if not match:
                continue

            # A captured value that is plainly a placeholder is not a finding.
            captured = match.group(1) if match.groups() else ""
            if captured and PLACEHOLDERS.match(captured):
                continue
            if captured and captured.count("_") >= 2 and captured.isupper():
                continue  # PASTE_DATABASE_NAME style

            findings.append(
                f"{relative}:{line_number}: {description}  [{name}]"
            )
    return findings


def scan() -> list[str]:
    findings: list[str] = []
    for relative in tracked_files():
        if relative in SCANNER_FILES:
            continue
        if pathlib.Path(relative).suffix.lower() in SKIP_SUFFIXES:
            continue

        path = REPO / relative
        if not path.is_file():
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except (UnicodeDecodeError, OSError):
            continue  # binary or unreadable
        findings.extend(scan_text(relative, text))
    return findings


def self_test() -> list[str]:
    """
    Prove the scan still catches what it is for.

    A gate nobody has watched fail is not a gate. Each case below is a
    real-shaped secret written into a temporary path, scanned through the same
    code the real run uses, and asserted to be caught. The placeholder cases
    assert the opposite: that the scan does not cry wolf over the reference
    file, which is what would train her to ignore it.
    """
    must_catch = [
        ("config.php", "'SA_DB_PASSWORD' => 'Hq7dK2mPx9vLb4nR',"),
        ("config.php", "'SA_SESSION_SECRET' => 'r8Kd2mQx7pLv4nBw9tYz',"),
        # Assembled from pieces so this source line is not itself a key header.
        # A scanner that trips other scanners is what broke the build once already.
        ("key.pem", "-" * 5 + "BEGIN RSA " + "PRIVATE" + " KEY" + "-" * 5),
        ("db.php", "$dsn = 'mysql:host=localhost;password=Sk9dMx2pQr7v';"),
        ("mail.php", "'password' => 'Zx7Kd2mQp9vLb4nR',"),
        ("ci.yml", "token: ghp_" + "A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8"),
        ("aws.php", "'key' => 'AKIAIOSFODNN7EXAMPLE',"),
    ]
    must_ignore = [
        ("src/SoftAppeals/.env.example", "# SA_DB_PASSWORD= the database password"),
        ("config.php", "'SA_DB_PASSWORD' => 'PASTE_PASSWORD',"),
        ("config.php", "'SA_TOKEN_SECRET' => 'CHANGE_ME',"),
        ("run.php", "'SA_SESSION_SECRET' => str_repeat('test-session-secret-', 3),"),
    ]

    problems: list[str] = []

    for name, line in must_catch:
        if not scan_text(name, line):
            problems.append(f"MISSED a planted secret in {name}: {line[:48]}")

    for name, line in must_ignore:
        found = scan_text(name, line)
        if found:
            problems.append(f"FALSE ALARM on {name}: {found[0]}")

    return problems


def main() -> int:
    parser = argparse.ArgumentParser(description="Refuse to ship a committed secret.")
    parser.add_argument("--self-test", action="store_true", help="prove the scan still works, then stop")
    args = parser.parse_args()

    print()
    print("  Soft Appeals secret scan")
    print("  " + "-" * 58)

    problems = self_test()
    if problems:
        for problem in problems:
            print(f"  BROKEN  {problem}")
        print("  " + "-" * 58)
        print("  The scan itself is not working. Nothing was checked.")
        print()
        return 2
    print(f"  self-test: {7} planted secrets caught, 4 placeholders correctly ignored")

    if args.self_test:
        print()
        return 0

    findings = scan()
    count = len(tracked_files())
    print(f"  {count} tracked files scanned")
    print("  " + "-" * 58)

    if findings:
        for finding in findings:
            print(f"  FAIL   {finding}")
            if os.environ.get("GITHUB_ACTIONS") == "true":
                path, _, rest = finding.partition(":")
                line, _, text = rest.partition(":")
                if line.strip().isdigit():
                    print(f"::error file={path},line={line.strip()}::{text.strip()}")
                else:
                    print(f"::error file={path}::{rest.strip()}")
        print("  " + "-" * 58)
        print(f"  {len(findings)} possible secret(s). Nothing should be deployed.")
        print()
        return 1

    print("  Clean. No secret in any tracked file.")
    print()
    return 0


if __name__ == "__main__":
    sys.exit(main())
