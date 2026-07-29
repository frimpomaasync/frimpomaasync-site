# Full Customer Path Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild frimpomaasync.com around one clear visitor-to-payment path, with useful tools before the call, separate SynKasa and Siesie qualification paths, and accurate humanized copy.

**Architecture:** Keep the existing static HTML deployment and shared `fsnav.js` chrome. Add one shared stylesheet for the approved visual system and one ES module for the leak finder, visitor-input calculator, and five-role Siesie check. Rebuild the five public journey pages, add a fit router plus two real qualification forms, and preserve the existing clean-URL deployment.

**Tech Stack:** Static HTML5, CSS, browser JavaScript ES modules, Node.js built-in test runner, Python `http.server`, Python Playwright for final browser QA.

## Global Constraints

- frimpomaasync.com is the brand and every product sits under it.
- SynKasa is the flagship AI front desk with Start, Grow, and Full tiers.
- Siesie is the back office above the ladder and must show all five roles: Scheduling, Money, Coordination, Account management, and Reporting.
- Som is free at `/som`; Soma remains a section inside `/synkasa#soma`.
- Use the current public prices: SynKasa $555, $1,500, and $2,222; care $99, $222, and $444; Siesie $25,000.
- Show the founding Grow rate as $750 for the first three owners with a named testimonial.
- Use the exact guarantee: "Live in 7 days, or you don't pay."
- Use the slogan: "Watch it work before you pay."
- Customer-facing copy never names a technology vendor.
- No emojis, em dashes, remote fonts, invented metrics, invented proof, or guaranteed booking numbers.
- Use ink `#101426`, copper-orange `#C2501C`, paper `#FFFFFF` and `#F8F8F9`, the Iowan system serif stack, and system sans/mono stacks.
- Frame outcomes first and keep the approved mockup's restrained product-story direction.
- Run the copywriting and both humanizer passes on every human-facing page, metadata field, form message, and `llms.txt`.
- The ADHD material in the shared conversation guides assistant replies only and must not appear on the website.
- A push to `main` deploys the live site, so implementation and review stay on `codex/full-customer-path` until NaNa Frimpomaa explicitly approves publishing.

---

### Task 1: Shared journey behavior

**Files:**
- Create: `tests/journey.test.mjs`
- Create: `assets/journey.js`

**Interfaces:**
- Consumes: Browser form values and `data-*` attributes from Tasks 2 and 3.
- Produces: `calculateMonthlyOpportunity(input)`, `getLeakRecommendation(key)`, and `getSiesieRecommendation(count)` exports plus guarded DOM bindings.

- [ ] **Step 1: Write the failing behavior tests**

```js
import test from "node:test";
import assert from "node:assert/strict";
import {
  calculateMonthlyOpportunity,
  getLeakRecommendation,
  getSiesieRecommendation,
} from "../assets/journey.js";

test("calculates monthly opportunity from visitor numbers", () => {
  assert.deepEqual(
    calculateMonthlyOpportunity({
      weeklyInquiries: 10,
      missedPercent: 25,
      bookingPercent: 50,
      averageJobValue: 400,
    }),
    {
      amount: 2000,
      inquiriesAtRisk: 10,
      formula: "10 × 4 weeks × 25% at risk × 50% booked × $400",
    },
  );
});

test("clamps invalid percentages and negative values", () => {
  assert.equal(
    calculateMonthlyOpportunity({
      weeklyInquiries: -2,
      missedPercent: 160,
      bookingPercent: -4,
      averageJobValue: -50,
    }).amount,
    0,
  );
});

test("routes a back-office leak to Siesie with a same-day action", () => {
  const result = getLeakRecommendation("backoffice");
  assert.equal(result.path, "siesie");
  assert.match(result.action, /handoff/i);
});

test("routes four owner-dependent roles to the Siesie application", () => {
  const result = getSiesieRecommendation(4);
  assert.equal(result.path, "siesie-application");
  assert.match(result.label, /4 of 5/i);
});
```

- [ ] **Step 2: Run the tests and verify the missing module failure**

Run: `node --test tests/journey.test.mjs`

Expected: FAIL with `ERR_MODULE_NOT_FOUND` for `assets/journey.js`.

- [ ] **Step 3: Implement the pure behavior and guarded page bindings**

