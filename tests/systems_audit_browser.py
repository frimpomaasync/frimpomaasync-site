"""Drives both assessments in a real Chrome, at phone width and desktop width.

Run it in two terminals from the repo root:

    python3 tests/systems_audit_server.py 8899
    python3 tests/systems_audit_browser.py

The server stands in for the two PHP endpoints, which this Mac has no runtime
for, so the whole client-side flow can be driven without deploying anything.

Checks the things a visitor would notice: that a question fits on the screen,
that the progress moves, that the result renders every block, that the lead
form sends, that print and download exist, and that nothing overflows the
viewport sideways on a phone.
"""
import os
import sys
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8899"
SHOTS = os.path.join(os.path.dirname(os.path.abspath(__file__)), "shots")
fails = []


def check(ok, label):
    print(("  PASS  " if ok else "  FAIL  ") + label)
    if not ok:
        fails.append(label)


def no_sideways_scroll(page, label):
    over = page.evaluate(
        "() => document.documentElement.scrollWidth - document.documentElement.clientWidth"
    )
    check(over <= 1, f"{label}: no sideways scroll (overflow {over}px)")


def run_siesie(page, width, height, tag):
    page.set_viewport_size({"width": width, "height": height})
    page.goto(f"{BASE}/siesie-systems-audit", wait_until="networkidle")
    page.evaluate("() => localStorage.clear()")
    page.reload(wait_until="networkidle")

    title = (page.locator(".sya-q").first.inner_text()).lower()
    check("runs through you" in title, f"{tag}: intro headline renders")
    no_sideways_scroll(page, tag + " intro")
    page.screenshot(path=f"{SHOTS}/siesie-{tag}-1-intro.png", full_page=False)

    page.click("[data-begin]")
    page.wait_for_selector(".sya-choice")

    step = (page.locator("#sya-step").inner_text()).lower()
    check(step == "step 1 of 12", f"{tag}: step counter reads '{step}'")
    no_sideways_scroll(page, tag + " question 1")
    page.screenshot(path=f"{SHOTS}/siesie-{tag}-2-question.png", full_page=False)

    # Answer 1 to 11. Choosing moves on by itself.
    for i in range(11):
        page.locator(".sya-choice").nth((i % 5)).click()
        page.wait_for_function(
            "n => document.getElementById('sya-step').textContent.trim() === n",
            arg=f"Step {i + 2} of 12",
        )
    width_now = page.evaluate("() => document.getElementById('sya-prog').style.width")
    check(width_now.startswith("91"), f"{tag}: progress at question 12 is {width_now}")

    # The last question waits. Nobody lands on a result by accident.
    page.locator(".sya-choice").nth(0).click()
    page.wait_for_timeout(500)
    still = (page.locator("#sya-step").inner_text()).lower()
    check(still == "step 12 of 12", f"{tag}: the last question does not auto-advance")

    page.click("[data-next]")
    page.wait_for_selector(".sya-scoreblock")

    score = page.locator(".sya-dial-v b").inner_text()
    check(score.isdigit() and 0 <= int(score) <= 100, f"{tag}: score renders as {score}")
    band = (page.locator(".sya-band").inner_text()).lower()
    check(len(band) > 5, f"{tag}: band renders as '{band}'")

    for sel, label in [
        (".sya-card.is-strength", "strongest card"),
        (".sya-card.is-gap", "weakest card"),
        (".sya-card.is-priority", "where to start"),
        (".sya-plan .sya-step", "seven-day plan"),
        (".sya-rows .sya-row", "every area scored"),
        (".sya-guard", "what this is not"),
        (".sya-lead", "lead form"),
        (".sya-cta", "call to action"),
        ("[data-print]", "print button"),
        ("[data-download]", "download button"),
    ]:
        check(page.locator(sel).count() > 0, f"{tag}: result has the {label}")

    rows = page.locator(".sya-rows .sya-row").count()
    check(rows == 12, f"{tag}: all 12 areas scored, found {rows}")
    plan = page.locator(".sya-plan .sya-step").count()
    check(plan == 3, f"{tag}: three plan steps, found {plan}")

    no_sideways_scroll(page, tag + " result")
    page.screenshot(path=f"{SHOTS}/siesie-{tag}-3-result.png", full_page=True)

    # The lead form sends, and only after it does the message change.
    page.fill('.sya-lead input[name="name"]', "Test Reader")
    page.fill('.sya-lead input[name="email"]', "reader@example.com")
    page.fill('.sya-lead input[name="business"]', "Example Services")
    page.click('.sya-lead button[type="submit"]')
    page.wait_for_selector(".sya-lead-status.is-done", timeout=8000)
    msg = (page.locator(".sya-lead-status").inner_text()).lower()
    check("inbox" in msg, f"{tag}: lead form confirms with '{msg[:40]}'")
    check(page.locator(".sya-fields").is_hidden(), f"{tag}: the fields close after sending")
    page.screenshot(path=f"{SHOTS}/siesie-{tag}-4-sent.png", full_page=False)

    # Coming back after finishing offers the result, not question twelve.
    page.reload(wait_until="networkidle")
    check(page.locator("[data-again]").count() == 1, f"{tag}: a finished run offers the result back")
    page.click("[data-again]")
    page.wait_for_selector(".sya-scoreblock")
    check(page.locator(".sya-dial-v b").inner_text() == score,
          f"{tag}: and it is the same score ({score})")
    page.evaluate("() => localStorage.clear()")


