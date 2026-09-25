const FALLBACK_EMAIL = "hello@frimpomaasync.com";

const clamp = (value, min, max) =>
  Math.min(max, Math.max(min, Number(value) || 0));

export function calculateMonthlyOpportunity(input) {
  const weeklyInquiries = Math.max(0, Number(input.weeklyInquiries) || 0);
  const missedPercent = clamp(input.missedPercent, 0, 100);
  const bookingPercent = clamp(input.bookingPercent, 0, 100);
  const averageJobValue = Math.max(
    0,
    Number(input.averageJobValue) || 0,
  );
  const inquiriesAtRisk = Number(
    (weeklyInquiries * 4 * (missedPercent / 100)).toFixed(1),
  );
  const amount = Math.round(
    inquiriesAtRisk * (bookingPercent / 100) * averageJobValue,
  );

  return {
    amount,
    inquiriesAtRisk,
    formula:
      `${weeklyInquiries} × 4 weeks × ${missedPercent}% at risk × ` +
      `${bookingPercent}% booked × $${averageJobValue.toLocaleString()}`,
  };
}

const leakRecommendations = {
  missed: {
    heading: "Your front desk is the first leak.",
    action:
      "Turn on an instant missed-call text today. Ask one question and offer two times.",
    path: "synkasa",
    proofPath: "/portfolio#synkasa-proof",
  },
  followup: {
    heading: "The first answer happens, then the lead goes quiet.",
    action:
      "Give every inquiry a dated next action. A lead without a date is already slipping.",
    path: "synkasa",
    proofPath: "/portfolio#synkasa-proof",
  },
  noshow: {
    heading: "The booking is made, but the reminder is carrying too little.",
    action:
      "Send a confirmation immediately and a reminder the night before. Ask for a one-word reply.",
    path: "synkasa",
    proofPath: "/portfolio#synkasa-proof",
  },
  backoffice: {
    heading: "The work has outgrown the owner's memory.",
    action:
      "Write the handoff for the process that interrupts you most. Choose the software after the process is clear.",
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
      action:
        "Write the trigger, owner, next step, and finish line. Use Free or SynKasa if the leak starts with inquiries.",
      path: "free",
    };
  }

  if (total <= 3) {
    return {
      label: `${total} of 5 roles depend on you`,
      heading: "Map the handoff that touches the most people.",
      action:
        "Start with the process that crosses customers, crew, suppliers, or money. Review Siesie once that handoff is clear.",
      path: "siesie",
    };
  }

  return {
    label: `${total} of 5 roles depend on you`,
    heading: "Your back office is still waiting on you.",
    action:
      "Four or five roles depend on the owner. The detailed Siesie application is the right next step.",
    path: "siesie-application",
  };
}

/* The call is gone (her decision, 2026-09-25). After the fit form come five
   quick questions, then a written proposal within 24 hours. The five-question
   screen and the confirmation both read the source so the wording matches the
   path the visitor took. */
const fitConfirmations = {
  synkasa: {
    source: "synkasa",
    heading: "Your inquiry path is in. Five quick questions next.",
    body:
      "Answer these and I write your SynKasa proposal within 24 hours. No call needed.",
    q5: "What happens when you miss a call?",
    q5hint: "Be honest. This is the leak the whole build is sized around.",
    then:
      "The proposal holds what I would build, what stays human, the $555 price, and a payment link. You pay, I build. Live in 7 days, or you don't pay.",
  },
  siesie: {
    source: "siesie",
    heading: "Your back office is in. Five quick questions next.",
    body:
      "Answer these and I write your Operations Map proposal within 24 hours. No call needed.",
    q5: "Which handoff gets stuck on you most?",
    q5hint: "Be honest. This is the piece the map starts from.",
    then:
      "The proposal holds what I saw, what the Operations Map covers, the $2,500 price, and a payment link. The map is credited in full against the build. A price for the full build comes only after the map.",
  },
  fit: {
    source: "fit",
    heading: "Your form is in. Five quick questions next.",
    body:
      "Answer these and I write your proposal within 24 hours. No call needed.",
    q5: "What happens when you miss a call?",
    q5hint: "Be honest. This is the leak the whole build is sized around.",
    then:
      "The proposal holds what I would build, what it costs, and a payment link. You pay, I build. Live in 7 days, or you don't pay.",
  },
};

export function getFitConfirmation(source) {
  return fitConfirmations[source] || fitConfirmations.fit;
}

const proposalConfirmations = {
  synkasa: {
    then:
      "What I would build, what stays human, the $555 price, and a payment link. You pay, I build. Live in 7 days, or you don't pay.",
  },
  siesie: {
    then:
      "What I saw, what the Operations Map covers, the $2,500 price, and a payment link. The map is credited in full against the build.",
  },
  fit: {
    then:
      "What I would build, what stays human, the price, and a payment link. You pay, I build. Live in 7 days, or you don't pay.",
  },
};

