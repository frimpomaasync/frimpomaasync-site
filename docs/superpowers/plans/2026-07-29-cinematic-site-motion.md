# Cinematic Site and Motion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the approved Cinematic Working Landscape direction across the full frimpomaasync.com customer journey, restore the original photography, add purposeful motion, keep all five Siesie roles visible, and produce a tested deployment candidate.

**Architecture:** Keep the current static HTML, shared `assets/journey.css`, shared `fsnav.js`, and the working customer-journey behavior in `assets/journey.js`. Add reusable cinematic hero, paper-rise, compact hero, and sticky-story classes. Extend the existing navigation and reveal code with small observer-based state changes, then verify structure, content, motion, accessibility, routes, tools, and forms with Node and Playwright tests.

**Tech Stack:** Static HTML5, CSS custom properties, browser JavaScript, IntersectionObserver, Node.js built-in test runner, Python preview server, Python Playwright, macOS `sips`.

## Global Constraints

- frimpomaasync.com is the brand and every product sits under it.
- SynKasa remains the flagship. Siesie remains the back office above the ladder.
- Siesie must show all five roles: Scheduling, Money, Coordination, Account management, and Reporting.
- Use `assets/hero-scene-wide.jpg` on the homepage and SynKasa.
- Use `assets/siesie-hero.png` as the source for an optimized Siesie hero image.
- Do not use `og-client-catcher.png`, `ai-products-cover.png`, `og-cover.png`, or `blog/demo-followup-thread.png` on active customer-journey pages.
- Keep ink `#101426`, copper-orange `#C2501C`, white `#FFFFFF`, and paper `#F8F8F9`.
- Use the Iowan Old Style system serif stack plus system sans and mono stacks. Make no remote font requests.
- Keep the exact guarantee: "Live in 7 days, or you don't pay."
- Keep the current approved product names, prices, tier structure, care prices, booking link, form delivery endpoint, and public routes.
- Customer-facing copy never names a technology vendor.
- No emojis, em dashes, invented metrics, invented proof, unsupported booking promises, or ADHD guidance in customer-facing files.
- Motion must use `transform`, `opacity`, or a simple clipping boundary. Do not add `transition: all`.
- Controls are at least 44px tall. Focus remains visible. Text over photography meets AA contrast.
- The site must work without horizontal overflow at 390px, 768px, 1024px, 1280px, and 1440px.
- Reduced-motion mode removes parallax and scaling, keeps the complete content visible, and preserves every interaction.
- The leak finder, calculator, audit, forms, navigation, chat, copy action, redirects, structured data, and privacy behavior must keep working.
- Run copywriting and both humanizer passes on every changed public string, metadata field, form message, and `llms.txt`.
- Do not update the live branch until the complete test suite passes and NaNa Frimpomaa approves the verified deployment candidate.

---

### Task 1: Shared visual and motion foundation

**Files:**
- Create: `tests/visual_contract.test.mjs`
- Modify: `assets/journey.css:1-1046`
- Modify: `fsnav.js:7-219`

**Interfaces:**
- Consumes: `body[data-cinematic]`, `[data-cinematic-hero]`, and `[data-story-step]` attributes added by later tasks.
- Produces: CSS tokens `--motion-fast`, `--motion-panel`, `--motion-section`, `--ease-out`, and `--ease-state`; classes `.cinematic-hero`, `.cinematic-media`, `.cinematic-scrim`, `.cinematic-content`, `.hero-product`, `.paper-rise`, `.compact-hero`, `.sticky-story`, `.story-visual`, `.story-steps`, `.story-step`, `.is-active`, and `.is-past-hero`; observer-based navigation and story state.

- [ ] **Step 1: Write the failing shared-contract test**

```js
import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

const ROOT = fileURLToPath(new URL("..", import.meta.url));
const read = (path) => readFileSync(`${ROOT}/${path}`, "utf8");

test("shared styles define the cinematic motion system", () => {
  const css = read("assets/journey.css");
  assert.match(css, /--motion-fast:\s*180ms/);
  assert.match(css, /--motion-panel:\s*280ms/);
  assert.match(css, /--motion-section:\s*480ms/);
  assert.match(css, /--ease-out:\s*cubic-bezier\(\.22,\s*1,\s*\.36,\s*1\)/);
  assert.match(css, /\.cinematic-hero/);
  assert.match(css, /\.paper-rise/);
  assert.match(css, /\.sticky-story/);
  assert.match(css, /prefers-reduced-motion:\s*reduce/);
  assert.doesNotMatch(css, /transition\s*:\s*all\b/);
});

test("shared chrome observes cinematic heroes and story steps", () => {
  const js = read("fsnav.js");
  assert.match(js, /data-cinematic-hero/);
  assert.match(js, /data-story-step/);
  assert.match(js, /is-past-hero/);
  assert.match(js, /is-active/);
});
```

