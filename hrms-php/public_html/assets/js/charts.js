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

  document.querySelectorAll('canvas[data-chart]').forEach(function (canvas) {
    var cfg;
    try { cfg = JSON.parse(canvas.getAttribute('data-chart')); } catch (e) { return; }
    var unit = cfg.unit || '';
    var tooltip = { callbacks: { label: function (c) { return String(c.parsed[cfg.horizontal ? 'x' : 'y']) + unit; } } };

    if (cfg.type === 'line') {
      new Chart(canvas, {
        type: 'line',
        data: { labels: cfg.labels, datasets: [{ data: cfg.values, borderColor: brand, backgroundColor: brand, borderWidth: 2, pointRadius: 3, pointHoverRadius: 5, tension: 0.35 }] },
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
      data: { labels: cfg.labels, datasets: [{ data: cfg.values, backgroundColor: brand, borderRadius: 4, maxBarThickness: 28 }] },
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
