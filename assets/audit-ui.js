/* The assessment interface.
 *
 * One question per screen, a progress track, a result page, and an optional
 * lead capture that sits UNDER the finished result rather than in front of it.
 * The result is never gated. Somebody who gives no email still leaves with the
 * whole thing, and can print it or download it.
 *
 * Everything this renders comes out of a config object, so the wording lives in
 * /assets/audit-config.js and this file never needs editing to change copy.
 *
 * All CSS classes are prefixed sya- and live in /assets/systems-audit.css.
 */

import { scoreAudit, summaryText, MAX_POINTS } from "/assets/audit-engine.js?v=1";

export function esc(s) {
  return String(s == null ? "" : s).replace(/[&<>"']/g, (c) => (
    { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]
  ));
}

/* Anonymous funnel counter. Same endpoint the rest of the site uses: a date, an
   event name and a path, nothing else. Failure is silent on purpose, because a
   counter must never be able to break the thing it is counting. */
function count(event, path) {
  try {
    fetch("/event.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ e: event, p: path || location.pathname }),
      keepalive: true,
    }).catch(() => {});
  } catch (e) { /* nothing to do */ }
}

export function mountAudit(options) {
  const config = options.config;
  const stage = options.stage;
  const progress = options.progress;
  const stepLabel = options.stepLabel;
  const countLabel = options.countLabel;
  const scrollTarget = options.scrollTarget || stage;
  const storeKey = "fs-audit-" + config.id;

  const dims = config.dimensions;
  let answers = {};
  let step = 0;
  let started = false;

  /* Progress is kept in this browser so a phone that locks mid-way, or a tab
     restored after a crash, does not throw the answers away. It is cleared on
     Start over, and it never leaves the machine. */
  function save(done) {
    try {
      localStorage.setItem(storeKey, JSON.stringify({ answers, step, done: !!done }));
    } catch (e) {}
  }
  function saved() {
    try {
      const v = JSON.parse(localStorage.getItem(storeKey) || "null");
      if (v && v.answers && Object.keys(v.answers).length) return v;
    } catch (e) {}
    return null;
  }
  function clearSaved() {
    try { localStorage.removeItem(storeKey); } catch (e) {}
  }

  function setProgress(pct) {
    if (progress) progress.style.width = Math.max(0, Math.min(100, pct)) + "%";
  }

  function toTop() {
    const nav = document.getElementById("fs-nav");
    const navH = nav && /sticky|fixed/.test(getComputedStyle(nav).position)
      ? nav.getBoundingClientRect().height
      : 0;
    const y = window.scrollY + scrollTarget.getBoundingClientRect().top - navH;
    window.scrollTo({
      top: Math.max(0, y),
      behavior: matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth",
    });
  }

  /* ---- intro ------------------------------------------------------------ */

  function renderIntro() {
    const m = config.meta;
    const resume = saved();
    setProgress(0);
    if (stepLabel) stepLabel.textContent = dims.length + " questions";
    if (countLabel) countLabel.textContent = "";

    stage.innerHTML =
      '<div class="sya-screen">' +
        '<p class="sya-eyebrow">' + esc(m.eyebrow) + "</p>" +
        '<h1 class="sya-q">' + esc(m.title) + "</h1>" +
        '<p class="sya-help">' + esc(m.intro) + "</p>" +
        '<p class="sya-help">' + esc(m.intro2) + "</p>" +
        '<div class="sya-nav">' +
          '<span class="sya-hint">' + esc(m.timeNote) + "</span>" +
          '<span class="sya-nav-btns">' +
            /* Three states. A fresh visitor gets one button. Somebody who
               stopped halfway gets their place back. Somebody who already
               finished gets their result back, not question twelve again. */
            (resume
              ? '<button type="button" class="sya-btn" data-fresh>Start over</button>' +
                (resume.done
                  ? '<button type="button" class="sya-btn is-action" data-again>Show my result again</button>'
                  : '<button type="button" class="sya-btn is-action" data-resume>Pick up where you left off</button>')
              : '<button type="button" class="sya-btn is-action" data-begin>' + esc(m.startLabel) + "</button>") +
          "</span>" +
        "</div>" +
      "</div>";
  }

  /* ---- one question ------------------------------------------------------ */

  function renderQuestion() {
    const d = dims[step];
    const chosen = answers[d.key];
    const last = step === dims.length - 1;

    setProgress((step / dims.length) * 100);
    if (stepLabel) stepLabel.textContent = "Step " + (step + 1) + " of " + dims.length;
    if (countLabel) countLabel.textContent = Object.keys(answers).length + " answered";

    stage.innerHTML =
      '<div class="sya-screen">' +
        '<fieldset class="sya-fs">' +
          '<legend class="sya-legend">' +
            '<span class="sya-eyebrow">' + esc(d.name) + "</span>" +
            '<span class="sya-q">' + esc(d.question) + "</span>" +
          "</legend>" +
          (d.help ? '<p class="sya-help">' + esc(d.help) + "</p>" : "") +
          '<div class="sya-choices">' +
            d.options.map((o, i) => {
              const on = chosen === o.points;
              return '<label class="sya-choice' + (on ? " is-picked" : "") + '">' +
                '<input type="radio" name="q" value="' + o.points + '"' + (on ? " checked" : "") + ">" +
                '<span class="sya-mark" aria-hidden="true">' + (i + 1) + "</span>" +
                '<span class="sya-choice-t">' + esc(o.label) + "</span>" +
              "</label>";
            }).join("") +
          "</div>" +
          '<div class="sya-nav">' +
            '<button type="button" class="sya-btn" data-back>Back</button>' +
            '<span class="sya-nav-btns">' +
              (last
                ? '<button type="button" class="sya-btn is-action" data-next' + (chosen === undefined ? " disabled" : "") + ">" +
                  esc(config.meta.resultLabel) + "</button>"
                : '<button type="button" class="sya-btn is-action" data-next' + (chosen === undefined ? " disabled" : "") + ">Next</button>") +
            "</span>" +
          "</div>" +
        "</fieldset>" +
      "</div>";

    const first = stage.querySelector("input");
    if (first) first.focus({ preventScroll: true });
  }

  /* ---- the result -------------------------------------------------------- */

  function renderResult() {
    const r = scoreAudit(config, answers);
    const R = config.result;
    const m = config.meta;
    setProgress(100);
    if (stepLabel) stepLabel.textContent = "Your result";
    if (countLabel) countLabel.textContent = r.answered + " of " + r.total + " answered";
    count("audit_completed");
    save(true);

    const dash = 2 * Math.PI * 54;
    const dial =
      '<div class="sya-dial" role="img" aria-label="Score ' + r.score + ' out of 100">' +
        '<svg viewBox="0 0 120 120" aria-hidden="true">' +
          '<circle class="sya-dial-track" cx="60" cy="60" r="54"></circle>' +
          '<circle class="sya-dial-value" cx="60" cy="60" r="54" ' +
            'stroke-dasharray="' + dash.toFixed(1) + '" ' +
            'stroke-dashoffset="' + (dash * (1 - r.score / 100)).toFixed(1) + '"></circle>' +
        "</svg>" +
        '<div class="sya-dial-v"><b>' + r.score + "</b><span>" + esc(m.scoreCaption) + "</span></div>" +
      "</div>";

    stage.innerHTML =
      '<div class="sya-screen is-result">' +

      '<div class="sya-scoreblock">' +
        dial +
        "<div>" +
          '<p class="sya-eyebrow">Your result</p>' +
          '<h1 class="sya-band">' + esc(r.band) + "</h1>" +
          '<p class="sya-band-blurb">' + esc(r.bandBlurb) + "</p>" +
          '<p class="sya-meta">Based on all ' + r.answered + " answers you selected. Your answers stayed in this browser.</p>" +
        "</div>" +
      "</div>" +

      '<h2 class="sya-sub">' + esc(R.pairTitle) + "</h2>" +
      '<div class="sya-cards">' +
        (r.strongest
          ? '<div class="sya-card is-strength"><p class="sya-card-k">' + esc(R.strongestLabel) + "</p><h3>" +
            esc(r.strongest.name) + "</h3><p>" + esc(r.strongest.note) + "</p></div>"
          : '<div class="sya-card is-strength"><p class="sya-card-k">' + esc(R.strongestLabel) +
            "</p><h3>Nothing scored as a strength yet</h3><p>" + esc(config.noStrengthNote) + "</p></div>") +
        (r.weakest
          ? '<div class="sya-card is-gap"><p class="sya-card-k">' + esc(R.weakestLabel) + "</p><h3>" +
            esc(r.weakest.name) + "</h3><p>" + esc(r.weakest.note) + "</p></div>"
          : "") +
      "</div>" +

      (r.topGaps.length
        ? '<h2 class="sya-sub">' + esc(R.gapsTitle) + "</h2>" +
          '<ol class="sya-list">' + r.topGaps.map((g) =>
            "<li><b>" + esc(g.name) + " · " + esc(g.band) + "</b>" + esc(g.note) + "</li>"
          ).join("") + "</ol>"
        : '<h2 class="sya-sub">Your gaps</h2><p class="sya-body">' + esc(config.noGapsNote) + "</p>") +

      '<h2 class="sya-sub">' + esc(R.priorityTitle) + "</h2>" +
      '<div class="sya-card is-priority"><p class="sya-card-k">' + esc(r.priority.area) + "</p>" +
        "<p>" + esc(r.priority.action) + "</p></div>" +

      '<h2 class="sya-sub">' + esc(R.planTitle) + "</h2>" +
      '<div class="sya-plan">' +
        r.plan.map((p) =>
          '<div class="sya-step"><div class="d">' + esc(p.days) + "</div>" +
          '<div class="a"><b>' + esc(p.area) + "</b><p>" + esc(p.action) + "</p></div></div>"
        ).join("") +
      "</div>" +
      '<p class="sya-note">' + esc(R.planNote) + "</p>" +

      '<h2 class="sya-sub">' + esc(R.rowsTitle) + "</h2>" +
      '<div class="sya-rows">' +
        r.rows.map((row) =>
          '<div class="sya-row"><div class="t"><span>' + esc(row.name) +
          ' <span class="w">weight ' + row.weight + "</span></span><b>" + esc(row.band) + "</b></div>" +
          '<div class="sya-meter' + (row.percent <= 50 ? " is-low" : "") + '" role="img" aria-label="' +
          esc(row.name) + ": " + esc(row.band) + ", " + row.percent + ' percent">' +
          '<i style="width:' + row.percent + '%"></i></div></div>'
        ).join("") +
      "</div>" +

      (r.recommendations.length
        ? '<h2 class="sya-sub">' + esc(R.recsTitle) + "</h2><ul class=\"sya-list\">" +
          r.recommendations.map((x) => "<li><b>" + esc(x.area) + "</b>" + esc(x.action) + "</li>").join("") +
          "</ul>"
        : "") +

      (r.bandNext
        ? '<h2 class="sya-sub">' + esc(R.nextTitle) + '</h2><p class="sya-body">' + esc(r.bandNext) + "</p>"
        : "") +

      '<div class="sya-guard"><div class="k">' + esc(m.guardTitle) + "</div><p>" + esc(m.guardBody) + "</p></div>" +

      leadFormHTML(config.lead) +

      '<div class="sya-cta">' +
        '<p class="sya-cta-k">' + esc(config.cta.eyebrow) + "</p>" +
        "<h2>" + esc(config.cta.title) + "</h2>" +
        "<p>" + esc(config.cta.body) + "</p>" +
        '<div class="sya-cta-actions">' +
          '<a class="sya-btn is-action" href="' + esc(config.cta.primary.href) + '">' +
            esc(config.cta.primary.label) + ' <span aria-hidden="true">&#8594;</span></a>' +
          config.cta.secondary.map((s) =>
            '<a class="sya-btn" href="' + esc(s.href) + '">' + esc(s.label) + "</a>"
          ).join("") +
        "</div>" +
      "</div>" +

      '<div class="sya-actions">' +
        '<button type="button" class="sya-btn" data-print>Save or print this result</button>' +
        '<button type="button" class="sya-btn" data-download>Download as a text file</button>' +
        '<button type="button" class="sya-btn is-quiet" data-restart>Start over</button>' +
      "</div>" +

      "</div>";

    wireResult(r);
    toTop();
  }

  function wireResult(r) {
    const printBtn = stage.querySelector("[data-print]");
    if (printBtn) printBtn.addEventListener("click", () => window.print());

    const dl = stage.querySelector("[data-download]");
    if (dl) {
      dl.addEventListener("click", () => {
        const text = summaryText(config, r, { when: today() });
        const blob = new Blob([text], { type: "text/plain;charset=utf-8" });
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = config.id + "-" + today() + ".txt";
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 4000);
      });
    }

    const restart = stage.querySelector("[data-restart]");
    if (restart) {
      restart.addEventListener("click", () => {
        answers = {};
        step = 0;
        started = false;
        clearSaved();
        renderIntro();
        toTop();
      });
    }

    wireLeadForm(stage, config, r, answers);
  }

  /* ---- events ------------------------------------------------------------ */

  stage.addEventListener("change", (e) => {
    if (e.target.name !== "q") return;
    const d = dims[step];
    answers[d.key] = Number(e.target.value);
    save();
    stage.querySelectorAll(".sya-choice").forEach((l) => {
      l.classList.toggle("is-picked", !!l.querySelector("input:checked"));
    });
    const next = stage.querySelector("[data-next]");
    if (next) next.disabled = false;
    if (countLabel) countLabel.textContent = Object.keys(answers).length + " answered";

    /* Choosing an answer moves on by itself on every screen except the last,
       which keeps the whole thing inside four minutes on a phone. The last
       screen waits, so nobody lands on their result by accident. */
    if (step < dims.length - 1) {
      const quick = matchMedia("(prefers-reduced-motion: reduce)").matches;
      setTimeout(() => {
        if (answers[d.key] === undefined) return;
        step++;
        renderQuestion();
        save();
        toTop();
      }, quick ? 0 : 260);
    }
  });

  stage.addEventListener("click", (e) => {
    if (e.target.closest("[data-begin]") || e.target.closest("[data-fresh]")) {
      answers = {};
      step = 0;
      clearSaved();
      if (!started) { started = true; count("audit_started"); }
      renderQuestion();
      toTop();
    } else if (e.target.closest("[data-resume]") || e.target.closest("[data-again]")) {
      const v = saved();
      if (v) {
        answers = v.answers;
        step = Math.min(v.step || 0, dims.length - 1);
      }
      if (!started) { started = true; count("audit_started"); }
      if (v && v.done) renderResult();
      else { renderQuestion(); toTop(); }
    } else if (e.target.closest("[data-next]")) {
      if (answers[dims[step].key] === undefined) return;
      if (step === dims.length - 1) renderResult();
      else { step++; renderQuestion(); save(); toTop(); }
    } else if (e.target.closest("[data-back]")) {
      if (step === 0) renderIntro();
      else { step--; renderQuestion(); save(); toTop(); }
    }
  });

  /* Number keys pick an answer, which is how somebody on a laptop gets through
     twelve questions quickly. Ignored while a text field has focus. */
  document.addEventListener("keydown", (e) => {
    const tag = (document.activeElement && document.activeElement.tagName) || "";
    if (tag === "INPUT" && document.activeElement.type !== "radio") return;
    if (tag === "TEXTAREA") return;
    const n = Number(e.key);
    if (!n || n < 1 || n > MAX_POINTS + 1) return;
    const inputs = stage.querySelectorAll('input[name="q"]');
    if (!inputs.length) return;
    const target = inputs[n - 1];
    if (!target) return;
    target.checked = true;
    target.dispatchEvent(new Event("change", { bubbles: true }));
    e.preventDefault();
  });

  renderIntro();

  return {
    reset() { answers = {}; step = 0; clearSaved(); renderIntro(); },
    current() { return { answers: Object.assign({}, answers), step }; },
  };
}