- [ ] **Step 2: Run the new test and verify the expected failure**

Run: `node --test tests/visual_contract.test.mjs`

Expected: FAIL because the motion tokens and cinematic classes do not exist.

- [ ] **Step 3: Add the shared CSS tokens and components**

Add these tokens to `:root`:

```css
--motion-fast: 180ms;
--motion-panel: 280ms;
--motion-section: 480ms;
--ease-out: cubic-bezier(.22, 1, .36, 1);
--ease-state: cubic-bezier(.65, 0, .35, 1);
--nav-height: 76px;
--layer-base: 0;
--layer-hero: 10;
--layer-nav: 40;
--layer-overlay: 60;
```

Add one reusable cinematic component family:

```css
.cinematic-hero {
  position: relative;
  min-height: min(860px, 100svh);
  overflow: clip;
  isolation: isolate;
  padding: clamp(96px, 12vw, 164px) 0 clamp(118px, 14vw, 190px);
  background: var(--ink);
  color: var(--paper);
}

.cinematic-media,
.cinematic-scrim {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
}

.cinematic-media {
  z-index: -3;
  object-fit: cover;
  object-position: center;
  transform: scale(1.025);
  animation: cinematic-settle 800ms var(--ease-out) both;
}

.cinematic-scrim {
  z-index: -2;
  background:
    linear-gradient(90deg, rgba(8, 11, 24, .9) 0%, rgba(8, 11, 24, .58) 47%, rgba(8, 11, 24, .18) 74%),
    linear-gradient(0deg, rgba(8, 11, 24, .68) 0%, transparent 46%);
}

.cinematic-content {
  position: relative;
  z-index: var(--layer-hero);
}

.paper-rise {
  position: relative;
  z-index: 12;
  margin-top: clamp(-72px, -6vw, -44px);
  border-radius: clamp(28px, 4vw, 58px) clamp(28px, 4vw, 58px) 0 0;
  background: var(--paper);
}

.sticky-story {
  display: grid;
  grid-template-columns: minmax(0, 1.05fr) minmax(300px, .7fr);
  gap: clamp(32px, 7vw, 92px);
  align-items: start;
}

.story-visual {
  position: sticky;
  top: calc(var(--nav-height) + 28px);
}

.story-step {
  min-height: 58vh;
  opacity: .42;
  transform: translateY(18px);
  transition:
    opacity var(--motion-panel) var(--ease-out),
    transform var(--motion-panel) var(--ease-out);
}

.story-step.is-active {
  opacity: 1;
  transform: none;
}
```

Use hover media queries so hover effects do not run on touch-only devices. Replace the current broad reduced-motion rule with targeted rules that remove scaling, parallax, smooth scrolling, and sticky storytelling while leaving focus and state changes visible.

- [ ] **Step 4: Add observer-based navigation and story state**

In `fsnav.js`, detect `body[data-cinematic]`. Observe `[data-cinematic-hero]` and toggle `.is-past-hero` on `#fs-nav` after the hero leaves the top of the viewport. Observe each `[data-story-step]` with `rootMargin: "-38% 0px -48% 0px"` and move `.is-active` to the intersecting step.

Use this state shape:

```js
function setActiveStoryStep(step, group) {
  group.forEach(function (item) {
    item.classList.toggle("is-active", item === step);
  });
}
```

Do not add a continuous scroll handler for the story. Keep the existing requestAnimationFrame-throttled sticky CTA handler.

Replace the current 650 to 700 millisecond reveal transitions with `var(--motion-section)` and limit stagger steps to 50 milliseconds. Keep content visible immediately when IntersectionObserver is unavailable or reduced motion is requested.

- [ ] **Step 5: Run the shared-contract and existing behavior tests**

Run: `node --test tests/visual_contract.test.mjs tests/journey.test.mjs`

Expected: 9 tests pass, 0 fail.

- [ ] **Step 6: Commit the shared foundation**

```bash
git add tests/visual_contract.test.mjs assets/journey.css fsnav.js
git commit -m "feat: add cinematic motion foundation"
```

---

### Task 2: Homepage cinematic hero and paper rise

**Files:**
- Modify: `tests/visual_contract.test.mjs`
- Modify: `index.html:10-79`
- Modify: `assets/journey.css`

**Interfaces:**
- Consumes: Shared cinematic classes and navigation observer from Task 1.
- Produces: A full-bleed homepage hero using `hero-scene-wide.jpg`, an immediately usable `#page-leak-tool`, and the first `.paper-rise` transition.

- [ ] **Step 1: Add the failing homepage contract**

