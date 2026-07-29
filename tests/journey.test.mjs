import test from "node:test";
import assert from "node:assert/strict";
import {
  calculateMonthlyOpportunity,
  getFitConfirmation,
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
  assert.deepEqual(
    calculateMonthlyOpportunity({
      weeklyInquiries: -2,
      missedPercent: 160,
      bookingPercent: -4,
      averageJobValue: -50,
    }),
    {
      amount: 0,
      inquiriesAtRisk: 0,
      formula: "0 × 4 weeks × 100% at risk × 0% booked × $0",
    },
  );
});

test("routes a back-office leak to Siesie with a same-day action", () => {
  const result = getLeakRecommendation("backoffice");
  assert.equal(result.path, "siesie");
  assert.equal(result.proofPath, "/portfolio#operations-proof");
  assert.match(result.action, /handoff/i);
});

test("falls back to the missed-inquiry path for an unknown leak", () => {
  assert.equal(getLeakRecommendation("unknown").path, "synkasa");
});

test("routes four owner-dependent roles to the Siesie application", () => {
  const result = getSiesieRecommendation(4);
  assert.equal(result.path, "siesie-application");
  assert.match(result.label, /4 of 5/i);
});

test("clamps the Siesie role count to five", () => {
  assert.match(getSiesieRecommendation(12).label, /5 of 5/i);
});

test("returns source-specific confirmation copy", () => {
  assert.match(getFitConfirmation("synkasa").heading, /inquiry path/i);
  assert.match(getFitConfirmation("siesie").heading, /back office/i);
  assert.equal(getFitConfirmation("unknown").source, "fit");
});
