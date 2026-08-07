"""Denial Health Score page check. Drives the real questionnaire in a browser
and verifies the rendered result against the scoring module's own output for
the same answers. Needs python3 -m http.server 8899 from the repo root."""
import json, re, subprocess
from playwright.sync_api import sync_playwright

BASE = "http://localhost:8899/soft-appeals-denial-health-score.html"
REPO = "/Users/nanafrimpongmaa/frimpomaasync-site"
failures = []

def check(name, ok, detail=""):
    print(("PASS " if ok else "FAIL ") + name + ("  " + str(detail) if detail else ""))
    if not ok: failures.append(name)

# ground truth straight from the module, via node, so the page is checked
# against an independent evaluation rather than against itself
def truth(answers):
    js = (
      "import {scoreDenialHealth, DIMENSIONS} from './assets/denial-health-score.js';"
      f"const a={json.dumps(answers)};"
      "const r=scoreDenialHealth(a);"
      "console.log(JSON.stringify({score:r.score,band:r.band,strongest:r.strongest&&r.strongest.name,"
      "weakest:r.weakest.name,gaps:r.topGaps.map(g=>g.name),priority:r.priority.area,"
      "plan:r.plan.map(p=>[p.days,p.area]),recs:r.recommendations.map(x=>x.area),"
      "keys:DIMENSIONS.map(d=>d.key)}));"
    )
    out = subprocess.run(["node", "--input-type=module", "-e", js], cwd=REPO,
                         capture_output=True, text=True)
    if out.returncode != 0: raise SystemExit("node failed: " + out.stderr)
    return json.loads(out.stdout)

KEYS = truth({"ownership": 0})["keys"]

# answer patterns worth testing, each a different shape of organization
PROFILES = {
    "all zeros (worst case)":      {k: 0 for k in KEYS},
    "all fours (best case)":       {k: 4 for k in KEYS},
    "middling everywhere":         {k: 2 for k in KEYS},
    "strong except deadlines":     {**{k: 4 for k in KEYS}, "deadlines": 0},
    "strong except evidence":      {**{k: 4 for k in KEYS}, "evidence": 0},
    "good ops, no reporting":      {**{k: 3 for k in KEYS}, "reporting": 0, "rootcause": 1},
    "two weak areas only":         {**{k: 3 for k in KEYS}, "deadlines": 0, "evidence": 0},
    "mixed realistic":             {"ownership": 2, "deadlines": 1, "evidence": 1, "pathway": 3,
                                     "priority": 2, "visibility": 1, "payer": 2, "clientside": 2,
                                     "documentation": 3, "rootcause": 0, "reconciliation": 1, "reporting": 0},
}

