/* The Denial Health Score.
 *
 * A process diagnostic, not a compliance determination. Every question asks
 * about how denied claims are handled as work, never about a patient, a claim,
 * a diagnosis or a regulatory position. Nothing here evaluates HIPAA, predicts
 * recovery, or produces a legal or compliance conclusion, and the copy is
 * written so it cannot be read as one.
 *
 * The module is deliberately separate from the page so the scoring can be
 * tested directly. tests/denial_health_score.test.mjs exercises it across
 * every answer combination that matters, including all-lowest and all-highest.
 *
 * Scoring shape:
 *   12 questions, one per dimension. Each answer carries 0 to 4 points.
 *   Dimensions carry different weights because the gaps are not equally
 *   expensive: losing a claim to an unnoticed deadline costs the whole claim,
 *   while thin reporting costs visibility. Weights are published on the page,
 *   so a buyer can check the arithmetic rather than trust it.
 */

export const DIMENSIONS = [
  {
    key: "ownership",
    name: "Denial ownership",
    weight: 3,
    question: "When a denial arrives, who owns it until it is resolved?",
    help: "Answer for the usual case, not the best case.",
    options: [
      { points: 0, label: "Nobody in particular. Denials are worked when someone has time." },
      { points: 1, label: "The billing team as a group, with no named person per claim." },
      { points: 2, label: "A named person owns denials, but not claim by claim." },
      { points: 3, label: "Each denial has a named owner, tracked somewhere shared." },
      { points: 4, label: "Each denial has a named owner and a named next action, both visible to others." }
    ],
    strength: "Every denial has a person attached to it, so nothing waits on someone noticing it.",
    gap: "Denials without a named owner tend to move only when someone happens to look. That is the most common way a workable claim ages out.",
    fix: "Assign a named owner to every denial at the moment it arrives, even if the owner is the same person every time. Ownership on paper beats ownership by assumption.",
    day: "Pick one person to own every denial that arrives this week and write their name next to each one."
  },
  {
    key: "deadlines",
    name: "Deadline tracking",
    weight: 4,
    question: "How do you know when a denied claim is running out of time?",
    help: "Deadlines vary by payer, plan and claim circumstance. This asks how you track them, not what they are.",
    options: [
      { points: 0, label: "We do not track deadlines on denied claims." },
      { points: 1, label: "People generally know the common ones from memory." },
      { points: 2, label: "Deadlines are noted on some claims, inconsistently." },
      { points: 3, label: "Every denied claim gets a date recorded when it is reviewed." },
      { points: 4, label: "Every denied claim carries a recorded date, and something surfaces it before it passes." }
    ],
    strength: "Time-sensitive claims surface before the window closes rather than after.",
    gap: "A deadline nobody recorded is a deadline nobody can meet. This is the single most expensive gap on the list, because a missed window closes the claim regardless of how strong the argument was.",
    fix: "Record a date on every denied claim when it is first reviewed, and put those dates somewhere that shows you what is closest. Confirm each one against the applicable payer, plan and claim circumstances rather than a remembered rule.",
    day: "Take your open denials, write a review-by date on each one, and sort the list by that date. Look at the top three."
  },
  {
    key: "evidence",
    name: "Submission evidence",
    weight: 4,
    question: "If a payer said it never received a claim or an appeal, what could you produce?",
    help: "This is about what your records can prove, not about any specific dispute.",
    options: [
      { points: 0, label: "Nothing we could locate quickly." },
      { points: 1, label: "We could probably reconstruct it from several places." },
      { points: 2, label: "Acceptance records exist for most submissions, if someone goes looking." },
      { points: 3, label: "Submission date and channel are recorded for each claim." },
      { points: 4, label: "Date, channel and what was sent are recorded at submission time, in one place." }
    ],
    strength: "Your records can answer a receipt dispute without a search.",
    gap: "Evidence that has to be reconstructed after a dispute is evidence you may not have. Timely-filing denials in particular stand or fall entirely on what you can produce.",
    fix: "Capture the submission record at the moment of submission: date, channel and what was sent. Retrieval later is the hard version of a task that takes seconds at the time.",
    day: "Pick your last five submissions and see how long it takes to produce proof for each. However long that took is your answer."
  },
  {
    key: "pathway",
    name: "Appeal and correction routing",
    weight: 3,
    question: "How do you decide whether a denial needs a correction or an appeal?",
    help: "Both are legitimate. The question is whether the choice is deliberate.",
    options: [
      { points: 0, label: "We resubmit and hope, or we appeal everything." },
      { points: 1, label: "It depends who picks it up." },
      { points: 2, label: "There is a general approach, mostly held in people's heads." },
      { points: 3, label: "We follow a consistent approach based on the denial reason." },
      { points: 4, label: "The reason drives the route, and the reasoning is written on the claim." }
    ],
    strength: "The route follows the denial reason, so effort lands where it can work.",
    gap: "Appealing a data error is slow and answers nothing. Resubmitting a medical-necessity denial meets the same determination again. When the route is not deliberate, both happen.",
    fix: "Sort denials by what the payer actually said before deciding anything. Data problems get corrections, determinations get appeals, and unclear ones get investigated before either.",
    day: "Take ten recent denials and sort them into correct, appeal, or find out more. Notice how many were in the wrong pile."
  },
  {
    key: "priority",
    name: "Prioritization",
    weight: 3,
    question: "How do you decide which denial to work first?",
    options: [
      { points: 0, label: "Whatever is on top, or whatever someone asks about." },
      { points: 1, label: "Oldest first." },
      { points: 2, label: "Largest dollar amount first." },
      { points: 3, label: "A mix of value and timing." },
      { points: 4, label: "Value, timing and whether the claim is actually ready to move." }
    ],
    strength: "Sequencing accounts for value, timing and readiness rather than one of the three.",
    gap: "Age alone puts the oldest claim first even when it is unworkable. Value alone ignores the clock. Either way the queue does not reflect what matters this week.",
    fix: "Rank by three things together: what the claim is worth, when it needs attention, and whether the information to move it exists yet.",
    day: "Re-rank your current denial list by value, timing and readiness. Compare the new top five to the old top five."
  },
  {
    key: "visibility",
    name: "Unresolved-denial visibility",
    weight: 3,
    question: "Can you see every unresolved denial in one place right now?",
    options: [
      { points: 0, label: "No, and pulling the list would take real work." },
      { points: 1, label: "It exists across several systems and spreadsheets." },
      { points: 2, label: "One list exists but it is not always current." },
      { points: 3, label: "One current list of unresolved denials exists." },
      { points: 4, label: "One current list, and it shows status and owner for each." }
    ],
    strength: "One current list answers what is outstanding without an exercise.",
    gap: "Denials spread across systems cannot be counted, which means the total is unknown. An unknown total is very hard to argue for staffing or attention.",
    fix: "Build one list of unresolved denials with status and owner on each row. It does not need to be a new system. It needs to be one list.",
    day: "Make the list, however roughly. One row per unresolved denial, with a status and a name."
  },
  {
    key: "payer",
    name: "Payer-response tracking",
    weight: 3,
    question: "After you submit an appeal or a corrected claim, what happens next?",
    options: [
      { points: 0, label: "We find out when the remittance arrives, or we do not." },
      { points: 1, label: "Someone checks when they remember to." },
      { points: 2, label: "We check periodically, without a set schedule." },
      { points: 3, label: "Each submission gets a follow-up date." },
      { points: 4, label: "Each submission gets a follow-up date and the payer's response is recorded against the claim." }
    ],
    strength: "Submitted work is tracked to a response rather than assumed to be handled.",
    gap: "A submission with no follow-up date is a claim that can sit indefinitely without anyone noticing. The work was already done. The result is what goes missing.",
    fix: "Put a follow-up date on every submission at the moment it goes out, and record what the payer says when it answers.",
    day: "Go through everything submitted in the last 60 days and give each one a follow-up date."
  },
  {
    key: "clientside",
    name: "Internal action ownership",
    weight: 2,
    question: "When a denial needs something from clinical, coding or the front office, what happens?",
    options: [
      { points: 0, label: "It usually stalls there." },
      { points: 1, label: "Someone asks by email or in person and hopes for a reply." },
      { points: 2, label: "Requests are made and followed up informally." },
      { points: 3, label: "The request is recorded against the claim with a named person." },
      { points: 4, label: "Recorded with a named person and a date, and visible until it comes back." }
    ],
    strength: "Requests to other departments stay visible until they are answered.",
    gap: "Claims waiting on someone else are the quietest way work stops. Nothing is wrong, nobody is blocked, and nothing moves.",
    fix: "Record every request to another department against the claim, with a person and a date, and keep it visible until the answer arrives.",
    day: "Find every denial currently waiting on someone internal. Write down who and since when."
  },
  {
    key: "documentation",
    name: "Documentation readiness",
    weight: 3,
    question: "When a payer requests supporting documentation, how quickly can you produce it?",
    options: [
      { points: 0, label: "It is a real project each time." },
      { points: 1, label: "Days, and it depends who is available." },
      { points: 2, label: "Usually within a couple of days." },
      { points: 3, label: "Same day, most of the time." },
      { points: 4, label: "Same day, by anyone who needs to, without asking a specific person." }
    ],
    strength: "Requested documentation can be produced quickly by more than one person.",
    gap: "When retrieval depends on one person's knowledge, the response time depends on their calendar. Documentation requests carry their own clocks.",
    fix: "Write down where each type of supporting documentation lives and how to retrieve it, so retrieval does not depend on who is in that day.",
    day: "Time yourself retrieving supporting documentation for one recent denial. Write down every place you had to look."
  },
  {
    key: "rootcause",
    name: "Root-cause review",
    weight: 3,
    question: "Do you look at why the same denials keep happening?",
    options: [
      { points: 0, label: "No. We work them as they come." },
      { points: 1, label: "We notice patterns informally." },
      { points: 2, label: "We review categories occasionally." },
      { points: 3, label: "We review denial reasons regularly." },
      { points: 4, label: "We review regularly and changes get made upstream as a result." }
    ],
    strength: "Recurring denial reasons reach the people who can stop them recurring.",
    gap: "Working denials without reviewing causes means the same category arrives again next month. Recovery is the expensive way to solve a problem prevention would have handled.",
    fix: "Once a month, count your denials by reason and look at the top three. Send the top one to whoever owns that step upstream.",
    day: "Count last month's denials by reason. The largest category is your first upstream conversation."
  },
  {
    key: "reconciliation",
    name: "Recovery reconciliation",
    weight: 3,
    question: "When money comes in on a worked denial, do you connect the payment back to the claim?",
    options: [
      { points: 0, label: "No. Payments land and we move on." },
      { points: 1, label: "Sometimes, when someone checks." },
      { points: 2, label: "For larger claims." },
      { points: 3, label: "Yes, for most worked denials." },
      { points: 4, label: "Yes, and we can say what recovery work produced over a period." }
    ],
    strength: "Recovered payments are tied back to the work that produced them.",
    gap: "Without reconciliation, you cannot tell which effort produced money and which did not, and a partial payment can be mistaken for a resolved claim.",
    fix: "Match each recovered payment back to the denial it came from, and check that the amount is the amount you expected.",
    day: "Take five recently paid denials and confirm the amount received matches what was expected. Note any that did not."
  },
  {
    key: "reporting",
    name: "Reporting",
    weight: 2,
    question: "If leadership asked what denials cost you last quarter, how long would the answer take?",
    options: [
      { points: 0, label: "We could not answer with confidence." },
      { points: 1, label: "Several days of pulling numbers together." },
      { points: 2, label: "A day or so." },
      { points: 3, label: "An hour, from existing reports." },
      { points: 4, label: "It is already reported, by category and by payer." }
    ],
    strength: "The denial picture can be reported without assembling it first.",
    gap: "A number that takes days to produce does not get produced, so denials stay invisible at the level where staffing and priorities are decided.",
    fix: "Produce one monthly summary: denied value, top reasons, top payers, and what was recovered. Keep it short enough that it actually gets produced.",
    day: "Write the one-page version for last month, even if some numbers are estimates. Mark the estimates."
  }
];