def run_validation(page):
    page.set_viewport_size({"width": 390, "height": 844})
    page.goto(f"{BASE}/siesie-systems-audit", wait_until="networkidle")
    page.click("[data-begin]")

    # Back on question one returns to the intro rather than dead-ending.
    page.click("[data-back]")
    page.wait_for_selector("[data-begin]")
    check(page.locator("[data-begin]").count() == 1, "back from question 1 returns to the intro")

    # A number key answers, which is how a laptop gets through this quickly.
    page.click("[data-begin]")
    page.wait_for_selector(".sya-choice")
    page.keyboard.press("3")
    page.wait_for_function(
        "() => document.getElementById('sya-step').textContent.trim() === 'Step 2 of 12'"
    )
    check(True, "a number key answers and moves on")

    # Reload mid-run and the answers are still there.
    page.reload(wait_until="networkidle")
    check(page.locator("[data-resume]").count() == 1, "a reload offers to pick up where it left off")
    page.click("[data-resume]")
    page.wait_for_selector(".sya-choice")
    resumed = page.locator("#sya-step").inner_text().lower()
    check(resumed == "step 2 of 12", f"resume lands back on the same question ({resumed})")

    # Start over clears it.
    page.reload(wait_until="networkidle")
    page.click("[data-fresh]")
    page.wait_for_selector(".sya-choice")
    check(page.locator("#sya-step").inner_text().lower() == "step 1 of 12", "start over clears the saved run")

    # A bad email is refused before anything is sent.
    page.goto(f"{BASE}/siesie-systems-audit", wait_until="networkidle")
    page.evaluate("() => localStorage.clear()")


def run_lead_validation(page):
    page.set_viewport_size({"width": 1440, "height": 900})
    page.goto(f"{BASE}/siesie-systems-audit", wait_until="networkidle")
    page.evaluate("() => localStorage.clear()")
    page.reload(wait_until="networkidle")
    page.click("[data-begin]")
    for i in range(11):
        page.locator(".sya-choice").nth(4).click()
        page.wait_for_function(
            "n => document.getElementById('sya-step').textContent.trim() === n",
            arg=f"Step {i + 2} of 12",
        )
    page.locator(".sya-choice").nth(4).click()
    page.click("[data-next]")
    page.wait_for_selector(".sya-scoreblock")

    check(page.locator(".sya-dial-v b").inner_text() == "100", "all best answers scores 100")
    check(page.locator(".sya-plan .sya-step").count() == 3, "a perfect run still gets three steps")

    page.fill('.sya-lead input[name="name"]', "Test Reader")
    page.fill('.sya-lead input[name="email"]', "not-an-email")
    page.click('.sya-lead button[type="submit"]')
    page.wait_for_selector(".sya-lead-status.is-warn")
    check(page.locator(".sya-fields").is_visible(), "a bad email keeps the form open")

    # No password field anywhere on the page.
    check(page.locator('input[type="password"]').count() == 0, "no password field on the page")


def run_dhs(page):
    page.set_viewport_size({"width": 390, "height": 844})
    page.goto(f"{BASE}/soft-appeals-denial-health-score", wait_until="networkidle")
    page.click("[data-begin]")
    page.wait_for_selector(".sa-choice")
    for i in range(12):
        page.locator(".sa-choice").nth(i % 5).click()
        page.click("[data-next]")
        page.wait_for_timeout(120)
    page.wait_for_selector(".sa-result")

    check(page.locator(".sa-dial-v b").count() > 0, "denial health score still renders its result")
    check(page.locator(".sya-lead").count() == 1, "the denial health score gained the lead form")
    check(page.locator("[data-download]").count() == 1, "and a download button")
    order = page.evaluate(
        "() => { const r = document.querySelector('.sa-score'), l = document.querySelector('.sya-lead');"
        "return r.compareDocumentPosition(l) & Node.DOCUMENT_POSITION_FOLLOWING ? 'after' : 'before'; }"
    )
    check(order == "after", f"the form sits {order} the result, never in front of it")
    no_sideways_scroll(page, "denial health score result")

    bg = page.evaluate(
        "() => getComputedStyle(document.querySelector('.sya-lead')).backgroundColor"
    )
    check(bg == "rgb(16, 20, 38)", f"the form picks up its palette outside the shell ({bg})")
    page.screenshot(path=f"{SHOTS}/dhs-390-result.png", full_page=True)

    page.fill('.sya-lead input[name="name"]', "Test Reader")
    page.fill('.sya-lead input[name="email"]', "reader@example.com")
    page.click('.sya-lead button[type="submit"]')
    page.wait_for_selector(".sya-lead-status.is-done", timeout=8000)
    check(True, "the denial health score lead form sends")


os.makedirs(SHOTS, exist_ok=True)

with sync_playwright() as p:
    browser = p.chromium.launch(channel="chrome")
    ctx = browser.new_context(device_scale_factor=2)
    page = ctx.new_page()
    errors = []
    page.on("pageerror", lambda e: errors.append(str(e)))
    page.on("console", lambda m: errors.append("console " + m.type + ": " + m.text)
            if m.type == "error" else None)

    print("\nPHONE 390x844")
    run_siesie(page, 390, 844, "390")
    print("\nDESKTOP 1440x900")
    run_siesie(page, 1440, 900, "1440")
    print("\nBEHAVIOUR")
    run_validation(page)
    print("\nEDGE CASES")
    run_lead_validation(page)
    print("\nDENIAL HEALTH SCORE")
    run_dhs(page)

    print("\nCONSOLE")
    real = [e for e in errors if "favicon" not in e.lower()]
    check(not real, "no page errors: " + ("; ".join(real[:4]) if real else "clean"))

    browser.close()

print("\n" + ("ALL PASS" if not fails else f"{len(fails)} FAILED:\n  " + "\n  ".join(fails)))
sys.exit(1 if fails else 0)
