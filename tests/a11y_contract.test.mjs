import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { fileURLToPath } from "node:url";

const ROOT = fileURLToPath(new URL("..", import.meta.url));
const read = (path) => readFileSync(`${ROOT}/${path}`, "utf8");

function pages(dir = "", depth = 0) {
  if (depth > 2) return [];
  const out = [];
  for (const entry of readdirSync(`${ROOT}/${dir}` || ROOT)) {
    if (entry.startsWith(".") || entry === "node_modules" || entry === "tests") continue;
    const rel = dir ? `${dir}/${entry}` : entry;
    if (statSync(`${ROOT}/${rel}`).isDirectory()) out.push(...pages(rel, depth + 1));
    else if (entry.endsWith(".html")) out.push(rel);
  }
  return out;
}

const openTags = (html, name) =>
  [...html.matchAll(new RegExp(`<${name}\\b[^>]*>`, "gi"))].map((m) => m[0]);

test("every visible form control carries a label a screen reader can read", () => {
  const orphans = [];
  for (const page of pages()) {
    const html = read(page);
    const labels = [...html.matchAll(/<label\b[^>]*>[\s\S]*?<\/label>/gi)].map((m) => m[0]);
    const labelFor = new Set(
      labels
        .map((b) => (b.match(/<label\b[^>]*>/i)[0].match(/\sfor\s*=\s*["']([^"']+)["']/i) || [])[1])
        .filter(Boolean)
    );
    for (const name of ["input", "select", "textarea"]) {
      for (const tag of openTags(html, name)) {
        const type = (tag.match(/\stype\s*=\s*["']([^"']*)["']/i) || [])[1] || "";
        if (/^(hidden|submit|button)$/i.test(type) || /\shidden(?=[\s/>])/i.test(tag)) continue;
        const id = (tag.match(/\sid\s*=\s*["']([^"']+)["']/i) || [])[1];
        const named =
          /aria-label(?:ledby)?\s*=/.test(tag) ||
          (id && labelFor.has(id)) ||
          labels.some((b) => b.includes(tag));
        if (!named) orphans.push(`${page}: ${tag.slice(0, 70)}`);
      }
    }
  }
  assert.deepEqual(orphans, [], `form controls with no accessible name:\n${orphans.join("\n")}`);
});

test("the focus ring stays solid enough to see", () => {
  const css = read("assets/journey.css");
  const rule = css.match(/:focus-visible\s*\{[^}]*\}/);
  assert.ok(rule, "journey.css must keep a :focus-visible rule");
  assert.ok(
    /outline:\s*3px solid var\(--copper\)/.test(rule[0]),
    "the focus outline must be solid --copper; a faded ring measured about 1.7:1 and fails the 3:1 minimum"
  );
  assert.ok(
    !/outline:[^;]*rgba\([^)]*0?\.[0-8]\d*\s*\)/.test(rule[0]),
    "no low-alpha focus outline"
  );
});