export const MAX_POINTS = 4;

/* Bands. The language is operational on purpose: no compliance verdicts, no
   grades, nothing a reader could mistake for a regulatory or legal opinion. */
export const BANDS = [
  { min: 0, max: 39, label: "Significant process opportunity", blurb: "Most of the structure that keeps denied claims moving is not in place yet. That is a common starting point, and it means the first few changes tend to be the ones that matter most." },
  { min: 40, max: 59, label: "Visibility gap", blurb: "Work is happening, but too much of it depends on individual memory rather than something written down. The usual symptom is that the answer to where is this claim depends on who you ask." },
  { min: 60, max: 79, label: "Needs attention in places", blurb: "The process holds up in most areas. The weak points are specific rather than general, which makes them straightforward to close one at a time." },
  { min: 80, max: 100, label: "Operational strength", blurb: "The fundamentals are in place. What is left is refinement, and the highest-value work is usually upstream prevention rather than recovery mechanics." }
];

export const AREA_BANDS = [
  { min: 0, max: 25, label: "Process opportunity" },
  { min: 26, max: 50, label: "Needs attention" },
  { min: 51, max: 75, label: "Working" },
  { min: 76, max: 100, label: "Operational strength" }
];

function bandFor(list, pct) {
  for (const b of list) if (pct >= b.min && pct <= b.max) return b;
  return list[list.length - 1];
}

