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
  6. A real `php -l` syntax check, WHEN a PHP binary exists.

On CI a PHP binary does exist, so the syntax check runs there and the deploy is
blocked by it. On her Mac it does not, and the check reports itself as skipped
rather than silently passing.

That gap cost a broken deploy on 2026-08-27: a parse error shipped, every Soft
Appeals page returned an empty 500, and nothing in this file had any way to see
it. A parse error is a compile-time fatal, so the application's own error
handler never runs and there is no message to read. Only a linter finds it.

Usage:
    python3 tests/SoftAppeals/static_check.py
"""

from __future__ import annotations

import os
import pathlib
import re
import shutil
import subprocess
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


ALTERNATIVE_SYNTAX = {
    "if": "endif",
    "foreach": "endforeach",
    "for": "endfor",
    "while": "endwhile",
    "switch": "endswitch",
}


def check_alternative_syntax(path: pathlib.Path, php_only: str) -> None:
    """
    Every `if (...):` in a view has a matching `endif;`.

    Brace counting cannot see this. A view that mixes PHP with markup uses the
    alternative syntax throughout, so a forgotten `endforeach;` leaves the brace
    count perfectly balanced and produces a parse error that only a real PHP
    binary would catch. On a machine with no PHP that means an empty 500 on the
    Desk, discovered by loading it.

    `elseif` and `else` open nothing and close nothing, so neither is counted.
    """
    opened = {keyword: 0 for keyword in ALTERNATIVE_SYNTAX}
    closed = {keyword: 0 for keyword in ALTERNATIVE_SYNTAX}

    for line in php_only.splitlines():
        stripped = line.strip()
        for keyword in ALTERNATIVE_SYNTAX:
            # An opener is the keyword at the start of a statement, on a line
            # that ends in a colon. A function with a return type ends in `{`,
            # and a match arm ends in `,`, so neither is picked up.
            if re.match(rf"^{keyword}\b", stripped) and stripped.endswith(":"):
                opened[keyword] += 1
        for keyword, ender in ALTERNATIVE_SYNTAX.items():
            closed[keyword] += len(re.findall(rf"\b{ender}\s*;", stripped))

    for keyword, ender in ALTERNATIVE_SYNTAX.items():
        difference = opened[keyword] - closed[keyword]
        if difference != 0:
            fail(
                path,
                f"{opened[keyword]} `{keyword} ...:` against {closed[keyword]} `{ender};`"
                f" (off by {difference:+d})",
            )


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

    # Views mix PHP with markup and use the alternative syntax throughout. A
    # missing endforeach there is invisible to a brace count.
    if "?>" in text:
        check_alternative_syntax(path, stripped)

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


def annotate(message: str) -> None:
    """
    Emit a GitHub Actions annotation.

    The workflow logs need admin rights on the repository to download, so a
    failing check said only "Static check failed" and nothing about which file.
    Annotations come back through the API without that, and show inline on the
    diff for a person. One line, and it turns a guess into an answer.
    """
    if os.environ.get("GITHUB_ACTIONS") != "true":
        return
    # A finding forwarded from the secret scan reads "secret: path:line: text",
    # so the prefix has to come off or the annotation points at a file called
    # "secret" and the line number lands in the message.
    for prefix in ("secret: ", "secret_scan.py: "):
        if message.startswith(prefix):
            message = message[len(prefix):]
            break
    path, _, rest = message.partition(":")
    line, _, text = rest.partition(":")
    flat = " ".join((text or rest or message).split())
    if line.strip().isdigit():
        print(f"::error file={path},line={line.strip()}::{flat}")
    else:
        print(f"::error file={path}::{flat}")


def php_lint(paths: list[pathlib.Path]) -> str:
    """
    `php -l` on every file, when a PHP binary is available.

    This is the only check here that can catch a parse error, and a parse error
    is the one failure the application cannot report on itself: it is a
    compile-time fatal, so the error handler never registers and the response is
    an empty 500 with nothing in it to read.

    On a machine with no PHP this returns a note saying so. It never pretends to
    have passed.
    """
    binary = shutil.which("php")
    if binary is None:
        return "php -l: SKIPPED, no PHP on this machine (it runs on CI)"

    bad = 0
    for path in paths:
        if not path.exists():
            continue
        result = subprocess.run(
            [binary, "-l", str(path)],
            capture_output=True,
            text=True,
        )
        if result.returncode != 0:
            bad += 1
            message = (result.stdout + result.stderr).strip().splitlines()
            detail = message[0] if message else "unknown parse error"
            failures.append(f"{path.relative_to(REPO)}: {detail}")
    return f"php -l: {len(paths)} files, {bad} with syntax errors"


def main() -> int:
    print()
    print("  Soft Appeals static check")
    print("  " + "-" * 58)

    php_files = sorted(
        list(SRC.rglob("*.php"))
        + list((REPO / "database").rglob("*.php"))
        + list((REPO / "cron").rglob("*.php"))
        # The Desk views. They interleave PHP with markup, they are the newest
        # code in the project, and a parse error in one of them is an empty 500
        # on the command centre. php_regions_only already handles the mixing.
        + list((REPO / "templates").rglob("*.php"))
        # The test suite itself. It cannot run on this machine, so a parse error
        # in a test file would go unseen until somebody had a PHP runtime, and
        # the whole point of these checks is that nothing ships unread.
        + list((REPO / "tests" / "SoftAppeals").rglob("*.php"))
        + [
            REPO / "sa-desk.php",
            REPO / "soft-appeals-login.php",
            REPO / "soft-appeals-setup.php",
            REPO / "soft-appeals-preferences.php",
            REPO / "soft-appeals-confirmed.php",
            REPO / "soft-appeals-room.php",
        ]
    )
    for path in php_files:
        if path.exists():
            check_php(path)

    tracked = set(
        subprocess.run(
            ["git", "ls-files"], cwd=REPO, capture_output=True, text=True, check=True
        ).stdout.splitlines()
    )

    # Every private directory, and every subdirectory of storage-private, needs
    # a deny-all that git actually tracks.
    #
    # Both halves of that sentence are load-bearing, and both were learned the
    # hard way on 2026-08-27. The deploy excludes **/.git*, which matches
    # .gitkeep, so five storage directories never reached the server at all. The
    # .htaccess files that replaced them were then swallowed by an over-broad
    # rule in .gitignore, so four of the five were about to go missing a second
    # time in the very commit that fixed the first.
    directories = list(PRIVATE_DIRS)
    storage = REPO / "storage-private" / "soft-appeals"
    if storage.is_dir():
        directories += [
            str(child.relative_to(REPO)) for child in sorted(storage.iterdir()) if child.is_dir()
        ]

    for directory in directories:
        path = REPO / directory
        if not path.is_dir():
            continue
        htaccess = path / ".htaccess"
        relative = f"{directory}/.htaccess"
        if not htaccess.exists():
            failures.append(f"{directory}/: no .htaccess, the deploy would publish it")
        elif "Require all denied" not in htaccess.read_text(encoding="utf-8"):
            failures.append(f"{directory}/.htaccess: does not deny access")
        elif relative not in tracked:
            failures.append(
                f"{relative}: exists but git does not track it, so the deploy "
                "will never create this directory on the server"
            )

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

    lint_note = php_lint(php_files)

    print(f"  {checked} PHP files checked")
    print(f"  {len(directories)} private directories checked for a tracked deny-all")
    print("  secret scan: self-tested, then run over every tracked file")
    print(f"  {lint_note}")
    print("  " + "-" * 58)

    if failures:
        for failure in failures:
            print(f"  FAIL   {failure}")
            annotate(failure)
        print("  " + "-" * 58)
        print(f"  {len(failures)} problem(s).")
        print()
        return 1

    print("  Clean. Namespaces match paths, no secret anywhere, every private folder denied.")
    print()
    if shutil.which("php") is None:
        print("  No PHP on this machine, so `php -l` did not run here.")
        print("  It runs on CI, and a parse error blocks the deploy there.")
    print()
    return 0


if __name__ == "__main__":
    sys.exit(main())
