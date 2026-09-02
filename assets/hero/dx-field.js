/* ---------------------------------------------------------------------------
   The Denial X-Ray · the claim field
   ---------------------------------------------------------------------------
   Thousands of claims fall through a dark 3D field, drawn on canvas with a
   perspective camera and no library. A copper beam sweeps the field. It
   catches one claim and lifts it; the field slows and dims. The claim is read
   through four stations (what happened, what is missing, how much time
   remains, what should happen next), splits into three pathways, one of
   which lights, and lands as OWNED with a deadline and a next action.

   The section's state classes drive the film (denial-xray.js sets them):
   is-stamped starts the beam · is-f1..f4 open the stations · is-split shows
   the three pathways · is-chosen lights APPEAL · is-owned lands the claim ·
   is-final pans the field so the headline has room.
   --------------------------------------------------------------------------- */
(function () {
  var CREAM = [243, 234, 217];
  var COPPER = [194, 80, 28];
  var STATIONS = [
    ["What happened", "Billed without the authorization the plan required", "No authorization on file"],
    ["What is missing", "Retro-authorization request · visit note", "Retro-auth request · visit note"],
    ["How much time remains", "168 of 180 days", "168 of 180 days"],
    ["What should happen next", "Request retro-authorization · file the appeal", "Retro-auth, then appeal"]
  ];
  var PATHS = ["Appeal", "Correct / resubmit", "Close"];

  function rgba(c, a) { return "rgba(" + c[0] + "," + c[1] + "," + c[2] + "," + a + ")"; }
  function ease(t) { return t < 0 ? 0 : t > 1 ? 1 : t * t * (3 - 2 * t); }
  function rng(seed) { var s = seed >>> 0; return function () { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296; }; }

  function Field(canvas, hero, opts) {
    this.canvas = canvas;
    this.hero = hero;
    this.ctx = canvas.getContext("2d");
    this.phone = opts.phone;
    this.reduce = opts.reduce;
    this.dpr = Math.min(window.devicePixelRatio || 1, 1.5);
    this.count = opts.phone ? 900 : 2200;
    this.rand = rng(20260901);
    this.particles = [];
    for (var i = 0; i < this.count; i++) {
      this.particles.push({
        x: this.rand() * 2 - 1, y: this.rand() * 2 - 1, z: this.rand(),
        v: 0.05 + this.rand() * 0.12, copper: this.rand() < 0.06, drift: (this.rand() - 0.5) * 0.04
      });
    }
    this.marks = {};
    this.pointer = { x: 0, y: 0 };
    this.visible = true;
    this.t0 = null;
    this.resize();
    var self = this;
    window.addEventListener("resize", function () { self.resize(); });
    if (!opts.phone) {
      window.addEventListener("pointermove", function (e) {
        self.pointer.x = (e.clientX / window.innerWidth - 0.5) * 2;
        self.pointer.y = (e.clientY / window.innerHeight - 0.5) * 2;
      }, { passive: true });
    }
    if ("IntersectionObserver" in window) {
      new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) { self.visible = entry.isIntersecting; });
      }, { threshold: 0.02 }).observe(canvas);
    }
    if (this.reduce) { this.draw(60000); return; }
    window.requestAnimationFrame(function step(now) {
      if (self.visible) self.draw(now);
      window.requestAnimationFrame(step);
    });
  }

  Field.prototype.resize = function () {
    var r = this.canvas.getBoundingClientRect();
    this.w = Math.max(1, Math.round(r.width));
    this.h = Math.max(1, Math.round(r.height));
    this.canvas.width = Math.round(this.w * this.dpr);
    this.canvas.height = Math.round(this.h * this.dpr);
  };

  /* A state's first frame time, so each beat animates from when it began. */
  Field.prototype.mark = function (name, t) {
    if (this.reduce) return 100;
    if (this.marks[name] === undefined) {
      if (this.hero.classList.contains(name)) this.marks[name] = t; else return -1;
    }
    return t - this.marks[name];
  };

  Field.prototype.project = function (p, cam) {
    var scale = 1 / (1 + p.z * 1.6);
    var px = cam.cx + (p.x + this.pointer.x * 0.05 * (1 - p.z)) * cam.half * scale;
    var py = cam.cy + (p.y + this.pointer.y * 0.04 * (1 - p.z)) * cam.halfY * scale;
    return [px, py, scale];
  };

  Field.prototype.draw = function (now) {
    if (this.t0 === null) this.t0 = now;
    var t = (now - this.t0) / 1000;
    var ctx = this.ctx, w = this.w, h = this.h;
    ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);

    var stamped = this.mark("is-stamped", t);
    var caughtAt = this.phone ? 0.2 : 3.2;            /* seconds after the beam starts */
    var caught = stamped < 0 ? 0 : ease((stamped - caughtAt) / 0.9);
    var f = [this.mark("is-f1", t), this.mark("is-f2", t), this.mark("is-f3", t), this.mark("is-f4", t)];
    var split = this.mark("is-split", t);
    var chosen = this.mark("is-chosen", t);
    var owned = this.mark("is-owned", t);
    var fin = this.mark("is-final", t);
    var pan = fin < 0 ? 0 : ease(fin / 1.2);
    if (this.phone) pan = 0;

    var cam = {
      cx: w * (0.5 + 0.14 * pan), cy: h * (this.phone ? 0.44 : 0.5),
      half: Math.max(w, h) * 0.62, halfY: Math.max(w, h) * 0.62
    };

    /* The field. Falls until the catch, then slows to a crawl and dims. */
    var speed = 1 - caught * 0.92;
    var dim = 1 - caught * 0.72 - pan * 0.15;
    var dt = this.last ? Math.min(0.05, (now - this.last) / 1000) : 0.016;
    this.last = now;
    var self = this;
    var beamX = stamped < 0 ? -2 : -1.15 + (stamped / caughtAt) * 1.15;   /* reaches the centre on the catch */
    if (stamped > caughtAt) beamX = 0;
    var sorted = this.particles;
    for (var i = 0; i < sorted.length; i++) {
      var p = sorted[i];
      p.y += p.v * dt * speed * (1.2 - p.z);
      p.x += p.drift * dt * speed;
      if (p.y > 1.15) { p.y = -1.15; p.x = this.rand() * 2 - 1; }
      var s = this.project(p, cam);
      if (s[0] < -4 || s[0] > w + 4 || s[1] < -4 || s[1] > h + 4) continue;
      var near = Math.abs(p.x - beamX);
      var lit = stamped >= 0 && stamped < caughtAt + 0.6 ? Math.max(0, 1 - near / 0.12) : 0;
      var a = (0.12 + (1 - p.z) * 0.5) * dim + lit * 0.5;
      var r = (0.7 + (1 - p.z) * 1.6) * s[2] * 1.6 + lit * 1.2;
      ctx.fillStyle = rgba(p.copper || lit > 0.3 ? COPPER : CREAM, Math.min(1, a));
      ctx.beginPath(); ctx.arc(s[0], s[1], r, 0, Math.PI * 2); ctx.fill();
    }

    /* The beam: a copper column of light sweeping left to right. */
    if (stamped >= 0 && stamped < caughtAt + 0.9) {
      var bx = cam.cx + beamX * cam.half * 0.62;
      var fade = stamped > caughtAt ? 1 - (stamped - caughtAt) / 0.9 : Math.min(1, stamped / 0.5);
      var g = ctx.createLinearGradient(bx - 60, 0, bx + 60, 0);
      g.addColorStop(0, rgba(COPPER, 0));
      g.addColorStop(0.5, rgba(COPPER, 0.28 * fade));
      g.addColorStop(1, rgba(COPPER, 0));
      ctx.fillStyle = g;
      ctx.fillRect(bx - 60, 0, 120, h);
      ctx.fillStyle = rgba(CREAM, 0.7 * fade);
      ctx.fillRect(bx - 0.5, 0, 1, h);
    }

    if (caught <= 0) return;

    /* The caught claim: lifted to the centre, held in a ring. */
    var cx = cam.cx, cy = cam.cy;
    var ring = 14 + 6 * Math.sin(t * 2.2);
    var glow = ctx.createRadialGradient(cx, cy, 0, cx, cy, 90);
    glow.addColorStop(0, rgba(COPPER, 0.35 * caught));
    glow.addColorStop(1, rgba(COPPER, 0));
    ctx.fillStyle = glow;
    ctx.beginPath(); ctx.arc(cx, cy, 90, 0, Math.PI * 2); ctx.fill();
    ctx.strokeStyle = rgba(COPPER, caught);
    ctx.lineWidth = 1.5;
    ctx.beginPath(); ctx.arc(cx, cy, ring, 0, Math.PI * 2); ctx.stroke();
    ctx.fillStyle = rgba(CREAM, caught);
    ctx.beginPath(); ctx.arc(cx, cy, 4, 0, Math.PI * 2); ctx.fill();

    ctx.font = "600 " + (this.phone ? 10 : 11) + "px ui-monospace, SF Mono, Menlo, monospace";
    ctx.textBaseline = "middle";
    var mono = ctx.font;
    var body = "400 " + (this.phone ? 12 : 13) + "px -apple-system, BlinkMacSystemFont, Segoe UI, Helvetica, Arial, sans-serif";

    /* Stations: four readings. Desktop: on an arc above the pathways.
       Phone: a column at the top, every label reading to the right. */
    var R = Math.min(w, h) * (0.36 - 0.06 * pan);
    var angles = [-2.35, -0.75, 0.3, -3.0];
    for (var k = 0; k < 4; k++) {
      var open = f[k] < 0 ? 0 : ease(f[k] / 0.6);
      if (open <= 0) continue;
      var sx, sy, rightSide;
      if (this.phone) {
        sx = 26; sy = h * 0.12 + k * 46; rightSide = true;
      } else {
        sx = cx + Math.cos(angles[k]) * R * 1.25;
        sy = cy + Math.sin(angles[k]) * R * 0.8;
        rightSide = Math.cos(angles[k]) >= 0;
      }
      ctx.globalAlpha = open;
      ctx.strokeStyle = rgba(COPPER, 0.8);
      ctx.lineWidth = 1;
      ctx.setLineDash([3, 5]);
      ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(cx + (sx - cx) * open, cy + (sy - cy) * open); ctx.stroke();
      ctx.setLineDash([]);
      ctx.fillStyle = rgba(COPPER, 1);
      ctx.beginPath(); ctx.arc(sx, sy, 3.5, 0, Math.PI * 2); ctx.fill();
      /* Once the headline needs the left half, the readings step back and
         only their dots and threads stay. */
      if (open * (1 - pan) > 0.01) {
        ctx.globalAlpha = open * (1 - pan);
        this.label(sx, sy, STATIONS[k][0].toUpperCase(), STATIONS[k][this.phone ? 2 : 1], rightSide, mono, body, open);
      }
      ctx.globalAlpha = 1;
    }

    /* Split: three pathways fan out below the claim; APPEAL lights. */
    if (split >= 0) {
      var spread = this.phone ? [-0.82, 0, 0.82] : [-0.55, 0, 0.55];
      var down = this.phone ? h * 0.30 : h * 0.30;
      for (var m = 0; m < 3; m++) {
        var grow = ease((split - m * 0.15) / 0.6);
        if (grow <= 0) continue;
        var ex = cx + spread[m] * (this.phone ? w * 0.42 : w * 0.16);
        var ey = cy + down;
        var isPick = m === 0;
        var lightUp = chosen < 0 ? 0 : ease(chosen / 0.5);
        var alpha = isPick ? 0.9 : 0.9 - lightUp * 0.65;
        ctx.strokeStyle = rgba(isPick && lightUp > 0 ? COPPER : CREAM, alpha * grow);
        ctx.lineWidth = isPick ? 1.5 + lightUp : 1;
        ctx.beginPath();
        ctx.moveTo(cx, cy + 18);
        ctx.bezierCurveTo(cx, cy + down * 0.5, ex, cy + down * 0.5, cx + (ex - cx) * grow, cy + 18 + (ey - cy - 18) * grow);
        ctx.stroke();
        if (grow >= 1) {
          ctx.globalAlpha = 1;
          var tag = PATHS[m].toUpperCase();
          ctx.font = mono;
          var tw = ctx.measureText(tag).width + 16;
          ctx.fillStyle = isPick && lightUp > 0 ? rgba(COPPER, 0.9 * lightUp + 0.1) : "rgba(16,20,38,0.9)";
          ctx.fillRect(ex - tw / 2, ey - 11, tw, 22);
          ctx.strokeStyle = rgba(isPick ? COPPER : CREAM, isPick ? 1 : alpha);
          ctx.lineWidth = 1;
          ctx.strokeRect(ex - tw / 2 + 0.5, ey - 10.5, tw - 1, 21);
          ctx.fillStyle = rgba(CREAM, isPick ? 1 : alpha);
          ctx.textAlign = "center";
          ctx.fillText(tag, ex, ey + 0.5);
          ctx.textAlign = "left";
        }
      }
    }

    /* Owned: the claim lands with a deadline and a next action under it. */
    if (owned >= 0) {
      var land = ease(owned / 0.7);
      var oy = cy + h * 0.30 + (this.phone ? 44 : 40);
      var ox = this.phone ? cx : cx - w * 0.09;
      ctx.globalAlpha = land;
      ctx.font = mono;
      var tag2 = "OWNED";
      var tw2 = ctx.measureText(tag2).width + 18;
      ctx.fillStyle = rgba(COPPER, 1);
      ctx.fillRect(ox - tw2 / 2, oy - 11, tw2, 22);
      ctx.fillStyle = rgba(CREAM, 1);
      ctx.textAlign = "center";
      ctx.fillText(tag2, ox, oy + 0.5);
      ctx.textAlign = "left";
      ctx.font = body;
      ctx.fillStyle = rgba(CREAM, 0.9);
      if (this.phone) {
        ctx.textAlign = "center";
        ctx.fillText("Deadline · day 12 of 180", ox, oy + 26);
        ctx.fillText("Next · retro-auth, then appeal", ox, oy + 44);
        ctx.textAlign = "left";
      } else {
        ctx.fillText("Deadline · day 12 of 180", ox + tw2 / 2 + 14, oy - 8);
        ctx.fillText("Next · request retro-authorization, then file the appeal", ox + tw2 / 2 + 14, oy + 10);
      }
      ctx.globalAlpha = 1;
    }
  };

  Field.prototype.label = function (x, y, head, text, rightSide, mono, body, a) {
    var ctx = this.ctx;
    ctx.font = mono;
    var hw = ctx.measureText(head).width;
    ctx.font = body;
    var bw = ctx.measureText(text).width;
    var wdt = Math.max(hw, bw) + 20;
    var bx = rightSide ? x + 10 : x - 10 - wdt;
    ctx.fillStyle = "rgba(16,20,38,0.88)";
    ctx.fillRect(bx, y - 20, wdt, 40);
    ctx.fillStyle = rgba(COPPER, 1);
    ctx.fillRect(rightSide ? bx : bx + wdt - 2, y - 20, 2, 40);
    ctx.font = mono;
    ctx.fillStyle = rgba(COPPER, a);
    ctx.fillText(head, bx + 10, y - 9);
    ctx.font = body;
    ctx.fillStyle = rgba(CREAM, a);
    ctx.fillText(text, bx + 10, y + 9);
  };

  window.DxField = Field;
})();
