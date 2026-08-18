/* The Siesie Systems Audit.
 *
 * The page makes three promises: the result matches the answers, the published
 * weighting is the weighting actually used, and no statistic is claimed that
 * has no source. These tests hold all three, plus the brand rules and the
 * privacy constraints the assessment has to keep.
 *
 * Run:  node tests/systems_audit.test.mjs
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { SIESIE_AUDIT, DHS_CONFIG } from "../assets/audit-config.js";
import { validateAudit, scoreAudit, summaryText, MAX_POINTS } from "../assets/audit-engine.js";

const C = SIESIE_AUDIT;
const KEYS = C.dimensions.map((d) => d.key);
const all = (points) => Object.fromEntries(KEYS.map((k) => [k, points]));
const read = (p) => readFile(new URL("../" + p, import.meta.url), "utf8");

/* --- the config is valid ------------------------------------------------- */

test("the config passes its own validator", () => {
  const problems = validateAudit(C);
  assert.deepEqual(problems, [], problems.join("\n"));
});

test("twelve questions, five options each, unique keys", () => {
  assert.equal(C.dimensions.length, 12);
  assert.equal(new Set(KEYS).size, 12, "keys unique");
  for (const d of C.dimensions) {
    assert.equal(d.options.length, 5, d.key + " has five options");
    assert.deepEqual(d.options.map((o) => o.points), [0, 1, 2, 3, 4], d.key + " ascends 0 to 4");
    assert.ok(d.weight >= 1 && d.weight <= 4, d.key + " weight in range");
    for (const f of ["name", "question", "strength", "gap", "fix", "day"]) {
      assert.ok(typeof d[f] === "string" && d[f].length > 0, d.key + " has " + f);
    }
  }
});

test("a broken edit is caught rather than shipped", () => {
  const bad = JSON.parse(JSON.stringify(C));
  bad.dimensions[0].weight = 9;
  bad.dimensions[1].options.pop();
  delete bad.dimensions[2].fix;
  bad.dimensions[3].key = bad.dimensions[0].key;
  const problems = validateAudit(bad);
  assert.ok(problems.some((p) => /weight must be/.test(p)), "bad weight named");
  assert.ok(problems.some((p) => /needs exactly 5 options/.test(p)), "missing option named");
  assert.ok(problems.some((p) => /"fix"/.test(p)), "missing fix named");
  assert.ok(problems.some((p) => /used twice/.test(p)), "duplicate key named");
});

/* --- scoring -------------------------------------------------------------- */

test("the extremes score 0 and 100", () => {
  assert.equal(scoreAudit(C, all(0)).score, 0);
  assert.equal(scoreAudit(C, all(MAX_POINTS)).score, 100);
});

test("the score rises with every better answer and never leaves 0 to 100", () => {
  let prev = -1;
  for (let p = 0; p <= MAX_POINTS; p++) {
    const s = scoreAudit(C, all(p)).score;
    assert.ok(s >= 0 && s <= 100, "in range at " + p);
    assert.ok(s > prev, "strictly increases at " + p);
    prev = s;
  }
});

test("a heavier area moves the score more than a lighter one", () => {
  const heavy = C.dimensions.reduce((a, b) => (b.weight > a.weight ? b : a));
  const light = C.dimensions.reduce((a, b) => (b.weight < a.weight ? b : a));
  assert.ok(heavy.weight > light.weight, "the config has a heavy and a light area");

  const base = all(MAX_POINTS);
  const dropHeavy = Object.assign({}, base, { [heavy.key]: 0 });
  const dropLight = Object.assign({}, base, { [light.key]: 0 });
  assert.ok(
    scoreAudit(C, dropHeavy).score < scoreAudit(C, dropLight).score,
    "dropping the heavy area costs more"
  );
});

