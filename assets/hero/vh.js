/* Home hero cinematic loop, 2026-08-17.
   Picks the mobile or desktop encode, keeps the poster up when video cannot
   play, honours reduced motion and Save-Data, pauses off screen and when the
   tab is hidden, and gives the visitor a Pause motion control. */
(function () {
  'use strict';
  var video = document.getElementById('vh-video');
  var motion = document.getElementById('vh-motion');
  var hero = video && video.closest('.vh-hero');
  if (!video || !motion || !hero) return;

  var mqSmall = window.matchMedia('(max-width: 760px)');
  var mqCalm = window.matchMedia('(prefers-reduced-motion: reduce)');
  var current = null;
  var wantsPlay = true;

  function applySource() {
    var key = mqSmall.matches ? 'mobile' : 'desktop';
    if (key === current) return;
    current = key;
    video.poster = video.dataset[key + 'Poster'];
    video.innerHTML = '';
    [['Webm', 'video/webm'], ['Mp4', 'video/mp4']].forEach(function (s) {
      var el = document.createElement('source');
      el.src = video.dataset[key + s[0]];
      el.type = s[1];
      video.appendChild(el);
    });
    video.load();
    if (wantsPlay && !mqCalm.matches) video.play().catch(function () {});
  }

  /* If the video cannot load or play, stay on the poster. Never a blank box. */
  function posterOnly() {
    try { video.pause(); } catch (e) {}
    video.innerHTML = '';
    video.removeAttribute('src');
    try { video.load(); } catch (e) {}
    hero.classList.add('vh-still');
    motion.hidden = true;
  }

  function setToggle(playing) {
    motion.setAttribute('aria-pressed', String(playing));
    motion.textContent = playing ? 'Pause motion' : 'Play motion';
  }

  var conn = navigator.connection || {};
  if (conn.saveData === true || /^(slow-)?2g$/.test(conn.effectiveType || '')) {
    posterOnly();
    return;
  }

  applySource();
  motion.hidden = false;
  mqSmall.addEventListener('change', applySource);
  video.addEventListener('error', posterOnly, true);
  video.addEventListener('playing', function () { hero.classList.add('vh-playing'); });

  var p = video.play();
  if (p && p.catch) p.catch(function () { /* autoplay refused: poster stays, control still works */ setToggle(false); wantsPlay = false; });

  setTimeout(function () {
    if (!mqCalm.matches && wantsPlay && video.readyState < 3 && video.networkState === 3) posterOnly();
  }, 6000);

  function applyCalm() {
    if (mqCalm.matches) { try { video.pause(); } catch (e) {} setToggle(false); wantsPlay = false; }
  }
  mqCalm.addEventListener('change', applyCalm);
  applyCalm();

  motion.addEventListener('click', function () {
    if (video.paused) { wantsPlay = true; video.play().catch(function () {}); setToggle(true); }
    else { wantsPlay = false; video.pause(); setToggle(false); }
  });

  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!wantsPlay || mqCalm.matches) return;
        if (entry.isIntersecting) video.play().catch(function () {});
        else video.pause();
      });
    }, { threshold: 0.05 }).observe(hero);
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { try { video.pause(); } catch (e) {} }
    else if (wantsPlay && !mqCalm.matches) video.play().catch(function () {});
  });
})();
