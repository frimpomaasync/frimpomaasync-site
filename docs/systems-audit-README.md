# The Systems Audit and the Denial Health Score

Two assessments. One engine. Built 2026-08-17.

**If you only read one line:** the words live in `assets/audit-config.js`, and
nothing else needs touching to change what an assessment says.

---

## What went live

| Thing | Where | What it is |
|---|---|---|
| The Systems Audit | `/siesie-systems-audit` | New. Twelve questions on how much of the business runs through her. Feeds the Operations Map. |
| The Denial Health Score | `/soft-appeals-denial-health-score` | Already existed. It was not rebuilt. It gained an emailed copy and a download. |
| The words | `assets/audit-config.js` | The only file to edit for copy, questions, weights or buttons. |
| The maths | `assets/audit-engine.js` | Scoring. No page, no network. Testable on its own. |
| The screen | `assets/audit-ui.js` | One question at a time, the result, the lead form. |
| The look | `assets/systems-audit.css` | Every class prefixed `sya-`. Nothing else on the site can collide with it. |
| The inbox | `audit-lead.php` | Stores the lead, emails her, emails the visitor. |
| The tests | `tests/systems_audit.test.mjs` | 32 checks. Run before every deploy. |

---

## The files, and which one to open

**To change a question, an answer, a weight, a band, or any wording:**
open `assets/audit-config.js`. That is the whole job. The file starts with
instructions written for someone who does not write code.

**To check you did not break it:**

```
cd ~/frimpomaasync-site
node tests/systems_audit.test.mjs
```

If something is wrong it tells you in plain English which question and what.
For example: `Question 4 (Scheduling): needs exactly 5 options, found 4.`

**Never edit** `audit-engine.js` or `audit-ui.js` to change words. They contain
no copy at all. Everything they show comes out of the config.

---

## The scoring model

### The shape

Twelve questions. One per area. Each answer is worth **0 to 4 points**.

Each area carries a **weight of 1 to 4**, because the gaps do not cost the same.

```
earned    = sum of (your points x that area's weight)
available = sum of (4 x that area's weight)
score     = earned / available x 100
```

A question you skip is left out of **both** halves. Answer six perfectly and
stop, and you score 100 out of the six you answered, not 50. The result says
`6 of 12 answered` so nobody is misled.

### The weights, and why

| Area | Weight | Why |
|---|---|---|
| Owner dependence | 4 | Work that stops when she is away caps how much she can sell. |
| Invoicing and collection | 4 | Finished work that never gets paid for is the fastest money on the table. |
| Inquiry handling | 3 | A slow reply loses the job to whoever answered first. |
| Quoting and pricing | 3 | A price in someone's head cannot be delegated or checked. |
| Scheduling | 3 | Every booking conversation costs the attention of a small job. |
| Handover into delivery | 3 | A retold handover drops the details nobody knew to ask for. |
| Written procedure | 3 | An unwritten process cannot be taught and leaves with the person. |
| Visibility of the numbers | 3 | A number you have to reconstruct gets checked too late. |
| Follow-up after the work | 2 | Costs repeat work rather than the job in hand. |
| Where the information lives | 2 | Slows everything down without stopping anything. |
| Re-entered information | 2 | Costs time and accuracy, not the job. |
| Bringing someone in | 2 | A ceiling on growth rather than a leak today. |

The weighting table is **printed on the page**, and it is generated from the same
array the scoring uses. It cannot drift away from the real weights.

### How the gaps get ranked

Every area is scored on what the gap costs:

```
loss = (4 - your points) x weight
```

Highest loss first. Ties break on the order the questions were asked, so the
same answers always produce the same result. This is why two people with the
same score out of 100 can be told to start in different places.

* An area at **0, 1 or 2** points is a **gap**.
* An area at **3 or 4** points is a **strength**.
* The top three gaps become the seven-day plan.
* Fewer than three gaps, and the plan is topped up from the ranked list, so a
  strong result still leaves with three things to do rather than a compliment.

### The four bands

| Score | Band | Where it points |
|---|---|---|
| 0 to 39 | The business runs through you | The Operations Map |
| 40 to 59 | Held together by memory | The Operations Map |
| 60 to 79 | Working, with named gaps | The Operations Map, narrower scope |
| 80 to 100 | Runs without you in the room | A build conversation may fit directly |

Every band carries a next step, so no result ends without one. A test checks
that every score from 0 to 100 lands in exactly one band, with no holes and no
overlaps.

### What the score is not

Printed on every result, and carried into the emailed copy:

> This is a read of how work moves, based only on the twelve answers you
> selected. It is not a valuation, an accounting review, a legal or tax
> position, and it does not predict revenue. It describes where the work waits.

