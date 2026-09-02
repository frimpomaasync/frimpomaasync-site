/* ---------------------------------------------------------------------------
   The Invisible Office · home hero script
   ---------------------------------------------------------------------------
   Moves the section through its states. Nothing here paints: every frame is
   CSS in invisible-office.css, this file only adds classes.

     load          .io-js, then .is-intro (the pile), then .is-pinned (button)
     click/scroll  .is-built (windows travel in), then .is-staffed (promise)
     phone         scenes are stacked, so the observer builds each one as it
                   scrolls into view; the button just scrolls to the next scene
     idle          on a desktop the office builds itself after eleven seconds,
                   so nobody is left on a button screen
     reduced       every state at once, no travel
   --------------------------------------------------------------------------- */
(function () {
  var hero = document.querySelector(".io-hero");
  if (!hero) return;

  var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var phone = window.matchMedia("(max-width: 760px)");
  var built = false;
  var staffed = false;

  hero.classList.add("io-js");

  function staff() {
    if (staffed) return;
    staffed = true;
    hero.classList.add("is-staffed");
  }

  function build() {
    if (built) return;
    built = true;
    hero.classList.add("is-built");
    setTimeout(staff, phone.matches ? 1200 : 1900);
  }

  if (reduce) {
    hero.classList.add("io-reduce", "is-intro", "is-pinned");
    build();
    staff();
    return;
  }

  setTimeout(function () { hero.classList.add("is-intro"); }, 80);
  setTimeout(function () { hero.classList.add("is-pinned"); }, 3700);

  var button = hero.querySelector(".io-build");
  var sceneWindows = hero.querySelector(".io-s2");
  var scenePromise = hero.querySelector(".io-s3");

  if (button) {
    button.addEventListener("click", function () {
      if (phone.matches && sceneWindows) {
        sceneWindows.scrollIntoView({ behavior: "smooth", block: "start" });
        setTimeout(build, 350);
      } else {
        build();
      }
    });
  }

  /* Desktop: the stage is pinned, so 90px of scroll inside the section is the
     visitor asking for the transformation. */
  function onScroll() {
    if (phone.matches || built) return;
    var top = hero.getBoundingClientRect().top + window.scrollY;
    if (window.scrollY - top > 90) build();
  }
  window.addEventListener("scroll", onScroll, { passive: true });

  /* Phone: each stacked scene builds as it arrives. */
  if ("IntersectionObserver" in window && sceneWindows && scenePromise) {
    var watcher = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting || !phone.matches) return;
        if (entry.target === sceneWindows) build();
        if (entry.target === scenePromise) { build(); staff(); }
      });
    }, { threshold: 0.2 });
    watcher.observe(sceneWindows);
    watcher.observe(scenePromise);
  }

  setTimeout(function () { if (!phone.matches) build(); }, 11000);
})();
