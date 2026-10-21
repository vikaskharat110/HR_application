/*
 * WorkNest — shared browser behaviour (vanilla JS, no framework).
 * Replaces the small React client components: BackLink, TabbedPanels,
 * ConfirmSubmitButton, AutoSubmitSelect, UserMenu/NotificationBell
 * dropdowns, MobileMenu and the "pending" button states. Everything is
 * progressive enhancement: forms and links still work without JavaScript.
 */
(function () {
  'use strict';

  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var iconsHref = (function () {
    var use = document.querySelector('svg.lucide use');
    return use ? use.getAttribute('href').split('#')[0] : '/assets/icons/lucide.svg';
  })();

  /** Inline SVG icon from the sprite (same markup as PHP icon()). */
  function svgIcon(name, size, cls) {
    size = size || 16;
    return '<svg class="lucide ' + (cls || '') + '" width="' + size + '" height="' + size + '" aria-hidden="true"><use href="' + iconsHref + '#' + name + '"></use></svg>';
  }
  window.WorkNest = { csrf: csrf, svgIcon: svgIcon, postJSON: postJSON };

  /** POST JSON with the CSRF header; resolves to the parsed JSON body. */
  function postJSON(url, data) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(data || {})
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (body) {
        if (!r.ok) { throw new Error(body.error || 'Request failed'); }
        return body;
      });
    });
  }

  function on(type, selector, handler) {
    document.addEventListener(type, function (e) {
      var el = e.target.closest ? e.target.closest(selector) : null;
      if (el) { handler(e, el); }
    });
  }

  // ── BackLink: real browser history, falling back to the link's href ──
  on('click', '[data-back-link]', function (e) {
    if (window.history.length > 1 && document.referrer && new URL(document.referrer).origin === location.origin) {
      e.preventDefault();
      window.history.back();
    }
  });

  // ── ConfirmSubmitButton ──
  on('click', '[data-confirm]', function (e, el) {
    if (!window.confirm(el.getAttribute('data-confirm'))) {
      e.preventDefault();
      e.stopImmediatePropagation();
    }
  });

  // ── AutoSubmitSelect ──
  on('change', 'select[data-autosubmit]', function (e, el) {
    if (el.form) { el.form.requestSubmit ? el.form.requestSubmit() : el.form.submit(); }
  });

  // ── Leave form: unpaid leave disables the leave-type select ──
  function syncLeaveKind(form) {
    var sel = form.querySelector('select[data-leave-type]');
    var unpaid = form.querySelector('input[name=leaveKind][value=UNPAID]');
    if (sel && unpaid) { sel.disabled = unpaid.checked; sel.required = !unpaid.checked; }
  }
  on('change', 'input[name=leaveKind]', function (e, el) { if (el.form) { syncLeaveKind(el.form); } });
  document.querySelectorAll('form').forEach(function (f) { if (f.querySelector('input[name=leaveKind]')) { syncLeaveKind(f); } });

  // ── TabbedPanels (active tab remembered in the URL hash) ──
  /** Elements matching selector that belong to this tab group (not to a nested one). */
  function own(container, selector) {
    return Array.prototype.filter.call(container.querySelectorAll(selector), function (el) {
      return el.closest('[data-tabs]') === container;
    });
  }
  function activateTab(container, key) {
    var found = false;
    own(container, '[data-tab]').forEach(function (btn) {
      var active = btn.getAttribute('data-tab') === key;
      if (active) { found = true; }
      btn.classList.toggle('bg-brand', active);
      btn.classList.toggle('text-white', active);
      btn.classList.toggle('text-ink-soft', !active);
      btn.classList.toggle('hover:text-ink', !active);
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    if (!found) { return false; }
    own(container, '[data-tab-panel]').forEach(function (p) {
      p.hidden = p.getAttribute('data-tab-panel') !== key;
    });
    return true;
  }
  // Restore the tab named in the URL hash — including nested tabs: every
  // enclosing tab panel is activated and enclosing <details> opened too.
  (function () {
    var key = location.hash.replace(/^#tab-/, '');
    if (!key || location.hash.indexOf('#tab-') !== 0) { return; }
    var btn = document.querySelector('[data-tab="' + key.replace(/"/g, '') + '"]');
    if (!btn) { return; }
    var el = btn;
    while (el) {
      var group = el.closest('[data-tabs]');
      if (!group) { break; }
      var panel = el.closest('[data-tab-panel]');
      activateTab(group, el === btn ? key : panel.getAttribute('data-tab-panel'));
      var details = group.closest('details');
      if (details) { details.open = true; }
      el = group.closest('[data-tab-panel]');
    }
  })();
  on('click', '[data-tab]', function (e, btn) {
    var container = btn.closest('[data-tabs]');
    var key = btn.getAttribute('data-tab');
    if (activateTab(container, key) && history.replaceState) {
      history.replaceState(null, '', '#tab-' + key);
    }
  });

  // ── Dropdowns (user menu, notification bell) ──
  function closeDropdowns(except) {
    document.querySelectorAll('[data-dropdown] > [data-dropdown-menu]').forEach(function (m) {
      if (m !== except) { m.hidden = true; }
    });
  }
  on('click', '[data-dropdown-toggle]', function (e, btn) {
    var menu = btn.parentElement.querySelector('[data-dropdown-menu]');
    closeDropdowns(menu);
    menu.hidden = !menu.hidden;
  });
  document.addEventListener('mousedown', function (e) {
    if (!e.target.closest('[data-dropdown]')) { closeDropdowns(null); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeDropdowns(null); setMobile(false); }
  });

  // ── MobileMenu ──
  function setMobile(open) {
    var m = document.getElementById('mobile-menu');
    if (m) { m.hidden = !open; }
  }
  on('click', '[data-mobile-open]', function () { setMobile(true); });
  on('click', '[data-mobile-close]', function () { setMobile(false); });
  on('click', '#mobile-menu nav a', function () { setMobile(false); });

  // ── Notifications: mark read on open, "mark all read" without reload ──
  on('click', '[data-notification-id]', function (e, el) {
    closeDropdowns(null);
    if (el.getAttribute('data-read') === '1') { return; }
    el.setAttribute('data-read', '1');
    var title = el.querySelector('p');
    if (title) { title.classList.remove('font-semibold'); }
    updateBadge(-1);
    var holder = el.closest('[data-read-url]');
    if (holder) {
      postJSON(holder.getAttribute('data-read-url'), { id: el.getAttribute('data-notification-id') }).catch(function () {});
    }
  });
  on('submit', '[data-mark-all-read]', function (e, form) {
    e.preventDefault();
    postJSON(form.getAttribute('action'), {}).then(function () {
      document.querySelectorAll('[data-notification-id]').forEach(function (n) {
        n.setAttribute('data-read', '1');
        var t = n.querySelector('p'); if (t) { t.classList.remove('font-semibold'); }
      });
      updateBadge(-9999);
      form.remove();
    }).catch(function () { form.submit(); });
  });
  function updateBadge(delta) {
    var badge = document.querySelector('[data-unread-badge]');
    if (!badge) { return; }
    var current = badge.textContent === '9+' ? 10 : parseInt(badge.textContent, 10) || 0;
    var next = Math.max(0, current + delta);
    if (next === 0) { badge.remove(); } else { badge.textContent = next > 9 ? '9+' : String(next); }
  }

  // ── Branch geofence: capture the admin's current location (BranchLocationForm) ──
  on('click', '[data-geo-capture]', function (e, btn) {
    var form = btn.closest('[data-geo-form]');
    var errBox = form.querySelector('[data-geo-error]');
    var status = form.querySelector('[data-geo-status]');
    var label = btn.innerHTML;
    errBox.hidden = true;
    if (!('geolocation' in navigator)) {
      errBox.querySelector('span').textContent = "Your browser doesn't support location.";
      errBox.hidden = false;
      return;
    }
    btn.disabled = true;
    btn.innerHTML = svgIcon('loader-circle', 14, 'animate-spin') + ' Getting location…';
    navigator.geolocation.getCurrentPosition(function (pos) {
      btn.disabled = false;
      btn.innerHTML = label;
      var lat = pos.coords.latitude, lng = pos.coords.longitude, acc = Math.round(pos.coords.accuracy);
      form.querySelector('[data-geo-lat]').value = lat;
      form.querySelector('[data-geo-lng]').value = lng;
      status.querySelector('[data-geo-text]').textContent = 'Captured: ' + lat.toFixed(5) + ', ' + lng.toFixed(5) + (acc > 0 ? ' (±' + acc + 'm accuracy)' : '');
      status.hidden = false;
      form.querySelector('[data-geo-submit]').disabled = false;
    }, function () {
      btn.disabled = false;
      btn.innerHTML = label;
      errBox.querySelector('span').textContent = "Couldn't get your location — make sure location access is allowed for this site, then try again.";
      errBox.hidden = false;
    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
  });

  // ── Salary structure: enter monthly or yearly figures (SalaryStructureForm) ──
  on('change', 'select[data-pay-period]', function (e, sel) {
    var form = sel.closest('[data-salary-form]');
    var factor = sel.value === 'YEARLY' ? 12 : 1;
    form.querySelectorAll('input[data-monthly]').forEach(function (input) {
      var base = input.getAttribute('data-monthly');
      input.value = base === '' ? '' : String(Number(base) * factor);
    });
    form.querySelectorAll('[data-period-label]').forEach(function (l) {
      l.textContent = factor === 12 ? '(per year)' : '(per month)';
    });
  });

  // ── Document upload: "Applies to" toggle + department filter (UploadDocumentForm) ──
  function syncScope(form) {
    var employee = form.querySelector('input[name="scope"][value="employee"]');
    var box = form.querySelector('[data-scope-employee]');
    if (!employee || !box) { return; }
    box.hidden = !employee.checked;
    form.querySelector('[data-employee-select]').required = employee.checked;
  }
  document.querySelectorAll('[data-scope-form]').forEach(syncScope);
  on('change', '[data-scope-form] input[name="scope"]', function (e, input) { syncScope(input.form); });
  on('change', 'select[data-dept-filter]', function (e, sel) {
    var target = sel.form.querySelector('[data-employee-select]');
    Array.prototype.forEach.call(target.options, function (o) {
      if (!o.value) { return; }
      o.hidden = !!sel.value && o.getAttribute('data-dept') !== sel.value;
    });
    if (target.selectedOptions[0] && target.selectedOptions[0].hidden) { target.value = ''; }
  });
  // Client-side size check before a long upload (the server re-validates everything).
  on('submit', 'form', function (e, form) {
    var input = form.querySelector('input[type="file"][data-max-bytes]');
    if (!input || !input.files || !input.files[0]) { return; }
    if (input.files[0].size > Number(input.getAttribute('data-max-bytes'))) {
      e.preventDefault();
      e.stopImmediatePropagation();
      window.alert('That file is larger than 10MB — pick a smaller one.');
    }
  });

  // ── Pending state + double-submit protection for every POST form ──
  on('submit', 'form', function (e, form) {
    if ((form.getAttribute('method') || '').toLowerCase() !== 'post' || e.defaultPrevented) { return; }
    if (form.dataset.submitting === '1') { e.preventDefault(); return; }
    form.dataset.submitting = '1';
    var btn = form.querySelector('[data-pending-label]');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = svgIcon('loader-circle', 16, 'animate-spin') + ' ' + btn.getAttribute('data-pending-label');
    }
    // Re-enable if the browser restores the page from the back/forward cache.
    window.addEventListener('pageshow', function () { form.dataset.submitting = ''; }, { once: true });
  });

  // ── Show / hide password (eye button) on every password field ──
  // Self-contained (inline icon + inline styles) so it needs no CSS/sprite changes.
  var EYE = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/>'
    + '<path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></svg>';
  document.querySelectorAll('input[type="password"]').forEach(function (input) {
    var wrap = document.createElement('div');
    wrap.style.position = 'relative';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    input.style.paddingRight = '2.75rem';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.style.cssText = 'position:absolute;top:0;bottom:0;right:0;width:2.5rem;display:flex;align-items:center;justify-content:center;'
      + 'background:transparent;border:0;cursor:pointer;color:var(--color-muted);';
    btn.addEventListener('mouseenter', function () { btn.style.color = 'var(--color-brand)'; });
    btn.addEventListener('mouseleave', function () { btn.style.color = 'var(--color-muted)'; });
    function render(shown) {
      btn.innerHTML = shown ? EYE_OFF : EYE;
      btn.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
      btn.setAttribute('aria-pressed', shown ? 'true' : 'false');
      btn.title = shown ? 'Hide password' : 'Show password';
    }
    render(false);
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      render(show);
      input.focus();
    });
    wrap.appendChild(btn);
    // Always submit as a masked field (keeps password managers happy).
    if (input.form) {
      input.form.addEventListener('submit', function () { input.type = 'password'; render(false); });
    }
  });
})();
