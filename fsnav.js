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
    "#fs-nav a{text-decoration:none}",
    "@media (hover:hover){#fs-nav [data-navlink]:hover{color:#101426!important}#fs-nav [data-navcta]:hover{background:#C2501C!important;color:#FFF!important}#fs-bar [data-navcta]:hover{background:#C2501C!important;color:#FFF!important}#fs-bar [data-navlink]:hover{color:#101426!important}#fs-foot a:hover{color:#FFFFFF}#fs-chat-fab:hover{background:#C2501C!important}}",
    "#fs-foot a{text-decoration:none;color:rgba(242,244,249,.72)}",
    "@media (max-width:1360px){#fs-rail{display:none!important}}",
    "@media (max-width:700px){#fs-nav .fs-grid{grid-template-columns:1fr auto!important}#fs-nav .fs-grid nav{order:3;grid-column:1 / -1}}",
    "@media (prefers-reduced-motion: reduce){#fs-veil{display:none!important}#fs-bar{transition:none!important}#fs-chat-panel span[style*='skcDot']{animation:none!important}}"
  ].join("\n");
  document.head.appendChild(css);

  /* ---------- nav ---------- */
  function activeKey() {
    var p = location.pathname.replace(/\.html$/, "").replace(/\/$/, "") || "/";
    if (p.indexOf("/synkasa") === 0 || p.indexOf("/client-catcher") === 0) return "synkasa";
    if (p.indexOf("/siesie") === 0) return "siesie";
    if (p.indexOf("/portfolio") === 0) return "proof";
    if (p.indexOf("/free") === 0) return "free";
    return "none";
  }
  function navLink(label, href, key, on) {
    return '<a data-navlink href="' + href + '" style="color:' + (on === key ? "#101426" : "rgba(16,20,38,.58)") + ';transition:color .25s ease">' + label + "</a>";
  }
  if (!cfg.noChrome) {
    var on = activeKey();
    var nav = document.createElement("header");
    nav.id = "fs-nav";
    nav.style.cssText = "position:sticky;top:0;z-index:40;background:rgba(255,255,255,.9);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);border-bottom:1px solid rgba(16,20,38,.08)";
    nav.innerHTML =
      '<div class="fs-grid" style="max-width:1180px;margin:0 auto;padding:18px 24px 16px;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:16px">' +
      '<nav style="display:flex;gap:clamp(14px,1.8vw,26px);flex-wrap:wrap;font-size:10.5px;letter-spacing:.15em;text-transform:uppercase">' +
      navLink("SynKasa", "/synkasa", "synkasa", on) +
      navLink("Siesie", "/siesie", "siesie", on) +
      navLink("Proof", "/portfolio", "proof", on) +
      navLink("Free", "/free", "free", on) +
      "</nav>" +
      '<a href="/" style="font-family:' + SERIF + ';font-size:clamp(14px,1.5vw,18px);letter-spacing:.2em;text-transform:uppercase;color:#101426;white-space:nowrap">frimpomaasync</a>' +
      '<div style="display:flex;align-items:center;gap:clamp(14px,1.8vw,22px);justify-content:flex-end;flex-wrap:wrap">' +
      '<button type="button" data-navlink data-fs-chat style="background:none;border:0;padding:0;font-family:inherit;font-size:10.5px;letter-spacing:.15em;text-transform:uppercase;color:rgba(16,20,38,.58);cursor:pointer;transition:color .25s ease">See it answer</button>' +
      '<a data-navcta href="' + BOOK + '" style="background:#101426;color:#FFFFFF;padding:10px 15px;border-radius:5px;font-size:10.5px;letter-spacing:.15em;text-transform:uppercase;white-space:nowrap;transition:background .25s ease">Book a call</a>' +
      "</div></div>";
    body.insertBefore(nav, body.firstChild);
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

    if (reduce || !("IntersectionObserver" in window)) {
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

      if (storySteps.length) {
        var storyObserver = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) setActiveStoryStep(entry.target, storySteps);
          });
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
      '<div style="display:flex;flex-direction:column;gap:10px;font-size:14px"><a href="/synkasa">SynKasa</a><a href="/siesie">Siesie</a><a href="/portfolio">Proof</a></div>' +
      '<div style="display:flex;flex-direction:column;gap:10px;font-size:14px"><a href="/free">Free</a><a href="/som">Som</a><a href="/blog/">Blog</a><a href="/fit">Find your fit</a></div>' +
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
      '<button type="button" data-navlink data-fs-chat style="font-family:inherit;font-size:10.5px;letter-spacing:.15em;text-transform:uppercase;cursor:pointer;background:none;border:0;padding:0;color:rgba(16,20,38,.58);transition:color .25s ease">See it answer</button>' +
      '<a data-navcta href="' + BOOK + '" style="background:#101426;color:#FFFFFF;padding:11px 16px;border-radius:5px;font-size:10.5px;letter-spacing:.15em;text-transform:uppercase;white-space:nowrap;text-decoration:none;transition:background .25s ease">Book a call</a>' +
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
  fab.style.cssText = "position:fixed;right:20px;bottom:20px;z-index:60;width:58px;height:58px;border-radius:999px;border:none;cursor:pointer;background:#101426;color:#FFFFFF;font-size:22px;box-shadow:0 18px 40px -18px rgba(0,0,0,.7);transition:background .25s ease";
  fab.textContent = "◎";
  body.appendChild(fab);

  var panel = document.createElement("div");
  panel.id = "fs-chat-panel";
  panel.style.cssText = "position:fixed;right:20px;bottom:90px;z-index:60;width:min(360px,calc(100vw - 40px));background:#0C1226;color:#EDEFF4;border:1px solid rgba(242,244,249,.14);border-radius:22px;overflow:hidden;box-shadow:0 30px 70px -30px rgba(0,0,0,.8);display:none";
  panel.innerHTML =
    '<div style="padding:16px 18px;border-bottom:1px solid rgba(242,244,249,.12);display:flex;align-items:center;gap:10px">' +
    '<span style="width:8px;height:8px;border-radius:999px;background:#4FC07E"></span>' +
    '<span style="font-size:14px">SynKasa &middot; <span id="fs-chat-biz"></span></span>' +
    '<div style="flex:1"></div>' +
    '<button type="button" id="fs-chat-x" style="background:transparent;border:none;color:rgba(237,239,244,.6);font-size:18px;cursor:pointer;font-family:inherit">&times;</button></div>' +
    '<div id="fs-chat-msgs" style="padding:16px 18px;display:flex;flex-direction:column;gap:10px;max-height:300px;overflow-y:auto"></div>' +
    '<div style="display:flex;gap:8px;padding:12px 14px;border-top:1px solid rgba(242,244,249,.12)">' +
    '<input id="fs-chat-inp" placeholder="Ask it something awkward" style="flex:1;min-width:0;font-family:inherit;font-size:14px;padding:11px 13px;border-radius:5px;border:1px solid rgba(242,244,249,.16);background:rgba(242,244,249,.06);color:#EDEFF4;outline:none">' +
    '<button type="button" id="fs-chat-send" style="font-family:inherit;font-size:12.5px;letter-spacing:.02em;cursor:pointer;border:none;background:#C2501C;color:#FFF;padding:11px 16px;border-radius:5px">Send</button></div>';
  body.appendChild(panel);

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
    chatOpen = v;
    panel.style.display = v ? "block" : "none";
    fab.textContent = v ? "×" : "◎";
    if (v) {
      panel.querySelector("#fs-chat-biz").textContent = bizLabel();
      if (!msgs.children.length) window.skResetChat();
      setTimeout(function () { inp.focus(); }, 150);
    }
  }
  fab.addEventListener("click", function () { setOpen(!chatOpen); });
  panel.querySelector("#fs-chat-x").addEventListener("click", function () { setOpen(false); });
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