**No statistic is claimed anywhere.** There is no benchmark, no industry
average, no "businesses like yours". There is no dataset behind this and the
page says so out loud. A test fails the build if a percentage, a money figure,
or a phrase like "studies show" ever appears in the assessment prose.

---

## The results page

The order, top to bottom:

1. **The score dial** and the band, with the band explanation.
2. **Strongest area and weakest area**, side by side.
3. **Top three gaps**, in the order they cost most.
4. **Where to start.** One area, one action.
5. **The seven-day plan.** Days 1 to 2, 3 to 4, 5 to 7.
6. **Every area, scored.** Twelve meters with the weight on each.
7. **What to change, area by area.**
8. **What this points to.** The band's next step.
9. **What this result is not.** The guard rail.
10. **The emailed copy form.** Optional, and it comes after everything above.
11. **The Operations Map call to action.** $2,500, credited in full.
12. **Save or print · Download as a text file · Start over.**

---

## The lead capture flow

**The result is never held back.** It renders in full before the form is even
built. A test enforces that. Somebody who gives no email still leaves with the
whole thing, on screen, printable and downloadable.

What happens when somebody asks for the emailed copy:

1. The browser posts to `/audit-lead.php`: name, email, business name, the
   score, the band, and the same plain-text summary the download contains.
2. The endpoint checks the honeypot, the email, the score range, and the rate
   limit (five per hour per visitor, held as a salted hash for one hour only).
3. One line goes into `fs-metrics/audit-leads.log`.
4. The full summary is written to `fs-metrics/audit-results/`, capped at the
   most recent 400, so a result can be read without opening an inbox.
5. She gets an email carrying the whole summary.
6. The visitor gets their own copy.
7. The page reports the truth: "sent" only if the visitor's copy actually left
   the mail server. If it did not, the page says so and points at Download.

**Nothing sensitive is collected.** Three fields: name, email, business name.
No password field exists anywhere. The Denial Health Score asks nothing about
any patient, claim or diagnosis at any point, so its summary cannot carry any.

### Where the leads land

| File | What is in it |
|---|---|
| `fs-metrics/audit-leads.log` | One line per lead: date, assessment, score, band, name, email, business. |
| `fs-metrics/audit-results/*.txt` | The full result, one file each, newest 400 kept. |
| `fs-metrics/events.log` | Anonymous counts only: `audit_started`, `audit_completed`, `audit_emailed`. |

---

## Installing on the host

Everything deploys the way the rest of the site does: **push to GitHub and the
FTPS job carries it up.** No build step, no dependencies, no npm install.

If you are uploading by hand in the host's File Manager instead, these are the
files and where they go. `public_html` is the site root.

| Upload this | To here |
|---|---|
| `siesie-systems-audit.html` | `public_html/` |
| `soft-appeals-denial-health-score.html` | `public_html/` (replaces the existing one) |
| `audit-lead.php` | `public_html/` |
| `event.php` | `public_html/` (replaces the existing one) |
| `assets/audit-config.js` | `public_html/assets/` |
| `assets/audit-engine.js` | `public_html/assets/` |
| `assets/audit-ui.js` | `public_html/assets/` |
| `assets/systems-audit.css` | `public_html/assets/` |

### After uploading

1. **Check the page loads:** open `https://frimpomaasync.com/siesie-systems-audit`.
   The clean URL works on its own. The existing `.htaccess` rule that serves
   `page.html` for `/page` covers it, and nothing needed adding.

2. **Check the folder can be written to.** `audit-lead.php` creates
   `fs-metrics/audit-results/` the first time somebody asks for an email. If
   nothing appears there after a test run, set `fs-metrics` to permissions
   `755` in the File Manager.

3. **Check the email.** The sender is the same one the free shelf uses:
   `fs-metrics/smtp.json` on the server, never in the repo. If that file is
   missing, the visitor's copy will not send, the page will say so honestly,
   and the lead is still saved and still relayed to her. Nothing is lost.

4. **Run one test lead** and confirm three things: the line in
   `fs-metrics/audit-leads.log`, the file in `fs-metrics/audit-results/`, and
   the email in her inbox. Then delete that test line, the same way the free
   shelf test rows were cleaned.

### A note on caching

Pages revalidate on every visit and assets are held for a year, so **every
reference carries a `?v=`**. If you edit any file in `assets/`, bump the number
in the pages that load it, or the change reaches nobody:

```
/assets/audit-config.js?v=1   ->   ?v=2
```

The pages to bump are `siesie-systems-audit.html` and
`soft-appeals-denial-health-score.html`.

---

## Testing

```
cd ~/frimpomaasync-site
node tests/systems_audit.test.mjs        # 32 checks, the assessment itself
node tests/denial_health_score.test.mjs  # 17 checks, the older assessment
```

