/* ---------------------------------------------------------------------------
   The Denial X-Ray · Soft Appeals hero script
   ---------------------------------------------------------------------------
   Nothing here paints. Every frame is CSS in denial-xray.css; this file adds
   classes to the section and moves the lens.

   Desktop
     load        .dx-js, then .is-stamped
     lens        follows a fine pointer over the paper; when it is idle, or
                 the device has no fine pointer, the lens scans on its own.
                 Passing over a hidden layer pins it (.is-f1 to .is-f4).
     all found   .is-split, .is-chosen, .is-room, .is-owned, .is-final in turn
     scroll      80px into the section fast-forwards the whole chain
     descent     once the room is open, scrolling moves it down under the
                 Maryland numbers that rise over it

   Phone
     tap SCAN    .is-scanned reveals the four findings one by one, then the
                 same chain, with the pathways and room switched on in flow
     no tap      four seconds with the paper in view runs it anyway
   --------------------------------------------------------------------------- */
(function () {
  var hero = document.querySelector(".dx-hero");
  if (!hero) return;

  var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var phone = window.matchMedia("(max-width: 760px)");
  var fine = window.matchMedia("(pointer: fine)").matches;
  var doc = hero.querySelector(".dx-doc");
  var paths = hero.querySelector(".dx-paths");
  var room = hero.querySelector(".dx-room");
  var scan = hero.querySelector(".dx-scan");
  var calls = Array.prototype.slice.call(hero.querySelectorAll(".dx-call"));
  var found = 0;
  var chained = false;
  var timers = [];

  hero.classList.add("dx-js");

  function later(fn, ms) { timers.push(setTimeout(fn, ms)); }
  function on(name) { hero.classList.add(name); }

  function chain(fast) {
    if (chained) return;
    chained = true;
    var k = fast ? 0.35 : 1;
    later(function () { if (paths) paths.classList.add("is-on"); on("is-split"); }, 500 * k);
    later(function () { on("is-chosen"); }, 1500 * k);
    later(function () { if (room) room.classList.add("is-on"); on("is-room"); }, 2600 * k);
    later(function () { on("is-owned"); }, 3600 * k);
    later(function () { on("is-final"); }, 4100 * k);
  }

  function findAll() {
    on("is-f1"); on("is-f2"); on("is-f3"); on("is-f4");
    found = 4;
  }

  if (reduce) {
    hero.classList.add("dx-reduce", "is-stamped", "is-scanned");
    findAll();
    if (paths) paths.classList.add("is-on");
    if (room) room.classList.add("is-on");
    ["is-split", "is-chosen", "is-room", "is-owned", "is-final"].forEach(on);
    return;
  }

  later(function () { on("is-stamped"); }, 350);

  /* ---------------- phone ---------------- */
  if (phone.matches) {
    var ran = false;
    function runPhone() {
      if (ran) return;
      ran = true;
      on("is-scanned");
      later(function () { on("is-f1"); }, 120);
      later(function () { on("is-f2"); }, 350);
      later(function () { on("is-f3"); }, 700);
      later(function () { on("is-f4"); }, 1050);
      later(function () { chain(false); }, 1300);
    }
    if (scan) scan.addEventListener("click", runPhone);
    if ("IntersectionObserver" in window && doc) {
      var idle = null;
      var watcher = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            if (!idle) idle = setTimeout(runPhone, 4000);
          } else if (idle) {
            clearTimeout(idle);
            idle = null;
          }
        });
      }, { threshold: 0.5 });
      watcher.observe(doc);
      var pastDoc = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting && entry.boundingClientRect.top < 0) runPhone();
        });
      }, { threshold: 0 });
      pastDoc.observe(doc);
    }
    return;
  }

  /* ---------------- desktop ---------------- */
  if (!doc) return;
  var lens = { x: 0.08, y: 0.1, tx: 0.08, ty: 0.1, r: 0 };
  var auto = true;
  var idleTimer = null;
  var legs = calls.map(function (call) {
    return {
      x: parseFloat(call.style.getPropertyValue("--x")) / 100,
      y: parseFloat(call.style.getPropertyValue("--y")) / 100,
      el: call
    };
  });
  var leg = 0;
  var legStart = 0;
  var pointerSeen = false;

  function setLens() {
    doc.style.setProperty("--lx", (lens.x * 100).toFixed(2) + "%");
    doc.style.setProperty("--ly", (lens.y * 100).toFixed(2) + "%");
    doc.style.setProperty("--lr", lens.r.toFixed(1) + "px");
  }

  function checkFound() {
    if (lens.r < 100) return;
    var rect = doc.getBoundingClientRect();
    var px = lens.x * rect.width;
    var py = lens.y * rect.height;
    legs.forEach(function (item, index) {
      if (item.done) return;
      var dx = item.x * rect.width - px;
      var dy = item.y * rect.height - py;
      if (Math.sqrt(dx * dx + dy * dy) < 96) {
        item.done = true;
        found += 1;
        on("is-f" + (index + 1));
        if (found === legs.length) later(function () { chain(false); }, 300);
      }
    });
  }

  function frame(now) {
    if (hero.classList.contains("is-room")) { lens.r = 0; setLens(); return; }
    if (auto && legs.length) {
      if (!legStart) legStart = now;
      var t = Math.min(1, (now - legStart) / 1100);
      var target = legs[leg % legs.length];
      var prev = legs[(leg + legs.length - 1) % legs.length];
      var from = leg === 0 ? { x: 0.08, y: 0.1 } : prev;
      var e = t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t;
      lens.tx = from.x + (target.x - from.x) * e;
      lens.ty = from.y + (target.y - from.y) * e;
      if (t >= 1) {
        leg += 1;
        legStart = now;
        if (leg >= legs.length * 2) auto = false;
      }
    }
    lens.x += (lens.tx - lens.x) * 0.18;
    lens.y += (lens.ty - lens.y) * 0.18;
    var wantR = hero.classList.contains("is-stamped") ? 130 : 0;
    lens.r += (wantR - lens.r) * 0.12;
    setLens();
    checkFound();
    window.requestAnimationFrame(frame);
  }

  if (fine) {
    doc.addEventListener("pointermove", function (event) {
      var rect = doc.getBoundingClientRect();
      lens.tx = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
      lens.ty = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height));
      auto = false;
      pointerSeen = true;
      if (idleTimer) clearTimeout(idleTimer);
      idleTimer = setTimeout(function () { auto = true; legStart = 0; }, 2200);
    });
  }

  later(function () { window.requestAnimationFrame(frame); }, 900);

  /* Scroll inside the pinned section: fast-forward, then let the room descend. */
  var top = null;
  function onScroll() {
    if (top === null) top = hero.getBoundingClientRect().top + window.scrollY;
    var into = window.scrollY - top;
    if (into > 80 && !chained) { findAll(); chain(true); }
    if (room && hero.classList.contains("is-room")) {
      var shift = Math.max(0, Math.min(into * 0.35, window.innerHeight * 0.4));
      room.style.transform = "translateY(" + shift.toFixed(0) + "px)";
    }
  }
  window.addEventListener("scroll", onScroll, { passive: true });
  window.addEventListener("resize", function () { top = null; });
})();
