/*
 * WorkNest — calendar day details. Hovering (or keyboard-focusing) a day shows
 * a small tooltip; clicking opens the full popup. Everything is read from the
 * cell's data-day JSON and written with textContent only.
 */
(function () {
  'use strict';
  var dialog = document.getElementById('day-dialog');
  if (!dialog) { return; }

  var BADGE = { present: 'badge-success', late: 'badge-warning', absent: 'badge-danger', leave: 'badge-info',
    holiday: 'badge-info', off: 'badge-neutral', pending: 'badge-warning', none: 'badge-neutral' };
  function f(name) { return dialog.querySelector('[data-f="' + name + '"]'); }
  function read(cell) {
    try { return JSON.parse(cell.getAttribute('data-day')); } catch (err) { return null; }
  }
  /** [label, value] pairs for a day, in display order (empty values skipped). */
  function lines(d) {
    return [['Check-in', d.checkIn], ['Check-out', d.checkOut], ['Hours worked', d.worked], ['Holiday', d.holiday],
      ['Leave', d.leave], ['Birthday', d.birthdays], ['Work anniversary', d.anniversaries]].filter(function (p) { return !!p[1]; });
  }

  // ── Hover tooltip ──
  var tip = document.createElement('div');
  tip.setAttribute('role', 'tooltip');
  tip.hidden = true;
  tip.style.cssText = 'position:fixed;z-index:60;max-width:17rem;pointer-events:none;background:#0f172a;color:#fff;'
    + 'border-radius:12px;padding:10px 12px;font-size:12px;line-height:1.45;box-shadow:0 12px 28px -8px rgba(15,23,42,.45);';
  document.body.appendChild(tip);

  function showTip(cell) {
    var d = read(cell);
    if (!d) { return; }
    tip.textContent = '';
    var head = document.createElement('div');
    head.style.cssText = 'font-weight:700;font-size:12.5px;';
    head.textContent = d.date;
    tip.appendChild(head);
    if (d.status) {
      var st = document.createElement('div');
      st.style.cssText = 'font-weight:700;margin-top:2px;color:' + ({ present: '#6ee7b7', late: '#fcd34d', absent: '#fda4af',
        leave: '#7dd3fc', holiday: '#c4b5fd', off: '#cbd5e1', pending: '#a5b4fc' }[d.tone] || '#e2e8f0') + ';';
      st.textContent = d.status;
      tip.appendChild(st);
    }
    lines(d).forEach(function (p) {
      var row = document.createElement('div');
      row.style.cssText = 'margin-top:3px;color:#e2e8f0;';
      var label = document.createElement('span');
      label.style.cssText = 'color:#94a3b8;';
      label.textContent = p[0] + ': ';
      row.appendChild(label);
      row.appendChild(document.createTextNode(p[1]));
      tip.appendChild(row);
    });
    tip.hidden = false;
    var r = cell.getBoundingClientRect();
    var t = tip.getBoundingClientRect();
    var left = Math.min(Math.max(8, r.left + r.width / 2 - t.width / 2), window.innerWidth - t.width - 8);
    var top = r.top - t.height - 8;
    if (top < 8) { top = r.bottom + 8; }
    tip.style.left = left + 'px';
    tip.style.top = top + 'px';
  }
  function hideTip() { tip.hidden = true; }

  var canHover = !window.matchMedia || window.matchMedia('(hover: hover)').matches;
  document.addEventListener('mouseover', function (e) {
    if (!canHover) { return; }
    var cell = e.target.closest ? e.target.closest('[data-day]') : null;
    if (cell) { showTip(cell); } else { hideTip(); }
  });
  document.addEventListener('focusin', function (e) {
    var cell = e.target.closest ? e.target.closest('[data-day]') : null;
    if (cell && canHover) { showTip(cell); }
  });
  document.addEventListener('focusout', hideTip);
  window.addEventListener('scroll', hideTip, true);

  // ── Click: full popup ──
  if (typeof dialog.showModal !== 'function') { return; }

  function row(dl, label, value) {
    var wrap = document.createElement('div');
    var dt = document.createElement('dt');
    dt.className = 'text-[11px] font-bold uppercase tracking-wider text-muted';
    dt.textContent = label;
    var dd = document.createElement('dd');
    dd.className = 'mt-0.5 font-semibold text-ink';
    dd.textContent = value;
    wrap.appendChild(dt);
    wrap.appendChild(dd);
    dl.appendChild(wrap);
  }

  document.addEventListener('click', function (e) {
    var cell = e.target.closest('[data-day]');
    if (!cell) { return; }
    var d = read(cell);
    if (!d) { return; }
    hideTip();

    f('date').textContent = d.date;
    var badge = f('status');
    badge.className = 'badge mt-2 ' + (BADGE[d.tone] || 'badge-neutral');
    badge.textContent = d.status || '—';
    badge.hidden = !d.status;

    var dl = f('rows');
    dl.textContent = '';
    lines(d).forEach(function (p) { row(dl, p[0], p[1]); });

    var map = f('map');
    if (d.mapUrl && d.mapUrl.indexOf('https://www.google.com/maps?q=') === 0) {
      map.href = d.mapUrl;
      map.querySelector('span').textContent = d.location || 'View on map';
      map.hidden = false;
    } else {
      map.hidden = true;
    }

    var reg = f('regularize');
    if (reg) {
      var iso = cell.getAttribute('data-date');
      var show = d.tone === 'absent' || (d.checkOut === 'Not recorded');
      reg.href = reg.getAttribute('data-base') + '?date=' + encodeURIComponent(iso || '');
      reg.hidden = !show;
    }

    var add = dialog.querySelector('[data-holiday-add]');
    var remove = dialog.querySelector('[data-holiday-remove]');
    if (add && remove) {
      add.querySelector('input[name="date"]').value = cell.getAttribute('data-date') || '';
      add.querySelector('input[name="name"]').value = '';
      remove.querySelector('input[name="holidayId"]').value = d.holidayId || '';
      f('holidayName').textContent = d.holiday || '';
      add.hidden = !!d.holidayId;
      remove.hidden = !d.holidayId;
    }
    dialog.showModal();
  });

  dialog.addEventListener('click', function (e) {
    if (e.target === dialog || e.target.closest('[data-dialog-close]')) { dialog.close(); }
  });

  // Deep link: …/calendar#day=YYYY-MM-DD opens that day's popup; #hover=… shows its tooltip.
  var m = /^#(day|hover)=(\d{4}-\d{2}-\d{2})$/.exec(location.hash);
  var target = m && document.querySelector('[data-date="' + m[2] + '"]');
  if (target) { m[1] === 'day' ? target.click() : showTip(target); }
})();