```js
const clamp = (value, min, max) =>
  Math.min(max, Math.max(min, Number(value) || 0));

export function calculateMonthlyOpportunity(input) {
  const weeklyInquiries = Math.max(0, Number(input.weeklyInquiries) || 0);
  const missedPercent = clamp(input.missedPercent, 0, 100);
  const bookingPercent = clamp(input.bookingPercent, 0, 100);
  const averageJobValue = Math.max(0, Number(input.averageJobValue) || 0);
  const amount = Math.round(
    weeklyInquiries *
      4 *
      (missedPercent / 100) *
      (bookingPercent / 100) *
      averageJobValue,
  );

  return {
    amount,
    inquiriesAtRisk: Number(
      (weeklyInquiries * 4 * (missedPercent / 100)).toFixed(1),
    ),
    formula:
      `${weeklyInquiries} × 4 weeks × ${missedPercent}% at risk × ` +
      `${bookingPercent}% booked × $${averageJobValue.toLocaleString()}`,
  };
}

const leakRecommendations = {
  missed: {
    heading: "Your front desk is the first leak.",
    action: "Turn on an instant missed-call text today. Ask one question and offer two times.",
    path: "synkasa",
    proofPath: "/portfolio#synkasa-proof",
  },
  followup: {
    heading: "The first answer happens, then the lead goes quiet.",
    action: "Give every inquiry a dated next action. A lead without a date is already slipping.",
    path: "synkasa",
    proofPath: "/portfolio#synkasa-proof",
  },
  noshow: {
    heading: "The booking is made, but the reminder is carrying too little.",
    action: "Send a confirmation immediately and a reminder the night before. Ask for a one-word reply.",
    path: "synkasa",
    proofPath: "/portfolio#synkasa-proof",
  },
  backoffice: {
    heading: "The work has outgrown the owner's memory.",
    action: "Write the handoff for the process that interrupts you most. Choose the software after the process is clear.",
    path: "siesie",
    proofPath: "/portfolio#operations-proof",
  },
};

export function getLeakRecommendation(key) {
  return leakRecommendations[key] || leakRecommendations.missed;
}

export function getSiesieRecommendation(count) {
  const total = Math.min(5, Math.max(0, Number(count) || 0));
  if (total <= 1) {
    return {
      label: `${total} of 5 roles depend on you`,
      heading: "Document the one interruption first.",
      action: "Write the trigger, owner, next step, and finish line. Use Free or SynKasa if the leak starts with inquiries.",
      path: "free",
    };
  }
  if (total <= 3) {
    return {
      label: `${total} of 5 roles depend on you`,
      heading: "Map the handoff that touches the most people.",
      action: "Start with the process that crosses customers, crew, suppliers, or money. Review Siesie once that handoff is clear.",
      path: "siesie",
    };
  }
  return {
    label: `${total} of 5 roles depend on you`,
    heading: "Your back office is still waiting on you.",
    action: "Four or five roles depend on the owner. The detailed Siesie application is the right next step.",
    path: "siesie-application",
  };
}
```

Bind the leak choices to `#leak-heading`, `#leak-action`, `#leak-proof`, and `#leak-path`; the calculator to `#opportunity-form`, `#calc-at-risk`, `#calc-output`, `#calc-formula`, and `#calc-error`; and the five `.audit-check` inputs to `#audit-output`. Each binding exits quietly when its root element is absent. Native form constraints prevent negative or impossible values, preserve the entered values, and expose missing fields in `#calc-error`.

- [ ] **Step 4: Run the tests and verify green**

Run: `node --test tests/journey.test.mjs`

Expected: 4 tests pass, 0 fail.

- [ ] **Step 5: Commit the behavior**

```bash
git add tests/journey.test.mjs assets/journey.js
git commit -m "feat: add customer journey tools"
```

---

### Task 2: Shared visual system and discovery pages

**Files:**
- Create: `assets/journey.css`
- Modify: `index.html`
- Modify: `portfolio.html`
- Modify: `free.html`

**Interfaces:**
- Consumes: `getLeakRecommendation()` and its DOM binding from Task 1.
- Produces: The home routing experience, proof-to-offer cards, and free resources grouped by visitor problem.

- [ ] **Step 1: Build the shared stylesheet**

Create named layout classes for the approved ink, paper, copper-orange, serif, mono, buttons, hero grids, cards, proof flows, result panels, forms, focus states, and reduced-motion behavior. The desktop `.role-grid` uses five equal columns, becomes two columns below 900px, and one column below 620px. `.hero-grid` becomes one column below 900px. Every interactive control uses a visible copper focus ring. At 390px, `html`, `body`, and every page section remain within the viewport. Reduced-motion mode removes animation and smooth scrolling.

- [ ] **Step 2: Rebuild the homepage**