```js
test("homepage uses the approved picture and keeps the leak finder usable", () => {
  const html = read("index.html");
  assert.match(html, /<body[^>]*data-cinematic/);
  assert.match(html, /data-cinematic-hero/);
  assert.match(
    html,
    /<img[^>]*class="cinematic-media"[^>]*src="\/assets\/hero-scene-wide\.jpg"/,
  );
  assert.match(html, /width="2400"[^>]*height="1024"/);
  assert.match(html, /fetchpriority="high"/);
  assert.match(html, /id="page-leak-tool"/);
  assert.match(html, /class="section soft paper-rise"/);
});
```

- [ ] **Step 2: Run the homepage contract and verify failure**

Run: `node --test --test-name-pattern="homepage" tests/visual_contract.test.mjs`

Expected: FAIL because the homepage has no cinematic image or paper-rise class.

- [ ] **Step 3: Rebuild the hero markup around the existing copy and tool**

Use this structure:

```html
<body data-cinematic data-sk-sections="Hero,First fix,Proof,Choose the path,Guarantee,Close" data-sk-bar="Live in 7 days, or you don't pay." data-sk-biz="service business">
  <main>
    <section class="hero cinematic-hero" data-cinematic-hero data-screen-label="Hero">
      <img class="cinematic-media" src="/assets/hero-scene-wide.jpg" width="2400" height="1024" alt="" fetchpriority="high" decoding="async">
      <div class="cinematic-scrim" aria-hidden="true"></div>
      <div class="wrap hero-grid cinematic-content">
        <div class="hero-message">
          <div class="eyebrow">One owner · a business that keeps moving</div>
          <h1>Stop being <em>the system.</em></h1>
          <p class="hero-copy">SynKasa handles the front desk. Siesie handles the work behind it. You close up knowing the business kept moving.</p>
          <div class="actions">
            <a class="button" href="#page-leak-tool">Find the first leak →</a>
            <button class="button secondary" type="button" data-fs-chat>Watch SynKasa answer →</button>
          </div>
        </div>
        <div class="screen hero-product" id="page-leak-tool">
          <div class="screen-top mono">
            <span>Where does work wait on you?</span>
            <span class="status">Local only</span>
          </div>
          <div class="screen-body">
            <div class="choice-list" aria-label="Choose the main business leak">
              <button class="choice" type="button" data-leak="missed" aria-pressed="false">Missed inquiries</button>
              <button class="choice" type="button" data-leak="followup" aria-pressed="false">Slow follow-up</button>
              <button class="choice" type="button" data-leak="noshow" aria-pressed="false">No-shows or unconfirmed bookings</button>
              <button class="choice" type="button" data-leak="backoffice" aria-pressed="false">The back office depends on me</button>
            </div>
            <div class="result" id="leak-output" aria-live="polite">
              <span class="mono">Your first fix</span>
              <h3 id="leak-heading">Pick the leak above.</h3>
              <p id="leak-action">Your answer and one same-day action will appear here.</p>
              <div class="result-actions">
                <a id="leak-proof" href="/portfolio">See the working build →</a>
                <a id="leak-path" href="/free">Take a free fix →</a>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="hero-trust wrap" aria-label="Service distinction">
        <span>SynKasa · front desk</span>
        <span>Siesie · back office</span>
        <span>Live in 7 days, or you don't pay</span>
      </div>
    </section>
    <section class="section soft paper-rise" data-screen-label="First fix">
```

Do not delay pointer or keyboard access to the leak finder while the hero settles.

- [ ] **Step 4: Add homepage-specific responsive positioning**

At 901px and above, place the copy on the left and keep the tool panel on the right with a maximum width of 470px. At 900px and below, stack copy then tool. At 620px and below, use `object-position: 62% center`, reduce the image scale to `1.01`, and keep the panel in normal document flow.

The hero trust row wraps on tablet and becomes a two-row list on mobile. Text contrast over the darkest and lightest parts of the image must remain at least 4.5:1.

- [ ] **Step 5: Run homepage, behavior, and static QA**

Run: `node --test tests/visual_contract.test.mjs tests/journey.test.mjs`

Run: `python3 tests/site_qa.py --static`

Expected: All tests pass. The leak finder IDs and internal links remain intact.

- [ ] **Step 6: Commit the homepage**

```bash
git add tests/visual_contract.test.mjs index.html assets/journey.css
git commit -m "feat: add cinematic homepage hero"
```

---

### Task 3: SynKasa product-stage hero and sticky story

**Files:**
- Modify: `tests/visual_contract.test.mjs`
- Modify: `synkasa.html:35-140`
- Modify: `assets/journey.css`

**Interfaces:**
- Consumes: Cinematic hero and sticky-story components from Task 1.
- Produces: `#synkasa-demo-stage`, four `[data-story-step]` elements, and the existing calculator, script, live chat, Soma, tiers, fit, ownership, guarantee, FAQ, and form path.

- [ ] **Step 1: Add the failing SynKasa contract**