test("gaps are ranked by weighted loss, not by the order they were asked in", () => {
  const heavy = C.dimensions.reduce((a, b) => (b.weight > a.weight ? b : a));
  const light = C.dimensions.reduce((a, b) => (b.weight < a.weight ? b : a));
  const answers = Object.assign(all(MAX_POINTS), { [light.key]: 0, [heavy.key]: 0 });
  const r = scoreAudit(C, answers);
  assert.equal(r.topGaps[0].key, heavy.key, "the heavy gap leads");
  assert.equal(r.priority.area, heavy.name, "and it is where to start");
});

test("the same answers always produce the same output", () => {
  const answers = Object.fromEntries(KEYS.map((k, i) => [k, i % 5]));
  assert.deepEqual(scoreAudit(C, answers), scoreAudit(C, answers));
});

test("a perfect run still leaves with three things to do", () => {
  const r = scoreAudit(C, all(MAX_POINTS));
  assert.equal(r.plan.length, 3, "three plan steps");
  assert.equal(new Set(r.plan.map((p) => p.area)).size, 3, "three different areas");
  assert.equal(r.topGaps.length, 0, "and no gaps claimed");
  assert.ok(r.priority.action.length > 0, "and somewhere to start");
});

test("a worst-case run names a weakest area and no strength", () => {
  const r = scoreAudit(C, all(0));
  assert.equal(r.strongest, null);
  assert.ok(r.weakest, "a weakest area is named");
  assert.equal(r.topGaps.length, 3, "three gaps surfaced");
  assert.equal(r.plan.length, 3);
});

test("a partial run is scored against what was answered, not marked down", () => {
  const half = Object.fromEntries(KEYS.slice(0, 6).map((k) => [k, MAX_POINTS]));
  const r = scoreAudit(C, half);
  assert.equal(r.score, 100, "six perfect answers is 100, not 50");
  assert.equal(r.answered, 6);
  assert.equal(r.complete, false);
});

test("nonsense answers are ignored rather than scored", () => {
  const r = scoreAudit(C, { [KEYS[0]]: 99, [KEYS[1]]: -3, [KEYS[2]]: "4", [KEYS[3]]: 4 });
  assert.equal(r.answered, 1, "only the one valid answer counted");
  assert.equal(r.score, 100);
});

test("no answers at all does not throw", () => {
  for (const input of [undefined, null, {}]) {
    const r = scoreAudit(C, input);
    assert.equal(r.score, 0);
    assert.equal(r.answered, 0);
    assert.equal(r.plan.length, 0);
  }
});

test("every score from 0 to 100 lands in exactly one band", () => {
  for (let s = 0; s <= 100; s++) {
    const hits = C.bands.filter((b) => s >= b.min && s <= b.max);
    assert.equal(hits.length, 1, "score " + s + " matched " + hits.length + " bands");
  }
  for (let s = 0; s <= 100; s++) {
    const hits = C.areaBands.filter((b) => s >= b.min && s <= b.max);
    assert.equal(hits.length, 1, "area percent " + s + " matched " + hits.length + " bands");
  }
});

test("every band carries a next step, so no result ends without one", () => {
  for (const b of C.bands) {
    assert.ok(b.next && b.next.length > 0, "band " + b.label + " has a next step");
  }
  const r = scoreAudit(C, all(2));
  assert.ok(r.bandNext.length > 0);
});

/* --- the emailed and downloaded summary ---------------------------------- */

test("the summary carries the whole result and wraps for plain text", () => {
  const r = scoreAudit(C, Object.fromEntries(KEYS.map((k, i) => [k, i % 5])));
  const text = summaryText(C, r, { who: "A Reader", when: "2026-08-17" });

  assert.ok(text.includes("Score: " + r.score), "score present");
  assert.ok(text.includes(r.band), "band present");
  assert.ok(text.includes("A Reader"), "who it was prepared for");
  for (const row of r.rows) assert.ok(text.includes(row.name), row.name + " present");
  for (const p of r.plan) assert.ok(text.includes(p.days), p.days + " present");
  // The body is hard-wrapped for plain text, so compare on a flattened copy.
  const flat = text.replace(/\s+/g, " ");
  assert.ok(flat.includes(C.meta.guardBody), "the guard rail travels with it");
  assert.ok(flat.includes(r.priority.action), "and so does where to start");

  for (const line of text.split("\n")) {
    assert.ok(line.length <= 72, "line wraps: " + JSON.stringify(line.slice(0, 80)));
  }
});