Use the approved headline "Stop being the system." The primary hero action scrolls to `#page-leak-tool`; the secondary opens the working SynKasa demo. The leak finder uses buttons with `data-leak="missed"`, `followup`, `noshow`, and `backoffice`, then writes its result into `#leak-output`. The next section has exactly three routing cards for SynKasa, Siesie, and Free. The guarantee section uses "Live in 7 days, or you don't pay." and "Watch it work before you pay." The close links to `/fit`. A `noscript` note sends the visitor to the free response script or 15-minute call.

- [ ] **Step 3: Rebuild Proof**

Lead with working builds, not unsupported client claims. Use anchors `#synkasa-proof`, `#soma-proof`, and `#som-proof`. Each card keeps its local video, states the problem, explains the build, gives one `Steal this flow` sequence, and routes to `/synkasa`, `/synkasa#soma`, or `/som`. Reserve one plain results panel saying client results appear only after the client approves the wording and result.

- [ ] **Step 4: Rebuild Free**

Group the free value by problem with these destinations: `I miss inquiries` to `#response-script`; `I do not know what comes first` to `/automated-small-business-blueprint.pdf`; `Ideas disappear` to `/som`; `I want proof` to `/client-catcher-demo`; and `I need a human diagnosis` to the current Google booking URL. Keep the four-message response script selectable and preserve visible copy success plus a selectable fallback.

- [ ] **Step 5: Run the unit tests and a static server smoke check**

Run: `node --test tests/journey.test.mjs`

Run: `python3 -m http.server 4173`

In another shell run: `curl -I http://127.0.0.1:4173/`

Expected: Tests pass and the homepage returns HTTP 200.

- [ ] **Step 6: Commit the discovery pages**

```bash
git add assets/journey.css index.html portfolio.html free.html
git commit -m "feat: rebuild discovery and proof path"
```

---

### Task 3: SynKasa and Siesie offer pages

**Files:**
- Modify: `synkasa.html`
- Modify: `siesie.html`

**Interfaces:**
- Consumes: Calculator and Siesie-check bindings from Task 1 plus shared styles from Task 2.
- Produces: Two complete offer pages that send qualified visitors to `/synkasa-fit` or `/siesie-application`.

- [ ] **Step 1: Rebuild SynKasa**

Use these sections in order: outcome hero; working demo; visitor-input calculator; response script; inquiry-to-follow-up workflow; Soma at `#soma`; Start, Grow, and Full cards; ownership; guarantee; frequently asked questions; `/synkasa-fit` close. The calculator exposes `#calc-at-risk`, `#calc-output`, `#calc-formula`, and a plain statement that the scenario uses only the visitor's inputs and is not a promise. Prices stay $555 plus $99 care, $1,500 plus $222 care, and $2,222 plus $444 care. Grow remains recommended. The $750 founding rate stays limited to the first three owners with a named testimonial.

- [ ] **Step 2: Rebuild Siesie with all five roles**

Show all five roles in the hero and in five equal `.role-card` elements: Scheduling, Money, Coordination, Account management, and Reporting. Add `#siesie-check` with five `.audit-check` inputs, the representative flow `Work received → scheduled → coordinated → invoiced → reported`, ideal-fit language for an established business where four or five roles return to the owner, `Siesie · $25,000, once`, and the build path `Map → build → test → approve → launch → train → hand over`. State that the owner supplies the current process, working rules, account access, and timely approvals. Judgment calls, exceptions, approvals, and sensitive conversations stay human. Add the exact seven-day guarantee, a `/siesie-application` close, and a `noscript` manual process-mapping step.

- [ ] **Step 3: Run behavior tests**

Run: `node --test tests/journey.test.mjs`

Expected: 4 tests pass, 0 fail.

- [ ] **Step 4: Commit the offer pages**

```bash
git add synkasa.html siesie.html
git commit -m "feat: rebuild SynKasa and Siesie offers"
```

---

### Task 4: Qualification and payment path

**Files:**
- Create: `fit.html`
- Create: `synkasa-fit.html`
- Create: `siesie-application.html`
- Create: `fit-thanks.html`
- Modify: `fsnav.js`
- Modify: `privacy.html`

**Interfaces:**
- Consumes: Existing Formspree endpoint `https://formspree.io/f/mnjkqydb` and Google booking URL `https://calendar.app.google/DkRJFRA3G6W6d8E48`.
- Produces: Separate qualification flows and a clear sequence from diagnosis through proposal, payment, onboarding, launch, support, and proof.

- [ ] **Step 1: Build the fit router**

Show one short choice between the SynKasa fit form and the Siesie application. Display `Useful diagnosis → fit form → 15-minute call → recommendation → proposal → payment → onboarding → launch → care`.

- [ ] **Step 2: Build the SynKasa fit form**

