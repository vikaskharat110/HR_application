/*
 * Check-in / check-out card (port of components/CheckInOutCard.tsx).
 * Asks the browser for its location only when geofencing is switched on
 * company-wide; the server alone decides whether the position is in range.
 */
(function () {
  'use strict';
  var card = document.querySelector('[data-checkin-card]');
  if (!card || !window.WorkNest) { return; }
  var geofence = card.getAttribute('data-geofence') === '1';
  var errBox = card.querySelector('[data-checkin-error]');

  function showError(msg) {
    errBox.querySelector('span').textContent = msg;
    errBox.hidden = false;
  }

  function locationErrorMessage() {
    if (!('geolocation' in navigator)) {
      return "Your browser doesn't support location — check-in may be blocked if your branch requires it.";
    }
    return "Couldn't get your location. Make sure location access is allowed for this site and location services are turned on, then try again.";
  }

  function getLocation() {
    return new Promise(function (resolve) {
      if (!('geolocation' in navigator)) { resolve(null); return; }
      navigator.geolocation.getCurrentPosition(resolve, function () { resolve(null); },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
    });
  }

  card.addEventListener('submit', function (e) {
    var form = e.target.closest('form[data-checkin-form]');
    if (!form) { return; }
    e.preventDefault();
    var btn = form.querySelector('button');
    var label = btn.innerHTML;
    errBox.hidden = true;
    btn.disabled = true;
    btn.innerHTML = window.WorkNest.svgIcon('loader-circle', 15, 'animate-spin') + (geofence ? ' Getting location…' : '');

    (geofence ? getLocation() : Promise.resolve(null)).then(function (pos) {
      var body = pos ? { latitude: pos.coords.latitude, longitude: pos.coords.longitude } : {};
      return window.WorkNest.postJSON(form.getAttribute('action'), body).then(function () {
        window.location.reload();
      }).catch(function (err) {
        btn.disabled = false;
        btn.innerHTML = label;
        // The server only errors when it needed the location (geofence on AND
        // this branch has one) — prefix the browser hint when we had none.
        showError(!pos && geofence ? locationErrorMessage() + ' ' + err.message : err.message);
      });
    });
  });
})();
