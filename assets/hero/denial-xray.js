/* ---------------------------------------------------------------------------
   The Denial X-Ray · Soft Appeals hero script
   ---------------------------------------------------------------------------
   Adds the state classes; dx-field.js reads them every frame and draws.

   Desktop: .is-stamped starts the beam. The beam catches the claim about
   three seconds in, then the four stations, the split, APPEAL, OWNED and the
   headline follow on a clock. Scrolling 80px into the pinned section
   fast-forwards the chain, and the field descends under the Maryland numbers
   that rise over it.

   Phone: SCAN DENIAL (or four seconds with the field in view) runs the same
   chain with the catch immediate.
   --------------------------------------------------------------------------- */
(function () {
  var hero = document.querySelector(".dx-hero");
  if (!hero || !window.DxField) return;

  var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var phone = window.matchMedia("(max-width: 760px)");
  var canvas = hero.querySelector(".dx-field");
  var scan = hero.querySelector(".dx-scan");
  var stage = hero.querySelector(".dx-stage");
  var chained = false;
  var timers = [];

  hero.classList.add("dx-js");
  var field = canvas ? new window.DxField(canvas, hero, { phone: phone.matches, reduce: reduce }) : null;

  function later(fn, ms) { timers.push(setTimeout(fn, ms)); }
  function on(name) { hero.classList.add(name); }

  function chain(fast) {
    if (chained) return;
    chained = true;
    var k = fast ? 0.35 : 1;
    later(function () { on("is-f1"); }, 400 * k);
    later(function () { on("is-f2"); }, 1300 * k);
    later(function () { on("is-f3"); }, 2200 * k);
    later(function () { on("is-f4"); }, 3100 * k);
    later(function () { on("is-split"); }, 4300 * k);
    later(function () { on("is-chosen"); }, 5500 * k);
    later(function () { on("is-owned"); }, 6500 * k);
    later(function () { on("is-final"); }, 7300 * k);
  }

  if (reduce) {
    hero.classList.add("dx-reduce", "is-stamped", "is-scanned", "is-f1", "is-f2", "is-f3", "is-f4", "is-split", "is-chosen", "is-owned", "is-final");
    return;
  }

  /* ---------------- phone ---------------- */
  if (phone.matches) {
    later(function () { on("is-stamped"); }, 400);
    var ran = false;
    function runPhone() {
      if (ran) return;
      ran = true;
      on("is-scanned");
      later(function () { chain(false); }, 900);
    }
    if (scan) scan.addEventListener("click", runPhone);
    if ("IntersectionObserver" in window && canvas) {
      var idle = null;
      new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) { if (!idle) idle = setTimeout(runPhone, 4500); }
          else if (idle) { clearTimeout(idle); idle = null; }
        });
      }, { threshold: 0.5 }).observe(canvas);
      new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting && entry.boundingClientRect.top < 0) runPhone();
        });
      }, { threshold: 0 }).observe(canvas);
    }
    return;
  }

  /* ---------------- desktop ---------------- */
  later(function () { on("is-stamped"); }, 500);
  later(function () { chain(false); }, 500 + 3200 + 900);

  var top = null;
  function onScroll() {
    if (top === null) top = hero.getBoundingClientRect().top + window.scrollY;
    var into = window.scrollY - top;
    if (into > 80 && !chained) { on("is-stamped"); chain(true); }
    if (stage && hero.classList.contains("is-final")) {
      var shift = Math.max(0, Math.min(into * 0.35, window.innerHeight * 0.4));
      stage.style.transform = "translateY(" + shift.toFixed(0) + "px)";
    }
  }
  window.addEventListener("scroll", onScroll, { passive: true });
  window.addEventListener("resize", function () { top = null; });
})();
