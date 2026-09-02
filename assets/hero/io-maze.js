/* ---------------------------------------------------------------------------
   The Invisible Office · the maze
   ---------------------------------------------------------------------------
   A 3D maze drawn on canvas, no library. Isometric projection with a slowly
   turning camera and pointer parallax. A copper signal explores the maze the
   way an owner does: every corridor, every dead end, back again. When the
   section reaches .is-built the walls sink into the floor, the one true path
   rises as a lit channel, and five signals run it in order. .is-staffed pans
   the maze to the right so the promise can sit on the left.

   Draws only while on screen. Device pixel ratio capped at 1.5. Reduced
   motion draws one solved frame and stops.
   --------------------------------------------------------------------------- */
(function () {
  var CREAM = [243, 234, 217];
  var COPPER = [194, 80, 28];
  var LABELS = ["Answering", "Qualifying", "Booking", "Following up", "Handing off"];

  function rgba(c, a) { return "rgba(" + c[0] + "," + c[1] + "," + c[2] + "," + a + ")"; }
  function shade(c, k) { return [Math.round(c[0] * k), Math.round(c[1] * k), Math.round(c[2] * k)]; }
  function ease(t) { return t < 0 ? 0 : t > 1 ? 1 : t * t * (3 - 2 * t); }

  /* Seeded random so the maze is the same maze every visit. */
  function rng(seed) {
    var s = seed >>> 0;
    return function () { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296; };
  }

  /* Maze on a (2w+1) x (2h+1) block grid: odd cells are rooms, even are walls. */
  function makeMaze(w, h, seed) {
    var rand = rng(seed);
    var W = 2 * w + 1, H = 2 * h + 1;
    var wall = [];
    for (var y = 0; y < H; y++) { wall.push([]); for (var x = 0; x < W; x++) wall[y].push(true); }
    var stack = [[1, 1]];
    wall[1][1] = false;
    var walk = [[1, 1]];
    while (stack.length) {
      var cur = stack[stack.length - 1];
      var opts = [];
      [[2, 0], [-2, 0], [0, 2], [0, -2]].forEach(function (d) {
        var nx = cur[0] + d[0], ny = cur[1] + d[1];
        if (nx > 0 && ny > 0 && nx < W && ny < H && wall[ny][nx]) opts.push([nx, ny, d]);
      });
      if (!opts.length) { stack.pop(); if (stack.length) walk.push(stack[stack.length - 1]); continue; }
      var pick = opts[Math.floor(rand() * opts.length)];
      wall[cur[1] + pick[2][1] / 2][cur[0] + pick[2][0] / 2] = false;
      wall[pick[1]][pick[0]] = false;
      stack.push([pick[0], pick[1]]);
      walk.push([pick[0], pick[1]]);
    }
    wall[1][0] = false;
    wall[H - 2][W - 1] = false;
    /* Solution by BFS from the entrance to the exit. */
    var prev = {}, q = [[0, 1]], seen = {"0,1": true};
    while (q.length) {
      var c = q.shift();
      if (c[0] === W - 1 && c[1] === H - 2) break;
      [[1, 0], [-1, 0], [0, 1], [0, -1]].forEach(function (d) {
        var nx = c[0] + d[0], ny = c[1] + d[1], k = nx + "," + ny;
        if (nx < 0 || ny < 0 || nx >= W || ny >= H || wall[ny][nx] || seen[k]) return;
        seen[k] = true; prev[k] = c; q.push([nx, ny]);
      });
    }
    var path = [], node = [W - 1, H - 2];
    while (node) { path.unshift(node); node = prev[node[0] + "," + node[1]]; }
    var onPath = {};
    path.forEach(function (p) { onPath[p[0] + "," + p[1]] = true; });
    return { W: W, H: H, wall: wall, walk: [[0, 1]].concat(walk), path: path, onPath: onPath };
  }

  function Maze(canvas, hero, opts) {
    this.canvas = canvas;
    this.hero = hero;
    this.ctx = canvas.getContext("2d");
    this.phone = opts.phone;
    this.reduce = opts.reduce;
    this.solvedFromStart = opts.solved;
    this.maze = makeMaze(opts.cols, opts.rows, 20260901);
    this.t0 = null;
    this.builtAt = null;
    this.staffedAt = null;
    this.pointer = { x: 0, y: 0 };
    this.visible = true;
    this.dpr = Math.min(window.devicePixelRatio || 1, 1.5);
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
    if (this.reduce) { this.builtAt = -10; this.staffedAt = -10; this.draw(4000); return; }
    window.requestAnimationFrame(function step(now) {
      if (self.visible) self.draw(now);
      window.requestAnimationFrame(step);
    });
  }

  Maze.prototype.resize = function () {
    var r = this.canvas.getBoundingClientRect();
    this.w = Math.max(1, Math.round(r.width));
    this.h = Math.max(1, Math.round(r.height));
    this.canvas.width = Math.round(this.w * this.dpr);
    this.canvas.height = Math.round(this.h * this.dpr);
  };

  /* Rotate the block grid around its centre, then 2:1 isometric projection. */
  Maze.prototype.project = function (gx, gy, z, cam) {
    var m = this.maze;
    var dx = gx - m.W / 2, dy = gy - m.H / 2;
    var rx = dx * cam.cos - dy * cam.sin;
    var ry = dx * cam.sin + dy * cam.cos;
    return [cam.cx + (rx - ry) * cam.s, cam.cy + (rx + ry) * cam.s * cam.tilt - z * cam.s * 0.9];
  };

  Maze.prototype.draw = function (now) {
    if (this.t0 === null) this.t0 = now;
    var t = (now - this.t0) / 1000;
    var cls = this.hero.classList;
    if (this.builtAt === null && (cls.contains("is-built") || this.solvedFromStart)) this.builtAt = t;
    if (this.staffedAt === null && cls.contains("is-staffed")) this.staffedAt = t;
    var built = this.builtAt === null ? 0 : ease((t - this.builtAt) / 1.6);
    var staffed = this.staffedAt === null ? 0 : ease((t - this.staffedAt) / 1.2);
    if (this.reduce) { built = 1; staffed = 1; }

    var ctx = this.ctx, w = this.w, h = this.h, m = this.maze;
    ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);

    /* Camera: slow drift, pointer parallax, pans right once staffed. */
    var angle = 0.62 + Math.sin(t * 0.11) * 0.12 + this.pointer.x * 0.16;
    var tilt = (this.phone ? 0.68 : 0.5) + this.pointer.y * 0.06;
    var fit = Math.min(w / (m.W + m.H), h / ((m.W + m.H) * tilt + 4)) * (this.phone ? 1.18 : 0.94);
    var cam = {
      cos: Math.cos(angle), sin: Math.sin(angle), s: fit, tilt: tilt,
      cx: w * (this.phone ? 0.5 : 0.58 + 0.14 * staffed),
      cy: h * (this.phone ? 0.5 : 0.55 - 0.03 * staffed)
    };
    var self = this;

    /* Floor plate. */
    var corners = [[0, 0], [m.W, 0], [m.W, m.H], [0, m.H]].map(function (c) { return self.project(c[0], c[1], 0, cam); });
    ctx.beginPath();
    corners.forEach(function (p, i) { i ? ctx.lineTo(p[0], p[1]) : ctx.moveTo(p[0], p[1]); });
    ctx.closePath();
    ctx.fillStyle = "rgba(243,234,217,0.035)";
    ctx.fill();
    ctx.strokeStyle = "rgba(243,234,217,0.14)";
    ctx.lineWidth = 1;
    ctx.stroke();

    /* Blocks: every wall, sinking once built; every path cell, rising once built. */
    var blocks = [];
    var wallH = 1.15;
    for (var y = 0; y < m.H; y++) {
      for (var x = 0; x < m.W; x++) {
        var isWall = m.wall[y][x];
        var key = x + "," + y;
        var onPath = !!m.onPath[key];
        if (!isWall && !onPath) continue;
        var z;
        if (isWall) {
          var stagger = ((x + y) / (m.W + m.H)) * 0.6;
          var sink = this.builtAt === null ? 0 : ease((t - this.builtAt - stagger) / 1.1);
          if (this.reduce) sink = 1;
          z = wallH * (1 - sink);
          if (z < 0.02) continue;
        } else {
          var rise = this.builtAt === null ? 0 : ease((t - this.builtAt - 0.9) / 0.9);
          if (this.reduce) rise = 1;
          z = 0.22 * rise;
          if (z < 0.01) continue;
        }
        var top = this.project(x + 0.5, y + 0.5, 0, cam);
        blocks.push({ x: x, y: y, z: z, depth: top[1], path: !isWall });
      }
    }
    blocks.sort(function (a, b) { return a.depth - b.depth; });
    var light = { x: Math.cos(angle + 2.2), y: Math.sin(angle + 2.2) };
    blocks.forEach(function (b) {
      var base = b.path ? COPPER : CREAM;
      var pts = [[b.x, b.y], [b.x + 1, b.y], [b.x + 1, b.y + 1], [b.x, b.y + 1]];
      var lo = pts.map(function (p) { return self.project(p[0], p[1], 0, cam); });
      var hi = pts.map(function (p) { return self.project(p[0], p[1], b.z, cam); });
      var normals = [[0, -1], [1, 0], [0, 1], [-1, 0]];
      for (var i = 0; i < 4; i++) {
        var j = (i + 1) % 4;
        /* A side is visible when its outward normal points toward the viewer. */
        var n = normals[i];
        var nx = n[0] * cam.cos - n[1] * cam.sin, ny = n[0] * cam.sin + n[1] * cam.cos;
        var toward = nx + ny;
        if (toward <= 0) continue;
        var k = 0.34 + 0.3 * Math.max(0, nx * light.x + ny * light.y);
        ctx.beginPath();
        ctx.moveTo(lo[i][0], lo[i][1]); ctx.lineTo(lo[j][0], lo[j][1]);
        ctx.lineTo(hi[j][0], hi[j][1]); ctx.lineTo(hi[i][0], hi[i][1]);
        ctx.closePath();
        ctx.fillStyle = rgba(shade(base, k), b.path ? 0.95 : 0.92);
        ctx.fill();
      }
      ctx.beginPath();
      hi.forEach(function (p, i) { i ? ctx.lineTo(p[0], p[1]) : ctx.moveTo(p[0], p[1]); });
      ctx.closePath();
      ctx.fillStyle = rgba(shade(base, b.path ? 1 : 0.86), b.path ? 1 : 0.96);
      ctx.fill();
    });

    /* Signals. Before the build: one signal walks the whole maze, dead ends
       included. After: five run the lit path in order, each with a label. */
    if (built < 0.999) {
      var walk = m.walk;
      var speed = 5.5;
      var pos = (t * speed) % (walk.length - 1);
      var i0 = Math.floor(pos), f = pos - i0;
      var a = walk[i0], b2 = walk[Math.min(i0 + 1, walk.length - 1)];
      var gx = a[0] + (b2[0] - a[0]) * f + 0.5, gy = a[1] + (b2[1] - a[1]) * f + 0.5;
      ctx.globalAlpha = 1 - built;
      this.trail(walk, pos, 14, cam, 0.5, COPPER);
      this.dot(this.project(gx, gy, 0.55, cam), 5, COPPER, 1);
      ctx.globalAlpha = 1;
    }
    if (this.builtAt !== null) {
      var since = t - this.builtAt - 1.6;
      if (this.reduce) since = 40;
      var path = m.path;
      for (var k2 = 0; k2 < 5; k2++) {
        var start = k2 * 0.9;
        var runT = since - start;
        if (runT < 0) continue;
        var p = ((runT * 6) % (path.length - 1 + 6));
        if (p > path.length - 1) continue;
        var i1 = Math.floor(p), f1 = p - i1;
        var a1 = path[i1], b1 = path[Math.min(i1 + 1, path.length - 1)];
        var gx1 = a1[0] + (b1[0] - a1[0]) * f1 + 0.5, gy1 = a1[1] + (b1[1] - a1[1]) * f1 + 0.5;
        this.trail(path, p, 8, cam, 0.42, CREAM);
        this.dot(this.project(gx1, gy1, 0.42, cam), 4.5, CREAM, 1);
      }
      /* Labels sit on the path at five even stations once the path is lit. */
      var lit = ease(since / 1.2);
      if (lit > 0) {
        ctx.font = "600 " + (this.phone ? 10 : 11) + "px ui-monospace, SF Mono, Menlo, monospace";
        ctx.textBaseline = "middle";
        for (var s2 = 0; s2 < 5; s2++) {
          var idx = Math.round((s2 + 0.5) / 5 * (path.length - 1));
          var pp = path[idx];
          var sp = this.project(pp[0] + 0.5, pp[1] + 0.5, 1.25, cam);
          var show = ease((since - 0.4 - s2 * 0.35) / 0.5);
          if (show <= 0) continue;
          ctx.globalAlpha = show;
          var foot = this.project(pp[0] + 0.5, pp[1] + 0.5, 0.3, cam);
          ctx.strokeStyle = rgba(COPPER, 0.9);
          ctx.lineWidth = 1;
          ctx.beginPath(); ctx.moveTo(foot[0], foot[1]); ctx.lineTo(sp[0], sp[1]); ctx.stroke();
          this.dot(sp, 3, COPPER, 1);
          var label = LABELS[s2].toUpperCase();
          var tw = ctx.measureText(label).width + 14;
          ctx.fillStyle = "rgba(11,11,15,0.86)";
          ctx.fillRect(sp[0] + 8, sp[1] - 10, tw, 20);
          ctx.strokeStyle = rgba(CREAM, 0.25);
          ctx.strokeRect(sp[0] + 8.5, sp[1] - 9.5, tw - 1, 19);
          ctx.fillStyle = rgba(CREAM, 1);
          ctx.fillText(label, sp[0] + 15, sp[1] + 0.5);
          ctx.globalAlpha = 1;
        }
      }
    }
  };

  Maze.prototype.trail = function (pts, pos, len, cam, z, colour) {
    var ctx = this.ctx;
    var i0 = Math.floor(pos);
    ctx.lineCap = "round";
    ctx.lineJoin = "round";
    for (var n = 0; n < len; n++) {
      var i = i0 - n;
      if (i < 0) break;
      var a = pts[i], b = pts[i + 1];
      var f = n === 0 ? pos - i0 : 1;
      var p0 = this.project(a[0] + 0.5, a[1] + 0.5, z, cam);
      var p1 = this.project(a[0] + (b[0] - a[0]) * f + 0.5, a[1] + (b[1] - a[1]) * f + 0.5, z, cam);
      ctx.strokeStyle = rgba(colour, 0.85 * (1 - n / len));
      ctx.lineWidth = 3 * (1 - n / len) + 0.5;
      ctx.beginPath(); ctx.moveTo(p0[0], p0[1]); ctx.lineTo(p1[0], p1[1]); ctx.stroke();
    }
  };

  Maze.prototype.dot = function (p, r, colour, a) {
    var ctx = this.ctx;
    var g = ctx.createRadialGradient(p[0], p[1], 0, p[0], p[1], r * 4);
    g.addColorStop(0, rgba(colour, 0.55 * a));
    g.addColorStop(1, rgba(colour, 0));
    ctx.fillStyle = g;
    ctx.beginPath(); ctx.arc(p[0], p[1], r * 4, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = rgba(colour, a);
    ctx.beginPath(); ctx.arc(p[0], p[1], r, 0, Math.PI * 2); ctx.fill();
  };

  window.IoMaze = Maze;
})();