export function getProposalConfirmation(input) {
  const source = proposalConfirmations[input.source] ? input.source : "fit";
  const name = (input.name || "").trim();
  const business = (input.business || "").trim();
  const heading = name ? `Thank you, ${name}. Your proposal is on its way.` : "Thank you. Your proposal is on its way.";
  const body = business
    ? `I write the plan for ${business} myself and send it within 24 hours. No call needed.`
    : "I write the plan myself and send it within 24 hours. No call needed.";
  return { heading, body, then: proposalConfirmations[source].then };
}

function pathDetails(path) {
  const paths = {
    synkasa: { href: "/synkasa", label: "See SynKasa →" },
    siesie: { href: "/siesie", label: "Review Siesie →" },
    "siesie-application": {
      href: "/siesie-application",
      label: "Start the Siesie application →",
    },
    free: { href: "/free", label: "Take a free fix →" },
  };
  return paths[path] || paths.free;
}

function bindLeakFinder() {
  const output = document.querySelector("#leak-output");
  const choices = Array.from(document.querySelectorAll("[data-leak]"));
  if (!output || choices.length === 0) return;

  const heading = document.querySelector("#leak-heading");
  const action = document.querySelector("#leak-action");
  const proofLink = document.querySelector("#leak-proof");
  const pathLink = document.querySelector("#leak-path");

  choices.forEach((choice) => {
    choice.addEventListener("click", () => {
      choices.forEach((item) => {
        item.classList.remove("is-selected");
        item.setAttribute("aria-pressed", "false");
      });
      choice.classList.add("is-selected");
      choice.setAttribute("aria-pressed", "true");

      const result = getLeakRecommendation(choice.dataset.leak);
      const nextPath = pathDetails(result.path);
      heading.textContent = result.heading;
      action.textContent = result.action;
      proofLink.href = result.proofPath;
      pathLink.href = nextPath.href;
      pathLink.textContent = nextPath.label;
      output.classList.add("is-ready");
    });
  });
}

function bindCalculator() {
  const form = document.querySelector("#opportunity-form");
  if (!form) return;

  const inquiries = document.querySelector("#calc-inquiries");
  const missed = document.querySelector("#calc-missed");
  const value = document.querySelector("#calc-value");
  const booking = document.querySelector("#calc-booking");
  const amount = document.querySelector("#calc-output");
  const atRisk = document.querySelector("#calc-at-risk");
  const formula = document.querySelector("#calc-formula");
  const error = document.querySelector("#calc-error");
  const button = document.querySelector("#calc-button");
  const inputs = [inquiries, missed, value, booking];

  const render = () => {
    const result = calculateMonthlyOpportunity({
      weeklyInquiries: inquiries.value,
      missedPercent: missed.value,
      bookingPercent: booking.value,
      averageJobValue: value.value,
    });
    amount.textContent = `$${result.amount.toLocaleString()}`;
    atRisk.textContent = result.inquiriesAtRisk.toLocaleString();
    formula.textContent = result.formula;
    error.textContent = "";
  };

  // Name the actual problem. "Add all four numbers" was shown even when all
  // four were there and one was simply out of range.
  const describeProblem = () => {
    if (inputs.some((field) => field.value.trim() === "")) {
      return "Add all four numbers so the formula can use your scenario.";
    }
    if (inputs.some((field) => Number.isNaN(Number(field.value)))) {
      return "Use plain numbers, with no letters or symbols.";
    }
    if ([missed, booking].some((field) => {
      const percent = Number(field.value);
      return percent < 0 || percent > 100;
    })) {
      return "The two percentages run from 0 to 100.";
    }
    if ([inquiries, value].some((field) => Number(field.value) < 0)) {
      return "Inquiries and job value cannot be less than zero.";
    }
    return "";
  };

  // Never leave a previous answer on screen beside an error.
  const holdResult = () => {
    amount.textContent = "$0";
    atRisk.textContent = "0";
    formula.textContent = "Enter your four numbers to see the formula.";
  };

  const calculateFromForm = (event) => {
    event.preventDefault();
    const problem = describeProblem();
    if (problem) {
      error.textContent = problem;
      holdResult();
      form.reportValidity();
      return;
    }
    render();
  };

  form.addEventListener("submit", calculateFromForm);
  button.addEventListener("click", calculateFromForm);

  inputs.forEach((input) => {
    input.addEventListener("input", () => {
      // Recompute as she types. Waiting for the button meant changing a number
      // left the old answer sitting there, which reads as a broken calculator.
      const problem = describeProblem();
      error.textContent = problem;
      if (problem) {
        holdResult();
        return;
      }
      render();
    });
  });

  render();
}

function bindSiesieCheck() {
  const form = document.querySelector("#siesie-check");
  const output = document.querySelector("#audit-output");
  if (!form || !output) return;

  const label = document.querySelector("#audit-label");
  const heading = document.querySelector("#audit-heading");
  const action = document.querySelector("#audit-action");
  const link = document.querySelector("#audit-link");

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    const count = form.querySelectorAll(".audit-check:checked").length;
    const result = getSiesieRecommendation(count);
    const nextPath = pathDetails(result.path);
    label.textContent = result.label;
    heading.textContent = result.heading;
    action.textContent = result.action;
    link.href = nextPath.href;
    link.textContent = nextPath.label;
    output.classList.add("is-ready");
  });
}

