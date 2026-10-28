/* Runs before the admin page is drawn, so a collapsed sidebar does not flicker open. */
try { if (window.localStorage.getItem('pbh_side') === '1') { document.documentElement.classList.add('side-collapsed'); } } catch (e) { /* ignore */ }
