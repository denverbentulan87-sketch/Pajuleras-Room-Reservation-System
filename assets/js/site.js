/* Public website interactions. Progressive enhancement only: the page is
   fully readable and usable without this file. Uses IntersectionObserver
   (no scroll-event listeners) so it stays smooth on mobile. */
(function () {
  'use strict';

  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hasIO = 'IntersectionObserver' in window;
  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  /* ---- Sticky navbar: transparent at the top, compact + blurred after scrolling ---- */
  var header = $('[data-header]');
  var sentinel = document.getElementById('top-sentinel');
  if (header && sentinel && hasIO) {
    new IntersectionObserver(function (entries) {
      header.classList.toggle('is-top', entries[0].isIntersecting);
    }).observe(sentinel);
  } else if (header) {
    header.classList.remove('is-top');
  }

  /* ---- Mobile menu (animated hamburger) ---- */
  var toggle = $('[data-nav-toggle]');
  var nav = document.getElementById('site-nav');
  var setMenu = function (open) {
    if (!toggle || !nav) { return; }
    nav.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (header) { header.classList.toggle('menu-open', open); }
  };
  if (toggle && nav) {
    toggle.addEventListener('click', function () { setMenu(!nav.classList.contains('is-open')); });
    nav.addEventListener('click', function (e) { if (e.target.closest('a, [data-open-login]')) { setMenu(false); } });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('is-open')) { setMenu(false); toggle.focus(); }
    });
    document.addEventListener('click', function (e) {
      if (nav.classList.contains('is-open') && !nav.contains(e.target) && !toggle.contains(e.target)) { setMenu(false); }
    });
    window.addEventListener('resize', function () { if (window.innerWidth > 1180) { setMenu(false); } });
  }

  /* ---- Scroll-spy: highlight the nav link of the section in view ---- */
  var links = $$('[data-nav]');
  var spyMap = { home: 'home', features: 'features', services: 'services', how: 'services', about: 'about', faq: 'about', contact: 'contact', pay: 'pay' };
  if (hasIO && document.body.classList.contains('landing') && links.length) {
    var setActive = function (key) {
      links.forEach(function (a) {
        var on = a.getAttribute('data-nav') === key;
        a.classList.toggle('is-active', on);
        if (on) { a.setAttribute('aria-current', 'location'); } else { a.removeAttribute('aria-current'); }
      });
    };
    var spy = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { setActive(spyMap[en.target.id]); } });
    }, { rootMargin: '-45% 0px -50% 0px' });
    Object.keys(spyMap).forEach(function (id) { var el = document.getElementById(id); if (el) { spy.observe(el); } });
  }

  /* ---- Fade-in / slide-up reveal ---- */
  var revealEls = $$('[data-reveal]');
  var finish = function (el) {
    var d = parseInt((el.style.getPropertyValue('--d') || '0'), 10) || 0;
    window.setTimeout(function () { el.classList.add('is-done'); }, 900 + d);
  };
  if (!hasIO || reduced) {
    revealEls.forEach(function (el) { el.classList.add('is-in', 'is-done'); });
  } else {
    var rev = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { en.target.classList.add('is-in'); finish(en.target); rev.unobserve(en.target); }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    revealEls.forEach(function (el) { rev.observe(el); });
  }

  /* ---- Animated counters ---- */
  var counters = $$('[data-count]');
  var render = function (el, v) {
    el.textContent = (el.getAttribute('data-prefix') || '') + Math.round(v).toLocaleString('en-US') + (el.getAttribute('data-suffix') || '');
  };
  if (hasIO && !reduced && counters.length) {
    counters.forEach(function (el) { render(el, 0); });
    var cio = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) { return; }
        var el = en.target, end = parseFloat(el.getAttribute('data-count')) || 0, t0 = null, dur = 1400;
        var step = function (t) {
          if (t0 === null) { t0 = t; }
          var p = Math.min(1, (t - t0) / dur);
          render(el, end * (1 - Math.pow(1 - p, 3)));      // ease-out cubic
          if (p < 1) { window.requestAnimationFrame(step); }
        };
        window.requestAnimationFrame(step);
        cio.unobserve(el);
      });
    }, { threshold: 0.6 });
    counters.forEach(function (el) { cio.observe(el); });
  }

  /* ---- Room board filter (All / Shared / Private) ---- */
  var group = $('[data-filter-group]');
  if (group) {
    var slots = $$('.room-slot[data-room-type]');
    var empty = $('[data-no-match]');
    group.addEventListener('click', function (e) {
      var b = e.target.closest('[data-filter]');
      if (!b) { return; }
      var f = b.getAttribute('data-filter'), shown = 0;
      $$('[data-filter]', group).forEach(function (x) {
        var on = x === b;
        x.classList.toggle('is-on', on);
        x.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      slots.forEach(function (s) {
        var match = f === 'all' || s.getAttribute('data-room-type') === f;
        s.hidden = !match;
        if (match) { shown++; }
      });
      if (empty) { empty.hidden = shown !== 0; }
    });
  }

  /* ---- Back-to-top button (appears after scrolling past the first screen) ---- */
  var top = $('[data-to-top]');
  if (top && hasIO) {
    var mark = document.createElement('div');
    mark.setAttribute('aria-hidden', 'true');
    mark.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:600px;pointer-events:none';
    document.body.insertBefore(mark, document.body.firstChild);
    var fabLate = $('.fab-late');
    new IntersectionObserver(function (entries) {
      var away = !entries[0].isIntersecting;
      top.classList.toggle('is-visible', away);
      if (fabLate) { fabLate.classList.toggle('is-visible', away); }
    }).observe(mark);
  }

  /* ---- Scroll progress bar (landing page) ---- */
  if (document.body.classList.contains('landing') && !reduced) {
    var bar = document.createElement('div');
    bar.className = 'scroll-progress';
    bar.setAttribute('aria-hidden', 'true');
    document.body.appendChild(bar);
    var ticking = false;
    var upd = function () {
      var h = document.documentElement.scrollHeight - window.innerHeight;
      bar.style.transform = 'scaleX(' + (h > 0 ? Math.min(1, window.scrollY / h) : 0) + ')';
      ticking = false;
    };
    window.addEventListener('scroll', function () {
      if (!ticking) { ticking = true; window.requestAnimationFrame(upd); }
    }, { passive: true });
    upd();
  }

  /* ---- Hero photos follow the pointer a little (desktop mouse only) ---- */
  var hv = $('.hero-visual');
  if (hv && !reduced && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    var main = $('.hv-main', hv), second = $('.hv-second', hv);
    hv.addEventListener('mousemove', function (e) {
      var r = hv.getBoundingClientRect();
      var x = (e.clientX - r.left) / r.width - 0.5, y = (e.clientY - r.top) / r.height - 0.5;
      if (main) { main.style.translate = (x * -10) + 'px ' + (y * -10) + 'px'; }
      if (second) { second.style.translate = (x * 16) + 'px ' + (y * 16) + 'px'; }
    });
    hv.addEventListener('mouseleave', function () {
      if (main) { main.style.translate = ''; }
      if (second) { second.style.translate = ''; }
    });
  }

  /* ---- Welcoming host: eyes follow the pointer, tap for a new greeting ---- */
  var host = $('[data-welcome]');
  if (host) {
    var greetings = ['Welcome!', 'Kumusta!', 'Rooms are open', 'Ask me anything', 'Come on in!', 'Salamat!'];
    var gi = 0, txt = $('.aw-text', host), pupils = $$('.aw-pupil', host), timer = null;
    var say = function () {
      gi = (gi + 1) % greetings.length;
      if (txt) { txt.textContent = greetings[gi]; }
      host.classList.remove('is-hello', 'is-bounce');
      void host.offsetWidth;                         // restart the animations
      host.classList.add('is-hello', 'is-bounce');
      window.clearTimeout(timer);
      timer = window.setTimeout(function () { host.classList.remove('is-hello', 'is-bounce'); }, 1800);
    };
    host.addEventListener('click', say);
    host.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); say(); }
    });
    if (!reduced && pupils.length && window.matchMedia('(hover: hover)').matches) {
      document.addEventListener('mousemove', function (e) {
        var r = host.getBoundingClientRect();
        var dx = e.clientX - (r.left + r.width / 2), dy = e.clientY - (r.top + r.height * 0.43);
        var d = Math.max(1, Math.sqrt(dx * dx + dy * dy)), k = Math.min(1, d / 220) * 4.5;
        var tx = (dx / d) * k, ty = (dy / d) * k;
        pupils.forEach(function (p) { p.style.transform = 'translate(' + tx.toFixed(2) + 'px,' + ty.toFixed(2) + 'px)'; });
      }, { passive: true });
    }
  }

  /* ---- Photos: zoom follows the pointer; click or tap opens a full-screen viewer ---- */
  var follow = $$('.zoomable, .room-photo');
  if (follow.length && window.matchMedia('(hover: hover)').matches && !reduced) {
    follow.forEach(function (el) {
      el.addEventListener('mousemove', function (e) {
        var r = el.getBoundingClientRect();
        el.style.setProperty('--mx', ((e.clientX - r.left) / r.width * 100).toFixed(1) + '%');
        el.style.setProperty('--my', ((e.clientY - r.top) / r.height * 100).toFixed(1) + '%');
      }, { passive: true });
    });
  }
  var zoomers = $$('[data-zoom]');
  if (zoomers.length && typeof HTMLDialogElement === 'function') {
    var dlg = document.createElement('dialog');
    dlg.className = 'lightbox';
    dlg.setAttribute('aria-label', 'Photo viewer');
    dlg.innerHTML = '<button type="button" class="lb-close" aria-label="Close photo">&times;</button><figure><img alt=""><figcaption></figcaption></figure>';
    document.body.appendChild(dlg);
    var lbImg = $('img', dlg), lbCap = $('figcaption', dlg), opener = null;
    var close = function () { if (dlg.open) { dlg.close(); } };
    zoomers.forEach(function (z) {
      z.addEventListener('click', function () {
        opener = z;
        lbImg.src = z.getAttribute('data-zoom');
        lbImg.alt = z.getAttribute('data-alt') || '';
        lbCap.textContent = z.getAttribute('data-alt') || '';
        dlg.showModal();
      });
    });
    dlg.addEventListener('click', close);
    dlg.addEventListener('close', function () { lbImg.removeAttribute('src'); if (opener) { opener.focus(); } });
  }
})();
