const BOOKING_URL = "https://calendar.app.google/DkRJFRA3G6W6d8E48";
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

const fitConfirmations = {
  synkasa: {
    source: "synkasa",
    heading: "Your inquiry path is ready for review.",
    body:
      "NaNa Frimpomaa will review how inquiries arrive, where they wait, and what you want handled before the 15-minute call.",
  },
  siesie: {
    source: "siesie",
    heading: "Your back office is ready for review.",
    body:
      "NaNa Frimpomaa will review the roles that still depend on you and the process causing the most interruptions before the 15-minute call.",
  },
  fit: {
    source: "fit",
    heading: "Your answers are ready for review.",
    body:
      "NaNa Frimpomaa will review the form before the 15-minute call, so the conversation can start with the real bottleneck.",
  },
};

export function getFitConfirmation(source) {
  return fitConfirmations[source] || fitConfirmations.fit;
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

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    if (!form.checkValidity()) {
      error.textContent =
        "Add all four numbers so the formula can use your scenario.";
      form.reportValidity();
      return;
    }
    render();
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

function bindQualificationForms() {
  document.querySelectorAll("[data-qual-form]").forEach((form) => {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!form.reportValidity()) return;

      const button = form.querySelector("button[type='submit']");
      const status = form.querySelector("[data-form-status]");
      button.disabled = true;
      status.textContent = "Sending your answers...";

      try {
        const response = await fetch(form.action, {
          method: "POST",
          body: new FormData(form),
          headers: { Accept: "application/json" },
        });
        if (!response.ok) throw new Error("delivery failed");
        window.location.href = form.dataset.success;
      } catch {
        status.innerHTML =
          `Your answers did not send. Email <a href="mailto:${FALLBACK_EMAIL}">${FALLBACK_EMAIL}</a> ` +
          `or <a href="${BOOKING_URL}">book the 15-minute call</a>.`;
        button.disabled = false;
      }
    });
  });
}

function bindFitConfirmation() {
  const root = document.querySelector("[data-fit-confirmation]");
  if (!root) return;
  const source = new URLSearchParams(window.location.search).get("source");
  const content = getFitConfirmation(source);
  root.querySelector("[data-confirm-heading]").textContent = content.heading;
  root.querySelector("[data-confirm-body]").textContent = content.body;
}

function startJourney() {
  bindLeakFinder();
  bindCalculator();
  bindSiesieCheck();
  bindCopyButtons();
  bindQualificationForms();
  bindFitConfirmation();
}

if (typeof document !== "undefined") {
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", startJourney);
  } else {
    startJourney();
  }
}
