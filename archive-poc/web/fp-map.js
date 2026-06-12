/* archive-poc front page — member/visitor map tile (Buck 2026-06-11).
   Zooms the bento map tile to the viewer's location + lists the closest
   members to their shop (distance-sorted), via the profile-app directory API.
   Location source:
     • logged-in member → GET /profile-api/v0/me/location (their coarse pin),
       "You" marker.
     • logged-out / member with no pin → IP geolocation (get.geojs.io),
       "Near you" marker.
   All coords are precision-coarsened (members) or city-level (IP) — F1-safe,
   and NO dependency on the dead members-geo WP route.
   Graceful: any failure leaves the static teaser tile exactly as rendered. */
(function () {
  'use strict';
  var host = document.getElementById('lg-fp-map');
  if (!host) return;

  function j(u, opts) {
    return fetch(u, opts || {}).then(function (r) {
      if (!r.ok) throw new Error(String(r.status));
      return r.json();
    });
  }
  function num(v) { return typeof v === 'number' && isFinite(v); }

  // me/location nests coords under `place`; tolerate other shapes too.
  function pickMe(b) {
    if (!b || typeof b !== 'object') return null;
    var cands = [b.place, b, b.display, b.pin, b.map, b.coarse, b.exact];
    for (var i = 0; i < cands.length; i++) {
      var c = cands[i];
      if (c && num(c.lat) && num(c.lng)) {
        return { lat: c.lat, lng: c.lng, zoom: c.zoom || b.zoom || 10, source: 'me' };
      }
    }
    return null;
  }

  // 1) member's own location → 2) IP geolocation fallback.
  function resolveLocation() {
    return j('/profile-api/v0/me/location', { credentials: 'same-origin' })
      .then(function (b) { return pickMe(b); })
      .catch(function () { return null; })
      .then(function (loc) {
        if (loc) return loc;
        return j('https://get.geojs.io/v1/ip/geo.json')
          .then(function (g) {
            var lat = parseFloat(g && g.latitude), lng = parseFloat(g && g.longitude);
            if (!num(lat) || !num(lng)) return null;
            return { lat: lat, lng: lng, zoom: 9, source: 'ip' };
          })
          .catch(function () { return null; });
      });
  }

  resolveLocation().then(function (loc) {
    if (!loc) return;                                  // no location → teaser stays
    return j('/profile-api/v0/whoami', { credentials: 'same-origin' })
      .catch(function () { return {}; })
      .then(function (who) {
        var myUuid = (who && (who.uuid || (who.user && who.user.uuid))) || null;
        return j('/profile-api/v0/directory/members?lat=' + loc.lat + '&lng=' + loc.lng + '&page_size=9')
          .then(function (d) { loadLeaflet(loc, myUuid, (d && d.items) || []); });
      });
  }).catch(function () { /* teaser stays */ });

  function loadLeaflet(loc, myUuid, items) {
    if (window.L && window.L.map) return init(loc, myUuid, items);
    var css = document.createElement('link');
    css.rel = 'stylesheet'; css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    document.head.appendChild(css);
    var s = document.createElement('script');
    s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    s.onload = function () { init(loc, myUuid, items); };
    document.head.appendChild(s);
  }

  function init(loc, myUuid, items) {
    var canvas = host.querySelector('.lg-fp-map__canvas');
    var list = host.querySelector('.lg-fp-map__list');
    var titleEl = host.querySelector('.lg-bento__map-title');
    var subEl = host.querySelector('.lg-bento__map-sub');
    if (!canvas) return;
    host.classList.add('is-live');
    if (titleEl) titleEl.textContent = 'Luthiers near you';
    if (subEl) subEl.textContent = loc.source === 'ip'
      ? 'Based on your location — the closest luthiers and shops:'
      : 'You’re on the map. The closest luthiers and shops:';

    var map = L.map(canvas, { scrollWheelZoom: false, zoomControl: true })
      .setView([loc.lat, loc.lng], Math.min(11, Math.max(8, (loc.zoom || 10) - 1)));
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var meLabel = loc.source === 'ip' ? 'Near you' : 'You';
    L.circleMarker([loc.lat, loc.lng], { radius: 9, color: '#fff', weight: 3, fillColor: '#6f7c54', fillOpacity: 1 })
      .addTo(map)
      .bindTooltip(meLabel, { permanent: true, direction: 'top', offset: [0, -10], className: 'lg-fp-you' });

    var shown = 0;
    items.forEach(function (it) {
      var l = it && it.location;
      if (!l || !num(l.lat) || !num(l.lng)) return;
      var isMe = !!(myUuid && it.uuid === myUuid);
      if (!isMe) {
        L.circleMarker([l.lat, l.lng], { radius: 7, color: '#fff', weight: 2, fillColor: '#c66845', fillOpacity: .95 })
          .addTo(map).bindTooltip(it.display_name || 'Member');
      }
      if (list && shown < 4) {
        var a = document.createElement('a');
        a.className = 'lg-fp-near'; a.href = '/u/' + encodeURIComponent(it.slug || '');
        var n = document.createElement('span'); n.className = 'lg-fp-near__n'; n.textContent = it.display_name || 'Member';
        var m = document.createElement('span'); m.className = 'lg-fp-near__m'; m.textContent = l.text || '';
        var d = document.createElement('span'); d.className = 'lg-fp-near__d';
        d.textContent = isMe ? 'you' : (num(it.distance_mi) ? (it.distance_mi < 1 ? '<1 mi' : Math.round(it.distance_mi) + ' mi') : '');
        a.appendChild(n); a.appendChild(m); a.appendChild(d);
        list.appendChild(a); shown++;
      }
    });
    if (shown) host.classList.add('has-list');
    setTimeout(function () { map.invalidateSize(); }, 250);
  }
})();