function fallbackCopy(text, button) {
  const area = document.createElement("textarea");
  area.value = text;
  area.setAttribute("readonly", "");
  area.style.position = "fixed";
  area.style.opacity = "0";
  document.body.appendChild(area);
  area.select();
  try {
    document.execCommand("copy");
    button.textContent = "Copied ✓";
  } catch {
    button.textContent = "Select the script below";
  }
  area.remove();
}

function bindCopyButtons() {
  document.querySelectorAll("[data-copy-target]").forEach((button) => {
    button.addEventListener("click", async () => {
      const target = document.querySelector(button.dataset.copyTarget);
      if (!target) return;
      const original = button.textContent;
      const text = target.textContent.trim();

      try {
        await navigator.clipboard.writeText(text);
        button.textContent = "Copied ✓";
      } catch {
        fallbackCopy(text, button);
      }

      window.setTimeout(() => {
        button.textContent = original;
      }, 1800);
    });
  });
}

/* The next page personalises from the query string: source (which path),
   n (first name), b (business). data-success-fields names the form fields
   to carry over. Nothing else about the answers leaves the form. */
function successUrl(form) {
  const url = new URL(form.dataset.success, window.location.origin);
  const source = form.querySelector("[data-source-field]");
  if (source && source.value) url.searchParams.set("source", source.value);
  const fields = (form.dataset.successFields || "").split(",").map((f) => f.trim()).filter(Boolean);
  const keys = { name: "n", business_name: "b" };
  fields.forEach((field) => {
    const input = form.elements[field];
    if (!input || !keys[field]) return;
    const value = String(input.value || "").trim();
    if (value) url.searchParams.set(keys[field], field === "name" ? value.split(/\s+/)[0] : value);
  });
  return url.pathname + url.search;
}

function bindQualificationForms() {
  document.querySelectorAll("[data-qual-form]").forEach((form) => {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!form.reportValidity()) return;

      const button = form.querySelector("button[type='submit']");
      const status = form.querySelector("[data-form-status]");
      button.disabled = true;
      status.textContent = "Sending your answers...";

      /* Twenty seconds, then the fallback. A fetch that never settles used to
         leave the form on "Sending your answers..." with the button dead, which
         reads as a broken page and gets pressed again. */
      const abort = new AbortController();
      const timer = window.setTimeout(() => abort.abort(), 20000);

      try {
        const response = await fetch(form.action, {
          method: "POST",
          body: new FormData(form),
          headers: { Accept: "application/json" },
          signal: abort.signal,
        });
        window.clearTimeout(timer);
        if (!response.ok) throw new Error("delivery failed");
        window.location.href = successUrl(form);
      } catch {
        window.clearTimeout(timer);
        status.innerHTML =
          `Your answers did not send. Email <a href="mailto:${FALLBACK_EMAIL}">${FALLBACK_EMAIL}</a> ` +
          `with what you typed and the proposal comes back the same way.`;
        button.disabled = false;
      }
    });
  });
}

const TIER_LABELS = { start: "Start", grow: "Grow", full: "Full" };

function bindTierFields() {
  const fields = document.querySelectorAll("[data-tier-field]");
  if (!fields.length) return;
  const requested = (new URLSearchParams(window.location.search).get("tier") || "").toLowerCase();
  const label = TIER_LABELS[requested];
  if (!label) return;
  fields.forEach((field) => {
    field.value = label;
  });
}

function bindFitConfirmation() {
  const root = document.querySelector("[data-fit-confirmation]");
  if (!root) return;
  const source = new URLSearchParams(window.location.search).get("source");
  const content = getFitConfirmation(source);
  root.querySelector("[data-confirm-heading]").textContent = content.heading;
  root.querySelector("[data-confirm-body]").textContent = content.body;
  const then = root.querySelector("[data-confirm-then]");
  if (then) then.textContent = content.then;
  const q5 = root.querySelector("[data-q5-label]");
  const hint = root.querySelector("[data-q5-hint]");
  if (q5 && hint) {
    q5.firstChild.textContent = content.q5 + " ";
    hint.textContent = content.q5hint;
  }
  const sourceField = root.querySelector("[data-source-field]");
  if (sourceField) sourceField.value = content.source;
}

function bindProposalConfirmation() {
  const root = document.querySelector("[data-proposal-confirmation]");
  if (!root) return;
  const params = new URLSearchParams(window.location.search);
  const content = getProposalConfirmation({
    source: params.get("source"),
    name: (params.get("n") || "").slice(0, 40),
    business: (params.get("b") || "").slice(0, 80),
  });
  root.querySelector("[data-proposal-heading]").textContent = content.heading;
  root.querySelector("[data-proposal-body]").textContent = content.body;
  root.querySelector("[data-proposal-then]").textContent = content.then;
}

function startJourney() {
  bindLeakFinder();
  bindCalculator();
  bindSiesieCheck();
  bindCopyButtons();
  bindQualificationForms();
  bindTierFields();
  bindFitConfirmation();
  bindProposalConfirmation();
}

if (typeof document !== "undefined") {
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", startJourney);
  } else {
    startJourney();
  }
}