/* answers: { dimensionKey: pointsAwarded }. Unanswered dimensions are excluded
   from both the earned and the available totals, so a partial run is scored
   honestly against what was actually answered rather than penalized. */
export function scoreDenialHealth(answers) {
  const rows = [];
  let earned = 0;
  let available = 0;

  for (const d of DIMENSIONS) {
    const raw = answers ? answers[d.key] : undefined;
    if (typeof raw !== "number" || raw < 0 || raw > MAX_POINTS) continue;
    const points = Math.round(raw);
    earned += points * d.weight;
    available += MAX_POINTS * d.weight;
    const pct = Math.round((points / MAX_POINTS) * 100);
    rows.push({
      key: d.key,
      name: d.name,
      points,
      weight: d.weight,
      percent: pct,
      band: bandFor(AREA_BANDS, pct).label,
      note: points >= 3 ? d.strength : d.gap
    });
  }

  const answered = rows.length;
  const score = available === 0 ? 0 : Math.round((earned / available) * 100);
  const band = bandFor(BANDS, score);

  /* Ranking. Gaps are ordered by how much weighted score each one is costing,
     so a heavy dimension answered poorly always outranks a light one. Ties
     break on DIMENSIONS order, which keeps the output stable across runs. */
  const byLoss = rows
    .map((r, i) => ({ r, i, loss: (MAX_POINTS - r.points) * r.weight }))
    .sort((a, b) => b.loss - a.loss || a.i - b.i)
    .map((x) => x.r);

  const byStrength = rows
    .map((r, i) => ({ r, i, gain: r.points * r.weight }))
    .sort((a, b) => b.gain - a.gain || a.i - b.i)
    .map((x) => x.r);

  const gaps = byLoss.filter((r) => r.points <= 2);
  const strengths = byStrength.filter((r) => r.points >= 3);
  const topGaps = gaps.slice(0, 3);
  const spec = (key) => DIMENSIONS.find((d) => d.key === key);

  /* The seven-day plan is built from the ranked gaps, then topped up with the
     lightest-touch actions from the remaining dimensions if there are fewer
     than three gaps. A strong result still gets a usable plan. */
  const planSource = topGaps.slice();
  for (const r of byLoss) {
    if (planSource.length >= 3) break;
    if (!planSource.some((x) => x.key === r.key)) planSource.push(r);
  }
  const plan = planSource.map((r, i) => ({
    days: i === 0 ? "Days 1 to 2" : i === 1 ? "Days 3 to 4" : "Days 5 to 7",
    area: r.name,
    action: spec(r.key).day
  }));

  return {
    score,
    answered,
    total: DIMENSIONS.length,
    complete: answered === DIMENSIONS.length,
    band: band.label,
    bandBlurb: band.blurb,
    strongest: strengths.length ? strengths[0] : null,
    weakest: byLoss.length ? byLoss[0] : null,
    topGaps,
    priority: topGaps.length
      ? { area: topGaps[0].name, action: spec(topGaps[0].key).fix }
      : {
          area: byLoss[0] ? byLoss[0].name : "Prevention",
          action: byLoss[0] && byLoss[0].points < MAX_POINTS
            ? spec(byLoss[0].key).fix
            : "The recovery mechanics are in place. The next gain is usually upstream: take your largest denial category and change the step that produces it."
        },
    recommendations: byLoss.filter((r) => r.points <= 3).map((r) => ({ area: r.name, action: spec(r.key).fix })),
    plan,
    rows
  };
}
