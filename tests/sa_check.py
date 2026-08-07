"""Post-audit check for the Soft Appeals pages: chrome fit, the More menu,
the process phase rail, and the rebuilt code reference."""
import json
import re
import sys
from playwright.sync_api import sync_playwright

BASE = "http://localhost:8899"
PAGES = [
    "/soft-appeals.html",
    "/soft-appeals-process.html",
    "/soft-appeals-sample-assessment.html",
    "/soft-appeals-pricing.html",
    "/soft-appeals-data-security.html",
    "/soft-appeals-faq.html",
    "/soft-appeals-about.html",
    "/soft-appeals-contact.html",
    "/soft-appeals-start.html",
    "/soft-appeals-decoder.html",
    "/soft-appeals-recovery-lab.html",
]
WIDTHS = [320, 360, 390, 430, 768, 1024, 1440]

MEASURE = r"""
() => {
  const nav = document.getElementById('fs-nav');
  const cta = nav && nav.querySelector('[data-navcta]');
  const links = nav ? [...nav.querySelectorAll('.fs-grid nav > a, .fs-grid nav > span')] : [];
  const tops = new Set(links.map(a => Math.round(a.getBoundingClientRect().top)));
  const mark = nav && nav.querySelector('[data-navmark]');
  let ctaClipped = false;
  if (cta) {
    const r = cta.getBoundingClientRect();
    ctaClipped = r.right > window.innerWidth + 0.5 || r.left < -0.5;
  }
  let markOverlapsCta = false;
  if (cta && mark) {
    const a = mark.getBoundingClientRect(), b = cta.getBoundingClientRect();
    markOverlapsCta = !(a.right <= b.left + 0.5 || b.right <= a.left + 0.5 ||
                        a.bottom <= b.top + 0.5 || b.bottom <= a.top + 0.5);
  }
  return {
    overflow: document.documentElement.scrollWidth - window.innerWidth,
    navRows: tops.size,
    ctaText: cta ? cta.textContent.trim() : null,
    ctaClipped, markOverlapsCta,
    navH: nav ? Math.round(nav.offsetHeight) : null,
  };
}
"""

fails = []


def check(cond, msg):
    if not cond:
        fails.append(msg)


