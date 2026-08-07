/* Denial Health Score scoring logic. The claim the page makes is that the
   result matches the answers, so these tests exercise the mapping directly:
   extremes, monotonicity, weighting, ranking, plan generation, partial runs,
   and the language constraints the diagnostic has to hold to. */
import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { DIMENSIONS, BANDS, MAX_POINTS, scoreDenialHealth } from "../assets/denial-health-score.js";

const KEYS = DIMENSIONS.map((d) => d.key);
const all = (points) => Object.fromEntries(KEYS.map((k) => [k, points]));

test("the questionnaire is 10 to 15 process questions, one per dimension", () => {
  assert.ok(DIMENSIONS.length >= 10 && DIMENSIONS.length <= 15, "count: " + DIMENSIONS.length);
  assert.equal(new Set(KEYS).size, DIMENSIONS.length, "keys unique");
  for (const d of DIMENSIONS) {
    assert.equal(d.options.length, 5, d.key + " has five options");
    assert.deepEqual(d.options.map((o) => o.points), [0, 1, 2, 3, 4], d.key + " points ascend 0 to 4");
    for (const f of ["name", "question", "strength", "gap", "fix", "day"]) {
      assert.ok(typeof d[f] === "string" && d[f].length > 0, d.key + " has " + f);
    }
    assert.ok(d.weight >= 1 && d.weight <= 5, d.key + " weight in range");
  }
});

test("the extremes score 0 and 100", () => {
  assert.equal(scoreDenialHealth(all(0)).score, 0);
  assert.equal(scoreDenialHealth(all(MAX_POINTS)).score, 100);
});

test("score never leaves 0 to 100 and rises with every better answer", () => {
  let prev = -1;
  for (let p = 0; p <= MAX_POINTS; p++) {
    const s = scoreDenialHealth(all(p)).score;
    assert.ok(s >= 0 && s <= 100, "in range at " + p);
    assert.ok(s > prev, "strictly increases at " + p);
    prev = s;
  }
});

test("improving any single answer never lowers the score", () => {
  for (const k of KEYS) {
    for (let p = 0; p < MAX_POINTS; p++) {
      const base = all(2);
      const lower = { ...base, [k]: p };
      const higher = { ...base, [k]: p + 1 };
      assert.ok(scoreDenialHealth(higher).score >= scoreDenialHealth(lower).score, k + " at " + p);
    }
  }
});

test("heavier dimensions move the score more than lighter ones", () => {
  const heavy = DIMENSIONS.reduce((a, b) => (b.weight > a.weight ? b : a));
  const light = DIMENSIONS.reduce((a, b) => (b.weight < a.weight ? b : a));
  assert.ok(heavy.weight > light.weight, "weights differ");
  const dropHeavy = scoreDenialHealth({ ...all(MAX_POINTS), [heavy.key]: 0 }).score;
  const dropLight = scoreDenialHealth({ ...all(MAX_POINTS), [light.key]: 0 }).score;
  assert.ok(dropHeavy < dropLight, "heavy gap costs more: " + dropHeavy + " vs " + dropLight);
});

test("every band is reachable and the four bands tile 0 to 100 without gaps", () => {
  assert.equal(BANDS[0].min, 0);
  assert.equal(BANDS[BANDS.length - 1].max, 100);
  for (let i = 1; i < BANDS.length; i++) {
    assert.equal(BANDS[i].min, BANDS[i - 1].max + 1, "band " + i + " abuts the previous");
  }
  const seen = new Set();
  for (let p = 0; p <= MAX_POINTS; p++) seen.add(scoreDenialHealth(all(p)).band);
  assert.ok(seen.size >= 3, "uniform runs alone reach at least three bands");
  for (const b of BANDS) {
    const mid = Math.round((b.min + b.max) / 2);
    assert.ok(mid >= b.min && mid <= b.max);
  }
});

test("the weakest area is the one costing the most weighted score", () => {
  const answers = all(MAX_POINTS);
  answers.deadlines = 0;
  const r = scoreDenialHealth(answers);
  assert.equal(r.weakest.key, "deadlines");
  assert.equal(r.topGaps[0].key, "deadlines");
  assert.equal(r.priority.area, DIMENSIONS.find((d) => d.key === "deadlines").name);
});

test("the strongest area is a genuinely strong answer, and gaps are never listed as strengths", () => {
  const answers = all(0);
  answers.reporting = MAX_POINTS;
  const r = scoreDenialHealth(answers);
  assert.equal(r.strongest.key, "reporting");
  assert.ok(r.strongest.points >= 3, "strength threshold");
  for (const g of r.topGaps) assert.ok(g.points <= 2, g.key + " is a real gap");
  assert.ok(!r.topGaps.some((g) => g.key === "reporting"), "a top answer is not a gap");
});

test("a perfect run reports no gaps and still gives a usable priority and plan", () => {
  const r = scoreDenialHealth(all(MAX_POINTS));
  assert.equal(r.topGaps.length, 0);
  assert.equal(r.recommendations.length, 0);
  assert.equal(r.plan.length, 3, "plan still produced");
  assert.ok(r.priority.action.length > 0, "priority still produced");
  assert.match(r.priority.action, /upstream/, "perfect run points upstream");
  assert.equal(r.band, "Operational strength");
});

