"""Typography and card geometry across the width range.

The width sweep proves the nav and the step strip. This one answers the rest of
what NaNa asked for: are the fonts and the cards sized right on every device.
Flags orphan cells in any grid, controls that iOS will zoom, headlines that
outgrow their column, and cards that fall below a readable width.
"""

import json
import os
import socket
import subprocess
import sys
import time
from pathlib import Path
from urllib.error import URLError
from urllib.request import urlopen

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
SERVER = ROOT / "scripts" / "preview_server.py"
WIDTHS = [320, 360, 375, 390, 414, 430, 620, 621, 744, 820, 834, 900, 901,
          1024, 1080, 1081, 1112, 1180, 1194, 1280, 1366, 1440]
PAGES = ["/", "/synkasa", "/siesie", "/free", "/fit", "/portfolio"]
SHOT_WIDTHS = [390, 744, 820, 1024, 1194, 1366]

AUDIT = r"""
() => {
  const px = v => Math.round(parseFloat(v) || 0);
  const out = {w: window.innerWidth, orphans: [], zoomers: [], narrow: [], type: {}};

  const nav = document.getElementById('fs-nav');
  if (nav) {
    out.navH = Math.round(nav.getBoundingClientRect().height);
    out.navPct = Math.round(out.navH / window.innerHeight * 100);
  }

  const h1 = document.querySelector('h1');
  if (h1) {
    out.type.h1 = px(getComputedStyle(h1).fontSize);
    out.type.h1Ratio = Math.round(out.type.h1 / window.innerWidth * 100);
  }
  const t2 = document.querySelector('.section-title');
  if (t2) out.type.sectionTitle = px(getComputedStyle(t2).fontSize);
  const body = document.querySelector('.section-intro, .hero-copy, p');
  if (body) out.type.body = px(getComputedStyle(body).fontSize);

  // Any grid whose children do not fill the final row leaves a hole.
  document.querySelectorAll(
    '.workflow, .journey-strip, .card-grid, .role-grid, .free-grid, .form-grid'
  ).forEach(g => {
    const cs = getComputedStyle(g);
    if (cs.display !== 'grid') return;
    const cols = cs.gridTemplateColumns.split(' ').filter(Boolean).length;
    const kids = [...g.children].filter(c => getComputedStyle(c).display !== 'none');
    if (cols < 2 || kids.length <= cols) return;
    const rem = kids.length % cols;
    if (rem === 0) return;
    out.orphans.push({
      sel: g.className.split(' ')[0], cols, kids, empty: cols - rem,
      bordered: cs.borderTopWidth !== '0px',
      bg: cs.backgroundColor,
    });
  });

  // Under 16px iOS zooms the page on focus. That is true on an iPad in
  // landscape too, which is far wider than the old 900px breakpoint.
  document.querySelectorAll('input, select, textarea').forEach(el => {
    if (el.type === 'hidden' || el.offsetParent === null) return;
    const fs = parseFloat(getComputedStyle(el).fontSize);
    if (fs < 16) out.zoomers.push({id: el.id || el.name || el.tagName, size: fs});
  });

  // A card below 240px of content width cannot hold its serif headline.
  document.querySelectorAll('.path-card, .free-card, .role-card, .form-card')
    .forEach(c => {
      const b = c.getBoundingClientRect();
      const cs = getComputedStyle(c);
      const inner = b.width - px(cs.paddingLeft) - px(cs.paddingRight);
      if (inner < 240) out.narrow.push({sel: c.className.split(' ')[0],
                                        inner: Math.round(inner)});
    });

  return out;
}
"""


def free_port():
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        return listener.getsockname()[1]


def start_server(port):
    proc = subprocess.Popen(
        [sys.executable, str(SERVER), "--bind", "127.0.0.1", "--port", str(port)],
        cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    deadline = time.monotonic() + 8
    while time.monotonic() < deadline:
        try:
            body = urlopen(f"http://127.0.0.1:{port}/", timeout=1).read()
            if body.strip() != (ROOT / "index.html").read_bytes().strip():
                proc.terminate()
                raise SystemExit("the server on this port is not this checkout")
            return proc
        except (URLError, OSError):
            time.sleep(0.15)
    proc.terminate()
    raise SystemExit("preview server did not start")


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    shots = "--shots" in sys.argv
    tag = "after" if "--after" in sys.argv else "before"
    proc = None
    if args:
        base = args[0]
    else:
        port = free_port()
        proc = start_server(port)
        base = f"http://127.0.0.1:{port}"

    shot_dir = ROOT / "tests" / "shots" / tag
    shot_dir.mkdir(parents=True, exist_ok=True)
    results = {}
    try:
        with sync_playwright() as p:
            exe = os.environ.get("PLAYWRIGHT_EXECUTABLE_PATH")
            browser = p.chromium.launch(
                executable_path=exe, channel=None if exe else "chrome")
            for path in PAGES:
                page = browser.new_page(viewport={"width": 1440, "height": 900})
                rows = []
                for w in WIDTHS:
                    page.set_viewport_size({"width": w, "height": 940})
                    page.goto(base.rstrip("/") + path, wait_until="load")
                    page.wait_for_timeout(420)
                    rows.append(page.evaluate(AUDIT))
                    if shots and w in SHOT_WIDTHS:
                        name = (path.strip("/") or "home") + f"-{w}.png"
                        page.screenshot(path=str(shot_dir / name))
                results[path] = rows
                page.close()
            browser.close()
    finally:
        if proc:
            proc.terminate()

    for path, rows in results.items():
        print(f"\n=== {path} ===")
        print(f"{'width':>6} {'navH':>5} {'nav%':>5} {'h1':>4} {'title':>6} "
              f"{'body':>5}  orphans / zoomers / narrow")
        for m in rows:
            t = m["type"]
            orph = ",".join(f"{o['sel']}({o['kids']}in{o['cols']}"
                            f"{'|bordered' if o['bordered'] else ''})"
                            for o in m["orphans"]) or "-"
            zoom = ",".join(f"{z['id']}@{z['size']:g}" for z in m["zoomers"]) or "-"
            narrow = ",".join(f"{n['sel']}@{n['inner']}" for n in m["narrow"]) or "-"
            print(f"{m['w']:>6} {str(m.get('navH')):>5} {str(m.get('navPct')):>5} "
                  f"{str(t.get('h1')):>4} {str(t.get('sectionTitle')):>6} "
                  f"{str(t.get('body')):>5}  {orph} / {zoom} / {narrow}")

    out = ROOT / "tests" / f"size_audit_{tag}.json"
    out.write_text(json.dumps(results, indent=1))
    print(f"\nwrote {out}")


if __name__ == "__main__":
    main()