Both were green on 2026-08-17.

The browser run at 390px and 1440px covered: every screen renders, the progress
moves, nothing scrolls sideways, the last question does not auto-advance, the
result carries all twelve blocks, the lead form sends, a bad email is refused,
a reload resumes, a finished run offers the result back, and the console stays
clean.

### The three that were already failing

All three were proved pre-existing by running the suite against a clean copy of
`HEAD` with none of this work present. They failed there identically.

Two were fixed on 2026-08-17 because both were the test lying about the site:

* An em dash sat in a code comment in `fsnav.js`. No visitor ever saw it, and
  the rule is zero em dashes anywhere, so it became a comma.
* `tests/content_contract.test.mjs` still pointed at `client-catcher-demo`,
  renamed to `synkasa-demo` on 2026-08-10. The path was updated and the palette
  check now actually runs against that page. It passes.

The third was the SynKasa demo run on `portfolio.html`, which had no caption
file. **Closed on 2026-08-17, and the whole suite is now green at 98 of 98.**

The audio turned out to be nothing. The file carries an audio track and every
one of its 2,373,632 samples is zero, so there was no speech to transcribe and
no sound to describe. `videos/captions/client-catcher-demo-run.en.vtt` was
written the way the other four are written: eighteen cues describing what is on
screen. Verified in Chrome by seeking the player and reading back which cue was
live at each point.

Two supporting fixes went with it:

* `tests/a11y_contract.test.mjs` only recognised a player whose source sat in
  `/videos/`. The demo run is played from the demo folder it belongs to, so the
  source pattern now accepts any folder. The new file was also added to the
  house-style check, which holds it to the same rules as the other four.
* `tests/systems_audit_server.py` did not answer byte-range requests, so a
  browser could not seek inside a video at all. `currentTime` stayed pinned at
  zero and every caption check silently read the first cue forever. It answers
  ranges now, which is what made the verification real rather than decorative.

### The demo video was recaptured, not re-rendered

The old file was a recording of the app as it stood in July. It carried the
retired product name on the title card, in the sidebar the whole way through
and in its closing URL, the retired spelling of her name in the build credit,
emojis and em dashes in the demo messages, an unsourced "industry avg is 5
hours" on the first-reply card, and a closing line that read as a guarantee of
a number of bookings rather than the real one.

**Re-rendering it was not possible, and would have been the wrong fix anyway.**
The Remotion project that made it is not on this Mac, and more to the point the
app it filmed no longer exists: the live demo was rebuilt on 2026-08-11 and now
says SynKasa, credits the domain, and uses a generic worked example rather than
a named person.

So it was recaptured from the live demo instead, which means the video is the
real product rather than a picture of it.

| | Old | New |
|---|---|---|
| File | `client-catcher-demo-run.mp4` | `synkasa-demo-run.mp4` |
| Length | 49.5s | 30.5s |
| Size | 5.1 MB | 773 KB |
| Audio | A silent track | None at all |
| Name on screen | Client Catcher | SynKasa |
| Build credit | A person, retired spelling | The domain |

How it was made, in case it needs doing again:

1. `tests/systems_audit_server.py` serves the site locally.
2. A Chrome CDP screencast captures real frames of a live run, with their own
   timestamps. Playwright's own recorder wants an ffmpeg download and this Mac
   already has one inside Remotion, so it is not used.
3. Frames are sorted by timestamp, because CDP delivers them out of order, then
   laid out at a constant 30fps. Any hold longer than 2.5 seconds is capped:
   the demo pauses so a viewer can read, and those pauses are dead air in a
   video. That alone took it from 41.5s to 30.5s.
4. The title and end cards are rendered as real PNGs in a browser, because the
   Remotion ffmpeg ships with most filters disabled and has no `drawtext`.
5. The three segments are joined with the concat demuxer.

The old URL is kept alive by a redirect in `.htaccess`, since the deploy
deletes files that leave the repo.

---

## Things worth knowing before you change anything

* **Never nest the form.** The result renders a real `<form>` for the emailed
  copy. Both pages used to wrap the questions in an outer `<form>`, and a
  browser silently deletes a form inside a form. That is why both wrappers are
  plain `<div>` elements now. Do not put them back.
* **`hidden` loses to `display: grid`.** The fields close after sending using
  the `hidden` attribute, and it only works because
  `.sya-lead [hidden] { display: none !important }` is in the stylesheet.
* **Prefix every new class `sya-`.** Four stylesheets load on these pages.
* **The price appears in two places.** `assets/audit-config.js` and
  `operations-map.html`. A test fails if they disagree.
* **The weighting table is generated, never typed.** If you change a weight in
  the config, the published table changes with it.
