"""Width sweep for the site chrome and the numbered step strips.

Measures, at every width NaNa's buyers actually hold:
  navH        the nav bar height in px
  rows        how many rows the four nav links occupy
  gap         px between the bottom of the nav and the top of the hero eyebrow
  overflow    document.documentElement.scrollWidth minus window.innerWidth
  cols/steps  the step strip grid, plus the colour of any unfilled tail cell
  heroVar     colour variance across the hero photo area

Run against the local preview server or against production:
  python3 tests/width_sweep.py                       local
  python3 tests/width_sweep.py https://frimpomaasync.com
"""

import base64
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
WIDTHS = [320, 360, 375, 390, 414, 430, 744, 820, 834, 900, 901, 940, 960,
          961, 1000, 1024, 1080, 1081, 1112, 1180, 1194, 1280, 1366, 1440]
PAGES = ["/", "/synkasa", "/siesie", "/fit"]

MEASURE = r"""
() => {
  const nav = document.getElementById('fs-nav');
  const out = {
    w: window.innerWidth,
    overflow: document.documentElement.scrollWidth - window.innerWidth,
    navH: null, rows: null, gap: null, wordmarkRow: null,
    cols: null, steps: null, tail: null, tailBg: null,
    smallControls: [],
  };
  if (nav) {
    const nb = nav.getBoundingClientRect();
    out.navH = Math.round(nb.height);
    const links = [...nav.querySelectorAll('nav a')];
    const tops = new Set(links.map(a => Math.round(a.getBoundingClientRect().top)));
    out.rows = tops.size;
    const wordmark = nav.querySelector('.fs-grid > a[href="/"]');
    if (wordmark) {
      const wt = Math.round(wordmark.getBoundingClientRect().top);
      out.wordmarkRow = [...tops].sort((a, b) => a - b).indexOf(wt);
      out.wordmarkBetween = ![...tops].includes(wt) &&
        wt > Math.min(...tops) && wt < Math.max(...tops);
    }
    const eyebrow = document.querySelector('.hero .eyebrow, .cinematic-hero .eyebrow');
    if (eyebrow) out.gap = Math.round(eyebrow.getBoundingClientRect().top - nb.bottom);
  }
  /* The strip is flex now, so a column count means nothing. Group the steps by
     the row they landed on and ask the only question that matters: does the last
     row reach the right edge, or is there bare container showing. */
  const strip = document.querySelector('.workflow, .journey-strip');
  if (strip) {
    const cs = getComputedStyle(strip);
    const sb = strip.getBoundingClientRect();
    const kids = [...strip.children];
    out.steps = kids.length;
    out.tailBg = cs.backgroundColor;
    const byRow = new Map();
    kids.forEach(k => {
      const b = k.getBoundingClientRect();
      const key = Math.round(b.top);
      if (!byRow.has(key)) byRow.set(key, []);
      byRow.get(key).push(b);
    });
    const rows = [...byRow.entries()].sort((a, b) => a[0] - b[0]).map(e => e[1]);
    out.cols = Math.max(...rows.map(r => r.length));
    out.stripRows = rows.length;
    const lastRow = rows[rows.length - 1];
    const lastRight = Math.max(...lastRow.map(b => b.right));
    out.tail = Math.round(sb.right - lastRight - 1);
    if (out.tail > 2) {
      out.tailRect = {x: Math.round(lastRight + 2),
                      y: Math.round(lastRow[0].top + lastRow[0].height / 2),
                      w: out.tail - 4};
    }
  }
  document.querySelectorAll('input, select, textarea, button').forEach(el => {
    const fs = parseFloat(getComputedStyle(el).fontSize);
    if (fs < 16 && el.offsetParent !== null &&
        ['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName)) {
      out.smallControls.push(el.id || el.name || el.tagName);
    }
  });
  return out;
}
"""

HERO_VARIANCE = r"""
async () => {
  const img = document.querySelector('.cinematic-media');
  if (!img) return null;
  if (!img.complete || !img.naturalWidth) {
    await new Promise(r => { img.onload = r; img.onerror = r; setTimeout(r, 2500); });
  }
  if (!img.naturalWidth) return {loaded: false};
  const c = document.createElement('canvas');
  c.width = 64; c.height = 40;
  const ctx = c.getContext('2d');
  ctx.drawImage(img, 0, 0, 64, 40);
  const d = ctx.getImageData(0, 0, 64, 40).data;
  let n = 0, s = 0, s2 = 0;
  for (let i = 0; i < d.length; i += 4) {
    const l = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
    n++; s += l; s2 += l * l;
  }
  const mean = s / n;
  return {loaded: true, variance: Math.round(s2 / n - mean * mean), mean: Math.round(mean),
          natural: img.naturalWidth + 'x' + img.naturalHeight};
}
"""


