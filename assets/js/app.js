/* Small, dependency-free helpers. Everything works without JavaScript
   except the convenience features listed here. */
(function () {
  'use strict';

  // Admin sidebar toggle (the public navbar is handled in site.js)
  var side = document.querySelector('[data-side-toggle]');
  if (side) {
    side.addEventListener('click', function () {
      var bar = document.getElementById('sidebar');
      var open = bar.classList.toggle('open');
      side.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function (e) {
      var bar = document.getElementById('sidebar');
      if (bar.classList.contains('open') && !bar.contains(e.target) && e.target !== side) {
        bar.classList.remove('open');
        side.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // Dismiss flash messages
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-dismiss]');
    if (b && b.parentNode) { b.parentNode.remove(); }
  });

  // Print buttons
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-print]')) { window.print(); }
  });

})();

/* Landlord login pop-up (landing page only) */
(function () {
  'use strict';
  var dlg = document.getElementById('login-dialog');
  if (!dlg || typeof dlg.showModal !== 'function') { return; }

  var open = function () {
    if (!dlg.open) { dlg.showModal(); }
    var u = document.getElementById('login-user');
    var p = document.getElementById('login-pass');
    var target = (u && u.value && p) ? p : u;   // after a failed attempt, go straight to the password
    if (target) { target.focus(); }
  };
  var tidyUrl = function () {
    if (window.history && window.history.replaceState && /[?&]login=1/.test(window.location.search)) {
      window.history.replaceState({}, '', window.location.pathname);
    }
  };

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-open-login]')) { e.preventDefault(); open(); }
    if (e.target.closest('[data-close-login]')) { dlg.close(); }
    var t = e.target.closest('[data-toggle-pass]');
    if (t) {
      var inp = document.getElementById('login-pass');
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      t.setAttribute('aria-pressed', show ? 'true' : 'false');
      t.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    }
  });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } });   // click on the dimmed backdrop
  dlg.addEventListener('close', function () {
    var inp = document.getElementById('login-pass');
    if (inp) { inp.value = ''; inp.type = 'password'; }
    tidyUrl();
  });
  if (dlg.hasAttribute('data-autoopen')) { open(); }
})();

/* Copy a prepared message (admin > Payment reminders, reservation page) */
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-copy-text]');
  if (!b) { return; }
  var text = b.getAttribute('data-copy-text');
  var isChat = b.matches('a[href*="m.me"]');
  var done = function () {
    var label = b.querySelector('span');
    if (label && !isChat) { var old = label.textContent; label.textContent = 'Copied!'; window.setTimeout(function () { label.textContent = old; }, 1600); }
    if (window.pbhToast) { window.pbhToast(isChat ? 'Message copied. In the Messenger chat, paste it and press send.' : 'Message copied.', 'success'); }
  };
  var fail = function () {
    if (window.pbhToast) { window.pbhToast('The message could not be copied automatically. Press "Copy text" or open "Read the message" and copy it by hand.', 'warn'); }
  };
  var fallback = function () {
    try {
      var t = document.createElement('textarea');
      t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
      document.body.appendChild(t); t.select();
      var ok = document.execCommand('copy');
      document.body.removeChild(t);
      if (ok) { done(); } else { fail(); }
    } catch (err) { fail(); }
  };
  if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, fallback); } else { fallback(); }
});
