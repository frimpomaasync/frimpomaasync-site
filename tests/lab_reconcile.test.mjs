/* Recovery Lab reconciliation. The Lab page computes every displayed figure
   from its LAB_CLAIMS array; this test holds that array to the numbers the
   published sample assessment states in prose. If either side changes alone,
   this fails. */
import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const labHtml = await readFile(new URL("../soft-appeals-recovery-lab.html", import.meta.url), "utf8");
const sampleHtml = await readFile(new URL("../soft-appeals-sample-assessment.html", import.meta.url), "utf8");

const start = labHtml.indexOf("var LAB_CLAIMS = [");
const end = labHtml.indexOf("];", start);
assert.ok(start > -1 && end > start, "LAB_CLAIMS array found in the Lab page");
const CLAIMS = new Function("return " + labHtml.slice(start + "var LAB_CLAIMS = ".length, end + 1))();

const sum = (list) => list.reduce((t, c) => t + c.amount, 0);
const by = (key, val) => CLAIMS.filter((c) => c[key] === val);

test("portfolio matches the published totals", () => {
  assert.equal(CLAIMS.length, 20);
  assert.equal(sum(CLAIMS), 14850);
});

test("disposition buckets match the published executive summary", () => {
  for (const [action, count, value] of [
    ["Appeal", 7, 6400],
    ["Correct / resubmit", 5, 2750],
    ["Investigate", 4, 3100],
    ["Close / deprioritize", 4, 2600],
  ]) {
    const list = by("action", action);
    assert.equal(list.length, count, action + " count");
    assert.equal(sum(list), value, action + " value");
  }
});

test("priority attention matches: 3 claims, $4,150", () => {
  const a = by("priority", "A");
  assert.equal(a.length, 3);
  assert.equal(sum(a), 4150);
});

test("the six published claims are reproduced exactly", () => {
  const expect = {
    "SA-001": ["Sample Health Plan", 1850, "Authorization"],
    "SA-002": ["Sample Health Plan", 1425, "Medical necessity"],
    "SA-003": ["Example Insurance", 875, "Timely filing"],
    "SA-004": ["Example Insurance", 640, "Claim information"],
    "SA-005": ["Sample Health Plan", 525, "Documentation"],
    "SA-006": ["Example Insurance", 185, "Duplicate"],
  };
  for (const [id, [payer, amount, category]] of Object.entries(expect)) {
    const c = CLAIMS.find((x) => x.id === id);
    assert.ok(c, id + " exists");
    assert.equal(c.payer, payer, id + " payer");
    assert.equal(c.amount, amount, id + " amount");
    assert.equal(c.category, category, id + " category");
  }
});

test("pattern counts match the published observations", () => {
  assert.equal(by("category", "Authorization").length, 4);
  assert.equal(by("category", "Documentation").length, 3);
  assert.equal(by("category", "Claim information").length, 5);
});

test("published action-item owners hold: 003/005/008/011 with the client", () => {
  for (const id of ["SA-003", "SA-005", "SA-008", "SA-011"]) {
    const c = CLAIMS.find((x) => x.id === id);
    assert.equal(c.owner, "Your team", id);
    assert.equal(c.status, "Additional information needed", id);
  }
});

test("ids are SA-001 through SA-020, unique", () => {
  const ids = CLAIMS.map((c) => c.id).sort();
  assert.deepEqual(ids, Array.from({ length: 20 }, (_, i) => "SA-" + String(i + 1).padStart(3, "0")));
});

test("every status is one of the twelve canonical statuses", () => {
  const canon = ["Received", "Under review", "Additional information needed", "Correction recommended",
    "Appeal recommended", "Client review", "Approved for submission", "Submitted", "Payer follow-up",
    "Additional review or escalation", "Recovered", "Closed"];
  for (const c of CLAIMS) {
    assert.ok(canon.includes(c.status), c.id + " status: " + c.status);
    for (const [step] of c.history) assert.ok(canon.includes(step), c.id + " history step: " + step);
  }
});

test("every claim record is complete", () => {
  for (const c of CLAIMS) {
    for (const f of ["payer", "category", "action", "actionLabel", "priority", "status", "owner", "next", "timing", "readiness", "happened", "reviewed", "missing"]) {
      assert.ok(typeof c[f] === "string" && c[f].length > 0, c.id + " has " + f);
    }
    assert.ok(Array.isArray(c.why) && c.why.length >= 3, c.id + " has reasoning factors");
    assert.ok(Array.isArray(c.history) && c.history.length >= 2, c.id + " has status history");
    assert.equal(c.history[c.history.length - 1][0], c.status, c.id + " history ends at its current status");
  }
});

test("the sample assessment still states the figures the Lab reconciles to", () => {
  for (const s of ["$14,850", "$6,400", "$2,750", "$3,100", "$2,600", "$4,150", "20 claims"]) {
    assert.ok(sampleHtml.includes(s), "sample page states " + s);
  }
});

test("no probability, projection or banned language in the Lab", () => {
  const lower = labHtml.toLowerCase();
  for (const phrase of ["winnable", "dead claim", "found money", "free audit", "small practice", "independent practice", "win rate", "win probability", "guaranteed recovery", "recovery rate"]) {
    assert.ok(!lower.includes(phrase), "no “" + phrase + "”");
  }
  const probs = lower.split("probability").length - 1;
  const negated = (lower.match(/no probability|not a probability|probability score would|a probability score/g) || []).length;
  assert.ok(probs <= negated, "probability appears only in its own refusal");
  assert.ok(!/—/.test(labHtml), "no em dashes");
});
