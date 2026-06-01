<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_render.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Whoami;

looth_issue_bounce_if_needed();   // mint looth_id for logged-in WP users who land here without one

$qs = $_GET;
$insts  = (array)($qs['inst']  ?? []);
$skills = (array)($qs['skill'] ?? []);
$music  = (array)($qs['music'] ?? []);
$creds  = (array)($qs['cred']  ?? []);
$lat    = isset($qs['lat']) ? (float)$qs['lat']    : null;
$lng    = isset($qs['lng']) ? (float)$qs['lng']    : null;
$radius = isset($qs['radius']) ? (int)$qs['radius'] : 50;
$locTxt = (string)($qs['loc'] ?? '');
$sort   = ($qs['sort'] ?? 'joined_asc') === 'joined_desc' ? 'joined_desc' : 'joined_asc';

$pg = Db::pg();
// Only surface tags that at least one member actually uses — an option that
// matches zero members is noise. The EXISTS clauses mirror each filter's own
// match semantics below (instruments/skills count both the full list AND
// migrated highlights). A facet that comes back empty is dropped from the bar.
$cats = [
    'instruments' => $pg->query("SELECT id, slug, name FROM instrument_catalog ic WHERE active=true
        AND (EXISTS (SELECT 1 FROM profile_instruments pi WHERE pi.instrument_id = ic.id)
          OR EXISTS (SELECT 1 FROM profile_highlights h WHERE h.kind='instrument' AND h.ref_id = ic.id))
        ORDER BY sort_order, name")->fetchAll(),
    'skills'      => $pg->query("SELECT id, slug, name, category FROM skill_catalog sc WHERE active=true
        AND (EXISTS (SELECT 1 FROM profile_skills ps WHERE ps.skill_id = sc.id)
          OR EXISTS (SELECT 1 FROM profile_highlights h WHERE h.kind='skill' AND h.ref_id = sc.id))
        ORDER BY category, sort_order, name")->fetchAll(),
    'music'       => $pg->query("SELECT slug, name FROM genre_catalog gc WHERE active=true
        AND EXISTS (SELECT 1 FROM profile_genres pg WHERE pg.genre_id = gc.id)
        ORDER BY sort_order, name")->fetchAll(),
    'credentials' => $pg->query("SELECT id, slug, issuer, program, category FROM credential_catalog cc WHERE active=true
        AND EXISTS (SELECT 1 FROM profile_credentials pc WHERE pc.owner_type='profile' AND pc.catalog_id = cc.id)
        ORDER BY category, issuer, program")->fetchAll(),
];

// Catalog options for the multiselect search bars. The four facets mirror the
// member-profile taxonomy (instruments, skills, credentials, music/genres);
// keep them in lockstep when the profile taxo changes.
$msCatalogs = [
    'inst'  => array_map(fn($c) => ['v' => $c['slug'], 'l' => $c['name']], $cats['instruments']),
    'skill' => array_map(fn($c) => ['v' => $c['slug'], 'l' => $c['name']], $cats['skills']),
    'music' => array_map(fn($c) => ['v' => $c['slug'], 'l' => $c['name']], $cats['music']),
    'cred'  => array_map(fn($c) => ['v' => $c['slug'], 'l' => $c['issuer'] . ' — ' . $c['program']], $cats['credentials']),
];
$msSelected = [
    'inst'  => array_values(array_map('strval', $insts)),
    'skill' => array_values(array_map('strval', $skills)),
    'music' => array_values(array_map('strval', $music)),
    'cred'  => array_values(array_map('strval', $creds)),
];

$placesKey = looth_places_key();
require_once '/srv/lg-shared/site-header.php';
require_once '/srv/lg-shared/site-footer.php';
$_whoami = Whoami::resolve();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Directory · Looth</title>
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<link rel="stylesheet" href="/profile/edit/edit.css">
<link rel="stylesheet" href="/profile/edit/directory.css?v=<?= @filemtime(__DIR__ . '/directory.css') ?: '1' ?>">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin=""></script>
</head>
<body>
<?php
lg_shared_render_site_header([
    'authenticated' => (bool)($_whoami['authenticated'] ?? false),
    'tier'          => (string)($_whoami['tier'] ?? 'public'),
    'display_name'  => (string)($_whoami['display_name'] ?? ''),
    'avatar_url'    => $_whoami['avatar_url'] ?? null,
    'capabilities'  => (array)($_whoami['capabilities'] ?? []),
    'msg_unread'    => null,
    'notif_unread'  => null,
    'profile_url'   => isset($_whoami['slug']) && $_whoami['slug']
        ? '/u/' . rawurlencode((string)$_whoami['slug'])
        : '/profile/edit',
    'active_nav'    => 'members',
    'logout_url'    => ($_whoami['authenticated'] ?? false) ? '/wp-login.php?action=logout' : null,
]);
?>
<div class="dir-header">Members <span class="dir-meta" id="dir-meta">loading…</span></div>
<div id="dir-map" class="dir-map" aria-hidden="true"></div>

<div class="dir-filterbar" id="dir-filterbar">
  <div class="filt loc">
    <span class="flab">Location</span>
    <input type="text" id="dir-loc" placeholder="Start typing a city…" value="<?= htmlspecialchars($locTxt, ENT_QUOTES) ?>">
    <input type="hidden" id="dir-lat" value="<?= $lat !== null ? htmlspecialchars((string)$lat, ENT_QUOTES) : '' ?>">
    <input type="hidden" id="dir-lng" value="<?= $lng !== null ? htmlspecialchars((string)$lng, ENT_QUOTES) : '' ?>">
  </div>
  <div class="filt radiusbox">
    <span class="flab">Radius</span>
    <select id="dir-radius">
      <?php foreach ([10,25,50,100,250] as $r): ?>
      <option value="<?= $r ?>" <?= $radius===$r?'selected':'' ?>>within <?= $r ?> mi</option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if (!($_whoami['authenticated'] ?? false)): ?>
  <div class="filt viewbox">
    <span class="flab">Show</span>
    <select id="dir-view">
      <option value="all">All members</option>
      <option value="visible">Visible profiles</option>
    </select>
  </div>
  <?php endif; ?>
  <?php if ($msCatalogs['inst']): ?><div class="filt"><span class="flab">Instruments</span><div class="ms" data-ms="inst" data-ph="Any instrument…"></div></div><?php endif; ?>
  <?php if ($msCatalogs['skill']): ?><div class="filt"><span class="flab">Skills</span><div class="ms" data-ms="skill" data-ph="Any skill…"></div></div><?php endif; ?>
  <?php if ($msCatalogs['music']): ?><div class="filt"><span class="flab">Music</span><div class="ms" data-ms="music" data-ph="Any genre…"></div></div><?php endif; ?>
  <?php if ($msCatalogs['cred']): ?><div class="filt"><span class="flab">Credentials</span><div class="ms" data-ms="cred" data-ph="Any credential…"></div></div><?php endif; ?>
  <div class="filt sortbox">
    <span class="flab">Joined</span>
    <div class="dir-sort" id="dir-sort" role="group" aria-label="Sort by join date">
      <button type="button" data-sort="joined_asc"  class="<?= $sort==='joined_asc'?'on':'' ?>">Oldest</button>
      <button type="button" data-sort="joined_desc" class="<?= $sort==='joined_desc'?'on':'' ?>">Newest</button>
    </div>
  </div>
</div>

<div class="dir-app">
  <main>
    <div class="dir-results" id="dir-results"></div>
    <button class="btn dir-load-more" id="dir-more" hidden>Load more</button>
  </main>
</div>

<script>
const CATALOGS = <?= json_encode($msCatalogs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const state = {
  inst:  <?= json_encode($msSelected['inst'],  JSON_UNESCAPED_SLASHES) ?>,
  skill: <?= json_encode($msSelected['skill'], JSON_UNESCAPED_SLASHES) ?>,
  music: <?= json_encode($msSelected['music'], JSON_UNESCAPED_SLASHES) ?>,
  cred:  <?= json_encode($msSelected['cred'],  JSON_UNESCAPED_SLASHES) ?>,
};
let curPage = 1;
let curSort = <?= json_encode($sort) ?>;

function escH(s){ return (s||'').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

// Filter-only query (no page) — shared by the list and the map-pin feed.
function filterQs() {
  const sp = new URLSearchParams();
  ['inst','skill','music','cred'].forEach(k => state[k].forEach(v => sp.append(k + '[]', v)));
  const loc = document.getElementById('dir-loc').value.trim();
  const lat = document.getElementById('dir-lat').value;
  const lng = document.getElementById('dir-lng').value;
  if (loc) sp.set('loc', loc);
  if (lat && lng) { sp.set('lat', lat); sp.set('lng', lng); sp.set('radius', document.getElementById('dir-radius').value); }
  sp.set('sort', curSort);
  return sp;
}
function buildQs(page) { const sp = filterQs(); sp.set('page', page); return sp.toString(); }

// ---------- multiselect search bars ----------
function labelFor(name, val) { const o = CATALOGS[name].find(o => o.v === val); return o ? o.l : val; }
function renderChips(root, name) {
  const ctrl = root.querySelector('.ms-control');
  ctrl.querySelectorAll('.ms-chip').forEach(c => c.remove());
  const search = root.querySelector('.ms-search');
  state[name].forEach(v => {
    const chip = document.createElement('span');
    chip.className = 'ms-chip';
    chip.innerHTML = `<span>${escH(labelFor(name, v))}</span><button type="button" aria-label="remove">×</button>`;
    chip.querySelector('button').addEventListener('click', e => {
      e.stopPropagation();
      state[name] = state[name].filter(x => x !== v);
      renderChips(root, name); applyFilters();
    });
    ctrl.insertBefore(chip, search);
  });
}
function renderMenu(root, name) {
  const menu = root.querySelector('.ms-menu');
  const q = root.querySelector('.ms-search').value.trim().toLowerCase();
  const opts = CATALOGS[name].filter(o => !q || o.l.toLowerCase().includes(q));
  if (!opts.length) { menu.innerHTML = '<div class="ms-none">no matches</div>'; return; }
  menu.innerHTML = opts.map(o =>
    `<div class="ms-opt${state[name].includes(o.v) ? ' sel' : ''}" data-v="${escH(o.v)}">${escH(o.l)}</div>`).join('');
  menu.querySelectorAll('.ms-opt').forEach(el => el.addEventListener('mousedown', e => {
    e.preventDefault();
    const v = el.getAttribute('data-v');
    if (state[name].includes(v)) state[name] = state[name].filter(x => x !== v);
    else state[name].push(v);
    root.querySelector('.ms-search').value = '';
    renderChips(root, name); renderMenu(root, name); applyFilters();
  }));
}
function initMultiselect(root) {
  const name = root.getAttribute('data-ms');
  root.innerHTML =
    `<div class="ms-control"><input class="ms-search" type="text" placeholder="${escH(root.getAttribute('data-ph') || '')}"></div>` +
    `<div class="ms-menu" hidden></div>`;
  const ctrl = root.querySelector('.ms-control');
  const search = root.querySelector('.ms-search');
  const menu = root.querySelector('.ms-menu');
  const open = () => { ctrl.classList.add('open'); menu.hidden = false; renderMenu(root, name); };
  const close = () => { ctrl.classList.remove('open'); menu.hidden = true; };
  ctrl.addEventListener('click', () => { search.focus(); open(); });
  search.addEventListener('focus', open);
  search.addEventListener('input', () => renderMenu(root, name));
  document.addEventListener('click', e => { if (!root.contains(e.target)) close(); });
  renderChips(root, name);
}

// ---------- results + map ----------
function renderResults(items, append) {
  const wrap = document.getElementById('dir-results');
  const html = items.map(it => `
    <a class="dir-card" href="/u/${escH(it.slug)}">
      <div class="row1">
        <div class="avi-sm">${escH((it.display_name||'?').split(/\s+/).map(w=>w[0]).slice(0,2).join('').toUpperCase())}</div>
        <div><div class="name">${escH(it.display_name||'Member')}</div>
        ${it.location?.text?`<div class="loc-row">${escH(it.location.text)}${it.distance_mi!=null?` · ${it.distance_mi} mi`:''}</div>`:''}
        </div>
      </div>
      ${it.highlights?.length?`<div class="hl-chips">${it.highlights.map(h=>`<span class="hl">${escH(h.name)}</span>`).join('')}</div>`:''}
    </a>`).join('');
  if (append) wrap.insertAdjacentHTML('beforeend', html);
  else wrap.innerHTML = html || '<div class="dir-empty">no members match. try widening filters.</div>';
}

async function loadPage(page, append) {
  const res = await fetch('/profile-api/v0/directory/members?' + buildQs(page), {credentials:'include'});
  const d = await res.json();
  document.getElementById('dir-meta').textContent = `${d.total} member${d.total===1?'':'s'} matching`;
  renderResults(d.items || [], append);
  document.getElementById('dir-more').hidden = !d.has_more;
  curPage = page;
}

// All matching members' pins (not just the current page) — plotted on the map.
async function loadPins() {
  if (!dirMap) return;
  const sp = filterQs(); sp.set('pins', '1');
  const res = await fetch('/profile-api/v0/directory/members?' + sp.toString(), {credentials:'include'});
  const d = await res.json();
  plotPins(d.pins || []);
}

function applyFilters() {
  loadPage(1, false);
  loadPins();
  window.history.replaceState({}, '', '/directory/members?' + buildQs(1));
}

// Wire up controls.
document.querySelectorAll('.ms').forEach(initMultiselect);
document.getElementById('dir-radius').addEventListener('change', applyFilters);
document.getElementById('dir-more').addEventListener('click', () => loadPage(curPage + 1, true));
const viewSel = document.getElementById('dir-view');
if (viewSel) viewSel.addEventListener('change', () => { viewFilter = viewSel.value; plotPins(lastPins); });
document.querySelectorAll('#dir-sort button').forEach(btn => btn.addEventListener('click', () => {
  if (curSort === btn.dataset.sort) return;
  curSort = btn.dataset.sort;
  document.querySelectorAll('#dir-sort button').forEach(b => b.classList.toggle('on', b === btn));
  applyFilters();
}));

// Map setup — Leaflet + OpenStreetMap (no API key needed) + marker clustering.
let dirMap = null, dirCluster = null, lastPins = [], viewFilter = 'all';
const pinIcon = L.divIcon({
  className: '',
  html: '<div style="width:14px;height:14px;border-radius:50%;background:#b9450b;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>',
  iconSize: [14, 14], iconAnchor: [7, 7], popupAnchor: [0, -10],
});
// Anonymized "hidden member" pin — muted/grey so it reads as locked at a glance.
const pinIconGated = L.divIcon({
  className: '',
  html: '<div style="width:13px;height:13px;border-radius:50%;background:#9a948a;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.35);opacity:.9"></div>',
  iconSize: [13, 13], iconAnchor: [6.5, 6.5], popupAnchor: [0, -10],
});
function initDirMap() {
  if (dirMap) return;
  dirMap = L.map('dir-map', {zoomControl: true, scrollWheelZoom: true}).setView([39, -98], 3);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 18,
  }).addTo(dirMap);
  // Cluster many overlapping pins into counts; spiderfy on click at max zoom.
  dirCluster = L.markerClusterGroup({chunkedLoading: true, maxClusterRadius: 50, spiderfyOnMaxZoom: true, showCoverageOnHover: false});
  dirMap.addLayer(dirCluster);
  loadPins();
}
function plotPins(pins) {
  if (!dirMap) return;
  lastPins = pins;
  dirCluster.clearLayers();
  const pts = [];
  pins.forEach(p => {
    if (p.lat == null || p.lng == null) return;
    if (p.gated && viewFilter === 'visible') return;   // "Visible profiles" filter hides anonymized pins
    let m;
    if (p.gated) {
      m = L.marker([p.lat, p.lng], {icon: pinIconGated})
        .bindPopup(`<div style="font-size:13px;color:#6b665e;max-width:190px">${escH(p.message)}</div>`
          + `<a href="/wp-login.php" style="font-size:12px;font-weight:600;color:#b9450b;text-decoration:none">Sign in to view →</a>`);
    } else {
      m = L.marker([p.lat, p.lng], {icon: pinIcon, title: p.display_name})
        .bindPopup(`<a href="/u/${escH(p.slug)}" style="font-weight:600;text-decoration:none;color:#1f1d1a">${escH(p.display_name)}</a>`
          + (p.text ? `<div style="font-size:12px;color:#8a8478">${escH(p.text)}</div>` : ''));
    }
    dirCluster.addLayer(m);
    pts.push([p.lat, p.lng]);
  });
  if (pts.length) dirMap.fitBounds(pts, {padding: [32, 32], maxZoom: 10});
}

// Initialize map + first list load.
document.addEventListener('DOMContentLoaded', () => { initDirMap(); loadPage(1, false); });

// Location autocomplete via OSM Nominatim (server-proxied /me/location/search) — replaces the
// broken Google Places widget. Fills #dir-lat/#dir-lng on pick, then applyFilters(). No API key.
(function () {
  const input = document.getElementById('dir-loc');
  if (!input) return;
  input.parentNode.style.position = 'relative';
  const box = document.createElement('div');
  box.style.cssText = 'position:absolute;z-index:1200;top:100%;left:0;right:0;background:#fff;border:1px solid #e2e0d6;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14);max-height:260px;overflow:auto;margin-top:4px;display:none';
  input.parentNode.appendChild(box);
  let timer = null, lastQ = null;
  const close = () => { box.style.display = 'none'; };
  function pick(lat, lng, label) {
    document.getElementById('dir-lat').value = lat;
    document.getElementById('dir-lng').value = lng;
    if (label) input.value = label;
    close(); applyFilters();
  }
  function summarize(row) {
    const a = row.address || {};
    const city = a.city || a.town || a.village || a.hamlet || a.suburb;
    const parts = [city, a.state, a.country].filter(Boolean);
    return parts.length ? parts.join(', ') : (row.display_name || '').slice(0, 80);
  }
  function run() {
    const q = input.value.trim();
    if (q === lastQ) return; lastQ = q;
    if (q.length < 3) { close(); return; }
    // Directory is viewable by anon, so geocode client-side via Nominatim (CORS, no auth/key).
    fetch('https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=6&q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
      .then(r => r.ok ? r.json() : [])
      .then(rows => {
        if (input.value.trim() !== q) return;                 // stale
        const items = (Array.isArray(rows) ? rows : []).map(row => ({ short: summarize(row), lat: row.lat, lng: row.lon, display_name: row.display_name }));
        box.innerHTML = '';
        if (!items.length) { close(); return; }
        items.slice(0, 6).forEach(it => {
          const b = document.createElement('button');
          b.type = 'button';
          b.style.cssText = 'display:block;width:100%;text-align:left;border:0;background:none;cursor:pointer;padding:9px 12px;font:600 13px/1.3 system-ui,sans-serif;color:#1f1d1a';
          b.textContent = it.short || it.display_name || '';
          b.addEventListener('mouseenter', () => { b.style.background = '#eef2ea'; });
          b.addEventListener('mouseleave', () => { b.style.background = 'none'; });
          b.addEventListener('click', () => pick(it.lat, it.lng, it.short || it.display_name));
          box.appendChild(b);
        });
        box.style.display = 'block';
      })
      .catch(close);
  }
  input.addEventListener('input', () => {
    document.getElementById('dir-lat').value = '';
    document.getElementById('dir-lng').value = '';            // typing invalidates the previous pin
    clearTimeout(timer); timer = setTimeout(run, 500);        // debounce (Nominatim ≤1 req/sec policy)
  });
  input.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
  document.addEventListener('click', e => { if (!box.contains(e.target) && e.target !== input) close(); });
})();
</script>
<?php lg_shared_render_site_footer(); ?>
</body>
</html>
