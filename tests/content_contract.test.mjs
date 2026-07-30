import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
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
    // Em dashes never ship. En dashes only survive inside numeric ranges
    // such as "$97-$497" or "10-20 hrs", never as sentence punctuation.
    /—/,
    /(?<![\d$])\s*–|–\s*(?![\d$])/,
    /built in public|building in public|documented in public/i,
    /\b(?:Codex|Anthropic|OpenAI|Claude)\b/i,
    /minority-owned|Black-owned|women-owned|barbershop/i,
    /11\s?pm|midnight|late-night/i,
    /not just .+ it(?:'|’)s /i,
    /contentReference|oaicite|turn\d+(?:search|fetch)/i,
    // Emoji are banned. Typographic glyphs (arrows, checkmarks, middots) stay.
    /\p{Extended_Pictographic}|\u{FE0F}/u,
  ];
  for (const path of humanFacing) {
    const text = read(path);
    for (const pattern of banned) {
      assert.doesNotMatch(text, pattern, `${path}: ${pattern}`);
    }
  }
});

test("every human-facing page writes the full name", () => {
  for (const path of humanFacing) {
    const text = read(path);
    const bare = text.match(/NaNa(?! Frimpomaa)/g) || [];
    assert.equal(bare.length, 0, `${path}: ${bare.length} bare first-name uses`);
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
  assert.match(read("assets/journey.css"), /\.compact-hero \{/);
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

test("journey and reference pages share one palette", () => {
  const retiredPalette =
    /#(?:C9975B|c9975b|1A1A1A|1a1a1a|8A8479|8a8479|A87938|a87938|8F6329|8f6329|E8E4DD|e8e4dd|F6F5F3|f6f5f3|FAFAF8|fafaf8|3D3A35|3d3a35|6F6A5E|6f6a5e)\b/;
  const onCurrentTokens = [
    "privacy.html",
    "terms.html",
    "404.html",
    "blog/post.css",
    ...readdirSync(`${ROOT}/blog`)
      .filter((name) => name.endsWith(".html"))
      .map((name) => `blog/${name}`),
  ];
  for (const page of onCurrentTokens) {
    assert.doesNotMatch(read(page), retiredPalette, page);
  }
  // The pages that declare a palette rather than inherit one from post.css.
  for (const page of ["privacy.html", "terms.html", "404.html", "blog/post.css"]) {
    assert.match(read(page), /--ink:\s*#101426/i, page);
    assert.match(read(page), /--copper:\s*#C2501C/i, page);
  }
});

test("every page requests one shared fsnav.js version", () => {
  const pages = [
    ...readdirSync(ROOT).filter((name) => name.endsWith(".html")),
    ...readdirSync(`${ROOT}/blog`)
      .filter((name) => name.endsWith(".html"))
      .map((name) => `blog/${name}`),
  ];
  const versions = new Set();
  for (const page of pages) {
    for (const hit of read(page).matchAll(/fsnav\.js\?v=([^"']+)/g)) {
      versions.add(hit[1]);
    }
  }
  assert.equal(versions.size, 1, `fsnav versions in use: ${[...versions]}`);
});

test("the SynKasa calculator ships a default that matches its own formula", () => {
  const html = read("synkasa.html");
  const field = (id) =>
    Number(
      html
        .match(new RegExp(`id="${id}"[^>]*value="(\\d+)"`))[1],
    );
  const shown = (id) =>
    html.match(new RegExp(`id="${id}"[^>]*>([^<]+)<`))[1].trim();

  const weekly = field("calc-inquiries");
  const missed = field("calc-missed");
  const value = field("calc-value");
  const booking = field("calc-booking");
  const atRisk = Number((weekly * 4 * (missed / 100)).toFixed(1));
  const amount = Math.round(atRisk * (booking / 100) * value);

  assert.equal(shown("calc-at-risk"), String(atRisk));
  assert.equal(shown("calc-output"), `$${amount.toLocaleString("en-US")}`);
  assert.equal(
    shown("calc-formula"),
    `${weekly} × 4 weeks × ${missed}% at risk × ${booking}% booked × ` +
      `$${value.toLocaleString("en-US")}`,
  );
});

test("the retired guarantee variants stay retired", () => {
  for (const path of humanFacing) {
    const text = read(path);
    assert.doesNotMatch(text, /48[- ]hours?, or you don't pay/i, path);
    assert.doesNotMatch(text, /guarantee\w*\s+\d+\s+bookings/i, path);
  }
});
