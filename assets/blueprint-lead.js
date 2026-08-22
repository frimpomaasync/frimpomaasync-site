/* The Blueprint, emailed to the person who filled it in.
 * ---------------------------------------------------------------------------
 * /blueprint and /blueprint-workbook are bundled canvas documents: the outer
 * shell unpacks a template and REPLACES documentElement with it, so anything
 * added to the shell's body is thrown away when the swap happens. This file is
 * loaded from the shell's head, survives the swap because its timer lives on
 * window rather than in the DOM, waits for the unpacked document, and then
 * appends one card at the end of it. The bundle itself is never touched.
 *
 * The card does what the two assessments already do: composes the whole thing
 * as plain text in the browser, emails the person their own copy, emails Nana
 * Frimpongmaa the same text as a lead, and offers a download for somebody who
 * would rather give no email at all. Nothing is gated. The blueprint is theirs
 * either way.
 *
 * Both pages share the localStorage key fs-blueprint-v1 with /my-blueprint, so
 * a person who filled the workbook and then opened the command center gets one
 * blueprint out of this, not two halves.
 *
 * The labels below are a copy of the ones inside the bundle. The bundle keeps
 * them in a module-scoped const that nothing outside can read, so they are
 * repeated here rather than guessed at. If a task is ever renamed in the
 * bundle, rename it here in the same commit.
 */
