/* The assessment engine.
 *
 * Pure scoring. No DOM, no network, no globals. Everything here can be run
 * under plain node, which is what tests/systems_audit.test.mjs does.
 *
 * The engine is driven entirely by a config object from /assets/audit-config.js,
 * so a new assessment is a new config rather than new code.
 *
 * SCORING MODEL
 * -------------
 *   Each dimension asks one question. The chosen answer is worth 0 to 4 points.
 *   Each dimension carries a weight of 1 to 4, because the gaps are not equally
 *   expensive: work that stops entirely when the owner is away costs more than
 *   a follow-up email that never goes out.
 *
 *   earned    = sum over answered dimensions of (points * weight)
 *   available = sum over answered dimensions of (4 * weight)
 *   score     = round(earned / available * 100)
 *
 *   Unanswered dimensions are left out of BOTH totals. A partial run is scored
 *   honestly against what was actually answered rather than being punished for
 *   the questions that were never reached.
 *
 *   Gaps are ranked by weighted loss, (4 - points) * weight, so a heavy area
 *   answered badly always outranks a light one. Ties break on config order,
 *   which keeps the same answers producing the same output every time.
 */

export const MAX_POINTS = 4;

/* An area counts as a strength at 3 or 4 points, and as a gap at 0, 1 or 2.
   Three is "we do this deliberately". Two is "sometimes, if someone looks". */
export const STRENGTH_AT = 3;
export const GAP_AT = 2;

function bandFor(list, pct) {
  for (const b of list) if (pct >= b.min && pct <= b.max) return b;
  return list[list.length - 1];
}

/* ---------------------------------------------------------------------------
   validateAudit
   Checks a config from audit-config.js is still usable after an edit. Returns
   an array of plain-English problems. An empty array means the config is fine.
   The test suite fails on any problem, so a bad edit is caught before it ships.
   --------------------------------------------------------------------------- */
export function validateAudit(config) {
  const problems = [];
  const say = (m) => problems.push(m);

  if (!config || typeof config !== "object") return ["The config is missing entirely."];
  if (!Array.isArray(config.dimensions) || config.dimensions.length === 0) {
    return ["The config has no dimensions array, so there are no questions to ask."];
  }

  const seen = new Set();
  config.dimensions.forEach((d, i) => {
    const at = `Question ${i + 1}${d && d.name ? ` (${d.name})` : ""}`;
    if (!d.key) say(`${at}: has no key.`);
    else if (seen.has(d.key)) say(`${at}: the key "${d.key}" is used twice. Keys must be unique.`);
    else seen.add(d.key);

    if (!d.name) say(`${at}: has no name.`);
    if (!d.question) say(`${at}: has no question text.`);
    if (!Number.isInteger(d.weight) || d.weight < 1 || d.weight > 4) {
      say(`${at}: weight must be a whole number from 1 to 4, found ${JSON.stringify(d.weight)}.`);
    }
    for (const field of ["strength", "gap", "fix", "day"]) {
      if (!d[field]) say(`${at}: is missing its "${field}" text.`);
    }

    if (!Array.isArray(d.options) || d.options.length !== MAX_POINTS + 1) {
      say(`${at}: needs exactly ${MAX_POINTS + 1} options, found ${d.options ? d.options.length : 0}.`);
      return;
    }
    d.options.forEach((o, j) => {
      if (o.points !== j) say(`${at}: option ${j + 1} should be worth ${j} points, found ${JSON.stringify(o.points)}.`);
      if (!o.label) say(`${at}: option ${j + 1} has no label.`);
    });
  });

  for (const [listName, list] of [["bands", config.bands], ["areaBands", config.areaBands]]) {
    if (!Array.isArray(list) || list.length === 0) {
      say(`The ${listName} list is missing.`);
      continue;
    }
    if (list[0].min !== 0) say(`The first ${listName} entry must start at 0.`);
    if (list[list.length - 1].max !== 100) say(`The last ${listName} entry must end at 100.`);
    for (let i = 1; i < list.length; i++) {
      if (list[i].min !== list[i - 1].max + 1) {
        say(`The ${listName} ranges have a hole or an overlap between ${list[i - 1].max} and ${list[i].min}.`);
      }
    }
  }

  if (!config.meta || !config.meta.title) say("The config has no meta.title, so the first screen has no heading.");
  if (!config.cta || !config.cta.primary) say("The config has no cta.primary, so the result has no next step.");

  return problems;
}

/* ---------------------------------------------------------------------------
   scoreAudit
   answers: { dimensionKey: pointsAwarded }
   --------------------------------------------------------------------------- */