```js
test("SynKasa uses the original picture and a four-step product story", () => {
  const html = read("synkasa.html");
  assert.match(html, /<body[^>]*data-cinematic/);
  assert.match(html, /src="\/assets\/hero-scene-wide\.jpg"/);
  assert.match(html, /id="synkasa-demo-stage"/);
  assert.equal((html.match(/data-story-step/g) || []).length, 4);
  for (const id of [
    "opportunity-form",
    "calc-output",
    "synkasa-script",
  ]) {
    assert.match(html, new RegExp(`id="${id}"`));
  }
  assert.match(html, /href="\/synkasa-fit"/);
});
```

- [ ] **Step 2: Run the SynKasa contract and verify failure**

Run: `node --test --test-name-pattern="SynKasa" tests/visual_contract.test.mjs`

Expected: FAIL because the cinematic image and four-step story do not exist.

- [ ] **Step 3: Rebuild the SynKasa hero**

Use `hero-scene-wide.jpg` with `object-position: 58% center`. Keep the current eyebrow, headline, hero paragraph, CTAs, former-name line, approved prices, and guarantee.

Replace the decorative OPEN board with this foreground panel:

```html
<div class="screen hero-product synkasa-stage" id="synkasa-demo-stage">
  <div class="screen-top">
    <span>Front desk status</span>
    <span class="status">Working</span>
  </div>
  <div class="screen-body product-status-list">
    <div><span>01</span><strong>Inquiry received</strong></div>
    <div><span>02</span><strong>Question answered</strong></div>
    <div><span>03</span><strong>Two times offered</strong></div>
    <div><span>04</span><strong>Follow-up ready</strong></div>
    <button class="button" type="button" data-fs-chat>Watch it answer now →</button>
  </div>
</div>
```

The image remains atmosphere. The working foreground panel carries the readable product state.

- [ ] **Step 4: Turn the current workflow into a sticky product story**

Use the existing proof video as `.story-visual`. Place four short story steps beside it:

```html
<div class="sticky-story" data-story>
  <div class="proof-media story-visual">
    <video src="/videos/01-receptionist.mp4#t=2.5" controls preload="metadata" playsinline aria-label="SynKasa answering a customer inquiry"></video>
  </div>
  <div class="story-steps">
    <article class="story-step is-active" data-story-step>
      <span class="pill">01</span>
      <h3>Answer</h3>
      <p>The inquiry gets a fast first response in the business's voice.</p>
    </article>
    <article class="story-step" data-story-step>
      <span class="pill">02</span>
      <h3>Qualify</h3>
      <p>The front desk asks what the customer needs, where they are, and how soon they need it.</p>
    </article>
    <article class="story-step" data-story-step>
      <span class="pill">03</span>
      <h3>Book</h3>
      <p>The customer gets two real times and the chosen time reaches the calendar.</p>
    </article>
    <article class="story-step" data-story-step>
      <span class="pill">04</span>
      <h3>Follow up</h3>
      <p>A quiet lead keeps a dated next action until it books, needs the owner, or reaches a clear stop.</p>
    </article>
  </div>
</div>
```

The four headings are Answer, Qualify, Book, and Follow up. Each step uses one or two sentences from the existing page. No new capability claim is added.

At 900px and below, remove sticky positioning and show the video followed by all four steps.

- [ ] **Step 5: Run all unit and static tests**

Run: `node --test tests/visual_contract.test.mjs tests/journey.test.mjs`

Run: `python3 tests/site_qa.py --static`

Expected: All tests pass and all SynKasa internal anchors still resolve.

- [ ] **Step 6: Commit SynKasa**

```bash
git add tests/visual_contract.test.mjs synkasa.html assets/journey.css
git commit -m "feat: stage the SynKasa product story"
```

---

### Task 4: Siesie cinematic hero and accurate five-role story

**Files:**
- Create: `assets/siesie-hero.jpg`
- Modify: `tests/visual_contract.test.mjs`
- Modify: `siesie.html:34-177`
- Modify: `assets/journey.css`

**Interfaces:**
- Consumes: `assets/siesie-hero.png` as the source, shared cinematic and sticky-story classes, and the working Siesie audit binding.
- Produces: An optimized hero image, one visible five-role hero panel, exactly five `.role-card` elements, five checkboxes, and a representative five-step workflow.

- [ ] **Step 1: Add the failing Siesie contract**

```js
test("Siesie uses the optimized picture and shows all five roles", () => {
  const html = read("siesie.html");
  assert.match(html, /<body[^>]*data-cinematic/);
  assert.match(html, /src="\/assets\/siesie-hero\.jpg"/);
  assert.equal((html.match(/class="role-row"/g) || []).length, 5);
  assert.equal((html.match(/class="role-card"/g) || []).length, 5);
  assert.equal((html.match(/class="audit-check"/g) || []).length, 5);

  for (const role of [
    "Scheduling",
    "Money",
    "Coordination",
    "Account management",
    "Reporting",
  ]) {
    assert.match(html, new RegExp(`>${role}<`));
  }
});
```

