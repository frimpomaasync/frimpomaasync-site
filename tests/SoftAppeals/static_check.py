#!/usr/bin/env python3
"""
Static checks that can run without a PHP runtime.

This machine has no PHP, so `php -l` is not available and nothing here claims
to be a syntax check. What it does check is the class of mistake that a syntax
check would not catch anyway, and that would only surface as a blank page on a
live server:

  1. PSR-4 correctness. The autoloader maps SoftAppeals\Foo\Bar to
     src/SoftAppeals/Foo/Bar.php. A namespace that disagrees with its path is a
     class that never loads, and the failure looks like an empty response.
  2. No secret in the repository, by handing that job to secret_scan.py and
     failing if it finds anything. The patterns live in exactly one file: two
     copies is what made the build fail on its own definition of a secret.
  3. No stray output before <?php, and no closing ?> at the end of a class file.
     A byte before the opening tag breaks every header() call on the page.
  4. Balanced braces, parentheses and brackets outside strings and comments.
  5. Every private directory carries a deny-all .htaccess.

Usage:
    python3 tests/SoftAppeals/static_check.py
"""

from __future__ import annotations

import pathlib
import re
import sys

REPO = pathlib.Path(__file__).resolve().parents[2]
SRC = REPO / "src" / "SoftAppeals"

# Directories that must never be served, each of which must carry Require all denied.
PRIVATE_DIRS = ["src", "templates", "database", "storage-private", "cron", "tests", "docs", "vault", "fs-metrics"]

failures: list[str] = []
checked = 0


def fail(path: pathlib.Path, message: str) -> None:
    failures.append(f"{path.relative_to(REPO)}: {message}")


def php_regions_only(text: str) -> str:
    """
    Keep only what is between <?php or <?= and the matching ?>.

    The two page controllers interleave PHP with HTML, and that HTML carries an
    inline stylesheet. Counting a CSS rule's braces as PHP braces is how this
    check reported eight phantom problems the first time it ran.
    """
    regions: list[str] = []
    i = 0
    n = len(text)
    while i < n:
        start = text.find("<?", i)
        if start == -1:
            break
        if text.startswith("<?php", start):
            body_start = start + 5
        elif text.startswith("<?=", start):
            body_start = start + 3
        else:
            i = start + 2
            continue
        end = text.find("?>", body_start)
        if end == -1:
            regions.append(text[body_start:])
            break
        regions.append(text[body_start:end])
        i = end + 2
    return "\n".join(regions)


def strip_php_strings_and_comments(text: str) -> str:
    """
    Blank out strings, heredocs and comments so a brace inside them is not
    counted. Crude but sufficient: it only needs to make the counts trustworthy.
    """
    out: list[str] = []
    i = 0
    n = len(text)
    while i < n:
        two = text[i:i + 2]
        if two == "//":
            j = text.find("\n", i)
            i = n if j == -1 else j
            continue
        if two == "/*":
            j = text.find("*/", i + 2)
            i = n if j == -1 else j + 2
            continue
        if text[i] == "#" and text[i:i + 2] != "#[":
            j = text.find("\n", i)
            i = n if j == -1 else j
            continue
        if text[i] in "'\"":
            quote = text[i]
            i += 1
            while i < n:
                if text[i] == "\\":
                    i += 2
                    continue
                if text[i] == quote:
                    i += 1
                    break
                i += 1
            out.append(" ")
            continue
        if text[i:i + 3] == "<<<":
            match = re.match(r"<<<\s*['\"]?([A-Za-z_][A-Za-z0-9_]*)['\"]?\r?\n", text[i:])
            if match:
                label = match.group(1)
                end = re.search(rf"^\s*{label}\b", text[i:], re.M)
                if end:
                    i += end.end()
                    out.append(" ")
                    continue
        out.append(text[i])
        i += 1
    return "".join(out)


def check_php(path: pathlib.Path) -> None:
    global checked
    checked += 1
    raw = path.read_bytes()
    text = raw.decode("utf-8")

    if raw.startswith(b"\xef\xbb\xbf"):
        fail(path, "starts with a UTF-8 byte order mark, which breaks header()")
    if not raw.startswith(b"<?php"):
        fail(path, "does not begin with <?php on byte one")

    # A closing tag in a class file lets a trailing newline become output.
    body = text.rstrip()
    if body.endswith("?>") and "/src/" in str(path):
        fail(path, "ends with ?>, which can emit a stray newline")

    stripped = strip_php_strings_and_comments(php_regions_only(text))
    for opener, closer, name in [("{", "}", "brace"), ("(", ")", "paren"), ("[", "]", "bracket")]:
        difference = stripped.count(opener) - stripped.count(closer)
        if difference != 0:
            fail(path, f"{name} count is off by {difference:+d}")

    # PSR-4: namespace must match the directory under src/SoftAppeals.
    if SRC in path.parents or path.parent == SRC:
        namespace_match = re.search(r"^namespace\s+([A-Za-z0-9_\\]+);", text, re.M)
        class_match = re.search(r"^(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)", text, re.M)
        if namespace_match and class_match:
            expected_namespace = "SoftAppeals"
            relative_dir = path.parent.relative_to(SRC)
            if str(relative_dir) != ".":
                expected_namespace += "\\" + str(relative_dir).replace("/", "\\")
            if namespace_match.group(1) != expected_namespace:
                fail(path, f"namespace is {namespace_match.group(1)}, path says {expected_namespace}")
            if class_match.group(1) != path.stem:
                fail(path, f"class {class_match.group(1)} is in {path.name}, autoloader will not find it")


def main() -> int:
    print()
    print("  Soft Appeals static check")
    print("  " + "-" * 58)

    php_files = sorted(
        list(SRC.rglob("*.php"))
        + list((REPO / "database").rglob("*.php"))
        + list((REPO / "cron").rglob("*.php"))
        + [REPO / "sa-desk.php", REPO / "soft-appeals-login.php"]
    )
    for path in php_files:
        if path.exists():
            check_php(path)

    for directory in PRIVATE_DIRS:
        htaccess = REPO / directory / ".htaccess"
        if not (REPO / directory).is_dir():
            continue
        if not htaccess.exists():
            failures.append(f"{directory}/: no .htaccess, the deploy would publish it")
        elif "Require all denied" not in htaccess.read_text(encoding="utf-8"):
            failures.append(f"{directory}/.htaccess: does not deny access")

    # Secret scanning proper. Called rather than reimplemented, so the
    # patterns exist in one file only. This also means a workflow that only
    # runs the static check still gets the full gate.
    sys.path.insert(0, str(pathlib.Path(__file__).parent))
    import secret_scan

    broken = secret_scan.self_test()
    if broken:
        for problem in broken:
            failures.append(f"secret_scan.py: {problem}")
    else:
        for finding in secret_scan.scan():
            failures.append(f"secret: {finding}")

    print(f"  {checked} PHP files checked")
    print(f"  {len(PRIVATE_DIRS)} private directories checked for a deny-all")
    print("  secret scan: self-tested, then run over every tracked file")
    print("  " + "-" * 58)

    if failures:
        for failure in failures:
            print(f"  FAIL   {failure}")
        print("  " + "-" * 58)
        print(f"  {len(failures)} problem(s).")
        print()
        return 1

    print("  Clean. Namespaces match paths, no secret anywhere, every private folder denied.")
    print()
    print("  This is NOT a PHP syntax check. There is no PHP on this machine.")
    print("  The first real syntax gate is the staging deploy.")
    print()
    return 0


if __name__ == "__main__":
    sys.exit(main())