test("the summary of an empty run does not throw", () => {
  const r = scoreAudit(C, {});
  assert.ok(summaryText(C, r).includes("Score: 0"));
});

/* --- the promises the page makes ----------------------------------------- */

test("nothing collected or asked for is sensitive", async () => {
  const html = await read("siesie-systems-audit.html");
  const ui = await read("assets/audit-ui.js");
  assert.ok(!/type="password"/i.test(html + ui), "no password field anywhere");

  // The form asks for three things and no more.
  const fields = [...ui.matchAll(/name="([a-z_]+)"/g)].map((m) => m[1]);
  const allowed = new Set(["name", "email", "business", "notes", "q"]);
  for (const f of fields) assert.ok(allowed.has(f), "unexpected field: " + f);

  // The questions are about process. None of them asks for anything about a
  // person, and the Siesie audit has no reason to go near health information.
  const prose = C.dimensions
    .map((d) => [d.question, d.help, ...d.options.map((o) => o.label)].join(" "))
    .join(" ")
    .toLowerCase();
  for (const word of ["patient", "diagnosis", "medical record", "social security", "date of birth", "password", "card number"]) {
    assert.ok(!prose.includes(word), "no “" + word + "” in the questions");
  }
});

test("no statistic is claimed anywhere in the assessment prose", () => {
  const prose = [
    ...C.dimensions.flatMap((d) => [d.question, d.help, d.strength, d.gap, d.fix, d.day]),
    ...C.bands.flatMap((b) => [b.blurb, b.next]),
    C.noGapsNote,
    C.noStrengthNote,
    C.meta.intro,
    C.meta.intro2,
    C.meta.guardBody,
  ].join(" ");

  assert.ok(!/%/.test(prose), "no percentages");
  assert.ok(!/\b\d+\s*(percent|per cent|x\b)/i.test(prose), "no percent or multiple claims");
  assert.ok(!/\$\s?\d/.test(prose), "no money figures in the diagnostic prose");
  for (const phrase of ["studies show", "research shows", "on average", "industry average", "most businesses", "up to"]) {
    assert.ok(!prose.toLowerCase().includes(phrase), "no “" + phrase + "”");
  }
});

test("no promise the assessment cannot keep", () => {
  const prose = [
    ...C.dimensions.flatMap((d) => [d.strength, d.gap, d.fix, d.day]),
    ...C.bands.flatMap((b) => [b.blurb, b.next]),
    C.cta.body,
    C.meta.intro,
    C.meta.intro2,
  ].join(" ").toLowerCase();
  for (const phrase of ["guarantee", "guaranteed", "will double", "will save you", "risk-free", "instantly"]) {
    assert.ok(!prose.includes(phrase), "no “" + phrase + "”");
  }
});

test("the result always says what it is not", () => {
  assert.ok(C.meta.guardTitle && C.meta.guardBody.length > 60, "a guard rail exists");
  assert.ok(/not a valuation/i.test(C.meta.guardBody), "it names what it is not");
});

