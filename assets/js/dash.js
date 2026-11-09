/* Dashboard motion: count-up numbers, occupancy ring, collections curve with hover read-out. */
(function () {
  'use strict';
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* numbers count up once */
  $$('[data-count]').forEach(function (el) {
    var end = parseInt(el.getAttribute('data-count'), 10);
    if (reduced || !isFinite(end) || end < 1) { return; }
    var t0 = null, dur = 700;
    el.textContent = '0';
    function step(ts) {
      if (t0 === null) { t0 = ts; }
      var p = Math.min(1, (ts - t0) / dur), e = 1 - Math.pow(1 - p, 3);
      el.textContent = String(Math.round(end * e));
      if (p < 1) { requestAnimationFrame(step); }
    }
    requestAnimationFrame(step);
  });

  /* occupancy ring */
  $$('[data-ring]').forEach(function (ring) {
    var occ = parseFloat(ring.getAttribute('data-occ')) || 0;
    var res = parseFloat(ring.getAttribute('data-res')) || 0;
    var o = $('.r-occ', ring), r = $('.r-res', ring);
    function set(a, b) {
      o.style.strokeDasharray = a + ' ' + (100 - a);
      r.style.strokeDasharray = b + ' ' + (100 - b);
      r.style.strokeDashoffset = String(-a);
    }
    if (reduced) { set(occ, res); return; }
    set(0, 0);
    requestAnimationFrame(function () { requestAnimationFrame(function () { ring.classList.add('go'); set(occ, res); }); });
  });

  /* collections curve */
  $$('[data-trend]').forEach(function (box) {
    var svg = $('svg', box), tip = $('.t-tip', box), guide = $('.t-guide', svg);
    var dots = $$('.t-dot', svg);
    if (!dots.length) { return; }
    var active = -1;
    function show(i) {
      if (i === active) { return; }
      active = i;
      dots.forEach(function (d, k) { d.classList.toggle('on', k === i); });
      var d = dots[i], cx = parseFloat(d.getAttribute('cx'));
      guide.setAttribute('x1', cx); guide.setAttribute('x2', cx); guide.classList.add('on');
      $('strong', tip).textContent = d.getAttribute('data-value');
      $('span', tip).textContent = d.getAttribute('data-label');
      tip.hidden = false;
      var sb = svg.getBoundingClientRect(), vb = svg.viewBox.baseVal;
      var x = cx / vb.width * sb.width, y = parseFloat(d.getAttribute('cy')) / vb.height * sb.height;
      tip.style.left = Math.max(54, Math.min(sb.width - 54, x)) + 'px';
      tip.style.top = (y - 12) + 'px';
    }
    function hide() { active = -1; dots.forEach(function (d) { d.classList.remove('on'); }); guide.classList.remove('on'); tip.hidden = true; }
    svg.addEventListener('pointermove', function (e) {
      var sb = svg.getBoundingClientRect(), vb = svg.viewBox.baseVal, px = (e.clientX - sb.left) / sb.width * vb.width, best = 0, bd = 1e9;
      dots.forEach(function (d, k) { var dd = Math.abs(parseFloat(d.getAttribute('cx')) - px); if (dd < bd) { bd = dd; best = k; } });
      show(best);
    });
    svg.addEventListener('pointerleave', hide);
    svg.addEventListener('touchstart', function (e) { var t = e.touches[0]; svg.dispatchEvent(new PointerEvent('pointermove', { clientX: t.clientX, clientY: t.clientY })); }, { passive: true });
  });
})();
