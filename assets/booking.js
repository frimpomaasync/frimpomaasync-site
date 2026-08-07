/* Booking widget for frimpomaasync.com
   Used by /book, /soft-appeals-book and /booked.

   ============================================================
   PASTE YOUR APPS SCRIPT WEB APP URL BETWEEN THE QUOTES BELOW.
   It looks like  https://script.google.com/macros/s/AKfy..../exec
   This is the ONLY place it goes. All three pages read it from here.
   Leave it empty and every page runs in preview mode instead.
   ============================================================ */

var BK_API = 'https://script.google.com/macros/s/AKfycbz6ADI0QdLe2reOS0AlQkuPaYRSOrV5aYptAqQ5ienZB8fC_2fN2anUEfRNQgkH46Y3/exec';

(function () {
  'use strict';

  var CFG   = window.BK || {};
  var DEMO  = !BK_API || /[?&]demo=1/.test(location.search);
  var el    = function (id) { return document.getElementById(id); };
  var state = { type: null, types: [], slots: [], day: null, slot: null, busy: false };

  /* Copy that changes with what is being booked. All of it lives here,
     so there is one file to edit when the wording needs to change. */
  var COPY = {
    free: {
      blurb:  'Tell me what keeps getting missed. I tell you whether I can fix it, and what it would take.',
      note:   'What is going wrong right now',
      submit: 'Book it',
      foot:   'Nothing is charged for the free call. You do not make an account, and your answers go straight to me and nowhere else.'
    },
    map: {
      blurb:  'A working map of where your time and money leak, built from your real numbers. Credited in full against Siesie.',
      note:   'What should I look at first',
      submit: 'Hold my spot →',
      foot:   'Your spot is held for 24 hours while you pay. Miss it and the time quietly reopens, with nothing charged. The fee is credited in full against Siesie.'
    },
    appeals: {
      blurb:  'A complimentary look at 20 recent denials. The report is yours whether or not you carry on with us.',
      note:   'Your practice type, and roughly how many denials are sitting there',
      submit: 'Book the review',
      foot:   'No fee unless money is recovered, and no minimum. Nothing is signed on this call and no patient data changes hands. The agreement is in place before anything moves.'
    }
  };

  function copyFor(key) {
    return COPY[key] || { blurb: '', note: 'Anything I should know first', submit: 'Book it', foot: '' };
  }

  /* ---------- talking to the brain ----------
     JSONP rather than fetch, on purpose. Apps Script and browser security
     rules disagree often enough that this is the version that keeps working. */

  var jsonpCount = 0;

  function ask(params, done, fail) {
    if (DEMO) { return setTimeout(function () { done(demoAnswer(params)); }, 260); }

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
    { key: 'free',    label: 'Free 15-minute call',            minutes: 15, price: 0 },
    { key: 'appeals', label: 'Complimentary denial review',    minutes: 30, price: 0 },
    { key: 'map',     label: 'Operations Map',                 minutes: 45, price: 2500 }
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
        slots.push({
          iso:  when.toISOString(),
          ymd:  ymdOf(when),
          day:  when.toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short' }),
          time: when.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
        });
      }
    }
    return { ok: true, tz: 'America/New_York', paidEnabled: true, types: DEMO_TYPES, slots: slots };
  }

  function ymdOf(d) {
    var m = d.getMonth() + 1, day = d.getDate();
    return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
  }

  /* ---------- messages ---------- */

  function say(text, kind) {
    var box = el('bkMsg');
    if (!box) return;
    box.textContent = text || '';
    box.className = 'bk-msg' + (kind ? ' ' + kind : '');
  }

  /* ---------- step one: what are they booking ---------- */

  function drawTypes() {
    var wrap = el('bkTypes');
    wrap.innerHTML = '';
    wrap.className = 'bk-types' + (state.types.length > 1 ? ' multi' : '');

    // One option only means there is nothing to choose. Hide the whole step.
    el('stepType').hidden = state.types.length < 2;

    state.types.forEach(function (t) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'bk-type';
      b.setAttribute('aria-pressed', state.type === t.key ? 'true' : 'false');
      b.innerHTML = '<span class="t"></span><span class="d"></span><span class="p"></span>';
      b.querySelector('.t').textContent = t.label;
      b.querySelector('.d').textContent = copyFor(t.key).blurb;
      b.querySelector('.p').textContent =
        (t.price > 0 ? '$' + t.price.toLocaleString('en-US') : 'Free') + ' · ' + t.minutes + ' minutes';
      b.addEventListener('click', function () { chooseType(t.key); });
      wrap.appendChild(b);
    });
  }

  function chooseType(key) {
    if (state.busy) return;
    state.type = key;
    state.day  = null;
    state.slot = null;
    drawTypes();
    ['stepDay', 'stepTime', 'stepForm', 'stepDone'].forEach(function (id) { el(id).hidden = true; });

    var c = copyFor(key);
    el('bkNoteLabel').textContent = c.note;
    el('bkSubmit').textContent    = c.submit;
    if (c.foot) el('bkFoot').textContent = c.foot;

    loadSlots(key);
  }

  function typeOf(key) {
    for (var i = 0; i < state.types.length; i++) if (state.types[i].key === key) return state.types[i];
    return null;
  }

  /* ---------- step two and three: day, then time ---------- */

  function loadSlots(key) {
    state.busy = true;
    say('Checking the calendar…', 'wait');
    el('bkDays').innerHTML = '';

    ask({ action: 'slots', type: key }, function (res) {
      state.busy = false;
      if (!res || !res.ok) { say((res && res.error) || 'Could not load the times.', 'err'); return; }
      say('');
      state.slots = res.slots || [];
      drawDays();
    }, function (msg) {
      state.busy = false;
      say(msg, 'err');
    });
  }

  function drawDays() {
    var wrap = el('bkDays');
    wrap.innerHTML = '';
    el('stepDay').hidden = false;

    var order = [], seen = {};
    state.slots.forEach(function (s) {
      if (!seen[s.ymd]) { seen[s.ymd] = true; order.push(s.ymd); }
    });

    if (!order.length) {
      el('stepDay').innerHTML =
        '<p class="bk-label">Which day</p>' +
        '<div class="bk-empty">Nothing open in the next three weeks. Tell me what you need at frimpomaasync.com/fit and I will make room.</div>';
      return;
    }

    order.forEach(function (ymd) {
      var parts = ymd.split('-');
      var d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'bk-day';
      b.setAttribute('aria-pressed', state.day === ymd ? 'true' : 'false');
      b.innerHTML = '<span class="dow"></span><span class="num"></span><span class="mon"></span>';
      b.querySelector('.dow').textContent = d.toLocaleDateString('en-US', { weekday: 'short' });
      b.querySelector('.num').textContent = d.getDate();
      b.querySelector('.mon').textContent = d.toLocaleDateString('en-US', { month: 'short' });
      b.addEventListener('click', function () { chooseDay(ymd); });
      wrap.appendChild(b);
    });

    if (!state.day) chooseDay(order[0]);
  }

  function chooseDay(ymd) {
    state.day  = ymd;
    state.slot = null;
    el('stepForm').hidden = true;
    el('stepDone').hidden = true;
    drawDays();
    drawTimes();
  }

  function drawTimes() {
    var wrap = el('bkTimes');
    wrap.innerHTML = '';
    el('stepTime').hidden = false;

    state.slots.filter(function (s) { return s.ymd === state.day; })
      .forEach(function (s) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'bk-time';
        b.textContent = s.time;
        b.setAttribute('aria-pressed', state.slot && state.slot.iso === s.iso ? 'true' : 'false');
        b.addEventListener('click', function () { chooseSlot(s); });
        wrap.appendChild(b);
      });
  }

  function chooseSlot(s) {
    state.slot = s;
    drawTimes();

    var t = typeOf(state.type);
    var local = localLine(s.iso);
    el('bkChosen').innerHTML = '<strong></strong><span class="sub"></span>';
    el('bkChosen').querySelector('strong').textContent = s.day + ' at ' + s.time + ' Eastern';
    el('bkChosen').querySelector('.sub').textContent =
      t.label + ' · ' + t.minutes + ' minutes' +
      (t.price > 0 ? ' · $' + t.price.toLocaleString('en-US') : '') +
      (local ? ' · ' + local : '');

    el('stepForm').hidden = false;
    el('stepForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  /* ---------- the timezone trap, handled out loud ---------- */

  function localLine(iso) {
    try {
      var here = Intl.DateTimeFormat().resolvedOptions().timeZone;
      if (!here || here === 'America/New_York') return '';
      return new Date(iso).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) + ' where you are';
    } catch (e) { return ''; }
  }

  function drawTz() {
    var line = 'Every time on this page is Eastern.';
    try {
      var here = Intl.DateTimeFormat().resolvedOptions().timeZone;
      if (here && here !== 'America/New_York') {
        line += ' Your device is set to ' + here.replace(/_/g, ' ') +
                ', so each time also shows in your own clock once you pick it.';
      }
    } catch (e) {}
    el('bkTz').textContent = line;
  }

  /* ---------- step four: book it ---------- */

  function wireForm() {
    el('bkForm').addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (state.busy || !state.slot) return;

      var name  = el('bkName').value.trim();
      var email = el('bkEmail').value.trim();
      var note  = el('bkNote').value.trim();

      if (name.length < 2) { say('Please put your name in.', 'err'); el('bkName').focus(); return; }
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/.test(email)) {
        say('That email address does not look right. Please check it.', 'err');
        el('bkEmail').focus();
        return;
      }

      state.busy = true;
      el('bkSubmit').disabled = true;
      var t = typeOf(state.type);
      say(t.price > 0 ? 'Holding your spot…' : 'Booking it…', 'wait');

      ask({ action: 'hold', type: state.type, iso: state.slot.iso, name: name, email: email, note: note },
        function (res) {
          state.busy = false;
          el('bkSubmit').disabled = false;

          if (!res || !res.ok) {
            say((res && res.error) || 'That did not go through. Please try again.', 'err');
            if (res && /took that time/.test(res.error || '')) loadSlots(state.type);
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
          el('bkSubmit').disabled = false;
          say(msg, 'err');
        });
    });
  }

  function showDone(when, held) {
    ['stepType', 'stepDay', 'stepTime', 'stepForm'].forEach(function (id) { el(id).hidden = true; });
    el('bkTz').hidden = true;

    var appeals = state.type === 'appeals';
    var box = el('stepDone');
    box.hidden = false;
    box.innerHTML = '<h2></h2><span class="big"></span><p class="one"></p><p class="two"></p>';
    box.querySelector('h2').textContent = held ? 'Your spot is held.' : 'You are booked.';
    box.querySelector('.big').textContent = when;

    box.querySelector('.one').textContent = held
      ? 'It stays yours for 24 hours. Finish the payment and it locks in for good.'
      : 'Check your email. The invite is attached, so it will drop onto your phone calendar.';

    box.querySelector('.two').textContent = held
      ? 'If life gets in the way, the spot quietly reopens and nothing is charged.'
      : (appeals
          ? 'Before we meet, pull a denial export covering your last few months. Do not send it yet. We put the agreement in place first.'
          : 'Nothing else to do. If you need to move it, reply to that email.');

    el('bkFoot').textContent = appeals
      ? 'The report is yours either way.'
      : 'Live in 7 days, or you don\'t pay.';

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
          'The invite is in your email, so it will drop onto your phone calendar. Send me anything you already have before we meet. Rough is fine.',
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

  /* ---------- start ---------- */

  function start() {
    // The confirmation page has no picker, only a result to paint.
    if (el('bkConfirmPage')) return runConfirmPage();
    if (!el('bkTypes')) return;

    if (DEMO && el('bkDemo')) el('bkDemo').hidden = false;
    drawTz();
    wireForm();

    ask({ action: 'slots', type: (CFG.only && CFG.only[0]) || 'free' }, function (res) {
      if (!res || !res.ok) { say((res && res.error) || 'Could not load the booking page.', 'err'); return; }

      var allowed = CFG.only || null;
      state.types = (res.types || []).filter(function (t) {
        if (allowed && allowed.indexOf(t.key) === -1) return false;
        return t.price === 0 || res.paidEnabled;
      });

      if (!state.types.length) {
        say('Nothing is open for booking on this page yet.', 'err');
        return;
      }

      state.slots = res.slots || [];
      drawTypes();

      if (state.types.length === 1) {
        chooseType(state.types[0].key);
      } else if (/[?&]type=map/.test(location.search)) {
        chooseType('map');
      }
    }, function (msg) { say(msg, 'err'); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