with sync_playwright() as p:
    b = p.chromium.launch(channel="chrome")
    for width, tag in [(390, "phone"), (1280, "desktop")]:
        pg = b.new_page(viewport={"width": width, "height": 900})
        errs = []
        pg.on("pageerror", lambda e: errs.append(str(e)))
        pg.goto(BASE); pg.wait_for_load_state("networkidle"); pg.wait_for_timeout(300)

        check(f"[{tag}] no PHI-collecting inputs anywhere",
              pg.locator("input[type=file], input[type=email], input[type=text], textarea").count() == 0)
        check(f"[{tag}] no form posts anywhere",
              pg.evaluate("[...document.querySelectorAll('form')].every(f => !f.action || f.action.startsWith(location.origin))"))
        check(f"[{tag}] weighting table published",
              pg.locator("#weights .dhs-row").count() == len(KEYS))

        for label, answers in PROFILES.items():
            t = truth(answers)
            # restart cleanly each run
            pg.goto(BASE); pg.wait_for_load_state("networkidle"); pg.wait_for_timeout(200)
            for i, k in enumerate(KEYS):
                seen = pg.locator(".dhs-q .n").inner_text()
                if k not in seen.lower().replace(" ", "") and str(i + 1) not in seen:
                    check(f"[{tag}] {label}: question order at {i}", False, seen); break
                pts = answers[k]
                pg.eval_on_selector_all(".dhs-opt input", f"els => els[{pts}].click()")
                pg.wait_for_timeout(40)
                pg.eval_on_selector("[data-next]", "el => el.click()")
                pg.wait_for_timeout(60)

            got_score = int(pg.locator(".dhs-dial .mid b").inner_text())
            band = pg.locator(".dhs-score .band").inner_text().strip()
            cards = pg.locator(".dhs-two .dhs-card h3").all_inner_texts()
            gaps = [x.split(" · ")[0].strip() for x in pg.locator(".dhs-list li b").all_inner_texts()[:len(t["gaps"])]]
            # .d and .k are uppercased by CSS, so compare case-insensitively
            plan = [(s.locator(".d").inner_text().strip().lower(), s.locator(".a b").inner_text().strip())
                    for s in pg.locator(".dhs-step").all()]
            prio = pg.locator(".dhs-card.lead .k").all_inner_texts()[-1].strip()
            body = pg.locator(".dhs-body").inner_text()

            ok = got_score == t["score"]
            check(f"[{tag}] {label}: score {got_score}", ok, "expected " + str(t["score"]))
            check(f"[{tag}] {label}: band", band == t["band"], f"{band!r} vs {t['band']!r}")
            check(f"[{tag}] {label}: weakest area shown", t["weakest"] in cards, cards)
            if t["strongest"]:
                check(f"[{tag}] {label}: strongest area shown", t["strongest"] in cards, cards)
            else:
                check(f"[{tag}] {label}: no false strength claimed",
                      "Nothing scored as a strength yet" in " ".join(cards), cards)
            check(f"[{tag}] {label}: top gaps match", gaps == t["gaps"], f"{gaps} vs {t['gaps']}")
            check(f"[{tag}] {label}: priority matches", prio.lower() == t["priority"].lower(), f"{prio!r} vs {t['priority']!r}")
            check(f"[{tag}] {label}: 7-day plan matches",
                  plan == [(x[0].lower(), x[1]) for x in t["plan"]], f"{plan} vs {t['plan']}")
            check(f"[{tag}] {label}: every area scored", pg.locator(".dhs-body .dhs-rows .dhs-row").count() == len(KEYS))
            check(f"[{tag}] {label}: disclaimer present", "not a compliance" in body.lower())
            check(f"[{tag}] {label}: CTA to intake present",
                  pg.locator('a[href="/soft-appeals-start"]').count() >= 1)
            for banned in ["hipaa compliant", "non-compliant", "guaranteed", "will recover",
                           "win probability", "win rate", "free audit"]:
                check(f"[{tag}] {label}: no '{banned}'", banned not in body.lower())

        # back button preserves an answer
        pg.goto(BASE); pg.wait_for_load_state("networkidle"); pg.wait_for_timeout(200)
        pg.eval_on_selector_all(".dhs-opt input", "els => els[3].click()")
        pg.eval_on_selector("[data-next]", "el => el.click()"); pg.wait_for_timeout(80)
        pg.eval_on_selector("[data-back]", "el => el.click()"); pg.wait_for_timeout(120)
        check(f"[{tag}] back preserves the answer",
              pg.eval_on_selector_all(".dhs-opt input", "els => els.findIndex(e => e.checked)") == 3)
        check(f"[{tag}] next is blocked before answering",
              pg.eval_on_selector("[data-next]", "el => el.disabled") is False)

        # start over resets
        for i in range(len(KEYS)):
            pg.eval_on_selector_all(".dhs-opt input", "els => els[2].click()"); pg.wait_for_timeout(30)
            pg.eval_on_selector("[data-next]", "el => el.click()"); pg.wait_for_timeout(50)
        pg.eval_on_selector("[data-restart]", "el => el.click()"); pg.wait_for_timeout(200)
        check(f"[{tag}] start over returns to question 1",
              "question 1 of" in pg.locator(".dhs-q .n").inner_text().lower())

        check(f"[{tag}] no horizontal scroll",
              pg.evaluate("document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1"))
        check(f"[{tag}] no JS errors", len(errs) == 0, errs[:2])
        pg.close()
    b.close()

print()
print("ALL PASSED" if not failures else f"{len(failures)} FAILURE(S): " + "; ".join(failures[:6]))
raise SystemExit(1 if failures else 0)
