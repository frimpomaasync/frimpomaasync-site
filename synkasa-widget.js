/* ============================================================================
   SynKasa widget — frimpomaasync.com edition
   Powered by SynKasa · configured as NaNa Frimpomaa's site assistant.
   Self-contained (no CDN, no backend, no API key). Answers visitor questions
   about the site. Install: <script src="synkasa-widget.js" defer></script>
   ✅ diffed vs Brand Facts: copper #C9975B · live pricing · "f." site favicon logo
   ============================================================================ */
(function () {
  if (window.__synkasaWidget) return;
  window.__synkasaWidget = true;

  /* ---- config (this is what a real SynKasa client deployment sets) ---- */
  var CFG = {
    business: "NaNa Frimpomaa",
    tagline: "AI Assistant · Always On",
    booking: "https://calendar.app.google/p9Te84R34vPm2U3z9",
    email: "hello@frimpomaasync.com",
    greeting: "Hi 👋 I’m NaNa’s assistant — ask me anything about what she builds: Som, Soma, custom tools, or SynKasa. I can also get you booked for a free build call.",
    logoSVG: '<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg"><rect width="64" height="64" rx="15" fill="#13203A"/><text x="50%" y="52%" text-anchor="middle" dominant-baseline="middle" font-family="-apple-system,Segoe UI,Helvetica,Arial,sans-serif" font-size="40" font-weight="800" fill="#F5F0E8">f<tspan fill="#C9975B">.</tspan></text></svg>',
    quick: [
      { label: "What does NaNa build?", msg: "What do you build?" },
      { label: "Pricing", msg: "What are your prices?" },
      { label: "What’s SynKasa?", msg: "What is SynKasa?" },
      { label: "Book a free call", msg: "I’d like to book a call" }
    ],
    kb: [
      { keys: ["build", "make", "offer", "services", "service", "create", "do you do", "what do you"], a: "NaNa builds small AI tools and systems that do real jobs for you:\n\n• Som — $33, catches ideas/links/follow-ups before you lose them\n• Soma — free demo, “type it once, watch it land everywhere”\n• The Solo Business System — booking, leads & follow-up automated, done for you: $1,500, live in 7 days\n• SynKasa — a done-for-you AI receptionist (like me!)\n\nWant me to point you to one, or book a free call?" },
      { keys: ["soma"], a: "Soma is the free demo — “type it once, watch it land everywhere.” It shows you the idea with zero commitment. Want the link to try it?" },
      { keys: ["som"], a: "Som is a $33 one-time tool that catches what you’d normally lose — ideas, links, follow-ups. Type it once and it’s held for you. Want the link to grab it?" },
      { keys: ["synkasa", "receptionist", "chatbot", "assistant", "widget", "this thing", "are you"], a: "I’m SynKasa — a done-for-you AI receptionist. I answer messages, book appointments, and capture leads 24/7, built for your business in 48 hours. NaNa can put one exactly like me on your site. Setup is from $555 + $99/mo. Want a free call to see it built for you?" },
      { keys: ["custom", "website", "site", "app", "tool", "software", "system"], a: "The main offer is the Solo Business System: your booking, lead capture, and follow-up automated into one connected system — $1,500, live in 7 days, optional $99/mo care plan. The first 3 founding clients get it for $750. Smaller or personal ideas get an exact quote on the free call." },
      { keys: ["who", "nana", "frimpomaa", "about", "yourself", "you guys"], a: "NaNa Frimpomaa is a solo founder who builds calm AI tools for people and small businesses — everything here is real and live. What can I help you with?" },
      { keys: ["free", "cost nothing", "no cost", "try free"], a: "Soma is completely free to try, and the 15-minute build call is free too. Som is $33 one-time, and the Solo Business System is $1,500 ($750 for the first 3 founding clients). Want the free call?" },
      { keys: ["how long", "turnaround", "how fast", "timeline", "when can", "how quick"], a: "SynKasa is built and live in 48 hours, and the Solo Business System is live in 7 days — guaranteed, or you don’t pay the build fee. Want to talk timing on a free call?" },
      { keys: ["small business", "my business", "for me"], a: "Yes — NaNa builds specifically for small businesses and solo owners. The Solo Business System and SynKasa were made for exactly that. Want to see what it’d look like for your business? I can book you a free call." },
      { keys: ["guarantee", "risk", "refund", "what if"], a: "The Solo Business System comes with a 7-day guarantee — live in 7 days or you don’t pay the build fee. And you can try Soma free first to feel the quality. Want to talk it through on a call?" },
      { keys: ["upwork", "hire", "freelance", "work with"], a: "You can work with NaNa directly here — the fastest start is a free 15-minute build call. Want me to grab you a time?" }
    ]
  };

  /* ---- styles (namespaced skw-) ---- */
  var CSS = ''
    + '.skw-bubble{position:fixed;right:20px;bottom:20px;width:62px;height:62px;border-radius:50%;background:linear-gradient(135deg,#C9975B,#A8763B);border:none;cursor:pointer;box-shadow:0 10px 30px rgba(0,0,0,.35);z-index:2147483000;display:flex;align-items:center;justify-content:center;transition:transform .2s}'
    + '.skw-bubble:hover{transform:scale(1.07)}'
    + '.skw-bubble svg{width:28px;height:28px}'
    + '.skw-badge{position:absolute;top:-2px;right:-2px;width:20px;height:20px;border-radius:50%;background:#E0533B;color:#fff;font:700 11px/20px -apple-system,Segoe UI,Arial,sans-serif;text-align:center;border:2px solid #0B1220}'
    + '.skw-panel{position:fixed;right:20px;bottom:94px;width:min(374px,calc(100vw - 32px));height:min(566px,calc(100vh - 128px));background:linear-gradient(170deg,#111C2E,#0A1019);border:1px solid rgba(201,151,91,.22);border-radius:20px;box-shadow:0 26px 60px rgba(0,0,0,.5);z-index:2147483000;display:none;flex-direction:column;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
    + '.skw-panel.skw-open{display:flex;animation:skw-pop .22s ease}'
    + '@keyframes skw-pop{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}'
    + '.skw-head{display:flex;align-items:center;gap:11px;padding:15px 16px;border-bottom:1px solid rgba(255,255,255,.06)}'
    + '.skw-logo{width:38px;height:38px;border-radius:11px;overflow:hidden;flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,.3)}'
    + '.skw-logo svg{width:100%;height:100%;display:block}'
    + '.skw-who{flex:1;min-width:0}'
    + '.skw-name{color:#F1EADC;font-weight:700;font-size:14.5px;line-height:1.2}'
    + '.skw-status{color:#7FE0A0;font-size:11.5px;display:flex;align-items:center;gap:5px;margin-top:2px}'
    + '.skw-status::before{content:"";width:7px;height:7px;border-radius:50%;background:#4ADE80;box-shadow:0 0 0 0 rgba(74,222,128,.6);animation:skw-live 2s infinite}'
    + '@keyframes skw-live{0%{box-shadow:0 0 0 0 rgba(74,222,128,.5)}70%{box-shadow:0 0 0 7px rgba(74,222,128,0)}100%{box-shadow:0 0 0 0 rgba(74,222,128,0)}}'
    + '.skw-x{background:none;border:none;color:rgba(255,255,255,.45);font-size:22px;cursor:pointer;line-height:1;padding:2px 6px}.skw-x:hover{color:#fff}'
    + '.skw-body{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px}'
    + '.skw-body::-webkit-scrollbar{width:6px}.skw-body::-webkit-scrollbar-thumb{background:rgba(201,151,91,.3);border-radius:3px}'
    + '.skw-msg{max-width:84%;padding:11px 14px;font-size:14px;line-height:1.5;white-space:pre-wrap;word-wrap:break-word;border-radius:16px}'
    + '.skw-bot{align-self:flex-start;background:#1A2637;color:#E7E0D2;border-bottom-left-radius:5px}'
    + '.skw-me{align-self:flex-end;background:linear-gradient(135deg,#C9975B,#A8763B);color:#fff;border-bottom-right-radius:5px}'
    + '.skw-msg a{color:#E4C79A;font-weight:600}.skw-me a{color:#fff}'
    + '.skw-time{align-self:flex-start;color:#5D6A7C;font-size:10.5px;margin:-4px 0 2px 4px}'
    + '.skw-typing{align-self:flex-start;background:#1A2637;border-radius:16px;border-bottom-left-radius:5px;padding:13px 15px;display:flex;gap:4px}'
    + '.skw-typing i{width:7px;height:7px;border-radius:50%;background:#C9975B;display:block;animation:skw-bnc 1.2s infinite}'
    + '.skw-typing i:nth-child(2){animation-delay:.15s}.skw-typing i:nth-child(3){animation-delay:.3s}'
    + '@keyframes skw-bnc{0%,60%,100%{transform:translateY(0);opacity:.5}30%{transform:translateY(-5px);opacity:1}}'
    + '.skw-chips{display:flex;flex-wrap:wrap;gap:8px;padding:0 16px 10px}'
    + '.skw-chip{background:transparent;border:1px solid rgba(201,151,91,.5);color:#E4C79A;font:600 13px/1 -apple-system,Segoe UI,Arial,sans-serif;padding:9px 14px;border-radius:999px;cursor:pointer}'
    + '.skw-chip:hover{background:#C9975B;color:#0B1220;border-color:#C9975B}'
    + '.skw-input{display:flex;align-items:center;gap:9px;padding:12px;border-top:1px solid rgba(255,255,255,.06)}'
    + '.skw-input input{flex:1;background:#0C1420;border:1px solid rgba(255,255,255,.1);border-radius:999px;padding:12px 16px;color:#EDE6D8;font-size:14px;outline:none;font-family:inherit}'
    + '.skw-input input::placeholder{color:#5D6A7C}.skw-input input:focus{border-color:#C9975B}'
    + '.skw-send{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#C9975B,#A8763B);border:none;color:#fff;font-size:17px;cursor:pointer;flex-shrink:0}.skw-send:hover{filter:brightness(1.08)}'
    + '.skw-foot{text-align:center;font-size:10.5px;color:#5D6A7C;padding:8px}.skw-foot b{color:#93A0B2;font-weight:600}';

  /* ---- build DOM ---- */
  var st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st);

  var bubble = document.createElement('button');
  bubble.className = 'skw-bubble'; bubble.setAttribute('aria-label', 'Open chat');
  bubble.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg><span class="skw-badge">1</span>';

  var panel = document.createElement('div');
  panel.className = 'skw-panel'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Chat with ' + CFG.business);
  panel.innerHTML =
      '<div class="skw-head">'
    +   '<div class="skw-logo">' + CFG.logoSVG + '</div>'
    +   '<div class="skw-who"><div class="skw-name">' + CFG.business + '</div><div class="skw-status">' + CFG.tagline + '</div></div>'
    +   '<button class="skw-x" aria-label="Close">×</button>'
    + '</div>'
    + '<div class="skw-body" id="skw-body"></div>'
    + '<div class="skw-chips" id="skw-chips"></div>'
    + '<div class="skw-input"><input id="skw-text" type="text" placeholder="Ask anything…" autocomplete="off"><button class="skw-send" aria-label="Send">↑</button></div>'
    + '<div class="skw-foot">Powered by <b>SynKasa AI</b></div>';

  document.body.appendChild(bubble);
  document.body.appendChild(panel);

  var body = panel.querySelector('#skw-body');
  var chipsEl = panel.querySelector('#skw-chips');
  var input = panel.querySelector('#skw-text');
  var started = false, convo = { mode: 'idle', lead: {} };

  function nowTime() { var d = new Date(); var h = d.getHours(), m = d.getMinutes(); var ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12; return h + ':' + (m < 10 ? '0' + m : m) + ' ' + ap; }
  function scrollDown() { body.scrollTop = body.scrollHeight; }
  function addMsg(html, who, withTime) {
    if (withTime) { var t = document.createElement('div'); t.className = 'skw-time'; t.textContent = nowTime(); }
    var d = document.createElement('div'); d.className = 'skw-msg ' + (who || 'skw-bot'); d.innerHTML = html; body.appendChild(d);
    if (withTime) body.appendChild(t); scrollDown();
  }
  function typing() { var w = document.createElement('div'); w.className = 'skw-typing'; w.id = 'skw-typing'; w.innerHTML = '<i></i><i></i><i></i>'; body.appendChild(w); scrollDown(); }
  function untyping() { var t = document.getElementById('skw-typing'); if (t) t.remove(); }
  function say(html) { typing(); setTimeout(function () { untyping(); addMsg(html, 'skw-bot', true); }, 560); }

  function renderChips() { chipsEl.innerHTML = ''; CFG.quick.forEach(function (q) { var b = document.createElement('button'); b.className = 'skw-chip'; b.textContent = q.label; b.onclick = function () { handle(q.msg); }; chipsEl.appendChild(b); }); }

  function open() { if (!panel.classList.contains('skw-open')) { panel.classList.add('skw-open'); bubble.style.display = 'none'; if (!started) { started = true; renderChips(); addMsg(CFG.greeting, 'skw-bot', true); } setTimeout(function(){ input.focus(); }, 100); } }
  function close() { panel.classList.remove('skw-open'); bubble.style.display = 'flex'; }
  bubble.onclick = open;
  panel.querySelector('.skw-x').onclick = close;

  /* ---- intent engine (word-boundary aware) ---- */
  function norm(s) { return (' ' + s.toLowerCase() + ' ').replace(/[^a-z0-9\s]/g, ' '); }
  function has(t) { for (var i = 1; i < arguments.length; i++) { var a = arguments[i]; var hit = a.indexOf(' ') > -1 ? (t.indexOf(a) > -1) : (t.indexOf(' ' + a + ' ') > -1); if (hit) return true; } return false; }

  function priceList() {
    return "Here’s the full menu:\n\n• Som — $33 one-time\n• Soma — free\n• The Solo Business System — $1,500, live in 7 days ($750 for the first 3 founding clients) + optional $99/mo care\n• SynKasa AI receptionist — from $555 setup + $99/mo\n\nWant me to book you a free call to figure out the right fit?";
  }

  function handle(text) {
    text = ('' + text).trim(); if (!text) return;
    addMsg(text.replace(/</g, '&lt;'), 'skw-me'); input.value = '';
    var t = norm(text);

    if (convo.mode === 'lead-name') { convo.lead.name = text; convo.mode = 'lead-contact'; say("Lovely to meet you, " + text.replace(/</g,'&lt;') + "! What’s the best email or phone to reach you?"); return; }
    if (convo.mode === 'lead-contact') {
      convo.lead.contact = text; convo.mode = 'idle';
      try { var L = JSON.parse(localStorage.getItem('skw_leads') || '[]'); L.push({ name: convo.lead.name, contact: convo.lead.contact, at: new Date().toISOString() }); localStorage.setItem('skw_leads', JSON.stringify(L)); } catch (e) {}
      say("Perfect — got it ✅ NaNa will reach out shortly. Want to grab a time now so you don’t wait?\n\n👉 <a href='" + CFG.booking + "' target='_blank' rel='noopener'>Pick a time</a>"); return;
    }
    if (has(t, 'book', 'appointment', 'schedule', 'get started', 'sign up', 'start', 'reserve', 'call', 'demo', 'talk to', 'consult')) { convo.mode = 'lead-name'; say("Love it 🎉 I can set that up. First — what’s your name?"); return; }
    if (has(t, 'price', 'pricing', 'cost', 'how much', 'rate', 'fee', 'charge', 'pay', 'expensive')) { say(priceList()); return; }
    if (has(t, 'email', 'phone', 'reach', 'contact', 'number', 'get in touch')) { say("You can reach NaNa at <a href='mailto:" + CFG.email + "'>" + CFG.email + "</a>, or I can take your details right here — just say “book me.”"); return; }
    if (has(t, 'hi', 'hello', 'hey', 'good morning', 'good evening') && text.length < 14) { say(CFG.greeting); return; }
    if (has(t, 'thank', 'thanks', 'appreciate')) { say("Anytime 💛 Want me to book you a free call before you go?"); return; }

    var best = null, bestScore = 0;
    CFG.kb.forEach(function (f) { var sc = 0; f.keys.forEach(function (k) { if (t.indexOf(k) > -1) sc++; }); if (sc > bestScore) { bestScore = sc; best = f; } });
    if (best && bestScore > 0) { say(best.a); return; }

    say("Great question — let me point you right. I can help with what NaNa builds, pricing, SynKasa, or booking you a free call. Which would you like?\n\nOr reach out directly at <a href='mailto:" + CFG.email + "'>" + CFG.email + "</a>.");
  }

  panel.querySelector('.skw-send').onclick = function () { handle(input.value); };
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter') handle(input.value); });
})();
