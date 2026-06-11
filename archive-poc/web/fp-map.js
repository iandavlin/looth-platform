/* archive-poc front page — member map tile upgrade (Buck 2026-06-11).
   Zooms the bento map tile to the LOGGED-IN member's own (coarse) location,
   drops a "You" marker, and lists the closest members to their shop using
   the profile-app directory API's distance sort. Coordinates are the same
   precision-coarsened points the public directory shows (F1-safe).
   Graceful: any failure (logged out, no location set, API down) leaves the
   static teaser tile exactly as rendered. No deps until data confirms —
   Leaflet (already used by /directory) loads only on success. */
(function () {
  'use strict';
  var host = document.getElementById('lg-fp-map');
  if (!host) return;

  function j(u) {
    return fetch(u, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error(String(r.status));
      return r.json();
    });
  }
  function num(v) { return typeof v === 'number' && isFinite(v); }
  // me/location block shapes vary (display/pin/two-tier) — take the first pair.
  function pickLL(b) {
    if (!b || typeof b !== 'object') return null;
    var cands = [b, b.display, b.pin, b.map, b.coarse, b.exact];
    for (var i = 0; i < cands.length; i++) {
      var c = cands[i];
      if (c && num(c.lat) && num(c.lng)) {
        return { lat: c.lat, lng: c.lng, zoom: c.zoom || b.zoom || 10, text: c.text || b.text || '' };
      }
    }
    return null;
  }

  Promise.all([
    j('/profile-api/v0/me/location').catch(function () { return null; }),
    j('/profile-api/v0/whoami').catch(function () { return null; })
  ]).then(function (res) {
    var ll = pickLL(res[0]);
    if (!ll) return;                                   // no location → teaser stays
    var who = res[1] || {};
    var myUuid = who.uuid || (who.user && who.user.uuid) || null;
    return j('/profile-api/v0/directory/members?lat=' + ll.lat + '&lng=' + ll.lng + '&page_size=9')
      .then(function (d) { loadLeaflet(ll, myUuid, (d && d.items) || []); });
  }).catch(function () { /* teaser stays */ });

  function loadLeaflet(ll, myUuid, items) {
    if (window.L && window.L.map) return init(ll, myUuid, items);
    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    document.head.appendChild(css);
    var s = document.createElement('script');
    s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    s.onload = function () { init(ll, myUuid, items); };
    document.head.appendChild(s);
  }

  function init(ll, myUuid, items) {
    var canvas = host.querySelector('.lg-fp-map__canvas');
    var list = host.querySelector('.lg-fp-map__list');
    if (!canvas) return;
    host.classList.add('is-live');
    var map = L.map(canvas, { scrollWheelZoom: false, zoomControl: true })
      .setView([ll.lat, ll.lng], Math.min(11, Math.max(8, (ll.zoom || 10) - 1)));
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    L.circleMarker([ll.lat, ll.lng], { radius: 9, color: '#fff', weight: 3, fillColor: '#6f7c54', fillOpacity: 1 })
      .addTo(map)
      .bindTooltip('You', { permanent: true, direction: 'top', offset: [0, -10], className: 'lg-fp-you' });

    var shown = 0;
    items.forEach(function (it) {
      var loc = it && it.location;
      if (!loc || !num(loc.lat) || !num(loc.lng)) return;
      var isMe = !!(myUuid && it.uuid === myUuid);
      if (!isMe) {
        L.circleMarker([loc.lat, loc.lng], { radius: 7, color: '#fff', weight: 2, fillColor: '#c66845', fillOpacity: .95 })
          .addTo(map).bindTooltip(it.display_name || 'Member');
      }
      if (list && shown < 4) {
        var a = document.createElement('a');
        a.className = 'lg-fp-near';
        a.href = '/u/' + encodeURIComponent(it.slug || '');
        var n = document.createElement('span'); n.className = 'lg-fp-near__n'; n.textContent = it.display_name || 'Member';
        var m = document.createElement('span'); m.className = 'lg-fp-near__m'; m.textContent = loc.text || '';
        var d = document.createElement('span'); d.className = 'lg-fp-near__d';
        d.textContent = isMe ? 'you' : (num(it.distance_mi) ? (it.distance_mi < 1 ? '<1 mi' : Math.round(it.distance_mi) + ' mi') : '');
        a.appendChild(n); a.appendChild(m); a.appendChild(d);
        list.appendChild(a);
        shown++;
      }
    });
    if (shown) host.classList.add('has-list');
    setTimeout(function () { map.invalidateSize(); }, 250);
  }
})();