/* ---------------------------------------------------------------------------
   The lead capture.
   It renders under a finished result. It never gates one. Exported on its own
   so the Denial Health Score page, which was built before this engine, can use
   the same form and the same endpoint without being rebuilt.
   --------------------------------------------------------------------------- */

export function leadFormHTML(lead) {
  if (!lead || !lead.endpoint) return "";
  return (
    '<form class="sya-lead" data-lead novalidate>' +
      '<h2 class="sya-lead-t">' + esc(lead.title) + "</h2>" +
      '<p class="sya-lead-b">' + esc(lead.body) + "</p>" +
      '<div class="sya-fields">' +
        '<label class="sya-field"><span>' + esc(lead.nameLabel) + "</span>" +
          '<input type="text" name="name" autocomplete="name" required maxlength="80"></label>' +
        '<label class="sya-field"><span>' + esc(lead.emailLabel) + "</span>" +
          '<input type="email" name="email" autocomplete="email" required maxlength="120" inputmode="email"></label>' +
        '<label class="sya-field"><span>' + esc(lead.businessLabel) +
          ' <i>' + esc(lead.businessHint) + "</i></span>" +
          '<input type="text" name="business" autocomplete="organization" maxlength="80"></label>' +
      "</div>" +
      /* Named "notes" to match the honeypot the rest of the site already uses.
         A human never sees it. A filled one is treated as a bot. */
      '<div class="sya-trap" aria-hidden="true">' +
        '<label>Notes<input type="text" name="notes" tabindex="-1" autocomplete="off"></label>' +
      "</div>" +
      '<div class="sya-lead-go">' +
        '<button type="submit" class="sya-btn is-action">' + esc(lead.button) + "</button>" +
        '<p class="sya-lead-status" role="status" aria-live="polite"></p>' +
      "</div>" +
      '<p class="sya-lead-c">' + esc(lead.consent) + "</p>" +
    "</form>"
  );
}