(function () {
  "use strict";

  var KEY = "fs-blueprint-v1";
  var ENDPOINT = "/audit-lead.php";

  var MODULES = [
    { name: "The Automated Receptionist", tasks: ["Missed-call text-back", "Auto-FAQs", "Frictionless scheduling"] },
    { name: "The Lead Nurture Engine", tasks: ["Immediate nurture sequence", "Review and rebooking messages", "Quote recovery reminders"] },
    { name: "The Frictionless Finance Hub", tasks: ["Automated invoicing", "Unified intake form", "Real-time tracking sheet"] },
    { name: "The Content Vault", tasks: ["2-second rapid capture", "Hook swipe file", "Weekly batching"] }
  ];

  /* Weights, in task order. Same numbers the command center scores with. */
  var WEIGHTS = [3, 2, 2, 3, 2, 2, 2, 2, 1, 2, 1, 1];

  var TASKS = [];
  MODULES.forEach(function (m) { m.tasks.forEach(function (t) { TASKS.push(t); }); });

  var PLAN = [
    ["Choose one operational leak", "15 min"],
    ["Map the current process", "20 min"],
    ["Write the workflow or message", "30 min"],
    ["Build the smallest working version", "45 min"],
    ["Test the workflow", "20 min"],
    ["Correct failures", "30 min"],
    ["Activate and document it", "15 min"]
  ];

  var FIELD_LABELS = [
    ["current", "What happens today"],
    ["automate", "What to automate"],
    ["tool", "Tool"],
    ["due", "Due"],
    ["next", "Next step"],
    ["notes", "Notes"]
  ];

  var ANSWER_WORDS = { manual: "Manual", partly: "Partly systemized", auto: "Runs without me" };

  /* --- the state ---------------------------------------------------------- */

  function read() {
    var s = null;
    try { s = JSON.parse(localStorage.getItem(KEY) || "null"); } catch (e) { s = null; }
    if (!s || typeof s !== "object") { s = {}; }
    if (!Array.isArray(s.audit)) { s.audit = []; }
    if (!Array.isArray(s.tasks)) { s.tasks = []; }
    if (!Array.isArray(s.fields)) { s.fields = []; }
    if (!Array.isArray(s.plan)) { s.plan = []; }
    if (!Array.isArray(s.myScripts)) { s.myScripts = []; }
    return s;
  }

  /* The same arithmetic the command center shows on screen. If this and the
     screen ever disagree, the email is the one that looks broken, so it is
     copied rather than reinvented: auto is worth 2, partly 1, manual 0, out of
     a possible 24. */
  function compute(s) {
    var answered = 0, pts = 0;
    for (var i = 0; i < 12; i++) {
      var a = s.audit[i];
      if (a) { answered++; }
      pts += a === "auto" ? 2 : a === "partly" ? 1 : 0;
    }
    var score = answered ? Math.round((pts / 24) * 100) : 0;
    var band = answered === 0 ? "Not scored yet"
      : score >= 67 ? "Low dependence"
      : score >= 34 ? "Moderate dependence"
      : "High dependence";

    /* Where to start: the heaviest task that is not installed yet, weighted the
       way the command center weights it. */
    var focus = -1, best = -1;
    for (var j = 0; j < 12; j++) {
      if (s.tasks[j]) { continue; }
      var ans = s.audit[j];
      var aw = ans === "manual" ? 3 : ans === "partly" ? 2 : ans === "auto" ? 0.5 : 2;
      var sc = aw * WEIGHTS[j];
      if (sc > best) { best = sc; focus = j; }
    }

    return {
      answered: answered,
      score: score,
      band: band,
      installed: s.tasks.filter(Boolean).length,
      planDone: s.plan.filter(function (d) { return d && d.done; }).length,
      focus: focus,
      focusTask: focus < 0 ? "" : TASKS[focus],
      focusModule: focus < 0 ? "" : MODULES[Math.floor(focus / 3)].name
    };
  }

  /* --- the summary -------------------------------------------------------- */

  function wrap(text, width) {
    var words = String(text || "").split(/\s+/);
    var lines = [], line = "";
    words.forEach(function (w) {
      if (!w) { return; }
      if ((line + " " + w).trim().length > (width || 66)) { lines.push(line); line = w; }
      else { line = (line ? line + " " : "") + w; }
    });
    if (line) { lines.push(line); }
    return lines.join("\n");
  }

  function today() {
    var d = new Date();
    var p = function (n) { return n < 10 ? "0" + n : String(n); };
    return d.getFullYear() + "-" + p(d.getMonth() + 1) + "-" + p(d.getDate());
  }

  function summary(s, r, who) {
    var line = "";
    for (var i = 0; i < 66; i++) { line += "-"; }
    var out = [];
    var add = function (t) { out.push(t === undefined ? "" : t); };

    add("THE AUTOMATED SMALL BUSINESS BLUEPRINT");
    add(line);
    if (who) { add("Prepared for: " + who); }
    add("Date: " + today());
    add("Independence score: " + r.score + " out of 100");
    add("Dependence: " + r.band);
    add("Audit answered: " + r.answered + " of 12");
    add("Systems installed: " + r.installed + " of 12");
    add("Seven-day plan: " + r.planDone + " of 7 days done");
    add();

    add("WHERE TO START");
    add(line);
    if (r.focus < 0) {
      add(wrap("All twelve systems are marked installed. There is nothing left on this list, which means the next useful question is not which system to build, it is what the business does with the hours it just got back."));
    } else {
      add(r.focusTask + "  (" + r.focusModule + ")");
      var ans = s.audit[r.focus];
      add(wrap(
        ans === "manual"
          ? "You marked this one manual, and it carries more weight than anything else still open."
          : ans === "partly"
            ? "This one is only partly systemized, and it carries more weight than anything else still open."
            : "This is the heaviest task on the list that is not installed yet."
      ));
    }
    add();

    add("THE SYSTEM VS. YOU AUDIT");
    add(line);
    MODULES.forEach(function (m, mi) {
      add(m.name);
      m.tasks.forEach(function (t, ti) {
        var idx = mi * 3 + ti;
        var a = s.audit[idx];
        var state = a ? ANSWER_WORDS[a] : "Not answered";
        add("  " + t + ": " + state + (s.tasks[idx] ? " · installed" : ""));
      });
      add();
    });

    var anyField = false;
    s.fields.forEach(function (f) {
      if (!f) { return; }
      FIELD_LABELS.forEach(function (p) { if (String(f[p[0]] || "").trim()) { anyField = true; } });
    });
    if (anyField) {
      add("YOUR MODULE WORKSHEETS");
      add(line);
      s.fields.forEach(function (f, i) {
        if (!f) { return; }
        var rows = [];
        FIELD_LABELS.forEach(function (p) {
          var v = String(f[p[0]] || "").trim();
          if (v) { rows.push("  " + p[1] + ": " + v); }
        });
        if (!rows.length) { return; }
        add((MODULES[i] ? MODULES[i].name : "Module " + (i + 1)));
        rows.forEach(add);
        add();
      });
    }

    add("YOUR SEVEN-DAY PLAN");
    add(line);
    PLAN.forEach(function (p, i) {
      var mine = s.plan[i] || {};
      var own = String(mine.action || "").trim();
      var note = String(mine.notes || "").trim();
      add("Day " + (i + 1) + " · " + p[0] + " (" + p[1] + ")" + (mine.done ? " · done" : ""));
      if (own) { add("  Your action: " + own); }
      if (note) { add("  Notes: " + note); }
    });
    add();

    var scripts = s.myScripts.filter(function (t) { return String(t || "").trim(); });
    if (scripts.length) {
      add("THE SCRIPTS YOU WROTE");
      add(line);
      scripts.forEach(function (t, i) {
        add("Script " + (i + 1));
        add(wrap(t));
        add();
      });
    }

    add(line);
    add(wrap("This is your own copy, composed in your browser from what you filled in. The command center at frimpomaasync.com/blueprint keeps it, and it stays on your machine."));
    add();

    return out.join("\n");
  }

  /* --- the card ----------------------------------------------------------- */

  var CSS =
    /* position and z-index are load-bearing on /blueprint-workbook: that page
       scales its letter-sized pages with a transform, and the scaled page box
       reaches past its own edges and swallows clicks meant for anything under
       it. Without this the card renders and its buttons do nothing. */
    "#bpl{position:relative;z-index:2147483000;isolation:isolate;background:#101426;color:#fff;padding:44px 20px 52px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}" +
    /* The unpacked document does not set a global border-box, so an input at
       width 100% plus its own padding pushed the page 8px sideways on a phone. */
    "#bpl,#bpl *{box-sizing:border-box}" +
    "#bpl .bpl-in{max-width:640px;margin:0 auto}" +
    "#bpl .bpl-eyebrow{font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:#E5A886;font-weight:600;margin:0 0 10px}" +
    "#bpl h2{font-size:26px;line-height:1.2;font-weight:500;letter-spacing:-.01em;margin:0 0 10px;color:#fff}" +
    "#bpl p{margin:0 0 14px;font-size:15px;line-height:1.6;color:#c6c9d3}" +
    "#bpl .bpl-score{font-size:13px;color:#c6c9d3;margin:0 0 20px}" +
    "#bpl .bpl-score b{color:#fff}" +
    "#bpl .bpl-fields{display:grid;gap:12px;margin:0 0 16px}" +
    "@media(min-width:620px){#bpl .bpl-fields{grid-template-columns:1fr 1fr}#bpl .bpl-fields label:nth-child(3){grid-column:1/-1}}" +
    "#bpl label{display:block;font-size:12px;letter-spacing:.02em;color:#9fa3b2;font-weight:600}" +
    "#bpl label i{font-style:normal;font-weight:400;color:#767b8c}" +
    "#bpl input{display:block;width:100%;margin-top:6px;padding:12px 13px;font-size:16px;font-family:inherit;color:#101426;background:#fff;border:1px solid #2c3040;border-radius:10px}" +
    "#bpl input:focus{outline:2px solid #C2501C;outline-offset:1px}" +
    "#bpl .bpl-go{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:4px}" +
    "#bpl button{font-family:inherit;font-size:14px;font-weight:600;padding:13px 20px;border-radius:999px;border:1px solid transparent;cursor:pointer}" +
    "#bpl .bpl-send{background:#C2501C;color:#fff}" +
    "#bpl .bpl-send[disabled]{opacity:.55;cursor:default}" +
    "#bpl .bpl-dl{background:transparent;color:#fff;border-color:#3a3f52}" +
    "#bpl .bpl-status{margin:0;font-size:13.5px;color:#c6c9d3;min-height:1em}" +
    "#bpl .bpl-status.is-done{color:#9fd8b6}" +
    "#bpl .bpl-status.is-warn{color:#f0a882}" +
    "#bpl .bpl-fine{font-size:12.5px;color:#8a8e9c;margin:16px 0 0;line-height:1.55}" +
    "#bpl .bpl-back{color:#E5A886;text-decoration:underline}" +
    "#bpl .bpl-back:hover{color:#fff}" +
    "#bpl .bpl-trap{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}" +
    "@media print{#bpl{display:none!important}}";

  function el(tag, attrs, text) {
    var n = document.createElement(tag);
    if (attrs) { Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); }); }
    if (text != null) { n.textContent = text; }
    return n;
  }

  function build(auditId) {
    var s = read();
    var r = compute(s);

    var style = el("style");
    style.textContent = CSS;
    document.head.appendChild(style);

    var sec = el("section", { id: "bpl" });
    var box = el("div", { "class": "bpl-in" });
    sec.appendChild(box);

    box.appendChild(el("p", { "class": "bpl-eyebrow" }, "Take it with you"));
    box.appendChild(el("h2", null, "Email this blueprint to yourself"));
    box.appendChild(el("p", null,
      "Everything you have filled in so far, as one page you can keep, forward, or bring to a conversation. It is composed in this browser, so nothing about your business is sent anywhere unless you ask for it here."));

    var scoreLine = el("p", { "class": "bpl-score" });
    scoreLine.innerHTML = r.answered
      ? "Right now: <b>" + r.score + " out of 100</b> on independence · " + r.band +
        " · " + r.installed + " of 12 systems installed" +
        (r.focusTask ? " · next up is " + escapeHtml(r.focusTask.toLowerCase()) : "")
      : "You have not answered the audit yet. You can still send yourself the workbook as it stands.";
    box.appendChild(scoreLine);

    var form = el("form", { novalidate: "novalidate" });
    var fields = el("div", { "class": "bpl-fields" });

    fields.appendChild(field("Your name", "name", "text", "name", true));
    fields.appendChild(field("Your email", "email", "email", "email", true));
    fields.appendChild(field("Business name", "business", "text", "organization", false, "optional"));
    form.appendChild(fields);

    var trap = el("div", { "class": "bpl-trap", "aria-hidden": "true" });
    var trapLabel = el("label", null, "Notes");
    var trapInput = el("input", { type: "text", name: "notes", tabindex: "-1", autocomplete: "off" });
    trapLabel.appendChild(trapInput);
    trap.appendChild(trapLabel);
    form.appendChild(trap);

    var go = el("div", { "class": "bpl-go" });
    var send = el("button", { type: "submit", "class": "bpl-send" }, "Email it to me");
    var dl = el("button", { type: "button", "class": "bpl-dl" }, "Download it instead");
    go.appendChild(send);
    go.appendChild(dl);
    form.appendChild(go);

    var status = el("p", { "class": "bpl-status", role: "status", "aria-live": "polite" });
    form.appendChild(status);
    box.appendChild(form);

    var fine = el("p", { "class": "bpl-fine" });
    fine.appendChild(document.createTextNode(
      "Sending it shares your blueprint with Nana Frimpongmaa, which is how she can answer you about it. Downloading keeps it entirely on your machine. Either way the blueprint stays here in this browser. "));
    /* The workbook and my-blueprint are both in the sitemap, so somebody can
       land on either one cold with no way back into anything. This is that way
       back, and on the command center itself it is simply where they are. */
    if (auditId !== "blueprint") {
      var back = el("a", { href: "/blueprint", "class": "bpl-back" }, "Open the command center");
      fine.appendChild(back);
      fine.appendChild(document.createTextNode(" to keep filling it in."));
    }
    box.appendChild(fine);

    dl.addEventListener("click", function () {
      var fresh = read();
      var res = compute(fresh);
      var blob = new Blob([summary(fresh, res, "")], { type: "text/plain;charset=utf-8" });
      var a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = "my-blueprint-" + today() + ".txt";
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
      status.className = "bpl-status is-done";
      status.textContent = "Downloaded. It is a plain text file, so it opens anywhere.";
    });

    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var name = String(form.elements.name.value || "").trim();
      var email = String(form.elements.email.value || "").trim();

      if (!name || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
        status.className = "bpl-status is-warn";
        status.textContent = "A name and a working email address, and it will send.";
        return;
      }

      var fresh = read();
      var res = compute(fresh);
      var text = summary(fresh, res, name);

      send.disabled = true;
      status.className = "bpl-status";
      status.textContent = "Sending it...";

      var body = new URLSearchParams({
        audit: auditId,
        name: name,
        email: email,
        business: String(form.elements.business.value || "").trim(),
        notes: String(form.elements.notes.value || ""),
        score: String(res.score),
        band: res.band + (res.focusTask ? " · next up is " + res.focusTask.toLowerCase() : ""),
        answered: String(res.answered) + " of 12",
        summary: text
      });

      var settled = false;
      var timer = setTimeout(function () { if (!settled) { fail(); } }, 20000);

      function fail() {
        settled = true;
        clearTimeout(timer);
        send.disabled = false;
        status.className = "bpl-status is-warn";
        status.textContent = "That did not send. Download it instead, or email hello@frimpomaasync.com and it will be sent by hand.";
      }

      fetch(ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString()
      }).then(function (res2) {
        return res2.text().then(function (t) { return { ok: res2.ok, reply: t.trim() }; });
      }).then(function (out) {
        if (settled) { return; }
        settled = true;
        clearTimeout(timer);
        if (!out.ok || out.reply === "no") { throw new Error("rejected"); }
        status.className = "bpl-status is-done";
        status.textContent = out.reply === "logged"
          ? "It reached Nana Frimpongmaa, but your own copy did not send. Use Download it instead so you still have it."
          : "Sent. Check your inbox, and your spam folder if it is not there in a few minutes.";
        fields.hidden = true;
        send.hidden = true;
      }).catch(function () {
        if (settled && status.className.indexOf("is-warn") !== -1) { return; }
        fail();
      });
    });

    return sec;
  }

  function field(labelText, name, type, autocomplete, required, hint) {
    var l = el("label", null, labelText);
    if (hint) { l.appendChild(el("i", null, " " + hint)); }
    var attrs = { type: type, name: name, autocomplete: autocomplete, maxlength: "120" };
    if (required) { attrs.required = "required"; }
    if (type === "email") { attrs.inputmode = "email"; }
    l.appendChild(el("input", attrs));
    return l;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c];
    });
  }

  /* --- waiting for the unpacked document ---------------------------------- */

  /* The bundle replaces documentElement, so the card cannot simply be appended
     on load: it would be appended to a body that is about to be thrown away.
     Wait for the unpacked document to appear, then append once. If the page
     never unpacks, nothing is added and nothing is broken. */
  function start() {
    /* Three pages, three ids, so a lead says which one it came from. */
    var path = location.pathname;
    var auditId = path.indexOf("my-blueprint") !== -1 ? "my-blueprint"
      : path.indexOf("workbook") !== -1 ? "blueprint-workbook"
      : "blueprint";
    var tries = 0;
    var timer = setInterval(function () {
      tries++;
      if (tries > 200) { clearInterval(timer); return; }
      if (document.getElementById("bpl")) { clearInterval(timer); return; }
      /* x-dc is the element in the template. The runtime swaps it for #dc-root
         once it has rendered, so both have to count, or the card mounts on one
         load and misses the next. doc-page is the workbook's paged root. */
      var unpacked = document.querySelector("#dc-root, x-dc, doc-page");
      if (!unpacked || !document.body) { return; }
      clearInterval(timer);
      try {
        document.body.appendChild(build(auditId));
      } catch (e) {
        /* A card that cannot render must never take the page with it. */
        if (window.console) { console.warn("[blueprint-lead]", e); }
      }
    }, 150);
  }

  start();
})();