export function scoreAudit(config, answers) {
  const dims = config.dimensions;
  const rows = [];
  let earned = 0;
  let available = 0;

  for (const d of dims) {
    const raw = answers ? answers[d.key] : undefined;
    if (typeof raw !== "number" || !isFinite(raw) || raw < 0 || raw > MAX_POINTS) continue;
    const points = Math.round(raw);
    earned += points * d.weight;
    available += MAX_POINTS * d.weight;
    const percent = Math.round((points / MAX_POINTS) * 100);
    rows.push({
      key: d.key,
      name: d.name,
      points,
      weight: d.weight,
      percent,
      band: bandFor(config.areaBands, percent).label,
      note: points >= STRENGTH_AT ? d.strength : d.gap,
    });
  }

  const answered = rows.length;
  const score = available === 0 ? 0 : Math.round((earned / available) * 100);
  const band = bandFor(config.bands, score);
  const spec = (key) => dims.find((d) => d.key === key);

  const byLoss = rows
    .map((r, i) => ({ r, i, loss: (MAX_POINTS - r.points) * r.weight }))
    .sort((a, b) => b.loss - a.loss || a.i - b.i)
    .map((x) => x.r);

  const byStrength = rows
    .map((r, i) => ({ r, i, gain: r.points * r.weight }))
    .sort((a, b) => b.gain - a.gain || a.i - b.i)
    .map((x) => x.r);

  const gaps = byLoss.filter((r) => r.points <= GAP_AT);
  const strengths = byStrength.filter((r) => r.points >= STRENGTH_AT);
  const topGaps = gaps.slice(0, 3);

  /* The seven-day plan is built from the ranked gaps, then topped up from the
     rest of the ranked list when there are fewer than three gaps, so a strong
     result still leaves with three things to do rather than a compliment. */
  const planSource = topGaps.slice();
  for (const r of byLoss) {
    if (planSource.length >= 3) break;
    if (!planSource.some((x) => x.key === r.key)) planSource.push(r);
  }
  const plan = planSource.map((r, i) => ({
    days: i === 0 ? "Days 1 to 2" : i === 1 ? "Days 3 to 4" : "Days 5 to 7",
    area: r.name,
    action: spec(r.key).day,
  }));

  const priority = topGaps.length
    ? { area: topGaps[0].name, action: spec(topGaps[0].key).fix }
    : {
        area: byLoss.length ? byLoss[0].name : "Prevention",
        action:
          byLoss.length && byLoss[0].points < MAX_POINTS
            ? spec(byLoss[0].key).fix
            : config.noGapsNote,
      };

  return {
    id: config.id,
    score,
    answered,
    total: dims.length,
    complete: answered === dims.length,
    band: band.label,
    bandBlurb: band.blurb,
    bandNext: band.next || "",
    strongest: strengths.length ? strengths[0] : null,
    weakest: byLoss.length ? byLoss[0] : null,
    topGaps,
    priority,
    recommendations: byLoss
      .filter((r) => r.points <= STRENGTH_AT)
      .map((r) => ({ area: r.name, action: spec(r.key).fix })),
    plan,
    rows,
  };
}

/* ---------------------------------------------------------------------------
   summaryText
   One plain-text page. This is what gets downloaded as a .txt file and what
   gets posted to the server when somebody asks for it by email, so the email,
   the download and the screen can never say three different things.
   --------------------------------------------------------------------------- */
export function summaryText(config, result, opts = {}) {
  const name = config.meta.appName;
  const line = "".padEnd(66, "-");
  const out = [];
  const add = (s = "") => out.push(s);

  add(name.toUpperCase());
  add(line);
  if (opts.who) add(`Prepared for: ${opts.who}`);
  if (opts.when) add(`Date: ${opts.when}`);
  add(`Score: ${result.score} out of 100`);
  add(`Band: ${result.band}`);
  add(`Answered: ${result.answered} of ${result.total} questions`);
  add();
  add(wrap(result.bandBlurb));
  add();

  add("STRONGEST AND WEAKEST");
  add(line);
  add(
    result.strongest
      ? `Strongest: ${result.strongest.name}\n${wrap(result.strongest.note)}`
      : `Strongest: none yet.\n${wrap(config.noStrengthNote)}`
  );
  add();
  if (result.weakest) {
    add(`Weakest: ${result.weakest.name}`);
    add(wrap(result.weakest.note));
  }
  add();

  if (result.topGaps.length) {
    add("TOP GAPS, IN THE ORDER THEY COST YOU MOST");
    add(line);
    result.topGaps.forEach((g, i) => {
      add(`${i + 1}. ${g.name} (${g.band})`);
      add(wrap(g.note));
      add();
    });
  }

  add("WHERE TO START");
  add(line);
  add(result.priority.area);
  add(wrap(result.priority.action));
  add();

  add("SEVEN-DAY PLAN");
  add(line);
  result.plan.forEach((p) => {
    add(`${p.days} · ${p.area}`);
    add(wrap(p.action));
    add();
  });

  add("EVERY AREA, SCORED");
  add(line);
  result.rows.forEach((r) => {
    add(`${pad(r.name, 30)} ${pad(String(r.percent) + "%", 5)} ${r.band} (weight ${r.weight})`);
  });
  add();

  if (result.bandNext) {
    add("WHAT THIS POINTS TO");
    add(line);
    add(wrap(result.bandNext));
    add();
  }

  add(config.meta.guardTitle.toUpperCase());
  add(line);
  add(wrap(config.meta.guardBody));
  add();
  add("frimpomaasync.com");

  return out.join("\n");
}

function pad(s, n) {
  s = String(s);
  return s.length >= n ? s : s + "".padEnd(n - s.length, " ");
}

/* Hard wrap at 66 columns so the text reads the same in a plain-text email,
   in a terminal, and in a downloaded file that nobody reflows. */
function wrap(s, width = 66) {
  const words = String(s).split(/\s+/);
  const lines = [];
  let cur = "";
  for (const w of words) {
    if (!cur.length) cur = w;
    else if ((cur + " " + w).length <= width) cur += " " + w;
    else {
      lines.push(cur);
      cur = w;
    }
  }
  if (cur.length) lines.push(cur);
  return lines.join("\n");
}