- [ ] **Step 2: Run the Siesie contract and verify failure**

Run: `node --test --test-name-pattern="Siesie" tests/visual_contract.test.mjs`

Expected: FAIL because `assets/siesie-hero.jpg` and the cinematic markup do not exist.

- [ ] **Step 3: Create the optimized image**

Run:

```bash
sips -s format jpeg -s formatOptions 82 assets/siesie-hero.png --out assets/siesie-hero.jpg
```

Verify:

```bash
file assets/siesie-hero.jpg
du -h assets/siesie-hero.jpg
```

Expected: 1536 by 1024 JPEG and smaller than the 2.7MB PNG source. Keep the PNG as the editable source.

- [ ] **Step 4: Rebuild the Siesie hero and role panel**

Use `assets/siesie-hero.jpg` as `.cinematic-media`, `object-position: center`, width `1536`, height `1024`, `fetchpriority="high"`, and an empty `alt` because the image sets atmosphere.

Keep the exact five-role `.role-list` in the hero. Make its foreground panel readable with an ink background and tested contrast. Do not use the labels embedded in the picture as page content.

The hero keeps the current outcome, `$25,000, once` position, bottleneck-check action, and five-role action.

- [ ] **Step 5: Apply the sticky five-role explanation**

Keep all five `.role-card` elements in normal DOM order. On desktop, place a cropped operating-map image in `.story-visual` and the five role cards in `.story-steps`. The active card receives `.is-active`, but the complete numbered list remains visible in the hero.

At 900px and below, remove sticky positioning and show the five cards as a two-column grid. At 620px and below, show one column.

Keep `#siesie-check`, all five `.audit-check` inputs, `/siesie-application`, the five-step workflow, ownership copy, implementation expectations, and the guarantee unchanged.

- [ ] **Step 6: Run all unit and static tests**

Run: `node --test tests/visual_contract.test.mjs tests/journey.test.mjs`

Run: `python3 tests/site_qa.py --static`

Expected: All tests pass. Siesie has five hero rows, five role cards, and five audit inputs.

- [ ] **Step 7: Commit Siesie**

```bash
git add assets/siesie-hero.jpg tests/visual_contract.test.mjs siesie.html assets/journey.css
git commit -m "feat: show the complete Siesie role story"
```

---

### Task 5: Supporting journey pages and retired-image sweep

**Files:**
- Modify: `tests/visual_contract.test.mjs`
- Modify: `portfolio.html`
- Modify: `free.html`
- Modify: `fit.html`
- Modify: `synkasa-fit.html`
- Modify: `siesie-application.html`
- Modify: `fit-thanks.html`
- Modify: `privacy.html`
- Modify: `terms.html`
- Modify: `blog/index.html`
- Modify: `blog/post.css`
- Modify: `blog/answering-service-vs-ai-receptionist-vs-text-back.html`
- Modify: `blog/do-i-need-a-crm-if-im-a-one-person-business.html`
- Modify: `blog/reply-to-every-lead-in-five-minutes.html`
- Modify: `blog/stop-no-shows-without-being-the-bad-guy.html`
- Modify: `blog/what-automating-a-one-person-business-actually-costs.html`
- Modify: `blog/your-website-doesnt-have-to-be-squarespace.html`

**Interfaces:**
- Consumes: `.compact-hero`, current shared header, current forms, videos, and article markup.
- Produces: One quieter paper-stage treatment for supporting pages, current brand tokens on Blog, Privacy, and Terms, and current photography in active Open Graph metadata.

- [ ] **Step 1: Add the failing supporting-page contract**

```js
const compactPages = [
  "portfolio.html",
  "free.html",
  "fit.html",
  "synkasa-fit.html",
  "siesie-application.html",
  "fit-thanks.html",
];

test("supporting journey pages use the compact hero", () => {
  for (const page of compactPages) {
    assert.match(read(page), /class="hero(?: dark)? compact-hero"/, page);
  }
});

test("active pages do not publish retired image assets", () => {
  const activePages = [
    "index.html",
    "synkasa.html",
    "siesie.html",
    ...compactPages,
    "privacy.html",
    "terms.html",
    "blog/index.html",
  ];
  const retired = /(og-client-catcher|ai-products-cover|og-cover|demo-followup-thread)\.(png|jpg)/;
  for (const page of activePages) {
    assert.doesNotMatch(read(page), retired, page);
  }
});
```

- [ ] **Step 2: Run the supporting-page contract and verify failure**

Run: `node --test --test-name-pattern="supporting|retired" tests/visual_contract.test.mjs`

Expected: FAIL because the compact class is absent and active metadata still references `og-cover.png`.

- [ ] **Step 3: Apply the compact hero**

