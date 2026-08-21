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

  /* --- 2. Submission ------------------------------------------------------- */

  var status = form.querySelector("[data-form-status]");
  var button = form.querySelector("button[type='submit']");
  var trap = form.elements.company_website;

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    if (!form.reportValidity()) return;

    /* A filled honeypot is a bot. Show the same answer a person gets, send
       nothing anywhere. */
    if (trap && trap.value !== "") {
      succeed();
      return;
    }

    button.disabled = true;
    status.textContent = "Sending your request...";

    fetch(form.action, {
      method: "POST",
      body: new FormData(form),
      headers: { Accept: "application/json" }
    })
      .then(function (response) {
        if (!response.ok) throw new Error("delivery failed");
        succeed();
      })
      .catch(function () {
        status.innerHTML =
          'Your request did not send. Email <a href="mailto:' +
          FALLBACK_EMAIL +
          '">' +
          FALLBACK_EMAIL +
          '</a> or <a href="' +
          BOOKING_URL +
          '">book the 15-minute call</a>.';
        button.disabled = false;
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