with sync_playwright() as p:
    browser = p.chromium.launch(channel="chrome")
    page = browser.new_page()

    for path in PAGES:
        for w in WIDTHS:
            page.set_viewport_size({"width": w, "height": 780})
            page.goto(BASE + path, wait_until="networkidle")
            m = page.evaluate(MEASURE)
            tag = f"{path} @{w}"
            check(m["overflow"] <= 1, f"{tag}: horizontal overflow {m['overflow']}px")
            check(not m["ctaClipped"], f"{tag}: nav CTA runs off the edge")
            check(not m["markOverlapsCta"], f"{tag}: wordmark overlaps the CTA")
            check(m["navRows"] <= 2, f"{tag}: nav links wrap to {m['navRows']} rows")
            if w == 390 and path == "/soft-appeals.html":
                print("  nav CTA reads:", repr(m["ctaText"]), "navH", m["navH"])

    # --- the More menu ---
    page.set_viewport_size({"width": 390, "height": 780})
    page.goto(BASE + "/soft-appeals.html", wait_until="networkidle")
    check(page.locator("[data-fs-more]").count() == 1, "More button missing")
    panel = page.locator("[data-fs-more-panel]")
    check(not panel.is_visible(), "More panel is visible before opening")
    page.locator("[data-fs-more]").click()
    check(panel.is_visible(), "More panel did not open on click")
    box = panel.bounding_box()
    check(box and box["x"] >= 0 and box["x"] + box["width"] <= 390 + 1,
          f"More panel off-screen at 390px: {box}")
    check(page.locator("[data-fs-more]").get_attribute("aria-expanded") == "true",
          "aria-expanded not set to true")
    labels = page.locator("[data-fs-more-panel] a").all_text_contents()
    check([s.strip() for s in labels] == ["Recovery Lab", "FAQ", "About", "Contact"],
          f"More panel items wrong: {labels}")
    page.keyboard.press("Escape")
    check(not panel.is_visible(), "Escape did not close the More panel")
    page.locator("[data-fs-more]").click()
    page.locator("[data-fs-more-panel] a", has_text="Contact").click()
    page.wait_for_load_state("networkidle")
    check("soft-appeals-contact" in page.url, f"More > Contact went to {page.url}")

    # nav order
    page.goto(BASE + "/soft-appeals.html", wait_until="networkidle")
    order = page.locator("#fs-nav .fs-grid nav > a").all_text_contents()
    check([s.strip() for s in order] ==
          ["Overview", "How it works", "Sample", "Pricing", "Security"],
          f"nav order wrong: {order}")

    # --- the phase rail ---
    page.goto(BASE + "/soft-appeals-process.html", wait_until="networkidle")
    rail = page.locator(".prail")
    check(rail.count() == 1, "phase rail missing")
    check([s.strip() for s in page.locator(".prail a").all_text_contents()] ==
          ["01 Decide", "02 Recover", "03 Resolve"], "phase rail labels wrong")
    navh = page.evaluate("getComputedStyle(document.documentElement).getPropertyValue('--sa-nav-h')")
    check(navh.strip().endswith("px"), f"--sa-nav-h not set: {navh!r}")
    page.locator(".prail a", has_text="Recover").click()
    page.wait_for_timeout(700)
    hidden = page.evaluate("""() => {
      const r = document.getElementById('recover').getBoundingClientRect();
      const rail = document.querySelector('.prail').getBoundingClientRect();
      return r.top < rail.bottom - 2;
    }""")
    check(not hidden, "anchor jump lands under the phase rail")
    lit = page.locator(".prail a.on").all_text_contents()
    check([s.strip() for s in lit] == ["02 Recover"], f"scroll-spy lit {lit} after jumping to Recover")
    page.evaluate("window.scrollTo(0, 0)")
    page.wait_for_timeout(400)
    check(page.locator(".prail a.on").count() == 0, "rail lit above the first phase")

    # --- the code reference ---
    page.goto(BASE + "/soft-appeals-decoder.html", wait_until="networkidle")
    page.fill("#dec-q", "CO-29")
    page.wait_for_timeout(500)
    card = page.locator(".dec-card").inner_text()
    check("CO-29" in card, "lookup did not render CO-29")
    check("what determines the outcome" in card.lower(), "missing the determines row")
    check("what to check next" in card.lower(), "missing the check-next row")
    for banned in [r"\bdead\b", r"quick fix", r"\bthe odds\b", r"level three", r"\bthe audit\b"]:
        check(not re.search(banned, card, re.I), f"banned phrase {banned!r} still in the CO-29 card")
    page.fill("#dec-q", "")
    page.fill("#dec-q", "ZZ-999")
    page.wait_for_timeout(500)
    check(page.locator(".dec-miss").count() == 1, "unknown code did not render the miss state")
    page.fill("#dec-q", "")
    page.locator(".dec-chips .dec-chip", has_text="PR-204").first.click()
    page.wait_for_timeout(400)
    check("PR-204" in page.locator(".dec-card").inner_text(), "chip click did not render PR-204")

    # no network calls leave the page while typing
    reqs = []
    page.on("request", lambda r: reqs.append((r.method, r.url, r.post_data)))
    page.fill("#dec-q", "CO-50 shoulder pain")
    page.wait_for_timeout(900)
    # A static same-origin asset the browser happens to fetch is not a leak.
    STATIC = (".svg", ".css", ".js", ".png", ".woff2", ".ico")
    leaks = [r for r in reqs
             if not (r[0] == "GET" and r[1].startswith(BASE) and r[1].split("?")[0].endswith(STATIC))]
    check(not leaks, f"typing triggered a non-static request: {leaks}")
    carries = [r for r in reqs if "CO-50" in (r[1] + (r[2] or "")) or "shoulder" in (r[1] + (r[2] or ""))]
    check(not carries, f"a request carried the typed text: {carries}")

    browser.close()

print()
if fails:
    print(f"FAIL ({len(fails)})")
    for f in fails:
        print("  -", f)
    sys.exit(1)
print("ALL CHECKS PASSED")
