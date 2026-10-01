/*
 * Draws [data-chart] canvases with the locally hosted Chart.js — styled to
 * match the original Recharts TrendLine / RankBar components.
 */
(function () {
  'use strict';
  if (!window.Chart) { return; }
  var css = getComputedStyle(document.documentElement);
  var brand = css.getPropertyValue('--color-brand').trim() || '#1f4d3a';
  var muted = css.getPropertyValue('--color-muted').trim() || '#7a8177';
  var border = css.getPropertyValue('--color-border').trim() || '#e7e1d2';
  Chart.defaults.font.family = 'Manrope, ui-sans-serif, system-ui, sans-serif';
  Chart.defaults.font.size = 11;
  Chart.defaults.color = muted;

  function axis(showGrid, isCategory) {
    return {
      grid: { display: showGrid, color: border, drawTicks: false, tickBorderDash: [3, 3] },
      border: { display: false, dash: [3, 3] },
      ticks: isCategory ? { padding: 6 } : { precision: 0, padding: 6 },
      beginAtZero: !isCategory
    };
  }

  // One gradient per bar, cycling through the WorkNest palette.
  var BAR_PAIRS = [['#6366f1', '#8b5cf6'], ['#0ea5e9', '#6366f1'], ['#a855f7', '#ec4899'], ['#10b981', '#06b6d4'], ['#f59e0b', '#f97316']];
  function barColor(ctx, i, horizontal, w, h) {
    var pair = BAR_PAIRS[i % BAR_PAIRS.length];
    var g = horizontal ? ctx.createLinearGradient(0, 0, w, 0) : ctx.createLinearGradient(0, h, 0, 0);
    g.addColorStop(0, pair[0]);
    g.addColorStop(1, pair[1]);
    return g;
  }

  document.querySelectorAll('canvas[data-chart]').forEach(function (canvas) {
    var cfg;
    try { cfg = JSON.parse(canvas.getAttribute('data-chart')); } catch (e) { return; }
    var unit = cfg.unit || '';
    var tooltip = { callbacks: { label: function (c) { return String(c.parsed[cfg.horizontal ? 'x' : 'y']) + unit; } } };

    var ctx = canvas.getContext('2d');
    var h = canvas.parentNode.clientHeight || 224;
    var w = canvas.parentNode.clientWidth || 600;

    if (cfg.type === 'line') {
      var fill = ctx.createLinearGradient(0, 0, 0, h);
      fill.addColorStop(0, 'rgba(99, 102, 241, 0.28)');
      fill.addColorStop(1, 'rgba(139, 92, 246, 0)');
      var stroke = ctx.createLinearGradient(0, 0, w, 0);
      stroke.addColorStop(0, '#6366f1');
      stroke.addColorStop(1, '#a855f7');
      new Chart(canvas, {
        type: 'line',
        data: { labels: cfg.labels, datasets: [{ data: cfg.values, borderColor: stroke, backgroundColor: fill, fill: true, borderWidth: 2.5,
          pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: '#fff', pointBorderColor: '#6366f1', pointBorderWidth: 2, tension: 0.4 }] },
        options: {
          maintainAspectRatio: false, animation: false,
          plugins: { legend: { display: false }, tooltip: tooltip },
          scales: { x: axis(false, true), y: axis(true, false) }
        }
      });
      return;
    }
    new Chart(canvas, {
      type: 'bar',
      data: { labels: cfg.labels, datasets: [{ data: cfg.values, backgroundColor: cfg.values.map(function (_, i) { return barColor(ctx, i, cfg.horizontal, w, h); }), borderRadius: 8, borderSkipped: false, maxBarThickness: 26 }] },
      options: {
        indexAxis: cfg.horizontal ? 'y' : 'x',
        maintainAspectRatio: false, animation: false,
        plugins: { legend: { display: false }, tooltip: tooltip },
        scales: cfg.horizontal
          ? { x: axis(true, false), y: Object.assign(axis(false, true), { afterFit: function (s) { s.width = 110; } }) }
          : { x: axis(false, true), y: axis(true, false) }
      }
    });
  });
})();