test("the shared chrome ships the skip link and its own hidden-text styles", () => {
  const nav = read("fsnav.js");
  assert.match(nav, /skip-link/, "fsnav must inject a skip link");
  assert.match(nav, /Skip to the main content/, "the skip link needs its visible wording");
  // 14 pages carry fsnav without journey.css, so the styles must live in fsnav.
  assert.match(nav, /\.sr-only\{position:absolute/, "fsnav must define .sr-only itself");
  assert.match(nav, /\.skip-link:focus\{position:fixed/, "fsnav must define the focused skip link");
});

test("the chat demo stays operable without a mouse", () => {
  const nav = read("fsnav.js");
  assert.match(nav, /id="fs-chat-x" aria-label="Close chat"/, "the close control needs a real name");
  assert.match(nav, /aria-label="Your message to the front desk"/, "the chat input needs a name");
  assert.match(nav, /panel\.setAttribute\("role", "dialog"\)/, "the panel must announce itself");
  assert.match(nav, /aria-live="polite"/, "replies must be announced as they arrive");
  assert.match(nav, /e\.key === "Escape"/, "Escape must close the chat");
  assert.match(nav, /fab\.focus\(\)/, "closing must hand the keyboard back to the launcher");
  assert.match(nav, /aria-expanded/, "the launcher must report open or closed");
});

test("the tier a customer picked reaches the fit form", () => {
  const synkasa = read("synkasa.html");
  for (const tier of ["start", "grow", "full"]) {
    assert.match(
      synkasa,
      new RegExp(`href="/synkasa-fit\\?tier=${tier}"`),
      `the ${tier} card must pass its tier through`
    );
  }
  assert.match(read("synkasa-fit.html"), /name="tier"[^>]*data-tier-field/);
  const journey = read("assets/journey.js");
  assert.match(journey, /TIER_LABELS\s*=\s*\{ start: "Start", grow: "Grow", full: "Full" \}/);
  assert.match(journey, /bindTierFields\(\)/);
});

test("every proof video on a reachable page carries a caption track", () => {
  // services, products and client-catcher all 301 to /synkasa in production,
  // so their players are unreachable and exempt.
  for (const page of ["portfolio.html", "synkasa.html"]) {
    const html = read(page);
    const players = [...html.matchAll(/<video\b[\s\S]*?<\/video>/gi)].map((m) => m[0]);
    assert.ok(players.length, `${page} should still hold its proof videos`);
    for (const player of players) {
      const src = (player.match(/\/videos\/([\w-]+)\.mp4/) || [])[1];
      assert.ok(src, `a player in ${page} lost its source`);
      assert.match(
        player,
        new RegExp(`<track kind="captions" src="/videos/captions/${src}\\.en\\.vtt"`),
        `${src} on ${page} has no caption track`
      );
      readFileSync(`${ROOT}/videos/captions/${src}.en.vtt`, "utf8");
    }
  }
});

test("caption files stay inside their video and keep the house style", () => {
  const lengths = { "01-receptionist": 35.8, "03-soma": 47.0, "04-som": 46.85, "05-tracker": 46.53 };
  for (const [name, seconds] of Object.entries(lengths)) {
    const vtt = read(`videos/captions/${name}.en.vtt`);
    assert.match(vtt, /^WEBVTT/, `${name} must start with the WEBVTT header`);
    const stamps = [...vtt.matchAll(/(\d\d):(\d\d):(\d\d\.\d\d\d) --> (\d\d):(\d\d):(\d\d\.\d\d\d)/g)];
    assert.ok(stamps.length >= 10, `${name} looks too thin at ${stamps.length} cues`);
    let previousEnd = 0;
    for (const s of stamps) {
      const from = +s[2] * 60 + +s[3];
      const to = +s[5] * 60 + +s[6];
      assert.ok(to > from, `${name} has a cue that ends before it starts`);
      assert.ok(from >= previousEnd - 0.001, `${name} has overlapping cues at ${s[0]}`);
      assert.ok(to <= seconds + 0.1, `${name} runs a cue past the end of the video`);
      previousEnd = to;
    }
    assert.ok(!/[—–]/.test(vtt), `${name} contains a dash that is not allowed`);
    assert.ok(
      !/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/u.test(vtt),
      `${name} contains an emoji`
    );
    for (const banned of ["Claude", "Anthropic", "minority-owned", "women-owned", "Black-owned"]) {
      assert.ok(!vtt.includes(banned), `${name} names "${banned}"`);
    }
  }
});

test("no reachable page jumps a heading level", () => {
  const redirected = new Set(["services.html", "products.html", "client-catcher.html"]);
  const jumps = [];
  for (const page of pages()) {
    if (redirected.has(page) || /http-equiv="refresh"/.test(read(page))) continue;
    const heads = [...read(page).matchAll(/<h([1-6])\b/gi)].map((m) => +m[1]);
    let previous = 0;
    for (const level of heads) {
      if (previous && level > previous + 1) jumps.push(`${page}: h${previous} to h${level}`);
      previous = level;
    }
  }
  assert.deepEqual(jumps, [], `heading levels skipped:\n${jumps.join("\n")}`);
});
