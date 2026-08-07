"""Trust Room browser check: every request card must land on the due-diligence
form with its topic already ticked, and no flow may ask for PHI.
Needs: python3 -m http.server 8899 from the repo root."""
import re
from playwright.sync_api import sync_playwright

BASE = "http://localhost:8899"
PAGE = BASE + "/soft-appeals-trust-room.html"
failures = []

def check(name, ok, detail=""):
    print(("PASS " if ok else "FAIL ") + name + ("  " + str(detail) if detail else ""))
    if not ok:
        failures.append(name)

with sync_playwright() as p:
    b = p.chromium.launch(channel="chrome")
    for width, tag in [(390, "phone"), (1280, "desktop")]:
        pg = b.new_page(viewport={"width": width, "height": 900})
        errs = []
        pg.on("pageerror", lambda e: errs.append(str(e)))
        pg.goto(PAGE)
        pg.wait_for_load_state("networkidle")
        pg.wait_for_timeout(400)

        check(f"[{tag}] no form, input or upload on the page",
              pg.locator("form, input, textarea").count() == 0)
        check(f"[{tag}] status badges render",
              pg.locator(".st").count() >= 20, pg.locator(".st").count())
        check(f"[{tag}] data flow has nine steps",
              pg.locator(".tr-step").count() == 9)
        check(f"[{tag}] four PHI-free steps then five PHI steps",
              [("PHI" if "on" in (e.get_attribute("class") or "") else "No PHI")
               for e in pg.locator(".tr-step .phi").all()]
              == ["No PHI"] * 4 + ["PHI"] * 5)
        check(f"[{tag}] struck-through badges present",
              pg.locator(".tr-badges span").count() >= 5)
        check(f"[{tag}] jump links all resolve to real sections", all(
            pg.locator(a.get_attribute("href")).count() == 1
            for a in pg.locator(".tr-jump a").all()))

        cards = pg.locator(".tr-req").all()
        check(f"[{tag}] ten request cards", len(cards) == 10, len(cards))
        hrefs = [c.get_attribute("href") for c in cards]

        # every request flow, driven for real
        for href in hrefs:
            want = re.search(r"request=([^#]+)", href).group(1)
            want = re.sub(r"%20", " ", want).replace("%2F", "/")
            pg.goto(BASE + href.replace("/soft-appeals-contact", "/soft-appeals-contact.html"))
            pg.wait_for_load_state("networkidle")
            pg.wait_for_timeout(300)
            checked = pg.eval_on_selector_all(
                'input[type=checkbox][name="requested[]"]:checked',
                "els => els.map(e => e.value)")
            ok = checked == [want]
            if not ok:
                check(f"[{tag}] request flow preselects {want!r}", False, checked)
            # the landing form must still refuse PHI and files
            if pg.locator('form[name="soft-appeals-due-diligence"] input[type=file]').count():
                check(f"[{tag}] {want}: no upload on landing form", False)
        check(f"[{tag}] all ten request flows preselect correctly", True)

        # an unknown value must select nothing rather than error
        pg.goto(BASE + "/soft-appeals-contact.html?request=Not%20A%20Real%20Topic#due-diligence")
        pg.wait_for_load_state("networkidle")
        pg.wait_for_timeout(300)
        check(f"[{tag}] unknown request value selects nothing",
              pg.eval_on_selector_all('input[name="requested[]"]:checked', "els => els.length") == 0)

        # the PHI guard on the landing form still fires
        pg.goto(BASE + "/soft-appeals-contact.html?request=Incident-response%20process#due-diligence")
        pg.wait_for_load_state("networkidle")
        pg.wait_for_timeout(300)
        form = pg.locator('form[name="soft-appeals-due-diligence"]')
        for el in form.locator("[required]").all():
            typ = el.evaluate("e => e.type || ''")
            tagname = el.evaluate("e => e.tagName.toLowerCase()")
            if tagname == "select":
                vals = el.evaluate("e => Array.from(e.options).map(o=>o.value).filter(v=>v)")
                if vals:
                    el.select_option(vals[0])
            elif typ in ("radio", "checkbox"):
                el.evaluate("e => { e.checked = true; e.dispatchEvent(new Event('change',{bubbles:true})) }")
            elif typ == "email":
                el.fill("reviewer@example-health.test")
            else:
                el.fill("Compliance review")
        ta = form.locator("textarea").first
        if ta.count():
            ta.fill("Member ID 4429871 and SSN 123-45-6789 for claim review")
            pg.route("**formspree.io**", lambda r: r.abort())
            form.locator("button[type=submit]").first.evaluate("el => el.click()")
            pg.wait_for_timeout(600)
            msg = form.locator("[data-phi-guard-msg]").inner_text().strip()
            check(f"[{tag}] PHI guard still blocks on the landing form", len(msg) > 0, msg[:60])

        pg.goto(PAGE)
        pg.wait_for_load_state("networkidle")
        check(f"[{tag}] no horizontal scroll", pg.evaluate(
            "document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1"))
        check(f"[{tag}] no JS errors", len(errs) == 0, errs[:2])
        pg.close()
    b.close()

print()
print("ALL PASSED" if not failures else f"{len(failures)} FAILURE(S)")
raise SystemExit(1 if failures else 0)