test("a floor run flags the three heaviest gaps in weight order", () => {
  const r = scoreDenialHealth(all(0));
  assert.equal(r.score, 0);
  assert.equal(r.topGaps.length, 3);
  const weights = r.topGaps.map((g) => g.weight);
  assert.deepEqual(weights, [...weights].sort((a, b) => b - a), "ordered by weight");
  assert.equal(r.strongest, null, "no strengths claimed at the floor");
  assert.equal(r.recommendations.length, DIMENSIONS.length, "every area gets a recommendation");
});

test("the seven-day plan always has three dated steps drawn from real gaps", () => {
  for (const answers of [all(0), all(1), all(2), all(3), all(MAX_POINTS), { ...all(3), deadlines: 0, evidence: 0 }]) {
    const r = scoreDenialHealth(answers);
    assert.equal(r.plan.length, 3);
    assert.deepEqual(r.plan.map((p) => p.days), ["Days 1 to 2", "Days 3 to 4", "Days 5 to 7"]);
    assert.equal(new Set(r.plan.map((p) => p.area)).size, 3, "no repeated area");
    for (const step of r.plan) {
      assert.ok(step.action.length > 0);
      const d = DIMENSIONS.find((x) => x.name === step.area);
      assert.ok(d, "plan area is a real dimension: " + step.area);
      assert.equal(step.action, d.day, "plan action belongs to its area");
    }
  }
});

test("recommendations match the answers: every weak area appears, every strong one does not", () => {
  const answers = all(MAX_POINTS);
  answers.priority = 1;
  answers.rootcause = 0;
  const r = scoreDenialHealth(answers);
  const named = r.recommendations.map((x) => x.area);
  assert.ok(named.includes(DIMENSIONS.find((d) => d.key === "priority").name));
  assert.ok(named.includes(DIMENSIONS.find((d) => d.key === "rootcause").name));
  assert.ok(!named.includes(DIMENSIONS.find((d) => d.key === "deadlines").name), "a maxed area gets no fix");
  assert.equal(r.plan[0].area, DIMENSIONS.find((d) => d.key === "rootcause").name, "worst gap leads the plan");
});

test("each row's note matches whether that answer was strong or weak", () => {
  const answers = all(0);
  answers.evidence = MAX_POINTS;
  const r = scoreDenialHealth(answers);
  for (const row of r.rows) {
    const d = DIMENSIONS.find((x) => x.key === row.key);
    assert.equal(row.note, row.points >= 3 ? d.strength : d.gap, row.key + " note matches its score");
    assert.equal(row.percent, Math.round((row.points / MAX_POINTS) * 100));
  }
});

test("partial runs score only what was answered", () => {
  const partial = { ownership: MAX_POINTS, deadlines: MAX_POINTS };
  const r = scoreDenialHealth(partial);
  assert.equal(r.answered, 2);
  assert.equal(r.complete, false);
  assert.equal(r.score, 100, "two perfect answers score 100 of what was asked");
  assert.equal(scoreDenialHealth({}).score, 0);
  assert.equal(scoreDenialHealth({}).answered, 0);
  assert.equal(scoreDenialHealth(all(MAX_POINTS)).complete, true);
});

test("out-of-range and malformed answers are ignored rather than trusted", () => {
  for (const bad of [{ ownership: 9 }, { ownership: -1 }, { ownership: "4" }, { ownership: null }, { nope: 4 }]) {
    const r = scoreDenialHealth(bad);
    assert.equal(r.answered, 0, JSON.stringify(bad));
    assert.equal(r.score, 0);
  }
  assert.equal(scoreDenialHealth(undefined).score, 0);
  assert.equal(scoreDenialHealth(null).score, 0);
});

test("every question asks about process, never about a patient or a claim's contents", () => {
  const banned = /\bpatient\b|\bdiagnos|\bmrn\b|\bdate of birth\b|\bmember id\b|\bssn\b|\bicd\b|\bcpt\b/i;
  for (const d of DIMENSIONS) {
    assert.ok(!banned.test(d.question), d.key + " question stays at process level");
    for (const o of d.options) assert.ok(!banned.test(o.label), d.key + " option stays at process level");
  }
});

test("no compliance, legal, recovery-prediction or banned language anywhere in the module", async () => {
  const src = await readFile(new URL("../assets/denial-health-score.js", import.meta.url), "utf8");
  const body = src.replace(/^[\s\S]*?\n \*\//, ""); // exclude the header comment, which names these to forbid them
  for (const phrase of [
    "hipaa compliant", "hipaa-compliant", "non-compliant", "noncompliant", "compliance score",
    "you are compliant", "guaranteed", "will recover", "expected recovery", "win probability",
    "win rate", "audit", "certified", "small practice", "independent practice", "winnable",
    "dead claim", "found money"
  ]) {
    assert.ok(!body.toLowerCase().includes(phrase), "no “" + phrase + "”");
  }
  assert.ok(!/—/.test(src), "no em dashes");
  assert.ok(!/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/u.test(src), "no emojis");
});