Add `compact-hero` to the hero section on Proof, Free, Fit, SynKasa fit, Siesie application, and Fit confirmation. The component uses:

```css
.compact-hero {
  min-height: auto;
  padding: clamp(76px, 9vw, 120px) 0 clamp(66px, 8vw, 104px);
  border-bottom: 1px solid var(--line);
  background:
    radial-gradient(circle at 85% 15%, rgba(194, 80, 28, .12), transparent 32%),
    var(--paper);
}
```

Keep form fields, form names, hidden values, validation behavior, confirmation behavior, videos, proof anchors, and free-tool destinations unchanged.

- [ ] **Step 4: Repoint active metadata to current photography**

Use `https://frimpomaasync.com/assets/hero-scene-wide.jpg` for Home, SynKasa, Proof, Free, Fit, SynKasa fit, Fit confirmation, Privacy, Terms, and Blog metadata.

Use `https://frimpomaasync.com/assets/siesie-hero.jpg` for Siesie and the Siesie application.

Update both `og:image` and `twitter:image` where present. Do not delete the old files because retired redirects or external shares may still request them.

- [ ] **Step 5: Bring Blog, Privacy, and Terms onto current tokens**

In `blog/post.css`, `blog/index.html`, `privacy.html`, and `terms.html`, replace the retired palette with:

```css
--paper: #FFFFFF;
--panel: #F8F8F9;
--panel2: #F8F8F9;
--card: #FFFFFF;
--ink: #101426;
--ink-soft: rgba(16, 20, 38, .72);
--muted: rgba(16, 20, 38, .56);
--hairline: rgba(16, 20, 38, .12);
--copper: #C2501C;
--copper-deep: #9D3F16;
```

Use the Iowan Old Style system stack already approved. Keep article content, legal commitments, headings, tables, citations, and URLs intact. Update all active `fsnav.js` cache versions to the same new version after the shared file changes.

- [ ] **Step 6: Run visual contracts and static QA**

Run: `node --test tests/visual_contract.test.mjs tests/journey.test.mjs`

Run: `python3 tests/site_qa.py --static`

Expected: All tests pass and active pages contain no retired image references.

- [ ] **Step 7: Commit the supporting pages**

```bash
git add tests/visual_contract.test.mjs portfolio.html free.html fit.html synkasa-fit.html siesie-application.html fit-thanks.html privacy.html terms.html blog/index.html blog/post.css blog/*.html
git commit -m "feat: unify the supporting page system"
```

---

### Task 6: Copywriting accuracy and humanizer gate

**Files:**
- Create: `tests/content_contract.test.mjs`
- Modify: `index.html`
- Modify: `synkasa.html`
- Modify: `siesie.html`
- Modify: `portfolio.html`
- Modify: `free.html`
- Modify: `fit.html`
- Modify: `synkasa-fit.html`
- Modify: `siesie-application.html`
- Modify: `fit-thanks.html`
- Modify: `privacy.html`
- Modify: `terms.html`
- Modify: `blog/index.html`
- Modify: `blog/*.html`
- Modify: `fsnav.js`
- Modify: `assets/journey.js`
- Modify: `llms.txt`

**Interfaces:**
- Consumes: Approved product facts and the finished page hierarchy.
- Produces: Clear outcome-led public copy with exact prices, guarantee, names, next steps, metadata, form messages, and no banned writing patterns.

- [ ] **Step 1: Write the failing content contract**

```js
import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

const ROOT = fileURLToPath(new URL("..", import.meta.url));
const read = (path) => readFileSync(`${ROOT}/${path}`, "utf8");
const humanFacing = [
  "index.html",
  "synkasa.html",
  "siesie.html",
  "portfolio.html",
  "free.html",
  "fit.html",
  "synkasa-fit.html",
  "siesie-application.html",
  "fit-thanks.html",
  "privacy.html",
  "terms.html",
  "blog/index.html",
  "blog/answering-service-vs-ai-receptionist-vs-text-back.html",
  "blog/do-i-need-a-crm-if-im-a-one-person-business.html",
  "blog/reply-to-every-lead-in-five-minutes.html",
  "blog/stop-no-shows-without-being-the-bad-guy.html",
  "blog/what-automating-a-one-person-business-actually-costs.html",
  "blog/your-website-doesnt-have-to-be-squarespace.html",
  "fsnav.js",
  "assets/journey.js",
  "llms.txt",
];

test("human-facing files contain no banned public copy", () => {
  const banned = [
    /—|–/,
    /built in public/i,
    /\b(?:Codex|Anthropic|OpenAI)\b/i,
    /minority-owned|Black-owned|women-owned|barbershop/i,
    /11\s?pm|midnight|late-night/i,
    /not just .+ it(?:'|’)s /i,
    /contentReference|oaicite|turn\d+(?:search|fetch)/i,
  ];
  for (const path of humanFacing) {
    const text = read(path);
    for (const pattern of banned) {
      assert.doesNotMatch(text, pattern, `${path}: ${pattern}`);
    }
  }
});

test("core offers keep the approved facts", () => {
  const synkasa = read("synkasa.html");
  const siesie = read("siesie.html");
  assert.match(synkasa, /\$555/);
  assert.match(synkasa, /\$1,500/);
  assert.match(synkasa, /\$2,222/);
  assert.match(siesie, /\$25,000/);
  assert.match(`${synkasa}\n${siesie}`, /Live in 7 days, or you don't pay/);
  for (const role of [
    "Scheduling",
    "Money",
    "Coordination",
    "Account management",
    "Reporting",
  ]) {
    assert.match(siesie, new RegExp(role));
  }
});
```