def free_port():
    """Never reuse a fixed port. A preview server left running by an earlier
    session answered on 4173 and served a stale checkout, so a whole sweep
    measured code that was not on disk."""
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
            local = (ROOT / "index.html").read_bytes()
            if body.strip() != local.strip():
                proc.terminate()
                raise SystemExit("the server on this port is not this checkout")
            return proc
        except (URLError, OSError):
            time.sleep(0.15)
    proc.terminate()
    raise SystemExit("preview server did not start")


def sweep(base, pages=PAGES):
    exe = os.environ.get("PLAYWRIGHT_EXECUTABLE_PATH")
    results = {}
    with sync_playwright() as p:
        browser = p.chromium.launch(
            executable_path=exe, channel=None if exe else "chrome"
        )
        for page_path in pages:
            page = browser.new_page(viewport={"width": 1440, "height": 900})
            rows = []
            for w in WIDTHS:
                page.set_viewport_size({"width": w, "height": 900})
                page.goto(base.rstrip("/") + page_path, wait_until="load")
                page.wait_for_timeout(500)
                m = page.evaluate(MEASURE)
                m["hero"] = page.evaluate(HERO_VARIANCE)
                if m.get("tailRect") and m["tailRect"]["w"] > 6:
                    m["tailPixels"] = sample_tail(page)
                rows.append(m)
            results[page_path] = rows
            page.close()
        browser.close()
    return results


def sample_tail(page):
    """Photograph the empty tail cell of the step strip and average its pixels.

    A property check cannot prove what the eye sees, so the tail is scrolled
    into view and read off the rendered page instead.
    """
    rect = page.evaluate(r"""
    () => {
      const strip = document.querySelector('.workflow, .journey-strip');
      if (!strip) return null;
      strip.scrollIntoView({block: 'center', behavior: 'instant'});
      const sb = strip.getBoundingClientRect();
      const kids = [...strip.children];
      const byRow = new Map();
      kids.forEach(k => { const b = k.getBoundingClientRect();
        const key = Math.round(b.top);
        if (!byRow.has(key)) byRow.set(key, []); byRow.get(key).push(b); });
      const rows = [...byRow.entries()].sort((a,b) => a[0]-b[0]).map(e => e[1]);
      const lastRow = rows[rows.length - 1];
      const lastRight = Math.max(...lastRow.map(b => b.right));
      const w = sb.right - lastRight;
      if (w < 8) return null;
      return {x: Math.round(lastRight + 3), y: Math.round(lastRow[0].top + lastRow[0].height / 2),
              w: Math.round(w - 6)};
    }
    """)
    if not rect:
        return None
    page.wait_for_timeout(200)
    shot = page.screenshot(clip={"x": rect["x"], "y": rect["y"],
                                 "width": max(4, rect["w"]), "height": 6})
    return decode_average(page, shot)


def decode_average(page, png_bytes):
    """Average RGB of a screenshot, decoded by the browser that took it."""
    b64 = base64.b64encode(png_bytes).decode("ascii")
    return page.evaluate(r"""
    async (b64) => {
      const img = new Image();
      img.src = 'data:image/png;base64,' + b64;
      await img.decode();
      const c = document.createElement('canvas');
      c.width = img.width; c.height = img.height;
      const ctx = c.getContext('2d');
      ctx.drawImage(img, 0, 0);
      const d = ctx.getImageData(0, 0, c.width, c.height).data;
      let r = 0, g = 0, b = 0, n = 0;
      for (let i = 0; i < d.length; i += 4) { r += d[i]; g += d[i+1]; b += d[i+2]; n++; }
      return [Math.round(r/n), Math.round(g/n), Math.round(b/n)];
    }
    """, b64)


def report(results):
    for page_path, rows in results.items():
        print(f"\n=== {page_path} ===")
        print(f"{'width':>6} {'navH':>5} {'rows':>5} {'gap':>6} {'ovf':>5} "
              f"{'cols':>5} {'steps':>6} {'tail':>5} {'tailRGB':>14} "
              f"{'heroVar':>8} {'ctrl<16':>8}")
        for m in rows:
            hero = m.get("hero") or {}
            hv = hero.get("variance", "-") if hero.get("loaded") else "NOIMG"
            tail_rgb = m.get("tailPixels")
            print(f"{m['w']:>6} {str(m['navH']):>5} {str(m['rows']):>5} "
                  f"{str(m['gap']):>6} {str(m['overflow']):>5} "
                  f"{str(m['cols']):>5} {str(m['steps']):>6} {str(m['tail']):>5} "
                  f"{str(tail_rgb):>14} {str(hv):>8} "
                  f"{len(m['smallControls']):>8}")


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    if args:
        base = args[0]
        results = sweep(base)
    else:
        port = free_port()
        proc = start_server(port)
        try:
            results = sweep(f"http://127.0.0.1:{port}")
        finally:
            proc.terminate()
    report(results)
    out = ROOT / "tests" / "width_sweep_last.json"
    out.write_text(json.dumps(results, indent=1))
    print(f"\nwrote {out}")


if __name__ == "__main__":
    main()