export function wireLeadForm(root, config, result, answers) {
  const form = root.querySelector("[data-lead]");
  if (!form) return;
  const lead = config.lead;
  const status = form.querySelector(".sya-lead-status");
  const button = form.querySelector('button[type="submit"]');

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const name = String(fd.get("name") || "").trim();
    const email = String(fd.get("email") || "").trim();

    if (!name || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
      status.textContent = "A name and a working email address, and it will send.";
      status.className = "sya-lead-status is-warn";
      return;
    }

    button.disabled = true;
    status.className = "sya-lead-status";
    status.textContent = lead.sending;

    const body = new URLSearchParams({
      audit: config.id,
      audit_name: config.meta.appName,
      name,
      email,
      business: String(fd.get("business") || "").trim(),
      notes: String(fd.get("notes") || ""),
      score: String(result.score),
      band: result.band,
      answered: String(result.answered) + " of " + String(result.total),
      summary: summaryText(config, result, { who: name, when: today() }),
    });

    try {
      const res = await fetch(lead.endpoint, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString(),
      });
      const reply = (await res.text()).trim();
      if (!res.ok || reply === "no") throw new Error("rejected");

      /* Two different true answers. "ok" means the copy left the mail server.
         "logged" means it reached Nana Frimpongmaa but the visitor's own copy
         did not send, and saying "check your inbox" then would be a lie. */
      status.textContent = reply === "logged" ? lead.partial : lead.done;
      status.className = "sya-lead-status is-done";
      form.querySelector(".sya-fields").hidden = true;
      button.hidden = true;
      count("audit_emailed");
    } catch (err) {
      button.disabled = false;
      status.textContent = lead.failed;
      status.className = "sya-lead-status is-warn";
    }
  });
}

function today() {
  const d = new Date();
  const p = (n) => (n < 10 ? "0" + n : String(n));
  return d.getFullYear() + "-" + p(d.getMonth() + 1) + "-" + p(d.getDate());
}
