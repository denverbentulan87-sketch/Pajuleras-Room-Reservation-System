/* Admin dashboard: pop-up (modal) forms, confirmations, search, menus.
   Without JavaScript every link and form still works as a normal page. */
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var MODAL_RE = /\/admin\/(room_form|tenant_form|reservation_form|payment_form)\.php(\?|#|$)/;
  var HEAD = { 'X-Modal': '1', 'X-Requested-With': 'fetch', 'Accept': 'text/html,application/json' };
  var canDialog = typeof HTMLDialogElement === 'function';

  /* ---------------------------------------------------------- toasts */
  var toastBox = null;
  function toast(msg, type) {
    if (!toastBox) {
      toastBox = document.createElement('div');
      toastBox.className = 'toasts';
      toastBox.setAttribute('role', 'status');
      toastBox.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastBox);
    }
    var t = document.createElement('div');
    t.className = 'toast toast-' + (type || 'success');
    var span = document.createElement('span'); span.textContent = msg; t.appendChild(span);
    var x = document.createElement('button'); x.type = 'button'; x.setAttribute('aria-label', 'Dismiss'); x.textContent = '\u00d7';
    x.addEventListener('click', function () { t.remove(); });
    t.appendChild(x);
    toastBox.appendChild(t);
    window.setTimeout(function () { t.classList.add('out'); window.setTimeout(function () { t.remove(); }, 400); }, type === 'error' ? 9000 : 5500);
  }
  function queueToasts(list) {
    try {
      var q = JSON.parse(window.sessionStorage.getItem('pbh_toasts') || '[]').concat(list);
      window.sessionStorage.setItem('pbh_toasts', JSON.stringify(q));
    } catch (e) { (list || []).forEach(function (f) { toast(f.msg, f.type); }); }
  }
  try {
    var pending = JSON.parse(window.sessionStorage.getItem('pbh_toasts') || '[]');
    if (pending.length) { window.sessionStorage.removeItem('pbh_toasts'); pending.forEach(function (f) { toast(f.msg, f.type === 'warn' ? 'warn' : (f.type === 'error' ? 'error' : 'success')); }); }
  } catch (e) { /* ignore */ }

  /* ---------------------------------------------------------- modal */
  var dlg = null, body = null, titleEl = null, baseUrl = '', busy = false;
  function ensureDialog() {
    if (dlg) { return dlg; }
    dlg = document.createElement('dialog');
    dlg.className = 'amodal';
    dlg.setAttribute('aria-labelledby', 'am-title');
    dlg.innerHTML = '<div class="am-shell"><header class="am-head"><h2 id="am-title" class="am-title"></h2>' +
      '<button type="button" class="am-close" data-am-close aria-label="Close">\u00d7</button></header><div class="am-body" tabindex="-1"></div></div>';
    document.body.appendChild(dlg);
    body = $('.am-body', dlg); titleEl = $('.am-title', dlg);
    var down = false;
    dlg.addEventListener('mousedown', function (e) { down = e.target === dlg; });
    dlg.addEventListener('click', function (e) { if (down && e.target === dlg) { dlg.close(); } down = false; });
    dlg.addEventListener('close', function () { body.textContent = ''; baseUrl = ''; busy = false; });
    return dlg;
  }
  function loading() {
    body.innerHTML = '<div class="am-loading"><span class="am-spin" aria-hidden="true"></span><span>Loading\u2026</span></div>';
  }
  function setContent(html, title) {
    var holder = document.createElement('div');
    holder.innerHTML = html;
    var frag = $('.mfrag', holder);
    if (!frag) { body.innerHTML = '<div class="flash flash-error"><span>Sorry, this could not be loaded. Please try again.</span></div>'; return; }
    titleEl.textContent = title || frag.getAttribute('data-mtitle') || '';
    body.innerHTML = frag.innerHTML;
    body.scrollTop = 0;
    initForms(body);
    var first = $('input:not([type=hidden]):not([disabled]), select, textarea', body);
    if (first && window.matchMedia('(hover: hover)').matches) { try { first.focus({ preventScroll: true }); } catch (e) { /* ignore */ } }
  }
  function openShell(title) {
    ensureDialog();
    titleEl.textContent = title || '';
    loading();
    if (!dlg.open) { dlg.showModal(); }
  }
  function handleJson(data, fromForm) {
    var flashes = data.flashes || [];
    var errors = flashes.filter(function (f) { return f.type === 'error'; });
    if (errors.length && fromForm) { showInline(errors); return false; }
    if (flashes.length) { queueToasts(flashes); }
    return true;
  }
  function goTo(url) {
    if (MODAL_RE.test(url)) { openUrl(url); return; }
    if (dlg && dlg.open) { dlg.close(); }
    var here = window.location.href.split('#')[0];
    if (url.split('#')[0] === here) { window.location.reload(); } else { window.location.assign(url); }
  }
  function openUrl(url) {
    if (!canDialog) { window.location.assign(url); return; }
    openShell('');
    baseUrl = url;
    fetch(url, { headers: HEAD, credentials: 'same-origin' }).then(function (r) {
      var ct = r.headers.get('content-type') || '';
      if (ct.indexOf('json') !== -1) { return r.json().then(function (d) { if (handleJson(d, false)) { goTo(d.redirect); } }); }
      return r.text().then(function (t) { setContent(t); });
    }).catch(function () {
      body.innerHTML = '<div class="flash flash-error"><span>We could not reach the server. Please check your connection and try again.</span></div>';
    });
  }
  function openClone(title, html, action) {
    if (!canDialog) { return; }
    openShell(title);
    baseUrl = action || window.location.href;
    var holder = document.createElement('div');
    holder.innerHTML = '<div class="mfrag" data-mtitle="">' + html + '</div>';
    setContent(holder.innerHTML, title);
  }
  function showInline(list) {
    var box = $('.am-msg', body);
    if (!box) { box = document.createElement('div'); box.className = 'am-msg flash flash-error'; box.setAttribute('role', 'alert'); body.insertBefore(box, body.firstChild); }
    box.textContent = '';
    list.forEach(function (f) { var p = document.createElement('div'); p.textContent = f.msg; box.appendChild(p); });
    body.scrollTop = 0;
  }
  function submitModal(form, submitter) {
    if (busy) { return; }
    busy = true;
    var btn = submitter || $('button[type=submit]', form);
    if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
    var fd = new FormData(form);
    if (submitter && submitter.name) { fd.append(submitter.name, submitter.value); }
    var action = form.getAttribute('action');
    var url = action ? new URL(action, window.location.href).href : (baseUrl || window.location.href);
    fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: HEAD }).then(function (r) {
      var ct = r.headers.get('content-type') || '';
      if (ct.indexOf('json') !== -1) {
        return r.json().then(function (d) { if (handleJson(d, true)) { goTo(d.redirect); } });
      }
      return r.text().then(function (t) { baseUrl = action ? baseUrl : url; setContent(t, titleEl.textContent); });
    }).catch(function () {
      showInline([{ msg: 'We could not reach the server. Please check your connection and try again.' }]);
    }).then(function () {
      busy = false;
      if (btn && btn.isConnected) { btn.disabled = false; btn.classList.remove('is-loading'); }
    });
  }

  /* ---------------------------------------------------------- confirm box */
  var cdlg = null;
  function confirmBox(message, opts, done) {
    opts = opts || {};
    if (!canDialog) { done(window.confirm(message)); return; }
    if (!cdlg) {
      cdlg = document.createElement('dialog');
      cdlg.className = 'aconfirm';
      cdlg.setAttribute('aria-labelledby', 'ac-title');
      cdlg.innerHTML = '<div class="ac-shell"><h2 id="ac-title" class="ac-title"></h2><p class="ac-msg"></p><div class="ac-actions"><button type="button" class="btn" data-ac="no">Cancel</button><button type="button" class="btn btn-danger" data-ac="yes">Confirm</button></div></div>';
      document.body.appendChild(cdlg);
      cdlg.addEventListener('click', function (e) { if (e.target === cdlg) { cdlg.close('no'); } });
    }
    $('.ac-title', cdlg).textContent = opts.title || 'Please confirm';
    $('.ac-msg', cdlg).textContent = message;
    var yes = $('[data-ac=yes]', cdlg), no = $('[data-ac=no]', cdlg);
    yes.textContent = opts.confirm || 'Yes, continue';
    yes.className = 'btn ' + (opts.danger === false ? 'btn-primary' : 'btn-danger');
    var finish = function (v) { cdlg.removeEventListener('close', onClose); yes.onclick = no.onclick = null; if (cdlg.open) { cdlg.close(v); } done(v === 'yes'); };
    var onClose = function () { yes.onclick = no.onclick = null; cdlg.removeEventListener('close', onClose); done(false); };
    yes.onclick = function () { finish('yes'); };
    no.onclick = function () { finish('no'); };
    cdlg.addEventListener('close', onClose);
    cdlg.showModal();
    no.focus();
  }

  /* ---------------------------------------------------------- events */
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented) { return; }
    var close = e.target.closest('[data-am-close], [data-cancel]');
    if (close && dlg && dlg.contains(close)) { e.preventDefault(); dlg.close(); return; }
    if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
    var a = e.target.closest('a[href]');
    if (a && MODAL_RE.test(a.getAttribute('href')) && !a.hasAttribute('data-nomodal') && canDialog) {
      e.preventDefault();
      closeDropdowns();
      openUrl(a.href);
      return;
    }
    var act = e.target.closest('[data-action-modal]');
    if (act) {
      e.preventDefault();
      var tpl = act.nextElementSibling;
      if (tpl && tpl.tagName === 'DETAILS') {
        var copy = tpl.cloneNode(true);
        var sum = $('summary', copy);
        var title = sum ? sum.textContent.trim() : act.textContent.trim();
        if (sum) { sum.remove(); }
        var f = $('form', copy);
        openClone(title, copy.innerHTML, f ? f.getAttribute('action') : '');
      }
    }
  });

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM' || e.defaultPrevented) { return; }
    var inModal = dlg && dlg.contains(form);
    if (inModal) {
      e.preventDefault();
      submitModal(form, e.submitter || null);
      return;
    }
    var msg = form.getAttribute('data-confirm');
    if (msg && !form.__confirmed) {
      e.preventDefault();
      var danger = form.getAttribute('data-confirm-style') !== 'primary';
      confirmBox(msg, { title: form.getAttribute('data-confirm-title') || 'Please confirm', confirm: form.getAttribute('data-confirm-ok') || 'Yes, continue', danger: danger }, function (ok) {
        if (ok) { form.__confirmed = true; if (e.submitter && e.submitter.name) { var h = document.createElement('input'); h.type = 'hidden'; h.name = e.submitter.name; h.value = e.submitter.value; form.appendChild(h); } form.submit(); }
      });
    }
  }, true);

  /* inline "details" action boxes become pop-ups (void a payment, cancel, approve a GCash report, ...) */
  $$('details.action-box').forEach(function (d) {
    if (!canDialog || d.closest('.amodal')) { return; }
    var sum = $('summary', d);
    if (!sum || !$('form', d)) { return; }
    var b = document.createElement('button');
    b.type = 'button';
    b.className = (sum.className || 'btn btn-sm').replace(/\binline-box\b/, '').trim();
    b.setAttribute('data-action-modal', '');
    b.textContent = sum.textContent.trim();
    d.parentNode.insertBefore(b, d);
    d.hidden = true;
  });

  /* ---------------------------------------------------------- forms inside pop-ups and pages */
  function initForms(root) {
    // reservation form: rent / deposit / advance follow the chosen room and number of beds
    var form = $('#res-form', root);
    if (form) {
      var room = $('select[data-room-select]', form), beds = $('input[data-beds]', form), rate = $('input[data-rate]', form), dep = $('input[data-deposit]', form), adv = $('input[data-advance]', form);
      if (rate && dep && adv) {
        var touched = { rate: rate.value !== '', dep: dep.value !== '', adv: adv.value !== '' };
        rate.addEventListener('input', function () { touched.rate = true; });
        dep.addEventListener('input', function () { touched.dep = true; });
        adv.addEventListener('input', function () { touched.adv = true; });
        var fmt = function (n) { return (Math.round(n * 100) / 100).toFixed(2); };
        var refresh = function () {
          if (!room || room.disabled) { return; }
          var opt = room.options[room.selectedIndex];
          if (!opt || !opt.dataset.rate) { return; }
          var n = Math.max(1, parseInt(beds.value, 10) || 1);
          var cap = parseInt(opt.dataset.capacity, 10) || 1;
          beds.max = cap;
          var r = parseFloat(opt.dataset.rate) * n;
          var d = parseFloat(opt.dataset.deposit) * n;
          var hold = parseFloat(adv.getAttribute('data-hold-fee')) || 0;
          var months = parseInt(adv.getAttribute('data-advance-months'), 10) || 0;
          if (!touched.rate) { rate.value = fmt(r); }
          if (!touched.dep) { dep.value = fmt(d); }
          if (!touched.adv) { adv.value = fmt(hold > 0 ? hold : (touched.rate ? parseFloat(rate.value) || r : r) * months); }
        };
        if (room) { room.addEventListener('change', refresh); }
        if (beds) { beds.addEventListener('input', refresh); }
        rate.addEventListener('input', function () { if (!touched.adv) { refresh(); } });
        if (!rate.value && room && room.value) { refresh(); }
      }
    }
    // payment form: choosing a reservation reloads the form with its balance filled in
    var pick = $('[data-autosubmit-get]', root);
    if (pick) {
      pick.addEventListener('change', function () {
        if (!pick.value) { return; }
        var inM = dlg && dlg.contains(pick);
        var base = inM ? (baseUrl || window.location.pathname).split('?')[0] : window.location.pathname;
        var target = base + '?reservation_id=' + encodeURIComponent(pick.value);
        if (inM) { openUrl(target); } else { window.location.href = target; }
      });
    }
    // live refund calculator in the check-out pop-up: deposit held, minus unpaid rent (optional), minus damages
    $$('[data-refund-calc]', root).forEach(function (box) {
      var held = parseFloat(box.getAttribute('data-held')) || 0, bal = Math.max(0, parseFloat(box.getAttribute('data-balance')) || 0);
      var ded = $('[data-ded]', box), out = $('[data-out]', box), reason = $('[data-reason]', box), apply = $('[data-apply]', box), note = $('.reason-note', box);
      if (!ded || !out) { return; }
      var run = function () {
        var d = Math.max(0, parseFloat(String(ded.value).replace(/,/g, '')) || 0);
        var applied = apply && apply.checked ? Math.min(bal, held) : 0;
        var left = Math.max(0, Math.round((held - applied - d) * 100) / 100);
        out.textContent = '\u20b1' + left.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        out.classList.toggle('is-over', applied + d > held + 0.001);
        if (reason) { reason.required = d > 0; }
        if (note) { note.textContent = d > 0 ? '(required)' : '(needed only if you deduct something)'; }
      };
      ded.addEventListener('input', run);
      if (apply) { apply.addEventListener('change', run); }
      run();
    });
  }
  initForms(document);

  /* ---------------------------------------------------------- dropdown menus */
  function closeDropdowns(except) {
    $$('[data-dd]').forEach(function (d) {
      if (d === except) { return; }
      var m = $('.dd-menu', d), t = $('[data-dd-toggle]', d);
      if (m && !m.hidden) { m.hidden = true; t.setAttribute('aria-expanded', 'false'); }
    });
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-dd-toggle]');
    if (t) {
      var d = t.closest('[data-dd]'), m = $('.dd-menu', d);
      var open = m.hidden;
      closeDropdowns(d);
      m.hidden = !open;
      t.setAttribute('aria-expanded', open ? 'true' : 'false');
      return;
    }
    if (!e.target.closest('[data-dd]')) { closeDropdowns(); }
    var s = e.target.closest('[data-gsearch]');
    if (!s) { hideResults(); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeDropdowns(); hideResults(); } });

  /* ---------------------------------------------------------- sidebar collapse */
  var col = $('[data-side-collapse]');
  if (col) {
    col.addEventListener('click', function () {
      var on = document.documentElement.classList.toggle('side-collapsed');
      try { window.localStorage.setItem('pbh_side', on ? '1' : '0'); } catch (e) { /* ignore */ }
    });
  }

  /* ---------------------------------------------------------- global search */
  var gs = $('[data-gsearch]'), gsInput = gs ? $('input', gs) : null, gsBox = gs ? $('.gs-results', gs) : null;
  var gsCache = {}, gsCtl = null, gsTimer = null, gsActive = -1;
  function hideResults() { if (gsBox && !gsBox.hidden) { gsBox.hidden = true; gsInput.setAttribute('aria-expanded', 'false'); gsActive = -1; } }
  function items() { return $$('a.gs-item', gsBox); }
  function setActive(i) {
    var list = items();
    if (!list.length) { return; }
    gsActive = (i + list.length) % list.length;
    list.forEach(function (a, n) { a.classList.toggle('active', n === gsActive); a.setAttribute('aria-selected', n === gsActive ? 'true' : 'false'); });
    list[gsActive].scrollIntoView({ block: 'nearest' });
  }
  function render(data) {
    gsBox.textContent = '';
    if (!data.groups || !data.groups.length) {
      var none = document.createElement('p'); none.className = 'gs-empty'; none.textContent = 'No matches. Try a name, a phone number or a reservation number.';
      gsBox.appendChild(none);
    } else {
      data.groups.forEach(function (g) {
        var h = document.createElement('p'); h.className = 'gs-group'; h.textContent = g.label; gsBox.appendChild(h);
        g.items.forEach(function (it) {
          var a = document.createElement('a'); a.className = 'gs-item'; a.href = it.url; a.setAttribute('role', 'option');
          var t = document.createElement('strong'); t.textContent = it.title; a.appendChild(t);
          if (it.sub) { var s = document.createElement('span'); s.textContent = it.sub; a.appendChild(s); }
          gsBox.appendChild(a);
        });
      });
    }
    gsBox.hidden = false; gsInput.setAttribute('aria-expanded', 'true'); gsActive = -1;
  }
  function runSearch() {
    var q = gsInput.value.trim();
    if (q.length < 2) { hideResults(); return; }
    if (gsCache[q]) { render(gsCache[q]); return; }
    if (gsCtl) { gsCtl.abort(); }
    gsCtl = window.AbortController ? new AbortController() : null;
    fetch(gs.getAttribute('data-endpoint') + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' }, signal: gsCtl ? gsCtl.signal : undefined })
      .then(function (r) { return r.json(); })
      .then(function (d) { gsCache[q] = d; if (gsInput.value.trim() === q) { render(d); } })
      .catch(function () { /* aborted or offline: stay quiet */ });
  }
  if (gs) {
    gsInput.addEventListener('input', function () { window.clearTimeout(gsTimer); gsTimer = window.setTimeout(runSearch, 250); });
    gsInput.addEventListener('focus', function () { if (gsInput.value.trim().length >= 2 && gsBox.childNodes.length) { gsBox.hidden = false; gsInput.setAttribute('aria-expanded', 'true'); } });
    gsInput.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(gsActive + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(gsActive - 1); }
      else if (e.key === 'Enter') {
        var list = items(), pick = list[gsActive >= 0 ? gsActive : 0];
        if (pick) { e.preventDefault(); pick.click(); }
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName) && !(dlg && dlg.open)) { e.preventDefault(); gsInput.focus(); }
    });
    gsBox.addEventListener('click', function (e) { if (e.target.closest('a.gs-item')) { hideResults(); } });
  }
})();
