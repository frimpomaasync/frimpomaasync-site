/* ---------------------------------------------------------------------------
   The Invisible Office · home hero script
   ---------------------------------------------------------------------------
   Adds the state classes; io-maze.js reads them every frame and draws.
     load          .io-js, .is-intro (signal walks the maze), .is-pinned (button)
     click/scroll  .is-built (walls sink, the path lights), then .is-staffed
     phone         scenes are stacked; the second maze starts solved and the
                   observer builds each scene as it arrives
     idle          a desktop builds itself after eleven seconds
     reduced       every state at once, one solved frame
   --------------------------------------------------------------------------- */
(function () {
  var hero = document.querySelector(".io-hero");
  if (!hero || !window.IoMaze) return;

  var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var phone = window.matchMedia("(max-width: 760px)");
  var built = false;
  var staffed = false;

  hero.classList.add("io-js");

  var mazeA = hero.querySelector("[data-maze]");
  var mazeB = hero.querySelector("[data-maze-solved]");
  var cols = phone.matches ? 7 : 13;
  var rows = phone.matches ? 10 : 8;
  if (mazeA) new window.IoMaze(mazeA, hero, { cols: cols, rows: rows, phone: phone.matches, reduce: reduce, solved: false });
  if (mazeB && phone.matches) new window.IoMaze(mazeB, hero, { cols: cols, rows: rows, phone: true, reduce: reduce, solved: true });

  function staff() {
    if (staffed) return;
    staffed = true;
    hero.classList.add("is-staffed");
  }

  function build() {
    if (built) return;
    built = true;
    hero.classList.add("is-built");
    setTimeout(staff, phone.matches ? 1400 : 3200);
  }

  if (reduce) {
    hero.classList.add("io-reduce", "is-intro", "is-pinned");
    build();
    staff();
    return;
  }

  setTimeout(function () { hero.classList.add("is-intro"); }, 80);
  setTimeout(function () { hero.classList.add("is-pinned"); }, 4200);

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

  function onScroll() {
    if (phone.matches || built) return;
    var top = hero.getBoundingClientRect().top + window.scrollY;
    if (window.scrollY - top > 90) build();
  }
  window.addEventListener("scroll", onScroll, { passive: true });

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
