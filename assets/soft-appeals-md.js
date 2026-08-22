/* ---------------------------------------------------------------------------
   Soft Appeals · Maryland overview page  (/soft-appeals)
   ---------------------------------------------------------------------------
   Two jobs, nothing else.

   1. Show or hide the three conditional notices under the intake form.
   2. Submit the form the same way every other Soft Appeals form on this site
      submits: a FormData POST to the shared form relay, Accept: application/json.
      The only difference is that this one answers in place instead of moving the
      visitor to a thanks page, because the answer is short and the page behind
      it is the page they were reading.

   The form still carries a real action and method, so if this file never loads
   the browser posts it natively rather than doing nothing.

   Deliberately NOT using journey.js's [data-qual-form] binding: that one
   redirects to data-success, and this form answers in place. Same endpoint, same
   fetch, same failure text.
   --------------------------------------------------------------------------- */
(function () {
  "use strict";

  var form = document.getElementById("sam-form");
  if (!form) return;

  var FALLBACK_EMAIL = "hello@frimpomaasync.com";
  var BOOKING_URL = "/book";

  /* --- 1. The conditional notices ----------------------------------------- */

  /* Each rule is [the control, the notice, a test on the control's value]. */
  var rules = [
    [
      form.elements.state,
      document.getElementById("sam-note-state"),
      function (v) {
        return v !== "" && v !== "Maryland";
      }
    ],
    [
      form.elements.practice_type,
      document.getElementById("sam-note-dental"),
      function (v) {
        return v === "Dental practice or group";
      }
    ],
    [
      form.elements.clinicians,
      document.getElementById("sam-note-solo"),
      function (v) {
        return v === "Just me";
      }
    ]
  ];

  function sync() {
    rules.forEach(function (rule) {
      var control = rule[0];
      var notice = rule[1];
      if (!control || !notice) return;
      notice.hidden = !rule[2](control.value);
    });
  }

  rules.forEach(function (rule) {
    if (rule[0]) rule[0].addEventListener("change", sync);
  });
  sync();

  /* --- 2. The PHI guard ---------------------------------------------------- */

  /* Added 2026-08-22. The two forms on /soft-appeals-start and
     /soft-appeals-contact have scanned their free-text fields since 08-17. This
     one did not, and it sits on the page most people land on, which made it the
     easiest place on the site to type something that should never leave a
     practice. Same patterns, same message, same override. */

  var guard = form.querySelector("[data-phi-guard-msg]");
  var guarded = [].slice.call(form.querySelectorAll("[data-phi-check]"));

  var PATTERNS = [
    { re: /\b\d{3}-\d{2}-\d{4}\b/, what: "something shaped like a social security number" },
    { re: /\b(0?[1-9]|1[0-2])[\/\-.](0?[1-9]|[12]\d|3[01])[\/\-.](19|20)?\d{2}\b/, what: "something shaped like a date of birth" },
    { re: /\b(dob|d\.o\.b|date of birth|mrn|medical record|member id|subscriber id|patient)\b/i, what: "a word that usually travels with patient information" },
    { re: /\b\d{7,}\b/, what: "a long identifier that could be a member or claim number" }
  ];

  function scan() {
    var hits = [];
    guarded.forEach(function (field) {
      var v = field.value || "";
      PATTERNS.forEach(function (p) {
        if (p.re.test(v)) hits.push(p.what);
      });
    });
    return hits.filter(function (h, i) { return hits.indexOf(h) === i; });
  }

  /* Returns true when the submission should stop. An override checkbox the
     person has ticked means they have looked and they are sure. */
  function blocked() {
    if (!guard || !guarded.length) return false;
    var override = guard.querySelector("input[type=checkbox]");
    if (override && override.checked) return false;

    var hits = scan();
    if (!hits.length) return false;

    guard.innerHTML =
      "<b>Hold on, that looks like patient information.</b> This form found " +
      hits.join(", ") +
      ". Nothing has been sent. Please take it out and describe the situation at " +
      "business level instead. Patient-level detail is requested later, through " +
      "the secure intake process." +
      "<label><input type=\"checkbox\"> I have checked this field, and it contains no patient information.</label>";
    guard.classList.add("on");
    guard.scrollIntoView({ block: "center" });
    return true;
  }

  /* --- 3. Submission ------------------------------------------------------- */

  var status = form.querySelector("[data-form-status]");
  var button = form.querySelector("button[type='submit']");
  var trap = form.elements.company_website;
  var sending = false;

  /* Twenty seconds. A fetch that never settles used to leave this form sitting
     on "Sending your request..." with the button dead, which reads as a broken
     page and gets pressed again. Now it always ends: either the request lands,
     or the person gets the fallback and the button back. */
  var TIMEOUT_MS = 20000;

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    if (sending) return;
    if (!form.reportValidity()) return;
    if (blocked()) return;

    /* A filled honeypot is a bot. Show the same answer a person gets, send
       nothing anywhere. */
    if (trap && trap.value !== "") {
      succeed();
      return;
    }

    sending = true;
    button.disabled = true;
    status.textContent = "Sending your request...";

    var settled = false;
    var timer = window.setTimeout(function () {
      if (!settled) fail();
    }, TIMEOUT_MS);

    function fail() {
      settled = true;
      window.clearTimeout(timer);
      sending = false;
      status.innerHTML =
        'Your request did not send. Email <a href="mailto:' +
        FALLBACK_EMAIL +
        '">' +
        FALLBACK_EMAIL +
        '</a> or <a href="' +
        BOOKING_URL +
        '">book the 15-minute call</a>.';
      button.disabled = false;
    }

    fetch(form.action, {
      method: "POST",
      body: new FormData(form),
      headers: { Accept: "application/json" }
    })
      .then(function (response) {
        if (settled) return;
        settled = true;
        window.clearTimeout(timer);
        if (!response.ok) throw new Error("delivery failed");
        succeed();
      })
      .catch(function () {
        if (settled && status.textContent.indexOf("did not send") !== -1) return;
        fail();
      });
  });

  /* Replace the form with the answer, in place. The panel is already in the
     document, already inside an aria-live region, so a screen reader hears it
     the moment it is unhidden, and focus is moved so a keyboard lands on it. */
  function succeed() {
    var panel = document.getElementById("sam-success");
    if (!panel) return;
    form.hidden = true;
    panel.hidden = false;
    panel.setAttribute("tabindex", "-1");
    panel.focus();
  }
})();
