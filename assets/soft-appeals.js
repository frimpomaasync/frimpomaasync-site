/* ===========================================================================
   SOFT APPEALS SHELL BEHAVIOUR
   ---------------------------------------------------------------------------
   The small amount of shared wiring the four shells need. Everything here is
   opt-in through a data attribute, so a page gets only the behaviour it asks
   for, and nothing breaks on a page that asks for none of it.

     data-sa-seg          segmented control -> tab panels
     data-sa-open         opens the drawer named in the attribute
     data-sa-drawer       the drawer itself
     data-sa-close        closes the drawer it sits inside
     data-sa-search       filters [data-sa-item] blocks live
     data-sa-spy          highlights the topic rail link for the section in view
     data-sa-print        prints the page

   Loaded with defer, after fsnav.js, because it measures the nav fsnav builds.
   Plain ES5 in an IIFE for the same reason the rest of the site is: it has to
   run on an older iPhone with no build step in front of it.
   =========================================================================== */
(function () {
  "use strict";

  var doc = document;

  function all(sel, root) {
    return Array.prototype.slice.call((root || doc).querySelectorAll(sel));
  }

  /* -------------------------------------------------------------------------
     Nav height. Every sticky piece of shell chrome parks under the site bar.
     fsnav.js inserts #fs-nav on DOMContentLoaded, and its height changes when
     the chip row wraps, so this re-measures rather than hard-coding 76px.
     ------------------------------------------------------------------------- */
  function measureNav() {
    var nav = doc.getElementById("fs-nav");
    /* A fixed bar floats over the page and reserves no space, so shell chrome
       must clear it. A sticky bar is in flow and parks at 0 itself, so the
       chrome below it needs no offset at all. */
    var h = 0;
    if (nav) {
      var pos = window.getComputedStyle(nav).position;
      if (pos === "sticky" || pos === "fixed") h = nav.getBoundingClientRect().height;
    }
    doc.documentElement.style.setProperty("--sa-navh", Math.round(h) + "px");
  }

  function watchNav() {
    measureNav();
    var nav = doc.getElementById("fs-nav");
    if (nav && window.ResizeObserver) {
      new ResizeObserver(measureNav).observe(nav);
    } else {
      window.addEventListener("resize", measureNav);
    }
  }

  /* -------------------------------------------------------------------------
     Segmented control. Real tabs: roving tabindex, arrow keys, one panel shown.
     ------------------------------------------------------------------------- */
  function initSeg(seg) {
    var tabs = all("[role=tab]", seg);
    if (!tabs.length) return;

    function select(tab) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute("aria-selected", on ? "true" : "false");
        t.tabIndex = on ? 0 : -1;
        var panel = doc.getElementById(t.getAttribute("aria-controls"));
        if (panel) panel.hidden = !on;
      });
    }

    tabs.forEach(function (t, i) {
      t.addEventListener("click", function () {
        select(t);
      });
      t.addEventListener("keydown", function (e) {
        var next =
          e.key === "ArrowRight" || e.key === "ArrowDown"
            ? i + 1
            : e.key === "ArrowLeft" || e.key === "ArrowUp"
              ? i - 1
              : e.key === "Home"
                ? 0
                : e.key === "End"
                  ? tabs.length - 1
                  : null;
        if (next === null) return;
        e.preventDefault();
        var target = tabs[(next + tabs.length) % tabs.length];
        select(target);
        target.focus();
      });
    });

    var initial = tabs.filter(function (t) {
      return t.getAttribute("aria-selected") === "true";
    })[0];
    select(initial || tabs[0]);
  }

  /* -------------------------------------------------------------------------
     Drawer. A scrim, an Escape key, and focus returned to whatever opened it.
     No JavaScript dialog, alert or confirm anywhere: a modal browser dialog
     blocks every subsequent event on the page.
     ------------------------------------------------------------------------- */
  var openDrawer = null;
  var lastFocus = null;
  var scrim = null;

  function ensureScrim() {
    if (scrim) return scrim;
    scrim = doc.createElement("div");
    scrim.className = "sa-scrim";
    scrim.setAttribute("hidden", "");
    scrim.addEventListener("click", closeDrawer);
    doc.body.appendChild(scrim);
    return scrim;
  }

  function showDrawer(id, opener) {
    var el = doc.getElementById(id);
    if (!el) return;
    lastFocus = opener || doc.activeElement;
    openDrawer = el;
    ensureScrim().removeAttribute("hidden");
    /* Two frames, not one: the element has to be painted in its hidden state
       before the class change can animate from it. */
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        scrim.classList.add("is-open");
        el.classList.add("is-open");
      });
    });
    el.setAttribute("aria-hidden", "false");
    var focusable = el.querySelector("[data-sa-close], button, a[href], input, select, textarea");
    if (focusable) focusable.focus();
  }

  function closeDrawer() {
    if (!openDrawer) return;
    openDrawer.classList.remove("is-open");
    openDrawer.setAttribute("aria-hidden", "true");
    if (scrim) {
      scrim.classList.remove("is-open");
      var s = scrim;
      window.setTimeout(function () {
        if (!openDrawer) s.setAttribute("hidden", "");
      }, 320);
    }
    openDrawer = null;
    if (lastFocus && lastFocus.focus) lastFocus.focus();
    lastFocus = null;
  }

  /* Keeps the drawer inside the tab order it should own. */
  function trapTab(e) {
    if (!openDrawer || e.key !== "Tab") return;
    var items = all(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
      openDrawer,
    ).filter(function (el) {
      return !el.disabled && el.offsetParent !== null;
    });
    if (!items.length) return;
    var first = items[0];
    var last = items[items.length - 1];
    if (e.shiftKey && doc.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && doc.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  /* -------------------------------------------------------------------------
     Live search over blocks. Matches on the text the block declares in
     data-sa-terms, falling back to its own text content.
     ------------------------------------------------------------------------- */
  function initSearch(input) {
    var scope = doc.getElementById(input.getAttribute("data-sa-search")) || doc;
    var items = all("[data-sa-item]", scope);
    var countEl = input.getAttribute("data-sa-count")
      ? doc.getElementById(input.getAttribute("data-sa-count"))
      : null;
    var emptyEl = input.getAttribute("data-sa-empty")
      ? doc.getElementById(input.getAttribute("data-sa-empty"))
      : null;

    var haystack = items.map(function (el) {
      return (el.getAttribute("data-sa-terms") || el.textContent || "").toLowerCase();
    });

    function run() {
      var q = input.value.trim().toLowerCase();
      var shown = 0;
      items.forEach(function (el, i) {
        var hit = !q || haystack[i].indexOf(q) !== -1;
        el.hidden = !hit;
        if (hit) shown++;
        /* An answer that matched should be readable without a second click. */
        if (q && hit && el.tagName === "DETAILS") el.open = true;
        if (!q && el.tagName === "DETAILS") el.open = false;
      });
      /* A group with nothing left in it hides its own heading too. */
      all("[data-sa-group]", scope).forEach(function (g) {
        var live = all("[data-sa-item]", g).filter(function (el) {
          return !el.hidden;
        });
        g.hidden = live.length === 0;
      });
      if (countEl) countEl.textContent = String(shown);
      if (emptyEl) emptyEl.hidden = shown !== 0;
      updateCounts();
    }

    input.addEventListener("input", run);
    input.addEventListener("search", run);
    run();
  }

  /* -------------------------------------------------------------------------
     Live counts on a topic rail. Typed numbers drift the moment an answer is
     added; these are read off the page, and they follow the search.
     ------------------------------------------------------------------------- */
  function updateCounts() {
    all("[data-sa-countfor]").forEach(function (el) {
      var group = doc.getElementById(el.getAttribute("data-sa-countfor"));
      if (!group) return;
      el.textContent = all("[data-sa-item]", group).filter(function (x) {
        return !x.hidden;
      }).length;
    });
  }

  /* -------------------------------------------------------------------------
     Scrollspy for a topic rail.
     ------------------------------------------------------------------------- */
  function initSpy(rail) {
    var links = all("a[href^='#']", rail);
    if (!links.length || !window.IntersectionObserver) return;

    var byId = {};
    var targets = [];
    links.forEach(function (a) {
      var id = a.getAttribute("href").slice(1);
      var t = doc.getElementById(id);
      if (!t) return;
      byId[id] = a;
      targets.push(t);
    });

    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (e) {
          if (!e.isIntersecting) return;
          links.forEach(function (a) {
            a.classList.remove("is-on");
          });
          var a = byId[e.target.id];
          if (a) a.classList.add("is-on");
        });
      },
      { rootMargin: "-20% 0px -68% 0px", threshold: 0 },
    );

    targets.forEach(function (t) {
      io.observe(t);
    });
  }

  /* -------------------------------------------------------------------------
     Boot
     ------------------------------------------------------------------------- */
  function boot() {
    watchNav();

    all("[data-sa-seg]").forEach(initSeg);
    updateCounts();
    all("[data-sa-search]").forEach(initSearch);
    all("[data-sa-spy]").forEach(initSpy);

    doc.addEventListener("click", function (e) {
      var opener = e.target.closest ? e.target.closest("[data-sa-open]") : null;
      if (opener) {
        e.preventDefault();
        showDrawer(opener.getAttribute("data-sa-open"), opener);
        return;
      }
      if (e.target.closest && e.target.closest("[data-sa-close]")) {
        e.preventDefault();
        closeDrawer();
        return;
      }
      var printer = e.target.closest ? e.target.closest("[data-sa-print]") : null;
      if (printer) {
        e.preventDefault();
        window.print();
      }
    });

    doc.addEventListener("keydown", function (e) {
      if (e.key === "Escape") closeDrawer();
      trapTab(e);
    });
  }

  /* fsnav.js is deferred too, and defer preserves document order, so by the
     time this runs the nav exists. The readyState guard covers the case where
     this file is ever loaded some other way. */
  if (doc.readyState === "loading") {
    doc.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }

  /* Exported for the shell pages that build their own views and need to open a
     record from a row click. */
  window.SoftAppealsShell = {
    openDrawer: showDrawer,
    closeDrawer: closeDrawer,
    measureNav: measureNav,
  };
})();