Create `<form name="synkasa-fit" data-qual-form>` with required name, email, business name, business type, contact channels, main problem, weekly inquiry volume, current booking method, desired result, and implementation timing. Website or social page is optional. Post to `https://formspree.io/f/mnjkqydb`; include hidden `_subject=SynKasa fit`, `source=synkasa-fit`, and `data-success="/fit-thanks?source=synkasa"`.

- [ ] **Step 3: Build the Siesie application**

Create `<form name="siesie-application" data-qual-form>` with required name, email, business name, business type, team size, monthly job or client volume, scheduling process, invoicing process, people coordinated, owner-dependent tasks, main bottleneck, implementation timing, and investment readiness. Website, current tools, and additional context are optional. Post to `https://formspree.io/f/mnjkqydb`; include hidden `_subject=Siesie application`, `source=siesie-application`, and `data-success="/fit-thanks?source=siesie"`.

- [ ] **Step 4: Update the confirmation and shared navigation**

Keep the shared navigation's `Book a call` action on the current Google booking URL and keep the live SynKasa demo available. Make `/fit-thanks` `noindex`, read the `source` query parameter, explain what NaNa Frimpomaa reviews, and provide the existing 15-minute booking link.

Bind both forms to this behavior: call `reportValidity()`, disable the submit button during delivery, send `FormData` with `Accept: application/json`, redirect to `data-success` on a successful response, and restore the button after a failure. The inline failure message links to `hello@frimpomaasync.com` and the current booking URL. A `noscript` note provides the same email and booking fallback.

Update `/privacy` to name the business-process, volume, desired-result, timing, and investment-readiness fields collected only when a visitor submits a qualification form. State that public qualification forms do not request passwords, payment details, customer records, or account access.

- [ ] **Step 5: Commit the qualification path**

```bash
git add fit.html synkasa-fit.html siesie-application.html fit-thanks.html fsnav.js privacy.html
git commit -m "feat: add separate qualification paths"
```

---

### Task 5: Content accuracy, discovery files, and full QA

**Files:**
- Modify: `sitemap.xml`
- Modify: `llms.txt`
- Modify: `blog/reply-to-every-lead-in-five-minutes.html`
- Modify: `blog/do-i-need-a-crm-if-im-a-one-person-business.html`
- Modify: relevant metadata in all rebuilt HTML pages
- Create: `tests/site_qa.py`

**Interfaces:**
- Consumes: Every public page from Tasks 2 through 4.
- Produces: Search discovery, humanized public copy, responsive browser verification, and a publish-ready branch.

- [ ] **Step 1: Update discovery files**

Add `/fit`, `/synkasa-fit`, and `/siesie-application` to the sitemap. Keep `/fit-thanks` out because it is noindex. Add a contextual SynKasa link to the five-minute response article and a contextual Siesie link to the CRM article without changing their main copy. Rewrite `llms.txt` so it matches the current products, prices, guarantee, qualification forms, proof rules, and free resources.

- [ ] **Step 2: Run both humanizer passes**

Scan all rebuilt HTML, metadata, form messages, `fsnav.js` reader-facing strings, and `llms.txt`. Remove every em dash, emoji, vendor name, retired phrase, unsupported metric, negative-parallel phrase, repetitive triad, model artifact, and vague claim. Apply reference specificity and reader-presence as the structural interventions.

- [ ] **Step 3: Write the browser QA script**

Use Python Playwright against the running static server. Verify:

```python
assert page.locator("#page-leak-tool").is_visible()
assert page.locator("[data-leak='missed']").is_enabled()
assert page.locator(".role-card").count() == 5
assert page.locator("text=Account management").count() >= 1
assert page.locator("text=Reporting").count() >= 1
assert page.locator("#calc-output").inner_text() == "$2,000"
assert page.locator("#audit-output").inner_text().startswith("4 of 5")
assert page.locator("form[name='synkasa-fit']").count() == 1
assert page.locator("form[name='siesie-application']").count() == 1
```

Also test widths 390 and 1280, keyboard focus, internal links, browser console errors, and horizontal overflow.

- [ ] **Step 4: Run complete verification**

Run: `node --test tests/journey.test.mjs`

Run the static server and `python3 tests/site_qa.py`.

Run a retired-copy and artifact scan with `rg`.

Expected: Unit tests pass, browser checks pass at both widths, no console errors, no broken internal links, no horizontal overflow, and no prohibited public copy.

- [ ] **Step 5: Review the diff and commit the verified build**

```bash
git diff --check
git status --short
git add sitemap.xml llms.txt blog/reply-to-every-lead-in-five-minutes.html blog/do-i-need-a-crm-if-im-a-one-person-business.html tests/site_qa.py
git commit -m "test: verify complete customer path"
```