- [ ] **Step 2: Run the content contract and record every failure**

Run: `node --test tests/content_contract.test.mjs`

Expected: FAIL on existing em dashes, en dashes, retired metadata, or old public phrasing. Save the exact failing file list in the task notes before editing.

- [ ] **Step 3: Apply the copywriting pass**

Review one section at a time. Each important section must answer one buyer question:

1. What problem does this fix?
2. What changes for the owner?
3. Can the owner see it work?
4. What does it cost?
5. What happens next?

Keep one main idea per section and one visually dominant action. Preserve verified facts and cut unsupported adjectives. Keep the current actionable tools before the sales close.

Do not imitate Alex Hormozi or Steve Jobs. Apply the requested principles through concrete value, clear risk reversal, demonstration, reduction, and one dominant next step.

- [ ] **Step 4: Run both humanizer passes**

Pass 1:

- Remove every em dash, en dash, emoji, vague authority, inflated claim, negative-parallel phrase, AI vocabulary tell, repeated rule of three, model artifact, and unsupported promise.
- Keep all exact facts, prices, dates, routes, form fields, privacy commitments, and legal statements.

Pass 2:

- Use reference specificity on offer, price, timing, and next-step language.
- Use reader presence where an owner needs to know what happens after a click, form, calculation, or application.
- Do not force tangents or unresolved threads into legal copy, metadata, forms, or reference text.

- [ ] **Step 5: Run the content, visual, and behavior tests**

Run: `node --test tests/content_contract.test.mjs tests/visual_contract.test.mjs tests/journey.test.mjs`

Expected: All tests pass. The content test confirms exact offer facts and no banned public copy.

- [ ] **Step 6: Commit the copy gate**

```bash
git add tests/content_contract.test.mjs index.html synkasa.html siesie.html portfolio.html free.html fit.html synkasa-fit.html siesie-application.html fit-thanks.html privacy.html terms.html blog fsnav.js assets/journey.js llms.txt
git commit -m "fix: sharpen and humanize the customer path"
```

---

### Task 7: Browser, motion, accessibility, and route verification

**Files:**
- Modify: `tests/site_qa.py`
- Modify: `tests/test_preview_server.py`
- Modify: `assets/journey.css`
- Modify: `fsnav.js`
- Modify: relevant HTML files only when a failing browser check exposes a defect

**Interfaces:**
- Consumes: The complete local deployment candidate from Tasks 1 through 6.
- Produces: Automated coverage for images, navigation state, sticky stories, reduced motion, keyboard focus, tools, forms, internal links, clean routes, mobile layout, and browser errors.

- [ ] **Step 1: Extend browser QA with failing visual and motion assertions**

Add these checks to `tests/site_qa.py`:

```python
def assert_image_loaded(page, selector: str) -> None:
    loaded = page.locator(selector).evaluate(
        "(image) => image.complete && image.naturalWidth > 0"
    )
    assert loaded, selector


def assert_reduced_motion(page) -> None:
    page.goto(f"{BASE}/index.html", wait_until="networkidle")
    image = page.locator(".cinematic-media")
    assert image.evaluate(
        "(node) => getComputedStyle(node).animationName"
    ) == "none"
    assert page.locator("#page-leak-tool").is_visible()
```

In the normal browser context:

```python
page.goto(f"{BASE}/index.html", wait_until="networkidle")
assert_image_loaded(page, ".cinematic-media")
assert page.locator("#page-leak-tool").is_visible()
page.evaluate("scrollTo(0, document.querySelector('.paper-rise').offsetTop)")
page.wait_for_timeout(250)
assert page.locator("#fs-nav").evaluate(
    "(node) => node.classList.contains('is-past-hero')"
)

page.goto(f"{BASE}/synkasa.html", wait_until="networkidle")
assert page.locator("[data-story-step]").count() == 4
assert_image_loaded(page, ".cinematic-media")

page.goto(f"{BASE}/siesie.html", wait_until="networkidle")
assert page.locator(".role-row").count() == 5
assert page.locator(".role-card").count() == 5
assert page.locator(".audit-check").count() == 5
assert_image_loaded(page, ".cinematic-media")
```

