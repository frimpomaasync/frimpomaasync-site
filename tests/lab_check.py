"""Recovery Lab interaction check. Needs python3 -m http.server 8899 from repo root."""
import json, re
from playwright.sync_api import sync_playwright

BASE = "http://localhost:8899/soft-appeals-recovery-lab.html"
failures = []
def check(name, ok, detail=""):
    print(("PASS " if ok else "FAIL ") + name + ("  " + str(detail) if detail else ""))
    if not ok: failures.append(name)

# independent copy of the dataset, parsed straight from the file
src = open("/Users/nanafrimpongmaa/frimpomaasync-site/soft-appeals-recovery-lab.html").read()
m = src[src.index("var LAB_CLAIMS = ["):]
raw = m[len("var LAB_CLAIMS = "):m.index("];") + 1]

with sync_playwright() as p:
    b = p.chromium.launch(channel="chrome")
    for width, height, tag in [(390, 844, "phone"), (1280, 900, "desktop")]:
        pg = b.new_page(viewport={"width": width, "height": height})
        errors = []
        pg.on("pageerror", lambda e: errors.append(str(e)))
        pg.goto(BASE); pg.wait_for_load_state("networkidle"); pg.wait_for_timeout(400)
        claims = pg.evaluate(raw)
        total = sum(c["amount"] for c in claims)
        check(f"[{tag}] dataset loads, 20 claims ${total}", len(claims) == 20 and total == 14850)

        # exec view tiles computed
        tiles = pg.locator("#exec-tiles .lab-tile .v").all_inner_texts()
        check(f"[{tag}] exec total tile shows $14,850", "$14,850" in tiles[0])
        check(f"[{tag}] priority tile shows $4,150", "$4,150" in tiles[1])

        # action bars sum on screen
        bars = pg.locator("#exec-actions .lab-barrow .t b").all_inner_texts()
        shown = sum(int(re.sub(r"[^0-9]", "", x)) for x in bars)
        check(f"[{tag}] action bars sum to portfolio total", shown == 14850, shown)

        # worklist tab
        pg.click("#tab-work"); pg.wait_for_timeout(200)
        rows = pg.locator("#work-rows tr").count()
        check(f"[{tag}] worklist shows 20 rows", rows == 20, rows)
        count_line = pg.locator("#work-count").inner_text()
        check(f"[{tag}] count line reconciles", "20" in count_line and "$14,850" in count_line, count_line)

        # every filter value reconciles against the dataset
        for sel, key in [("#f-action", "action"), ("#f-status", "status"), ("#f-owner", "owner"), ("#f-payer", "payer"), ("#f-priority", "priority")]:
            options = pg.locator(sel + " option").all_inner_texts()[1:]
            for opt in options:
                pg.select_option(sel, label=opt)
                pg.wait_for_timeout(120)
                expect = [c for c in claims if c[key] == opt]
                got_rows = pg.locator("#work-rows tr").count()
                line = pg.locator("#work-count").inner_text()
                want_val = "${:,}".format(sum(c["amount"] for c in expect))
                ok = got_rows == len(expect) and want_val in line
                if not ok: check(f"[{tag}] filter {key}={opt}", False, f"rows {got_rows} vs {len(expect)}, line {line}")
            pg.select_option(sel, value="")
        check(f"[{tag}] all single filters reconcile", True)

        # a combined filter
        pg.select_option("#f-owner", label="Your team"); pg.select_option("#f-status", label="Client review"); pg.wait_for_timeout(150)
        expect = [c for c in claims if c["owner"] == "Your team" and c["status"] == "Client review"]
        check(f"[{tag}] combined filter reconciles", pg.locator("#work-rows tr").count() == len(expect), len(expect))
        pg.click("#f-reset"); pg.wait_for_timeout(150)
        check(f"[{tag}] clear filters restores 20", pg.locator("#work-rows tr").count() == 20)

        # sort by denied desc: first row should be SA-001 ($1,850) after two clicks? asc first then desc
        pg.eval_on_selector("th[data-sort=amount] button", "el => el.click()"); pg.wait_for_timeout(100)
        first_asc = pg.locator("#work-rows tr").first.get_attribute("data-id")
        pg.eval_on_selector("th[data-sort=amount] button", "el => el.click()"); pg.wait_for_timeout(100)
        first_desc = pg.locator("#work-rows tr").first.get_attribute("data-id")
        lo = min(claims, key=lambda c: c["amount"])["id"]; hi = max(claims, key=lambda c: c["amount"])["id"]
        check(f"[{tag}] amount sort works both ways", first_asc == lo and first_desc == hi, f"{first_asc}/{first_desc}")

        # open EVERY claim detail; verify fields + show-me-why factors render
        all_ok = True
        for c in claims:
            pg.eval_on_selector(f"[data-open={c['id']}]", "el => el.click()")
            pg.wait_for_timeout(80)
            region = pg.locator(".lab-detail")
            if region.count() != 1: all_ok = False; check(f"[{tag}] {c['id']} detail opens", False); break
            text = region.inner_text()
            if c["payer"] not in text or "${:,}".format(c["amount"]) not in text: all_ok = False; check(f"[{tag}] {c['id']} detail content", False, text[:80]); break
            region.locator(".lab-why summary").click()
            pg.wait_for_timeout(60)
            n = region.locator(".lab-why li").count()
            if n != len(c["why"]): all_ok = False; check(f"[{tag}] {c['id']} why factors", False, n); break
            if "No probability score" not in region.locator(".lab-why").inner_text(): all_ok = False; check(f"[{tag}] {c['id']} no-score note", False); break
            hist = region.locator(".lab-hist .h").count()
            if hist != len(c["history"]): all_ok = False; check(f"[{tag}] {c['id']} history", False, hist); break
        check(f"[{tag}] all 20 claim details + show-me-why verified", all_ok)
        pg.eval_on_selector("[data-close]", "el => el.click()")
        check(f"[{tag}] detail closes", pg.locator(".lab-detail").count() == 0)

        # patterns tab
        pg.click("#tab-pat"); pg.wait_for_timeout(200)
        pats = pg.locator("#pat-rows .lab-patrow").count()
        check(f"[{tag}] patterns render", pats == 4, pats)
        pat_text = pg.locator("#pat-rows").inner_text()
        check(f"[{tag}] pattern counts match published", "4 of 20" in pat_text and "5 of 20" in pat_text and "3 of 20" in pat_text)

        # keyboard: arrow between tabs
        pg.focus("#tab-pat"); pg.keyboard.press("ArrowLeft"); pg.wait_for_timeout(100)
        check(f"[{tag}] arrow-key tab switch", pg.evaluate("document.activeElement.id") == "tab-work")

        # CTAs
        for href in ["/soft-appeals-start", "/soft-appeals-sample-assessment", "/soft-appeals-data-security"]:
            check(f"[{tag}] CTA to {href}", pg.locator(f'a[href="{href}"]').count() >= 1)

        # no upload fields, no forms at all on this page
        check(f"[{tag}] no inputs collecting anything", pg.locator("form, input[type=file], textarea").count() == 0)
        check(f"[{tag}] fictional-data label visible", "fictional data" in pg.locator(".lab-top").inner_text().lower())
        check(f"[{tag}] no horizontal page scroll", pg.evaluate("document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1"))
        check(f"[{tag}] no JS errors", len(errors) == 0, errors[:2])
        pg.close()
    b.close()

print()
print("ALL PASSED" if not failures else f"{len(failures)} FAILURE(S)")
raise SystemExit(1 if failures else 0)
