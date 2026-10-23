/* Pop-up forms (inquiry, GCash) and the chat assistant.
   Progressive enhancement: every link still works as a normal page without this file. */
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ======================= Pop-up forms ======================= */
  var inq = document.getElementById('inquiry-dialog');
  var gc = document.getElementById('gcash-dialog');
  var trk = document.getElementById('track-dialog');
  var canDialog = typeof HTMLDialogElement === 'function';

  var closeAllDialogs = function () {
    $$('dialog[open]').forEach(function (d) { try { d.close(); } catch (e) { /* ignore */ } });
  };
  var resetViews = function (d) {
    var f = $('[data-view="form"]', d), done = $('[data-view="done"]', d), err = $('[data-errors]', d);
    if (f) { f.hidden = false; }
    if (done) { done.hidden = true; }
    if (err) { err.hidden = true; err.textContent = ''; }
    var panel = $('.pbh-panel', d);
    if (panel) { panel.scrollTop = 0; }
  };
  var openDialog = function (d, keepViews) {
    if (!d || !canDialog) { return false; }
    closeAllDialogs();
    if (window.pbhCloseChat) { window.pbhCloseChat(); }
    if (keepViews) {
      $$('[data-errors]', d).forEach(function (b) { b.hidden = true; b.textContent = ''; });
      var pn = $('.pbh-panel', d); if (pn) { pn.scrollTop = 0; }
    } else { resetViews(d); }
    d.showModal();
    return true;
  };

  var openInquiry = function (opts) {
    opts = opts || {};
    if (!inq) { return false; }
    if (!openDialog(inq)) { return false; }
    var sel = $('select[name="room_id"]', inq), date = $('input[name="move_in"]', inq);
    if (sel) { sel.value = ''; if (opts.room && $('option[value="' + String(opts.room).replace(/\D/g, '') + '"]', sel)) { sel.value = String(opts.room).replace(/\D/g, ''); } }
    if (date && opts.date && /^\d{4}-\d{2}-\d{2}$/.test(opts.date)) { date.value = opts.date; }
    return true;
  };
  var openGcash = function () { return gc ? openDialog(gc) : false; };
  var openTrack = function (opts) {
    opts = opts || {};
    if (!trk) { return false; }
    if (!openDialog(trk, true)) { return false; }
    var ref = $('input[name="ref"]', trk);
    if (ref && opts.ref) { ref.value = opts.ref; var c = $('input[name="contact"]', trk); if (c) { c.focus(); } }
    return true;
  };
  window.pbhOpenInquiry = openInquiry;
  window.pbhOpenGcash = openGcash;
  window.pbhOpenTrack = openTrack;

  [inq, gc, trk].forEach(function (d) {
    if (!d) { return; }
    d.addEventListener('click', function (e) {
      if (e.target === d || e.target.closest('[data-close-dialog]')) { d.close(); }
    });
  });

  /* Turn links to the inquiry page / GCash page into pop-ups */
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
    var a = e.target.closest('a[href]');
    if (!a) { return; }
    var href = a.getAttribute('href') || '';
    if (a.hasAttribute('data-open-gcash') || /(^|\/)gcash_pay\.php(\?|#|$)/.test(href)) {
      if (gc && canDialog) { e.preventDefault(); openGcash(); }
      return;
    }
    if (/(^|\/)track\.php(\?|#|$)/.test(href) && trk && canDialog) {
      e.preventDefault();
      var from = a.closest('dialog'), codeEl = from ? $('[data-code]', from) : null;
      openTrack({ ref: codeEl && /^[A-Z0-9-]{8,12}$/.test(codeEl.textContent.trim()) ? codeEl.textContent.trim() : '' });
      return;
    }
    if (/(^|\/)inquire\.php(\?|#|$)/.test(href) && inq && canDialog) {
      e.preventDefault();
      var u;
      try { u = new URL(a.href, window.location.href); } catch (err) { u = null; }
      openInquiry({ room: u ? u.searchParams.get('room') : '', date: u ? u.searchParams.get('date') : '' });
    }
  });

  /* Copy buttons */
  var copyText = function (text, btn) {
    var done = function () {
      var label = $('span', btn);
      if (!label) { return; }
      var old = label.textContent;
      label.textContent = 'Copied!';
      window.setTimeout(function () { label.textContent = old; }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () { /* ignore */ });
    } else {
      var t = document.createElement('textarea');
      t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
      document.body.appendChild(t); t.select();
      try { document.execCommand('copy'); done(); } catch (err) { /* ignore */ }
      document.body.removeChild(t);
    }
  };
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy], [data-copy-code]');
    if (!b) { return; }
    if (b.hasAttribute('data-copy')) { copyText(b.getAttribute('data-copy'), b); return; }
    var c = $('[data-code]', b.closest('dialog'));
    if (c) { copyText(c.textContent, b); }
  });

  /* Send the forms without leaving the page */
  var errBoxFor = function (el) {
    var view = el.closest('[data-view]') || el.closest('dialog');
    return $('[data-errors]', view);
  };
  var showErrors = function (box, list) {
    box.textContent = '';
    var strong = document.createElement('strong');
    strong.textContent = list.length > 1 ? 'Please fix the following:' : 'Please check this:';
    box.appendChild(strong);
    var ul = document.createElement('ul');
    list.forEach(function (m) { var li = document.createElement('li'); li.textContent = m; ul.appendChild(li); });
    box.appendChild(ul);
    box.hidden = false;
    box.style.animation = 'none'; void box.offsetWidth; box.style.animation = '';
    var panel = box.closest('.pbh-panel');
    if (panel) { panel.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' }); }
  };

  $$('[data-ajax-form]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.fetch || !window.FormData) { return; }          // fall back to the normal page
      e.preventDefault();
      var dlg = form.closest('dialog'), errs = errBoxFor(form), btn = $('[data-submit]', form);
      if (!form.checkValidity()) { form.reportValidity(); return; }
      errs.hidden = true;
      btn.disabled = true; btn.classList.add('is-loading');
      fetch(form.getAttribute('action'), {
        method: 'POST', body: new FormData(form), credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
      }).then(function (r) {
        return r.json().catch(function () { return { ok: false, errors: ['Something went wrong. Please try again.'] }; });
      }).then(function (data) {
        if (data && data.ok && form.getAttribute('data-ajax-form') === 'gcashstatus') {
          var box = $('[data-status-result]', form);
          $('[data-status-title]', box).textContent = data.title;
          $('[data-status-text]', box).textContent = data.text;
          box.className = 'auto-reply auto-' + ({ approved: 'confirmed', pending: 'info', rejected: 'full' }[data.status] || 'info');
          box.hidden = false;
        } else if (data && data.ok && data.html !== undefined && form.getAttribute('data-ajax-form') === 'track') {
          showThread(dlg, data.html);
          form.reset();
        } else if (data && data.ok) {
          var kind = form.getAttribute('data-ajax-form');
          var code = $('[data-code]', dlg), box = $('[data-code-box]', dlg), amt = $('[data-amount]', dlg);
          var value = data.ref !== undefined ? data.ref : data.code;
          if (code) { code.textContent = value || ''; }
          if (box) { box.hidden = !value; }
          if (amt) { amt.textContent = data.amount || ''; }
          var auto = $('[data-auto]', dlg);
          if (auto) {
            if (data.auto) {
              $('[data-auto-title]', auto).textContent = data.auto.title;
              var at = $('[data-auto-text]', auto); at.textContent = '';
              String(data.auto.text).split(/\n\n+/).forEach(function (para) {
                var p = document.createElement('p');
                para.split('\n').forEach(function (line, i) { if (i) { p.appendChild(document.createElement('br')); } p.appendChild(document.createTextNode(line)); });
                at.appendChild(p);
              });
              auto.className = 'auto-reply auto-' + data.auto.result;
              auto.hidden = false;
              var ic = $('[data-auto-icon]', dlg); if (ic) { ic.className = 'pbh-check pbh-check-' + data.auto.result; }
            } else { auto.hidden = true; }
          }
          form.reset();
          $('[data-view="form"]', dlg).hidden = true;
          $('[data-view="done"]', dlg).hidden = false;
          var panel = $('.pbh-panel', dlg); if (panel) { panel.scrollTop = 0; }
          if (kind === 'gcash') { /* proof input is cleared by reset() */ }
        } else {
          showErrors(errs, (data && data.errors && data.errors.length) ? data.errors : ['Something went wrong. Please try again.']);
        }
      }).catch(function () {
        showErrors(errs, ['We could not reach the server. Please check your connection and try again.']);
      }).then(function () {
        btn.disabled = false; btn.classList.remove('is-loading');
      });
    });
  });

  /* Check my inquiry: show the conversation, send follow-ups, close the view */
  var showThread = function (dlg, html, notice) {
    var holder = $('[data-thread]', dlg);
    holder.innerHTML = html;
    if (notice) {
      var n = document.createElement('div');
      n.className = 'flash flash-success'; n.setAttribute('role', 'status');
      var sp = document.createElement('span'); sp.textContent = notice; n.appendChild(sp);
      holder.insertBefore(n, holder.firstChild);
    }
    $('[data-view="form"]', dlg).hidden = true;
    $('[data-view="thread"]', dlg).hidden = false;
    var panel = $('.pbh-panel', dlg);
    var last = holder.querySelector('.thread .msg:last-child');
    if (panel) { panel.scrollTop = 0; }
    if (notice && last && last.scrollIntoView) { last.scrollIntoView({ block: 'nearest', behavior: reduced ? 'auto' : 'smooth' }); }
  };
  var showLookup = function (dlg, message) {
    $('[data-thread]', dlg).textContent = '';
    $('[data-view="thread"]', dlg).hidden = true;
    $('[data-view="form"]', dlg).hidden = false;
    var box = $('[data-view="form"] [data-errors]', dlg);
    if (message) { showErrors(box, [message]); } else { box.hidden = true; }
    var panel = $('.pbh-panel', dlg); if (panel) { panel.scrollTop = 0; }
  };
  if (trk) {
    trk.addEventListener('submit', function (e) {
      var form = e.target.closest('[data-track-form]');
      if (!form || !window.fetch || !window.FormData) { return; }
      e.preventDefault();
      var btn = $('[data-submit]', form), errs = errBoxFor(form);
      if (!form.checkValidity()) { form.reportValidity(); return; }
      errs.hidden = true;
      if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
      fetch(form.getAttribute('action'), {
        method: 'POST', body: new FormData(form), credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
      }).then(function (r) {
        return r.json().catch(function () { return { ok: false, errors: ['Something went wrong. Please try again.'] }; });
      }).then(function (data) {
        if (data && data.ok && data.left) { showLookup(trk); }
        else if (data && data.ok && data.html) { showThread(trk, data.html, data.notice); }
        else if (data && data.expired) { showLookup(trk, (data.errors || [])[0]); }
        else { showErrors(errs, (data && data.errors && data.errors.length) ? data.errors : ['Something went wrong. Please try again.']); }
      }).catch(function () {
        showErrors(errs, ['We could not reach the server. Please check your connection and try again.']);
      }).then(function () {
        if (btn && btn.isConnected) { btn.disabled = false; btn.classList.remove('is-loading'); }
      });
    });
  }

  /* ======================= Chat assistant ======================= */
  var chat = $('[data-chat]');
  if (!chat) { return; }
  var endpoint = chat.getAttribute('data-endpoint'), token = chat.getAttribute('data-csrf');
  var panel = $('.chat-panel', chat), log = $('[data-chat-log]', chat), chipsBox = $('[data-chat-chips]', chat);
  var form = $('[data-chat-form]', chat), input = $('input', form), launch = $('[data-chat-open]', chat);
  var KEY = 'pbh_chat_v1', history = [], busy = false, teaser = null;
  var DEFAULT_CHIPS = ['Available rooms', 'Rates', 'Requirements', 'How to pay', 'House rules', 'Location', 'Contact the landlord'];

  var store = {
    get: function () { try { return JSON.parse(window.sessionStorage.getItem(KEY) || 'null'); } catch (e) { return null; } },
    set: function (v) { try { window.sessionStorage.setItem(KEY, JSON.stringify(v)); } catch (e) { /* private mode */ } }
  };

  /* Safe formatting: **bold**, [label](/path) and plain links. Everything else is text. */
  var TOKEN = /\*\*(.+?)\*\*|\[([^\]]+)\]\(([^)\s]+)\)|(https?:\/\/[^\s)]+)/g;
  var safeHref = function (h) { return /^(\/|https?:\/\/)/.test(h) ? h : null; };
  var fmtInline = function (text, into) {
    var last = 0, m;
    TOKEN.lastIndex = 0;
    while ((m = TOKEN.exec(text))) {
      if (m.index > last) { into.appendChild(document.createTextNode(text.slice(last, m.index))); }
      if (m[1] !== undefined) {
        var s = document.createElement('strong'); s.textContent = m[1]; into.appendChild(s);
      } else {
        var href = safeHref(m[3] || m[4] || '');
        if (href) {
          var a = document.createElement('a'); a.href = href; a.textContent = m[2] || m[4];
          if (/^https?:/.test(href)) { a.target = '_blank'; a.rel = 'noopener noreferrer'; }
          into.appendChild(a);
        } else { into.appendChild(document.createTextNode(m[0])); }
      }
      last = m.index + m[0].length;
    }
    if (last < text.length) { into.appendChild(document.createTextNode(text.slice(last))); }
  };
  var fmt = function (text, into) {
    String(text).split('\n').forEach(function (line) {
      var d = document.createElement('span');
      d.className = 'cl' + (line.trim() === '' ? ' gap' : '');
      fmtInline(line, d);
      into.appendChild(d);
    });
  };

  var scrollDown = function () { log.scrollTop = log.scrollHeight; };

  var runAction = function (a) {
    if (a.type === 'inquiry') { closeChat(); if (!openInquiry({ room: a.room })) { window.location.href = (document.querySelector('a[href*="inquire.php"]') || {}).href || 'inquire.php'; } }
    else if (a.type === 'gcash') { closeChat(); if (!openGcash()) { window.location.href = 'gcash_pay.php'; } }
  };

  var addMsg = function (who, text, actions, silent) {
    var el = document.createElement('div');
    el.className = 'chat-msg ' + who;
    if (silent) { el.style.animation = 'none'; }
    fmt(text, el);
    if (actions && actions.length) {
      var box = document.createElement('div'); box.className = 'chat-actions';
      actions.forEach(function (a, i) {
        var b;
        if (a.type === 'link' && safeHref(a.href || '')) {
          b = document.createElement('a'); b.href = a.href;
        } else {
          b = document.createElement('button'); b.type = 'button';
          b.addEventListener('click', function () { runAction(a); });
        }
        b.className = 'chat-act' + (i > 0 ? ' alt' : '');
        b.textContent = a.label;
        box.appendChild(b);
      });
      el.appendChild(box);
    }
    log.appendChild(el);
    scrollDown();
    return el;
  };

  var setChips = function (list) {
    chipsBox.textContent = '';
    (list && list.length ? list : DEFAULT_CHIPS).forEach(function (c) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'chat-chip'; b.textContent = c;
      b.addEventListener('click', function () { send(c); });
      chipsBox.appendChild(b);
    });
    chipsBox.scrollLeft = 0;
  };

  var save = function () { store.set(history.slice(-40)); };

  var botSay = function (data) {
    history.push({ w: 'bot', t: data.text, a: data.actions || [], c: data.chips || [] });
    save();
    addMsg('bot', data.text, data.actions);
    setChips(data.chips);
  };

  var welcome = function () {
    botSay({
      text: 'Hi! 👋 I\'m the assistant of **' + (chat.getAttribute('data-site') || 'the boarding house') + '**. Ask me about rooms, rates, requirements, house rules, payments (including GCash) or how to reach the landlord.',
      actions: [], chips: DEFAULT_CHIPS
    });
  };

  var send = function (text) {
    text = String(text || '').trim();
    if (!text || busy) { return; }
    busy = true;
    addMsg('me', text);
    history.push({ w: 'me', t: text }); save();
    input.value = '';
    chipsBox.textContent = '';
    var typing = document.createElement('div');
    typing.className = 'chat-typing'; typing.setAttribute('aria-label', 'Assistant is typing');
    typing.innerHTML = '<i></i><i></i><i></i>';
    log.appendChild(typing); scrollDown();
    var started = Date.now();
    var fd = new FormData();
    fd.append('q', text); fd.append('_csrf', token);
    fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, text: 'Sorry, I could not connect right now. Please try again, or send the landlord an inquiry.', actions: [{ type: 'inquiry', label: 'Send an inquiry' }], chips: DEFAULT_CHIPS }; })
      .then(function (data) {
        var wait = reduced ? 0 : Math.max(0, 550 - (Date.now() - started));
        window.setTimeout(function () {
          if (typing.parentNode) { typing.parentNode.removeChild(typing); }
          botSay(data);
          busy = false;
        }, wait);
      });
  };

  var restore = function () {
    var saved = store.get();
    if (saved && saved.length) {
      history = saved;
      saved.forEach(function (m) { addMsg(m.w === 'me' ? 'me' : 'bot', m.t, m.a, true); });
      var last = null;
      for (var i = saved.length - 1; i >= 0; i--) { if (saved[i].w === 'bot') { last = saved[i]; break; } }
      setChips(last ? last.c : DEFAULT_CHIPS);
    } else { welcome(); }
  };

  var openChat = function () {
    if (!panel.hidden) { return; }
    if (teaser) { teaser.remove(); teaser = null; }
    panel.hidden = false;
    chat.classList.add('is-open', 'is-seen');
    launch.setAttribute('aria-expanded', 'true');
    store.set(history.length ? history : store.get() || []);
    if (!log.childNodes.length) { restore(); }
    scrollDown();
    if (window.matchMedia('(hover: hover)').matches) { window.setTimeout(function () { input.focus(); }, 60); }
    try { window.sessionStorage.setItem('pbh_chat_seen', '1'); } catch (e) { /* ignore */ }
  };
  function closeChat() {
    if (panel.hidden) { return; }
    panel.hidden = true;
    chat.classList.remove('is-open');
    launch.setAttribute('aria-expanded', 'false');
  }

  window.pbhCloseChat = closeChat;
  launch.addEventListener('click', openChat);
  $('[data-chat-close]', chat).addEventListener('click', function () { closeChat(); launch.focus(); });
  $('[data-chat-reset]', chat).addEventListener('click', function () {
    history = []; store.set([]); log.textContent = ''; chipsBox.textContent = ''; busy = false; welcome();
  });
  form.addEventListener('submit', function (e) { e.preventDefault(); send(input.value); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden && !document.querySelector('dialog[open]')) { closeChat(); launch.focus(); }
  });

  /* A friendly nudge once per visit, never after the chat was opened */
  var seen = false;
  try { seen = window.sessionStorage.getItem('pbh_chat_seen') === '1'; } catch (e) { /* ignore */ }
  if (seen) { chat.classList.add('is-seen'); }
  else if (!reduced) {
    window.setTimeout(function () {
      if (!panel.hidden || document.querySelector('dialog[open]')) { return; }
      teaser = document.createElement('div');
      teaser.className = 'chat-teaser';
      teaser.textContent = 'Hi! Need help finding a room? Ask me anything. ';
      var x = document.createElement('button');
      x.type = 'button'; x.setAttribute('aria-label', 'Dismiss'); x.textContent = '×';
      x.addEventListener('click', function (e) { e.stopPropagation(); teaser.remove(); teaser = null; try { window.sessionStorage.setItem('pbh_chat_seen', '1'); } catch (er) { /* ignore */ } });
      teaser.appendChild(x);
      teaser.addEventListener('click', openChat);
      chat.appendChild(teaser);
      window.setTimeout(function () { if (teaser) { teaser.remove(); teaser = null; } }, 9000);
    }, 7000);
  }
})();
