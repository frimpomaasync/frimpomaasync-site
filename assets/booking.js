/* Booking system for frimpomaasync.com
   Drives /book, the three booker pages, and /booked.

   ============================================================
   YOUR APPS SCRIPT WEB APP URL. The only place it lives.
   Every page reads it from here. Leave it empty for preview mode.
   ============================================================ */

var BK_API = 'https://script.google.com/macros/s/AKfycbz6ADI0QdLe2reOS0AlQkuPaYRSOrV5aYptAqQ5ienZB8fC_2fN2anUEfRNQgkH46Y3/exec';

(function () {
  'use strict';

  var CFG  = window.BK || {};
  var DEMO = !BK_API || /[?&]demo=1/.test(location.search);
  var el   = function (id) { return document.getElementById(id); };

  var state = {
    type: CFG.type || 'free',
    meta: null,        // {key,label,minutes,price} from the brain
    slots: [],         // raw, each {iso}
    byDay: {},         // ymd in the CHOSEN timezone -> [slot]
    tz: null,
    month: null,       // first of the month being shown
    day: null,
    slot: null,
    busy: false
  };

  /* ---------- copy that changes with what is being booked ----------
     One place to edit the wording for every booker page. */

  var COPY = {
    free: {
      note:   'What is going wrong right now',
      submit: 'Book it',
      fine:   'Nothing is charged for the free call. No account to make, and your answers come straight to me.',
      done:   'Check your email. The invite is attached, so it drops onto your phone calendar.',
      done2:  'Nothing else to do. If you need to move it, reply to that email.'
    },
    map: {
      note:   'What should I look at first',
      submit: 'Pay and lock it in',
      fine:   'Your spot is held for 24 hours while you pay. Miss it and the time quietly reopens, with nothing charged. The fee is credited in full against Siesie.',
      done:   'Check your email. The invite is attached, so it drops onto your phone calendar.',
      done2:  'Send me anything you already have before we meet. A price list, or a screenshot of your inbox. Rough is fine.'
    },
    appeals: {
      note:   'Your practice type, and roughly how many denials are sitting there',
      submit: 'Book the review',
      fine:   'No fee unless money is recovered, and no minimum. Nothing is signed on this call and no patient data changes hands.',
      done:   'Check your email. The invite is attached, so it drops onto your phone calendar.',
      done2:  'Before we meet, pull a denial export covering your last few months. Do not send it yet. The agreement goes in place first.'
    }
  };

  function copy() { return COPY[state.type] || COPY.free; }

  /* ---------- talking to the brain ----------
     JSONP rather than fetch, on purpose. Apps Script and browser security
     rules disagree often enough that this is the version that keeps working. */

  var jsonpCount = 0;

  function ask(params, done, fail) {
    if (DEMO) { return setTimeout(function () { done(demoAnswer(params)); }, 240); }

    var fn = '__bk' + (++jsonpCount) + '_' + Math.floor(Math.random() * 1e6);
    var script = document.createElement('script');
    var query = [];
    for (var k in params) {
      if (params[k] === undefined || params[k] === null) continue;
      query.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
    }
    query.push('callback=' + fn);

    var timer = setTimeout(function () {
      clean();
      fail('The booking system did not answer in time. Please try again.');
    }, 25000);

    function clean() {
      clearTimeout(timer);
      try { delete window[fn]; } catch (e) { window[fn] = undefined; }
      if (script.parentNode) script.parentNode.removeChild(script);
    }

    window[fn] = function (data) { clean(); done(data); };
    script.onerror = function () { clean(); fail('Could not reach the booking system. Please try again.'); };
    script.src = BK_API + (BK_API.indexOf('?') === -1 ? '?' : '&') + query.join('&');
    document.head.appendChild(script);
  }

  /* ---------- preview mode, so every page works before it is wired ---------- */

  var DEMO_TYPES = [
    { key: 'free',    label: 'Free 15-minute call',         minutes: 15, price: 0 },
    { key: 'appeals', label: 'Complimentary denial review',  minutes: 30, price: 0 },
    { key: 'map',     label: 'Operations Map',               minutes: 45, price: 2500 }
  ];

  function demoAnswer(p) {
    if (p.action === 'hold') {
      return p.type === 'map'
        ? { ok: true, confirmed: false, holdId: 'preview', when: 'your chosen time', payUrl: '' }
        : { ok: true, confirmed: true, when: 'your chosen time' };
    }
    if (p.action === 'confirm') return { ok: true, confirmed: true, when: 'your chosen time' };

    var t = p.type === 'map' ? DEMO_TYPES[2] : (p.type === 'appeals' ? DEMO_TYPES[1] : DEMO_TYPES[0]);
    var step = t.minutes >= 45 ? 60 : 30;
    var hours = { 0: null, 1: [10, 16], 2: [10, 16], 3: [10, 16], 4: [10, 16], 5: [10, 13], 6: null };
    var slots = [];
    var now = new Date();

    for (var d = 0; d <= 21; d++) {
      var day = new Date(now.getFullYear(), now.getMonth(), now.getDate() + d);
      var win = hours[day.getDay()];
      if (!win) continue;
      for (var m = win[0] * 60; m + t.minutes <= win[1] * 60; m += step) {
        var when = new Date(day.getFullYear(), day.getMonth(), day.getDate(), Math.floor(m / 60), m % 60);
        if (when.getTime() < now.getTime() + 4 * 3600000) continue;
        if ((d + m) % 5 === 0) continue; // pretend some are already taken
        slots.push({ iso: when.toISOString() });
      }
    }
    return { ok: true, tz: 'America/New_York', paidEnabled: true, types: DEMO_TYPES, slots: slots };
  }

  /* ---------- time helpers ----------
     Everything is recomputed from the raw moment in whichever timezone the
     visitor picked. Never trust a pre-formatted day: 3pm Eastern is already
     tomorrow in Perth, and that is exactly how people miss calls. */

  function inZone(iso, tz) {
    var d = new Date(iso);
    var ymd, time;
    try {
      ymd  = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(d);
      time = d.toLocaleTimeString('en-US', { timeZone: tz, hour: 'numeric', minute: '2-digit' });
    } catch (e) {
      ymd  = new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(d);
      time = d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }
    return { ymd: ymd, time: time };
  }

  function longDay(ymd) {
    var p = ymd.split('-');
    var d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
    return d.toLocaleDateString('en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  }

  function myZone() {
    try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'America/New_York'; }
    catch (e) { return 'America/New_York'; }
  }

  function regroup() {
    state.byDay = {};
    state.slots.forEach(function (s) {
      var k = inZone(s.iso, state.tz).ymd;
      (state.byDay[k] = state.byDay[k] || []).push(s);
    });
  }

  /* ---------- messages ---------- */

  function say(text, kind) {
    var box = el('bkxMsg');
    if (!box) return;
    box.textContent = text || '';
    box.className = 'bkx-msg' + (kind ? ' ' + kind : '');
  }

  /* ---------- the timezone dropdown ---------- */

  function buildZones() {
    var sel = el('bkxTz');
    if (!sel) return;

    var here = myZone();
    var list = [];
    try { if (Intl.supportedValuesOf) list = Intl.supportedValuesOf('timeZone'); } catch (e) {}
    if (!list.length) {
      list = ['America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
              'America/Toronto', 'Europe/London', 'Europe/Dublin', 'Europe/Paris', 'Europe/Berlin',
              'Africa/Accra', 'Africa/Lagos', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Singapore',
              'Australia/Perth', 'Australia/Sydney', 'Pacific/Auckland', 'UTC'];
    }
    if (list.indexOf(here) === -1) list.unshift(here);

    sel.innerHTML = '';
    list.forEach(function (z) {
      var o = document.createElement('option');
      o.value = z;
      o.textContent = z.replace(/_/g, ' ');
      if (z === here) o.selected = true;
      sel.appendChild(o);
    });

    state.tz = here;
    sel.addEventListener('change', function () {
      state.tz = sel.value;
      state.day = null;
      state.slot = null;
      regroup();
      firstOpenMonth();
      drawCal();
      drawSlots();
    });
  }

  /* ---------- the month calendar ---------- */

  function monthKey(d) { return d.getFullYear() + '-' + d.getMonth(); }

  function firstOpenMonth() {
    var keys = Object.keys(state.byDay).sort();
    if (!keys.length) { state.month = new Date(); state.month.setDate(1); return; }
    var p = keys[0].split('-');
    state.month = new Date(Number(p[0]), Number(p[1]) - 1, 1);
  }

  function hasSlotsIn(monthDate) {
    var y = monthDate.getFullYear(), m = monthDate.getMonth();
    for (var k in state.byDay) {
      var p = k.split('-');
      if (Number(p[0]) === y && Number(p[1]) - 1 === m) return true;
    }
    return false;
  }

  function drawCal() {
    var wrap = el('bkxDays');
    if (!wrap) return;

    el('bkxMonth').textContent =
      state.month.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

    var prev = new Date(state.month.getFullYear(), state.month.getMonth() - 1, 1);
    var next = new Date(state.month.getFullYear(), state.month.getMonth() + 1, 1);
    el('bkxPrev').disabled = !hasSlotsIn(prev);
    el('bkxNext').disabled = !hasSlotsIn(next);

    wrap.innerHTML = '';

    var y = state.month.getFullYear(), m = state.month.getMonth();
    var first = new Date(y, m, 1);
    // Monday-first grid, so the weekend sits together on the right.
    var lead = (first.getDay() + 6) % 7;
    var total = new Date(y, m + 1, 0).getDate();

    for (var i = 0; i < lead; i++) {
      var blank = document.createElement('span');
      blank.className = 'bkx-date empty';
      wrap.appendChild(blank);
    }

    for (var day = 1; day <= total; day++) {
      var ymd = y + '-' + String(m + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
      var open = !!(state.byDay[ymd] && state.byDay[ymd].length);
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'bkx-date' + (open ? ' open' : '');
      b.textContent = day;
      if (open) {
        b.setAttribute('aria-pressed', state.day === ymd ? 'true' : 'false');
        b.setAttribute('aria-label', longDay(ymd) + ', ' + state.byDay[ymd].length + ' times open');
        (function (k) { b.addEventListener('click', function () { pickDay(k); }); })(ymd);
      } else {
        b.disabled = true;
      }
      wrap.appendChild(b);
    }
  }

  function stepMonth(dir) {
    state.month = new Date(state.month.getFullYear(), state.month.getMonth() + dir, 1);
    drawCal();
  }

  function pickDay(ymd) {
    state.day = ymd;
    state.slot = null;
    drawCal();
    drawSlots();
  }

  function drawSlots() {
    var box = el('bkxSlots');
    if (!box) return;
    box.innerHTML = '';

    if (!state.day) {
      var note = document.createElement('p');
      note.className = 'bkx-slots-note';
      note.textContent = 'Choose a highlighted day and the times will appear here.';
      box.appendChild(note);
      return;
    }

    var head = document.createElement('p');
    head.className = 'bkx-slots-day';
    head.textContent = longDay(state.day);
    box.appendChild(head);

    (state.byDay[state.day] || []).forEach(function (s) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'bkx-slot';
      b.textContent = inZone(s.iso, state.tz).time;
      b.setAttribute('aria-pressed', state.slot && state.slot.iso === s.iso ? 'true' : 'false');
      b.addEventListener('click', function () { pickSlot(s); });
      box.appendChild(b);
    });
  }

  /* ---------- step two ---------- */

  function pickSlot(s) {
    state.slot = s;
    drawSlots();

    var z = inZone(s.iso, state.tz);
    el('bkxWhenDay').textContent = longDay(z.ymd);
    el('bkxWhenTime').textContent =
      z.time + ' · ' + state.meta.minutes + ' minutes · ' + state.tz.replace(/_/g, ' ');

    el('stepPick').hidden = true;
    el('stepDetails').hidden = false;
    say('');
    window.scrollTo({ top: 0, behavior: 'smooth' });
    el('bkxName').focus();
  }

  function backToPick() {
    el('stepDetails').hidden = true;
    el('stepPick').hidden = false;
    say('');
  }

  function wireForm() {
    el('bkxForm').addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (state.busy || !state.slot) return;

      var name  = el('bkxName').value.trim();
      var email = el('bkxEmail').value.trim();
      var note  = el('bkxNote') ? el('bkxNote').value.trim() : '';

      if (name.length < 2) { say('Please put your name in.', 'err'); el('bkxName').focus(); return; }
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/.test(email)) {
        say('That email address does not look right. Please check it.', 'err');
        el('bkxEmail').focus();
        return;
      }

      state.busy = true;
      el('bkxSubmit').disabled = true;
      say(state.meta.price > 0 ? 'Holding your spot…' : 'Booking it…', 'wait');

      ask({ action: 'hold', type: state.type, iso: state.slot.iso, name: name, email: email, note: note },
        function (res) {
          state.busy = false;
          el('bkxSubmit').disabled = false;

          if (!res || !res.ok) {
            say((res && res.error) || 'That did not go through. Please try again.', 'err');
            if (res && /took that time/.test(res.error || '')) { backToPick(); load(); }
            return;
          }
          say('');

          if (res.confirmed) return showDone(res.when, false);

          if (res.payUrl) {
            try { sessionStorage.setItem('bk_hold', res.holdId); } catch (e) {}
            say('Spot held. Sending you to payment…', 'wait');
            window.location.href = res.payUrl;
            return;
          }
          showDone(res.when, true);
        },
        function (msg) {
          state.busy = false;
          el('bkxSubmit').disabled = false;
          say(msg, 'err');
        });
    });
  }

  function showDone(when, held) {
    el('stepPick').hidden = true;
    el('stepDetails').hidden = true;
    var box = el('stepDone');
    box.hidden = false;

    box.querySelector('h2').textContent = held ? 'Your spot is held.' : 'You are booked.';
    box.querySelector('.when-line').textContent = when;
    box.querySelector('.p1').textContent = held
      ? 'It stays yours for 24 hours. Finish the payment and it locks in for good.'
      : copy().done;
    box.querySelector('.p2').textContent = held
      ? 'If life gets in the way, the spot quietly reopens and nothing is charged.'
      : copy().done2;

    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  /* ---------- the page people land on after paying ---------- */

  function runConfirmPage() {
    function param(name) {
      var m = new RegExp('[?&]' + name + '=([^&#]*)').exec(location.search);
      return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : '';
    }

    /* The payment page passes UTM values through to this page. The hold
       reference travels in utm_content. Session storage is the backup, for
       when payment was finished in a different tab. */
    var hold = param('utm_content') || param('hold') || param('client_reference_id') || '';
    if (!hold) { try { hold = sessionStorage.getItem('bk_hold') || ''; } catch (e) {} }

    function paint(eyebrow, head, when, body, quiet, isError) {
      el('eyebrow').textContent = eyebrow;
      el('head').textContent = head;
      if (when) { el('when').textContent = when; el('when').hidden = false; }
      el('body').textContent = body;
      el('body').className = isError ? 'err' : '';
      el('actions').hidden = false;
      if (quiet) { el('quiet').textContent = quiet; el('quiet').hidden = false; }
    }

    if (!BK_API) {
      return paint('Preview mode', 'Nothing to confirm yet.', '',
        'This page is waiting to be wired to the booking system.', '', false);
    }
    if (!hold) {
      return paint('Nothing to confirm', 'No booking reference came through.', '',
        'If you have paid, do not pay again. Reply to your payment receipt and I will sort it out today.', '', true);
    }

    ask({ action: 'confirm', hold: hold }, function (res) {
      try { sessionStorage.removeItem('bk_hold'); } catch (e) {}
      if (res && res.ok) {
        paint('Paid and confirmed', 'You are booked.', res.when || '',
          'The invite is in your email, so it drops onto your phone calendar. Send me anything you already have before we meet. Rough is fine.',
          'Need to move it? Reply to the confirmation email.', false);
      } else {
        paint('Something did not line up', 'Your payment went through.', '',
          (res && res.error ? res.error + ' ' : '') +
          'The time itself needs a hand. Reply to your payment receipt and I will confirm you today.',
          'Do not pay again.', true);
      }
    }, function () {
      paint('Could not reach the calendar', 'Your payment went through.', '',
        'The confirmation step could not connect. Reply to your payment receipt and I will lock your time in today.',
        'Do not pay again.', true);
    });
  }

  /* ---------- the chooser page ---------- */

  function runChooser() {
    ask({ action: 'slots', type: 'free' }, function (res) {
      if (!res || !res.ok) return;
      // The paid card only becomes bookable once a payment link exists.
      var card = el('bkcPaid');
      if (card && !res.paidEnabled) {
        var go = card.querySelector('.bkc-go');
        if (go) {
          go.setAttribute('href', '/operations-map');
          go.querySelector('span').textContent = 'Read how it works';
        }
      }
    }, function () {});
  }

  /* ---------- start ---------- */

  function load() {
    say('Checking the calendar…', 'wait');
    ask({ action: 'slots', type: state.type }, function (res) {
      if (!res || !res.ok) { say((res && res.error) || 'Could not load the times.', 'err'); return; }
      say('');

      (res.types || []).forEach(function (t) { if (t.key === state.type) state.meta = t; });
      if (!state.meta) state.meta = { key: state.type, label: '', minutes: 30, price: 0 };

      state.slots = res.slots || [];
      regroup();

      if (!state.slots.length) {
        el('bkxPicker').innerHTML =
          '<div class="bkx-empty">Nothing open in the next three weeks. Tell me what you need at ' +
          'frimpomaasync.com/fit and I will make room.</div>';
        return;
      }

      firstOpenMonth();
      drawCal();
      drawSlots();
    }, function (msg) { say(msg, 'err'); });
  }

  function start() {
    if (el('bkConfirmPage')) return runConfirmPage();
    if (el('bkcPaid') || el('bkcChooser')) return runChooser();
    if (!el('bkxDays')) return;

    if (DEMO && el('bkxDemo')) el('bkxDemo').hidden = false;

    el('bkxNoteLabel').textContent = copy().note;
    el('bkxSubmitText').textContent = copy().submit;
    el('bkxFine').textContent = copy().fine;

    buildZones();
    wireForm();
    el('bkxPrev').addEventListener('click', function () { stepMonth(-1); });
    el('bkxNext').addEventListener('click', function () { stepMonth(1); });
    el('bkxBack').addEventListener('click', backToPick);

    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