test("the call to action points at pages that exist", async () => {
  const links = [C.cta.primary.href, ...C.cta.secondary.map((s) => s.href)];
  for (const href of links) {
    assert.ok(href.startsWith("/"), href + " is a same-site link");
    await read(href.replace(/^\//, "") + ".html"); // throws if the page is missing
  }
});

test("the price on the result matches the price on the site", async () => {
  const map = await read("operations-map.html");
  assert.ok(C.cta.eyebrow.includes("$2,500"), "the map price is stated");
  assert.ok(map.includes("$2,500"), "and it is the price the map page carries");
  assert.ok(/credited in full/i.test(C.cta.body), "and the credit is stated");
});

/* --- brand rules ---------------------------------------------------------- */

test("no em dashes and no emojis in anything a visitor reads", async () => {
  const files = [
    "assets/audit-config.js",
    "assets/audit-engine.js",
    "assets/audit-ui.js",
    "assets/systems-audit.css",
    "siesie-systems-audit.html",
  ];
  for (const f of files) {
    const src = await read(f);
    assert.ok(!/—/.test(src), "no em dashes in " + f);
    assert.ok(!/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/u.test(src), "no emojis in " + f);
  }
});

test("her name is written in full wherever it appears", async () => {
  for (const f of ["assets/audit-config.js", "audit-lead.php", "siesie-systems-audit.html"]) {
    const src = await read(f);
    const loose = src.match(/\bNana\b(?! Frimpongmaa)/g) || [];
    assert.deepEqual(loose, [], "full name only in " + f);
  }
});

test("no vendor, host or tool is named in anything a visitor reads", async () => {
  const visible = (await read("assets/audit-config.js")) + (await read("siesie-systems-audit.html"));
  for (const vendor of ["Hostinger", "Formspree", "OpenAI", "Anthropic", "Claude", "Zapier", "Twilio", "Stripe"]) {
    assert.ok(!visible.includes(vendor), "no “" + vendor + "” in visitor-facing files");
  }
});

/* --- the page wiring ------------------------------------------------------ */

test("the page loads every file it depends on", async () => {
  const html = await read("siesie-systems-audit.html");
  for (const asset of [
    "/assets/systems-audit.css",
    "/assets/audit-config.js",
    "/assets/audit-ui.js",
  ]) {
    assert.ok(html.includes(asset), "page references " + asset);
  }
  const ui = await read("assets/audit-ui.js");
  assert.ok(ui.includes("/assets/audit-engine.js"), "the interface imports the engine");
  assert.ok(html.includes("<noscript>"), "there is a path for a visitor with scripting off");
});

test("the published weighting table is generated, never typed", async () => {
  const html = await read("siesie-systems-audit.html");
  assert.ok(html.includes("SIESIE_AUDIT.dimensions.map"), "weights come from the config");
});

test("the endpoint the interface posts to is the one that exists", async () => {
  const php = await read("audit-lead.php");
  assert.equal(C.lead.endpoint, "/audit-lead.php");
  assert.equal(DHS_CONFIG.lead.endpoint, "/audit-lead.php");
  assert.ok(php.includes("'siesie-systems-audit'"), "the endpoint knows this assessment");
  assert.ok(php.includes("'denial-health-score'"), "and the other one");
  assert.ok(php.includes("FILTER_VALIDATE_EMAIL"), "the email is validated server side");
  assert.ok(php.includes("$trap"), "the honeypot is checked");
  assert.ok(php.includes("audit_rate_ok"), "the endpoint is rate limited");
});

test("a name cannot write its own mail headers", async () => {
  const php = await read("audit-lead.php");
  // The name reaches the Subject line. If the filter leaves a carriage return
  // or a line feed in it, that name can add headers of its own.
  assert.ok(
    php.includes("preg_replace('/[\\x00-\\x1F\\x7F]/'"),
    "the short fields are stripped of every control character, newlines included"
  );
  assert.ok(
    !/\$clean = function[\s\S]{0,300}\\x0E-\\x1F/.test(php),
    "the filter does not carve out the newline range"
  );
});

test("both lead forms carry every string the interface reads", () => {
  for (const [label, lead] of [["siesie", C.lead], ["dhs", DHS_CONFIG.lead]]) {
    for (const k of ["title", "body", "consent", "button", "sending", "done", "partial", "failed",
                     "nameLabel", "emailLabel", "businessLabel", "businessHint", "endpoint"]) {
      assert.ok(typeof lead[k] === "string" && lead[k].length > 0, label + " lead has " + k);
    }
  }
});

test("the result is never held back behind the form", async () => {
  const ui = await read("assets/audit-ui.js");
  const result = ui.slice(ui.indexOf("function renderResult"), ui.indexOf("function wireResult"));
  assert.ok(result.indexOf("sya-scoreblock") < result.indexOf("leadFormHTML"),
    "the score renders before the form is even built");
  assert.ok(/print|download/i.test(result), "and it can be kept without giving an email");
});