Create a reduced-motion browser context:

```python
reduced = browser.new_context(
    viewport={"width": 1280, "height": 900},
    reduced_motion="reduce",
)
reduced_page = reduced.new_page()
assert_reduced_motion(reduced_page)
reduced.close()
```

- [ ] **Step 2: Expand responsive coverage**

Test widths 390, 768, 1024, 1280, and 1440 on:

- `/`
- `/synkasa`
- `/siesie`
- `/portfolio`
- `/free`
- `/fit`
- `/synkasa-fit`
- `/siesie-application`
- `/fit-thanks?source=siesie`
- `/privacy`
- `/terms`
- `/blog/`

At each width, assert `document.documentElement.scrollWidth <= innerWidth`. At 390px, assert the hero panel appears after the hero message and every primary control is at least 44px tall.

- [ ] **Step 3: Expand clean-route coverage**

Add `privacy`, `terms`, and `blog/` to `tests/test_preview_server.py`. Keep the existing clean routes. Verify query strings and fragments do not break route resolution.

- [ ] **Step 4: Run the browser suite and fix only observed failures**

Start the preview server:

```bash
python3 -B scripts/preview_server.py --bind 127.0.0.1 --port 4173
```

Run:

```bash
python3 tests/site_qa.py
python3 -m unittest tests/test_preview_server.py
```

Expected: Every route loads, every core interaction works, every image loads, no page has horizontal overflow, reduced motion keeps content usable, and the browser reports no uncaught console or page errors.

For each failure, write or tighten the failing assertion first, then make the smallest HTML, CSS, or JavaScript change that passes it.

- [ ] **Step 5: Run the animation review**

Use the `review-animations` skill against `assets/journey.css` and `fsnav.js`. Confirm:

- One or two key animated groups per view
- Micro-interactions between 140 and 300 milliseconds
- Section entrances at or below 560 milliseconds
- No layout-property animation
- No `transition: all`
- No hover dependency on touch devices
- Reduced motion removes scaling and parallax
- Exit motion is shorter than entrance motion
- Content and controls remain usable during every entrance

Fix every high or medium severity finding. Re-run the browser suite after changes.

- [ ] **Step 6: Run the complete automated suite**

Run:

```bash
node --test tests/*.test.mjs
python3 -m unittest tests/test_preview_server.py
python3 tests/site_qa.py --static
python3 tests/site_qa.py
git diff --check
```

Expected: All Node, Python, static, browser, and diff checks pass.

- [ ] **Step 7: Commit verification**

```bash
git add tests/site_qa.py tests/test_preview_server.py assets/journey.css fsnav.js '*.html' blog
git commit -m "test: verify the cinematic customer journey"
```

---

### Task 8: Ship check and deployment candidate

**Files:**
- Modify: no product file unless verification finds a defect
- Create: local screenshots in `work/site-review/`

**Interfaces:**
- Consumes: The fully committed feature branch.
- Produces: A clean deployment candidate, final review screenshots, exact test evidence, and a separate publish decision.

- [ ] **Step 1: Run the ship-check and verification skills**

Run the required checks from `ship-check` and `superpowers:verification-before-completion`. Do not rely on an earlier test run.

Required commands:

```bash
node --test tests/*.test.mjs
python3 -m unittest tests/test_preview_server.py
python3 tests/site_qa.py --static
python3 tests/site_qa.py
git diff --check
git status --short
git log --oneline -12
```

Expected: All checks pass, the worktree contains no unintended files, and `.superpowers/` remains uncommitted.

- [ ] **Step 2: Capture final review screenshots**

Capture Home, SynKasa, and Siesie at:

- 1440 by 1000 desktop
- 390 by 844 mobile

Capture the Siesie hero with all five roles visible and one reduced-motion homepage state. Save the files under `work/site-review/` and inspect each screenshot for clipping, low contrast, broken media, overlap, or accidental retired content.

- [ ] **Step 3: Present the verified candidate to NaNa Frimpomaa**

Report:

- Exact passing test counts
- Routes tested
- Widths tested
- Motion and reduced-motion result
- Five-role Siesie result
- Original image result
- Any known limitation
- Feature branch and final commit

Stop before updating the live branch.

- [ ] **Step 4: Publish only after explicit approval**

After NaNa Frimpomaa approves the verified candidate:

```bash
git fetch origin
git status --short
git log --oneline --decorate --graph -12
```

Confirm the live branch has not moved unexpectedly. Merge the verified feature branch into the live branch without discarding unrelated work, then push the live branch. Verify the production URLs for Home, SynKasa, Siesie, Proof, Free, both fit forms, Privacy, Terms, and Blog.

If the live branch moved or the merge conflicts, stop and report the exact conflict before changing live files.
