/* fsnav.js v810 · site chrome for the 2026-07 customer path.
   Ships the sticky nav, footer, sticky CTA bar, section rail, chat widget,
   page cross-fade veil, and the motion engine (data-reveal / data-stagger / data-count).
   Every page includes this once before </body>. Design source: design_handoff_frimpomaasync_site.
   Per-page config via <body> attributes: data-sk-sections, data-sk-bar, data-sk-biz, data-sk-nochrome. */
(function () {
  "use strict";
  if (window.__fsnav810) return;
  window.__fsnav810 = true;

  var BOOK = "https://calendar.app.google/DkRJFRA3G6W6d8E48";
  var CHAT_API = "https://synkasa-api.dawn-boat-ec20.workers.dev/chat";
  var SERIF = "'Iowan Old Style','Palatino Linotype',Palatino,'Hoefler Text',Garamond,serif";
  var MONO = "ui-monospace,'SF Mono',Menlo,Consolas,monospace";
  var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var body = document.body;
  var cfg = {
    sections: (body.getAttribute("data-sk-sections") || "").split(",").map(function (s) { return s.trim(); }).filter(Boolean),
    barText: body.getAttribute("data-sk-bar") || "Live in 7 days, or you don't pay.",
    noChrome: body.hasAttribute("data-sk-nochrome")
  };

  /* ---------- base css ---------- */
  var css = document.createElement("style");
  css.textContent = [
    "@keyframes skcDot{0%,100%{transform:translateY(0);opacity:.3}50%{transform:translateY(-3px);opacity:1}}",
    /* Ships here, not in journey.css: 14 pages carry fsnav without journey.css,
       and an unstyled skip link would just sit visible at the top of them. */
    ".sr-only{position:absolute;width:1px;height:1px;margin:-1px;padding:0;border:0;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap}",
    ".skip-link:focus{position:fixed;top:12px;left:12px;z-index:200;width:auto;height:auto;margin:0;padding:12px 18px;clip:auto;clip-path:none;background:#101426;color:#FFFFFF;border-radius:8px;font-size:15px;text-decoration:none}",
    "main:focus{outline:none}",
    "#fs-nav a{text-decoration:none}",
    "@media (hover:hover){#fs-nav [data-navlink]:hover{color:#101426!important}#fs-nav [data-navcta]:hover{background:#C2501C!important;color:#FFF!important}#fs-bar [data-navcta]:hover{background:#C2501C!important;color:#FFF!important}#fs-bar [data-navlink]:hover{color:#101426!important}#fs-foot a:hover{color:#FFFFFF}#fs-chat-fab:hover{background:#C2501C!important}}",
    "#fs-foot a{text-decoration:none;color:rgba(242,244,249,.72)}",
    /* The rail needs the 1180px column plus about 170px of clear margin on each
       side. At 1360 it had 90px and its labels ran under the hero copy, which is
       what "RESPONSE SCRIPT" was doing across the first paragraph of free.html
       at 1440 on 30 July. Below the width where it fits cleanly, it is gone. */
    "@media (max-width:1520px){#fs-rail{display:none!important}}",
    /* Tablets. The three-column bar needs about 910px of room: the outer columns are
       both 1fr, so the links get half of what is left once the wordmark is placed,
       and 339px of links need 960px of window to win that half. Below
       that the links get their own row. The old breakpoint was 1080, which forced
       a stacked 132px bar across 901 to 1080 that had no reason to stack, and a
       1024px laptop wore it. */
    "@media (max-width:960px){#fs-nav .fs-grid{grid-template-columns:1fr auto!important;padding:10px 20px!important;row-gap:8px!important}" +
      /* Left, not stretched. The wordmark is the grid item in the 1fr column and
         over a photograph it carries an ink chip, so stretching painted a
         760px-wide block across the top of the hero. */
      "#fs-nav .fs-grid > a[data-navmark]{justify-self:start}" +
      "#fs-nav .fs-grid nav{order:3;grid-column:1 / -1;flex-wrap:nowrap;justify-content:flex-start}}",
    "@media (max-width:700px){#fs-nav .fs-grid{grid-template-columns:1fr auto!important}#fs-nav .fs-grid nav{order:3;grid-column:1 / -1}#fs-nav [data-fs-chat]{display:none!important}}",
    /* An iPhone SE is 320 css px. The wordmark is set nowrap at 14px with .2em
       of tracking, which is 171px, and the booking button is 118px. With the
       40px of bar padding that is 329px in a 320px window, so the last letters
       of FRIMPOMAASYNC ran under the button. Tighten the tracking, not the
       name. */
    "@media (max-width:359px){#fs-nav .fs-grid > a[data-navmark]{font-size:13px!important;letter-spacing:.08em!important}}",
    /* Under 16px iOS zooms the page when the field takes focus. An iPad in
       landscape is 1024 to 1366 css px, so ask the pointer, not the width. */
    "@media (max-width:900px),(pointer:coarse){#fs-chat-inp{font-size:16px!important}}",
    /* The footer carried 152px of padding on a phone. */
    "@media (max-width:620px){#fs-foot > div{padding:38px 20px 52px!important;gap:24px!important}}",
    /* The chrome links are set small on purpose and a 12px-tall link is a
       coin toss under a thumb. Vertical padding on an inline link grows what
       the finger can hit without moving the line, so the bar, the nav and the
       footer keep the exact layout they have on a mouse. */
    "@media (pointer:coarse){#fs-nav [data-navlink],#fs-bar [data-navlink]{padding:7px 0!important}#fs-foot a{padding:5px 0!important}}",
    "@media (prefers-reduced-motion: reduce){#fs-veil{display:none!important}#fs-bar{transition:none!important}#fs-chat-panel span[style*='skcDot']{animation:none!important}}"
  ].join("\n");
  document.head.appendChild(css);

  /* ---------- nav ---------- */
  function activeKey() {
    var p = location.pathname.replace(/\.html$/, "").replace(/\/$/, "") || "/";
    if (p.indexOf("/synkasa") === 0 || p.indexOf("/client-catcher") === 0) return "synkasa";
    if (p.indexOf("/siesie") === 0) return "siesie";
    if (p.indexOf("/soft-appeals") === 0) return "softappeals";
    if (p.indexOf("/portfolio") === 0) return "proof";
    if (p.indexOf("/free") === 0) return "free";
    return "none";
  }
  /* Which of Soft Appeals' own three pages is open. Only read when activeKey()
     is "softappeals", so the exact-match order below is safe. */
  function softKey() {
    var p = location.pathname.replace(/\.html$/, "").replace(/\/$/, "");
    if (p.indexOf("/soft-appeals-audit") === 0) return "soft-audit";
    if (p.indexOf("/soft-appeals-your-data") === 0) return "soft-data";
    return "soft-offer";
  }
  function navLink(label, href, key, on) {
    return '<a data-navlink href="' + href + '" style="color:' + (on === key ? "#101426" : "rgba(16,20,38,.58)") + ';transition:color .25s ease">' + label + "</a>";
  }
  var isSoft = activeKey() === "softappeals";
  if (!cfg.noChrome) {
    var on = activeKey();
    var onSoft = isSoft ? softKey() : "";
    var nav = document.createElement("header");
    nav.id = "fs-nav";
    /* Sticky, in flow, on every page. The handoff spec puts the bar above the
       hero rather than floating over it, and the hero reserves 68px for it. That
       also removes the whole class of collisions the fixed transparent bar
       caused: on a touch width its seven chips wrapped into three rows and
       landed on the headline. A bar in normal flow cannot overlap anything. */
    var heroIsPhotographic = !!document.querySelector("[data-photo-hero]");
    nav.style.cssText = (body.hasAttribute("data-cinematic") && !heroIsPhotographic
      ? "position:fixed;top:0;left:0;right:0;"
      : "position:sticky;top:0;") +
      "z-index:40;background:rgba(255,255,255,.9);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);border-bottom:1px solid rgba(16,20,38,.08)";
    /* Soft Appeals sells to medical practices, and nothing else on this site does.
       A practice manager checking us out mid-decision must never be one tap
       from a plumbing demo, so these pages get their own bar: Soft Appeals' three
       pages, Soft Appeals' wordmark, and no front-desk chat. The parent brand
       stays visible but quiet, and the shared footer below still shows the
       real business behind it, which is the thing that makes a contingency
       offer in healthcare look legitimate rather than fly-by-night. */
    var softNav =
      navLink("The offer", "/soft-appeals", "soft-offer", onSoft) +
      navLink("The audit", "/soft-appeals-audit", "soft-audit", onSoft) +
      navLink("Your data", "/soft-appeals-your-data", "soft-data", onSoft);
    var siteNav =
      navLink("SynKasa", "/synkasa", "synkasa", on) +
      navLink("Siesie", "/siesie", "siesie", on) +
      navLink("Soft Appeals", "/soft-appeals", "softappeals", on) +
      navLink("Proof", "/portfolio", "proof", on) +
      navLink("Free", "/free", "free", on);
    nav.innerHTML =
      '<div class="fs-grid" style="max-width:1180px;margin:0 auto;padding:18px 24px 16px;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:16px">' +
      '<nav style="display:flex;gap:clamp(14px,1.8vw,26px);flex-wrap:wrap;font-size:10.5px;letter-spacing:.15em;text-transform:uppercase">' +
      (isSoft ? softNav : siteNav) +
      "</nav>" +
      '<a data-navlink data-navmark href="' + (isSoft ? "/soft-appeals" : "/") + '" style="font-family:' + SERIF + ';font-size:clamp(14px,1.5vw,18px);letter-spacing:.2em;text-transform:uppercase;color:#101426;white-space:nowrap">' + (isSoft ? "Soft Appeals" : "frimpomaasync") + "</a>" +
      '<div style="display:flex;align-items:center;gap:clamp(14px,1.8vw,22px);justify-content:flex-end;flex-wrap:wrap">' +
      (isSoft
        ? '<a data-navlink href="/" style="font-size:10.5px;letter-spacing:.15em;text-transform:uppercase;color:rgba(16,20,38,.58);transition:color .25s ease">by frimpomaasync</a>'
        : '<button type="button" data-navlink data-fs-chat style="background:none;border:0;padding:0;font-family:inherit;font-size:10.5px;letter-spacing:.15em;text-transform:uppercase;color:rgba(16,20,38,.58);cursor:pointer;transition:color .25s ease">See it answer</button>') +
      '<a data-navcta href="' + BOOK + '" style="background:#101426;color:#FFFFFF;padding:10px 15px;border-radius:5px;font-size:10.5px;letter-spacing:.15em;text-transform:uppercase;white-space:nowrap;transition:background .25s ease">' + (isSoft ? "Free audit" : "Book a call") + "</a>" +
      "</div></div>";
    body.insertBefore(nav, body.firstChild);

    /* One skip link for every page: a keyboard reaches the page's own content
       without tabbing the nav, the chat control and the booking link first. */
    /* 14 pages (privacy, terms, the blog posts, 404 and friends) never had a
       <main>, so fall back to the page's own first content block. */
    var target =
      document.querySelector("main") ||
      document.querySelector("body > .wrap article") ||
      document.querySelector("body > .wrap") ||
      document.querySelector("body > section") ||
      document.querySelector("body > div section");
    if (target) {
      if (!target.id) target.id = "fs-main";
      var skip = document.createElement("a");
      skip.className = "sr-only skip-link";
      skip.href = "#" + target.id;
      skip.textContent = "Skip to the main content";
      skip.addEventListener("click", function () {
        if (!target.hasAttribute("tabindex")) target.setAttribute("tabindex", "-1");
        target.focus();
      });
      body.insertBefore(skip, body.firstChild);
    }
    if (body.hasAttribute("data-cinematic")) {
      var syncCinematicNavHeight = function () {
        var height = nav.offsetHeight + "px";
        document.documentElement.style.setProperty("--nav-height", height);
        document.documentElement.style.setProperty("--cinematic-nav-height", height);
      };
      syncCinematicNavHeight();
      if ("ResizeObserver" in window) {
        new ResizeObserver(syncCinematicNavHeight).observe(nav);
      }
    }
  }

  /* ---------- cinematic state ---------- */
  function setActiveStoryStep(step, group) {
    group.forEach(function (item) {
      item.classList.toggle("is-active", item === step);
    });
  }
  if (body.hasAttribute("data-cinematic")) {
    var cinematicHero = document.querySelector("[data-cinematic-hero]");
    var storySteps = Array.prototype.slice.call(document.querySelectorAll("[data-story-step]"));
    var cinematicNav = document.getElementById("fs-nav");

    if (!("IntersectionObserver" in window)) {
      storySteps.forEach(function (step) {
        step.style.opacity = "1";
        step.style.transform = "none";
        step.style.transition = "none";
      });
    } else {
      if (cinematicHero && cinematicNav) {
        var heroBoundary = document.createElement("span");
        heroBoundary.setAttribute("aria-hidden", "true");
        heroBoundary.style.cssText = "position:absolute;top:0;left:0;width:1px;height:1px;pointer-events:none";
        cinematicHero.appendChild(heroBoundary);
        var heroObserver = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) cinematicNav.classList.remove("is-past-hero");
          });
        });
        heroObserver.observe(cinematicHero);
        var heroBoundaryObserver = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            cinematicNav.classList.toggle("is-past-hero", !entry.isIntersecting);
          });
        });
        heroBoundaryObserver.observe(heroBoundary);
      }

      if (reduce) {
        storySteps.forEach(function (step) {
          step.style.opacity = "1";
          step.style.transform = "none";
          step.style.transition = "none";
        });
      } else if (storySteps.length) {
        /* Two steps can straddle the band at once. Pick the one nearest the
           band instead of whichever entry happened to arrive last, so the
           active step is the same every time you reach a given scroll spot. */
        var inBand = [];
        var storyObserver = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            var at = inBand.indexOf(entry.target);
            if (entry.isIntersecting) {
              if (at === -1) inBand.push(entry.target);
            } else if (at !== -1) {
              inBand.splice(at, 1);
            }
          });
          if (!inBand.length) return;
          var band = window.innerHeight * 0.45;
          var best = null;
          var bestDistance = Infinity;
          storySteps.forEach(function (step) {
            if (inBand.indexOf(step) === -1) return;
            var rect = step.getBoundingClientRect();
            var distance = Math.abs((rect.top + rect.bottom) / 2 - band);
            if (distance < bestDistance) {
              bestDistance = distance;
              best = step;
            }
          });
          if (best) setActiveStoryStep(best, storySteps);
        }, { rootMargin: "-38% 0px -48% 0px" });
        storySteps.forEach(function (step) { storyObserver.observe(step); });
      }
    }
  }

  /* ---------- footer ---------- */
  if (!cfg.noChrome) {
    var foot = document.createElement("footer");
    foot.id = "fs-foot";
    foot.style.cssText = "background:#101426;color:rgba(242,244,249,.6);border-top:1px solid rgba(242,244,249,.12)";
    foot.innerHTML =
      '<div style="max-width:1180px;margin:0 auto;padding:56px 24px 96px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:32px;align-items:start">' +
      '<div><div style="font-family:' + SERIF + ';font-size:19px;color:#F2F4F9">frimpomaasync</div>' +
      '<div style="margin-top:10px;font-size:13.5px;line-height:1.6">Built, wired, and cared for by NaNa Frimpomaa.</div></div>' +
      '<div style="display:flex;flex-direction:column;gap:10px;font-size:14px"><a href="/synkasa">SynKasa</a><a href="/siesie">Siesie</a><a href="/soft-appeals">Soft Appeals</a><a href="/portfolio">Proof</a></div>' +
      '<div style="display:flex;flex-direction:column;gap:10px;font-size:14px"><a href="/free">Free</a><a href="/som">Som</a><a href="/blog/">Blog</a><a href="/fit">Find your fit</a></div>' +
      '<div style="display:flex;flex-direction:column;gap:10px;font-size:14px"><a href="/operations-map">Operations Map</a><a href="/method">The method</a><a href="/results">Evidence</a><a href="/comparison">Compare</a><a href="/about">About</a><a href="/data">Your data</a></div>' +
      '<div style="display:flex;flex-direction:column;gap:10px;font-size:14px"><a href="' + BOOK + '">Book a call</a><a href="/privacy">Privacy</a><a href="/terms">Terms</a>' +
      '<div style="margin-top:14px;font-family:' + MONO + ';font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:rgba(242,244,249,.34)">Watch it work before you pay</div></div>' +
      "</div>";
    body.appendChild(foot);
  }

  /* ---------- sticky CTA bar ---------- */
  if (!cfg.noChrome) {
    var barEl = document.createElement("div");
    barEl.id = "fs-bar";
    barEl.style.cssText = "position:fixed;left:0;right:0;bottom:0;z-index:45;background:rgba(255,255,255,.94);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);border-top:1px solid rgba(16,20,38,.1);transform:translateY(115%);transition:transform .45s cubic-bezier(.2,.7,.2,1)";
    barEl.innerHTML =
      '<div style="max-width:1180px;margin:0 auto;padding:12px 92px 12px 24px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">' +
      '<span style="font-size:13px;color:rgba(16,20,38,.62)">' + cfg.barText + "</span>" +
      '<div style="flex:1"></div>' +
      (isSoft
        ? '<a data-navlink href="' + (onSoft === "soft-audit" ? "/soft-appeals-your-data" : "/soft-appeals-audit") + '" style="font-size:10.5px;letter-spacing:.15em;text-transform:uppercase;color:rgba(16,20,38,.58);transition:color .25s ease">' + (onSoft === "soft-audit" ? "Your data" : "See the report") + "</a>"
        : '<button type="button" data-navlink data-fs-chat style="font-family:inherit;font-size:10.5px;letter-spacing:.15em;text-transform:uppercase;cursor:pointer;background:none;border:0;padding:0;color:rgba(16,20,38,.58);transition:color .25s ease">See it answer</button>') +
      '<a data-navcta href="' + BOOK + '" style="background:#101426;color:#FFFFFF;padding:11px 16px;border-radius:5px;font-size:10.5px;letter-spacing:.15em;text-transform:uppercase;white-space:nowrap;text-decoration:none;transition:background .25s ease">' + (isSoft ? "Free audit" : "Book a call") + "</a>" +
      "</div>";
    body.appendChild(barEl);

    var barShown = false, queued = false;
    var paintBar = function () {
      var y = window.scrollY || 0;
      var nearBottom = y + window.innerHeight > body.scrollHeight - 240;
      var want = y > 620 && !nearBottom;
      if (want === barShown) return;
      barShown = want;
      barEl.style.transform = want ? "translateY(0)" : "translateY(115%)";
    };
    window.addEventListener("scroll", function () {
      if (queued) return;
      queued = true;
      requestAnimationFrame(function () { queued = false; paintBar(); });
    }, { passive: true });
    paintBar();
  }

  /* ---------- section rail ---------- */
  if (!cfg.noChrome && cfg.sections.length) {
    var rail = document.createElement("div");
    rail.id = "fs-rail";
    rail.style.cssText = "position:fixed;left:20px;top:50%;transform:translateY(-50%);z-index:35;display:flex;flex-direction:column;gap:13px";
    var railNodes = [], railActive = -1;
    var railEls = cfg.sections.map(function (l) { return document.querySelector('[data-screen-label="' + l + '"]'); });
    cfg.sections.forEach(function (label, i) {
      var b = document.createElement("button");
      b.type = "button";
      b.style.cssText = "display:flex;align-items:center;gap:10px;background:none;border:0;padding:0;cursor:pointer;font-family:inherit";
      b.innerHTML =
        '<span style="width:12px;height:2px;border-radius:2px;background:rgba(16,20,38,.28);transition:width .35s cubic-bezier(.2,.7,.2,1),background .35s ease"></span>' +
        '<span style="font-size:9.5px;letter-spacing:.13em;text-transform:uppercase;color:rgba(16,20,38,.34);transition:color .35s ease">' + label + "</span>";
      b.addEventListener("click", function () {
        var el = railEls[i];
        if (el) window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 80, behavior: reduce ? "auto" : "smooth" });
      });
      rail.appendChild(b);
      railNodes.push(b);
    });
    body.appendChild(rail);
    var paintRail = function (active) {
      if (active === railActive) return;
      railActive = active;
      railNodes.forEach(function (node, i) {
        var onN = i === active;
        node.firstElementChild.style.width = onN ? "26px" : "12px";
        node.firstElementChild.style.background = onN ? "#C2501C" : "rgba(16,20,38,.28)";
        node.lastElementChild.style.color = onN ? "#101426" : "rgba(16,20,38,.34)";
      });
    };
    if ("IntersectionObserver" in window) {
      var sio = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          if (!e.isIntersecting) return;
          var i = railEls.indexOf(e.target);
          if (i >= 0) paintRail(i);
        });
      }, { rootMargin: "-45% 0px -45% 0px" });
      railEls.forEach(function (el) { if (el) sio.observe(el); });
    }
    paintRail(0);
  }

  /* ---------- motion engine ---------- */
  if (!reduce && "IntersectionObserver" in window) {
    var els = Array.prototype.slice.call(document.querySelectorAll("[data-reveal]"));
    els.forEach(function (el) {
      el.style.opacity = "0";
      el.style.transform = "translateY(18px)";
      el.style.transition = "opacity var(--motion-section) var(--ease-out), transform var(--motion-section) var(--ease-out)";
      if (el.hasAttribute("data-stagger")) {
        var step = parseInt(el.getAttribute("data-stagger-step") || "50", 10);
        step = isNaN(step) ? 50 : Math.min(step, 50);
        Array.prototype.forEach.call(el.children, function (c, i) {
          c.style.opacity = "0";
          c.style.transform = "translateY(22px)";
          c.style.transition = "opacity var(--motion-section) var(--ease-out) " + (i * step) + "ms, transform var(--motion-section) var(--ease-out) " + (i * step) + "ms";
        });
      }
    });
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        e.target.style.opacity = "1";
        e.target.style.transform = "none";
        if (e.target.hasAttribute("data-stagger")) {
          Array.prototype.forEach.call(e.target.children, function (c) { c.style.opacity = "1"; c.style.transform = "none"; });
        }
        io.unobserve(e.target);
      });
    }, { rootMargin: "-8% 0px -8% 0px" });
    els.forEach(function (el) { io.observe(el); });

    var nums = Array.prototype.slice.call(document.querySelectorAll("[data-count]"));
    var cio = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        var el = e.target, to = parseFloat(el.getAttribute("data-count")) || 0, suf = el.getAttribute("data-suffix") || "";
        var t0 = performance.now(), dur = 900;
        var tick = function (now) {
          var p = Math.min(1, (now - t0) / dur), eased = 1 - Math.pow(1 - p, 3);
          el.textContent = Math.round(to * eased) + suf;
          if (p < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
        cio.unobserve(el);
      });
    }, { rootMargin: "-10% 0px -10% 0px" });
    nums.forEach(function (el) { cio.observe(el); });
  }

  /* ---------- page cross-fade veil ---------- */
  if (!reduce) {
    var veil = document.createElement("div");
    veil.id = "fs-veil";
    veil.style.cssText = "position:fixed;inset:0;z-index:90;background:#FFFFFF;opacity:1;pointer-events:none;transition:opacity .42s cubic-bezier(.2,.7,.2,1)";
    body.appendChild(veil);
    requestAnimationFrame(function () { veil.style.opacity = "0"; });
    document.addEventListener("click", function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var a = e.target.closest && e.target.closest("a[href]");
      if (!a || a.target === "_blank" || a.hasAttribute("download")) return;
      var href = a.getAttribute("href") || "";
      if (!href || href.charAt(0) === "#" || /^(https?:)?\/\//.test(href) || /^(mailto|tel):/.test(href)) return;
      var dest = href.split("#")[0];
      if (!dest || dest === location.pathname || dest === location.pathname.replace(/\.html$/, "")) return;
      e.preventDefault();
      veil.style.opacity = "1";
      setTimeout(function () { location.href = href; }, 320);
    });
  }

  /* ---------- chat widget ---------- */
  var chatOpen = false, thinking = false, history = [];
  function persona() {
    if (window.SK_SYSTEM) return window.SK_SYSTEM;
    var biz = window.SK_BIZ || body.getAttribute("data-sk-biz") || "plumber";
    return [
      "You are the front desk for a small owner-run " + biz + " business, answering an incoming text from a stranger.",
      "Rules: sound like a busy, warm human who works there. Two or three short sentences, never more.",
      "Get the job, the area and the urgency. Offer a real-sounding time slot when it makes sense.",
      "You are the SynKasa demo: if asked directly whether you are an AI or a person, say plainly that you are the SynKasa AI front desk being demoed. Otherwise just do the job. Never name any software vendor.",
      "If you can't decide something (discounts, disputes), say you'll pass it to the owner."
    ].join(" ");
  }
  function greeting() { return "Hi, you've reached the shop. What do you need, and what area are you in?"; }
  function bizLabel() { return (window.SK_BIZ || body.getAttribute("data-sk-biz") || "plumber").toLowerCase(); }

  var fab = document.createElement("button");
  fab.type = "button";
  fab.id = "fs-chat-fab";
  fab.setAttribute("aria-label", "Chat with the front desk");
  fab.setAttribute("aria-expanded", "false");
  fab.setAttribute("aria-controls", "fs-chat-panel");
  fab.style.cssText = "position:fixed;right:20px;bottom:20px;z-index:60;width:58px;height:58px;border-radius:999px;border:none;cursor:pointer;background:#101426;color:#FFFFFF;font-size:22px;box-shadow:0 18px 40px -18px rgba(0,0,0,.7);transition:background .25s ease";
  fab.textContent = "◎";
  /* The chat widget is the SynKasa front-desk demo. On Soft Appeals it would open a
     booking bot for a practice manager who came here about denied claims, so
     the launcher and its panel stay out of the document on those pages. The
     nodes are still built, which keeps every listener below unchanged; they
     simply have nothing to attach to on screen. */
  if (!isSoft) body.appendChild(fab);

  var panel = document.createElement("div");
  panel.id = "fs-chat-panel";
  panel.setAttribute("role", "dialog");
  panel.setAttribute("aria-label", "SynKasa front desk demo");
  panel.style.cssText = "position:fixed;right:20px;bottom:90px;z-index:60;width:min(360px,calc(100vw - 40px));background:#0C1226;color:#EDEFF4;border:1px solid rgba(242,244,249,.14);border-radius:22px;overflow:hidden;box-shadow:0 30px 70px -30px rgba(0,0,0,.8);display:none";
  panel.innerHTML =
    '<div style="padding:16px 18px;border-bottom:1px solid rgba(242,244,249,.12);display:flex;align-items:center;gap:10px">' +
    '<span style="width:8px;height:8px;border-radius:999px;background:#4FC07E"></span>' +
    '<span style="font-size:14px">SynKasa &middot; <span id="fs-chat-biz"></span></span>' +
    '<div style="flex:1"></div>' +
    '<button type="button" id="fs-chat-x" aria-label="Close chat" style="background:transparent;border:none;color:rgba(237,239,244,.6);font-size:18px;cursor:pointer;font-family:inherit">&times;</button></div>' +
    '<div id="fs-chat-msgs" role="log" aria-live="polite" aria-label="Conversation" style="padding:16px 18px;display:flex;flex-direction:column;gap:10px;max-height:300px;overflow-y:auto"></div>' +
    '<div style="display:flex;gap:8px;padding:12px 14px;border-top:1px solid rgba(242,244,249,.12)">' +
    '<input id="fs-chat-inp" aria-label="Your message to the front desk" placeholder="Ask it something awkward" style="flex:1;min-width:0;font-family:inherit;font-size:14px;padding:11px 13px;border-radius:5px;border:1px solid rgba(242,244,249,.16);background:rgba(242,244,249,.06);color:#EDEFF4;outline:none">' +
    '<button type="button" id="fs-chat-send" style="font-family:inherit;font-size:12.5px;letter-spacing:.02em;cursor:pointer;border:none;background:#C2501C;color:#FFF;padding:11px 16px;border-radius:5px">Send</button></div>';
  if (!isSoft) body.appendChild(panel);

  var msgs = panel.querySelector("#fs-chat-msgs");
  var inp = panel.querySelector("#fs-chat-inp");
  function bubble(who, text) {
    var d = document.createElement("div");
    d.style.cssText = "align-self:" + (who === "you" ? "flex-end" : "flex-start") +
      ";background:" + (who === "you" ? "#C2501C" : "rgba(242,244,249,.08)") +
      ";color:" + (who === "you" ? "#FFFFFF" : "#EDEFF4") +
      ";border-radius:16px;padding:11px 14px;font-size:14px;line-height:1.5;max-width:84%";
    d.textContent = text;
    msgs.appendChild(d);
    msgs.scrollTop = msgs.scrollHeight;
    return d;
  }
  var typing = document.createElement("div");
  typing.style.cssText = "align-self:flex-start;display:none;gap:4px;align-items:center;padding:6px 4px";
  typing.innerHTML = '<span style="width:5px;height:5px;border-radius:999px;background:rgba(237,239,244,.6);animation:skcDot 1.2s ease-in-out infinite"></span><span style="width:5px;height:5px;border-radius:999px;background:rgba(237,239,244,.6);animation:skcDot 1.2s ease-in-out .15s infinite"></span><span style="width:5px;height:5px;border-radius:999px;background:rgba(237,239,244,.6);animation:skcDot 1.2s ease-in-out .3s infinite"></span>';

  window.skResetChat = function () {
    history = [];
    msgs.innerHTML = "";
    bubble("bot", greeting());
    history.push({ role: "assistant", content: greeting() });
    panel.querySelector("#fs-chat-biz").textContent = bizLabel();
  };
  function setOpen(v) {
    var wasOpen = chatOpen;
    chatOpen = v;
    panel.style.display = v ? "block" : "none";
    fab.textContent = v ? "×" : "◎";
    fab.setAttribute("aria-expanded", v ? "true" : "false");
    fab.setAttribute("aria-label", v ? "Close chat" : "Chat with the front desk");
    if (v) {
      panel.querySelector("#fs-chat-biz").textContent = bizLabel();
      if (!msgs.children.length) window.skResetChat();
      setTimeout(function () { inp.focus(); }, 150);
    } else if (wasOpen) {
      /* Send the keyboard back where it came from, not to the top of the page. */
      fab.focus();
    }
  }
  fab.addEventListener("click", function () { setOpen(!chatOpen); });
  panel.querySelector("#fs-chat-x").addEventListener("click", function () { setOpen(false); });
  /* Document level, not panel level: Escape should close the chat wherever the
     keyboard happens to be sitting when someone wants out. */
  document.addEventListener("keydown", function (e) {
    if (chatOpen && (e.key === "Escape" || e.key === "Esc")) setOpen(false);
  });
  window.addEventListener("sk-open-chat", function () { setOpen(true); });
  Array.prototype.forEach.call(document.querySelectorAll("[data-fs-chat]"), function (b) {
    b.addEventListener("click", function () { setOpen(true); });
  });
  /* legacy globals some pages still call */
  window.skToggleFloat = function () { setOpen(!chatOpen); };
  window.skToggle = window.skToggleFloat;
  window.skFpReset = window.skResetChat;

  function send() {
    var text = (inp.value || "").trim();
    if (!text || thinking) return;
    thinking = true;
    inp.value = "";
    bubble("you", text);
    history.push({ role: "user", content: text });
    typing.style.display = "flex";
    msgs.appendChild(typing);
    msgs.scrollTop = msgs.scrollHeight;
    var fallback = "Happy to help with that. Tell me the job and your area and I'll get you a time.";
    fetch(CHAT_API, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ clientId: "demo", systemPrompt: persona(), messages: history.slice(-10) })
    }).then(function (r) { return r.json(); }).then(function (data) {
      var reply = (data && data.reply && data.reply.trim()) ? data.reply.trim() : fallback;
      typing.style.display = "none";
      bubble("bot", reply);
      history.push({ role: "assistant", content: reply });
      thinking = false;
    }).catch(function () {
      typing.style.display = "none";
      bubble("bot", fallback);
      thinking = false;
    });
  }
  panel.querySelector("#fs-chat-send").addEventListener("click", send);
  inp.addEventListener("keydown", function (e) { if (e.key === "Enter") send(); });

  /* ---------- photographic hero parallax ----------
     translateY(scrollY x 0.12) with scrollY capped at 900, plus a slow scale to
     about 1.099. rAF-throttled and registered passive so it never blocks the
     scroll thread. The entrance zoom lives on the inner element, because a CSS
     animation outranks an inline style and would otherwise freeze this. */
  var heroParallax = document.querySelector("[data-hero-parallax]");
  if (heroParallax && !reduce) {
    var parallaxTicking = false;
    var paintParallax = function () {
      var shift = Math.min(window.pageYOffset || 0, 900) * 0.12;
      var scale = 1 + Math.min(shift, 110) * 0.0009;
      heroParallax.style.transform = "translateY(" + shift + "px) scale(" + scale + ")";
      parallaxTicking = false;
    };
    window.addEventListener("scroll", function () {
      if (parallaxTicking) return;
      parallaxTicking = true;
      requestAnimationFrame(paintParallax);
    }, { passive: true });
    paintParallax();
  }

  /* ---------- the nine seconds replay ----------
     Cumulative schedule: 300, 700, 1600, 3100, 4200, 5500, 6400ms. The 1500ms
     gap before the shop's first reply is the beat the section is named after,
     so it is a real pause, not a rounding artefact. */
  var replaySection = document.querySelector("[data-proof-replay]");
  if (replaySection) {
    var thread = replaySection.querySelector("[data-proof-thread]");
    var msgs = thread ? Array.prototype.slice.call(thread.querySelectorAll(".proof-msg")) : [];
    var againBtn = replaySection.querySelector("[data-proof-replay-again]");
    var timers = [];
    var GAPS = [300, 400, 900, 1500, 1100, 1300, 900, 1200];

    var clearTimers = function () {
      timers.forEach(clearTimeout);
      timers = [];
    };
    var showAll = function () {
      msgs.forEach(function (m) { m.classList.add("is-in"); });
      replaySection.classList.add("is-done");
    };
    var runReplay = function () {
      clearTimers();
      replaySection.classList.remove("is-done");
      msgs.forEach(function (m) { m.classList.remove("is-in"); });
      if (reduce) { showAll(); return; }
      var at = 0;
      msgs.forEach(function (m, i) {
        at += GAPS[i];
        timers.push(setTimeout(function () {
          m.classList.add("is-in");
          if (i === msgs.length - 1) {
            timers.push(setTimeout(function () {
              replaySection.classList.add("is-done");
            }, 500));
          }
        }, at));
      });
    };

    if (againBtn) againBtn.addEventListener("click", runReplay);

    if (reduce || !("IntersectionObserver" in window)) {
      showAll();
    } else {
      var replayObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          replayObserver.unobserve(entry.target);
          runReplay();
        });
      }, { rootMargin: "-15% 0px -25% 0px" });
      replayObserver.observe(replaySection);
      /* If the section is already past the observer band on load, or the
         observer never fires, the thread still ends up readable. */
      setTimeout(function () {
        var anyIn = msgs.some(function (m) { return m.classList.contains("is-in"); });
        var box = replaySection.getBoundingClientRect();
        if (!anyIn && box.top < 0 && box.bottom < window.innerHeight) showAll();
      }, 1200);
    }
  }

  /* ---------- FAQ deep links ---------- */
  var hash = (location.hash || "").slice(1);
  if (hash.indexOf("faq-") === 0) {
    setTimeout(function () {
      var el = document.getElementById(hash);
      if (!el) return;
      el.open = true;
      window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 96, behavior: reduce ? "auto" : "smooth" });
    }, 220);
  }
})();
