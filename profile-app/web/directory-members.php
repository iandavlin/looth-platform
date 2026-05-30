<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_render.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Whoami;

$qs = $_GET;
$insts  = (array)($qs['inst']  ?? []);
$skills = (array)($qs['skill'] ?? []);
$scenes = (array)($qs['scene'] ?? []);
$creds  = (array)($qs['cred']  ?? []);
$lat    = isset($qs['lat']) ? (float)$qs['lat']    : null;
$lng    = isset($qs['lng']) ? (float)$qs['lng']    : null;
$radius = isset($qs['radius']) ? (int)$qs['radius'] : 50;
$locTxt = (string)($qs['loc'] ?? '');

$pg = Db::pg();
$cats = [
    'instruments' => $pg->query("SELECT id, slug, name FROM instrument_catalog WHERE active=true ORDER BY sort_order, name")->fetchAll(),
    'skills'      => $pg->query("SELECT id, slug, name, category FROM skill_catalog WHERE active=true ORDER BY category, sort_order, name")->fetchAll(),
    'scenes'      => $pg->query("SELECT slug, name FROM scene_tags WHERE active=true ORDER BY sort_order, name")->fetchAll(),
    'credentials' => $pg->query("SELECT id, slug, issuer, program, category FROM credential_catalog WHERE active=true ORDER BY category, issuer, program")->fetchAll(),
];

$initialQS = http_build_query([
    'inst' => $insts, 'skill' => $skills, 'scene' => $scenes, 'cred' => $creds,
    'lat' => $lat, 'lng' => $lng, 'radius' => $radius, 'page' => 1,
]);

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
<link rel="stylesheet" href="/lg-shared/site-header.css">
<link rel="stylesheet" href="/profile/edit/edit.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
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
<div class="dir-app">

  <aside class="dir-filters">
    <form id="dir-form" method="get">
      <div class="filter-block">
        <h4>Location</h4>
        <input type="text" id="dir-loc" name="loc" placeholder="Start typing a city…" value="<?= htmlspecialchars($locTxt, ENT_QUOTES) ?>">
        <input type="hidden" id="dir-lat" name="lat" value="<?= $lat !== null ? htmlspecialchars((string)$lat, ENT_QUOTES) : '' ?>">
        <input type="hidden" id="dir-lng" name="lng" value="<?= $lng !== null ? htmlspecialchars((string)$lng, ENT_QUOTES) : '' ?>">
        <select name="radius" style="margin-top:6px">
          <?php foreach ([10,25,50,100,250] as $r): ?>
          <option value="<?= $r ?>" <?= $radius===$r?'selected':'' ?>>within <?= $r ?> mi</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="filter-block">
        <h4>Instruments</h4>
        <div class="multi-pick">
          <?php foreach ($cats['instruments'] as $c): ?>
            <label><input type="checkbox" name="inst[]" value="<?= htmlspecialchars($c['slug']) ?>" <?= in_array($c['slug'], $insts, true)?'checked':'' ?>><?= htmlspecialchars($c['name']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="filter-block">
        <h4>Skills</h4>
        <div class="multi-pick">
          <?php foreach ($cats['skills'] as $c): ?>
            <label><input type="checkbox" name="skill[]" value="<?= htmlspecialchars($c['slug']) ?>" <?= in_array($c['slug'], $skills, true)?'checked':'' ?>><?= htmlspecialchars($c['name']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="filter-block">
        <h4>Scenes</h4>
        <div class="multi-pick">
          <?php foreach ($cats['scenes'] as $c): ?>
            <label><input type="checkbox" name="scene[]" value="<?= htmlspecialchars($c['slug']) ?>" <?= in_array($c['slug'], $scenes, true)?'checked':'' ?>><?= htmlspecialchars($c['name']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="filter-block">
        <h4>Credentials</h4>
        <div class="multi-pick">
          <?php foreach ($cats['credentials'] as $c): ?>
            <label><input type="checkbox" name="cred[]" value="<?= htmlspecialchars($c['slug']) ?>" <?= in_array($c['slug'], $creds, true)?'checked':'' ?>><?= htmlspecialchars($c['issuer']) ?> — <?= htmlspecialchars($c['program']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <button type="button" class="btn btn-pri" id="dir-apply">Apply filters</button>
    </form>
  </aside>

  <main>
    <div class="dir-results" id="dir-results"></div>
    <button class="btn dir-load-more" id="dir-more" hidden>Load more</button>
  </main>
</div>

<script>
const INITIAL_QS = '<?= $initialQS ?>';
let curPage = 1;

function buildQs(page) {
  const f = document.getElementById('dir-form');
  const fd = new FormData(f);
  const sp = new URLSearchParams();
  for (const [k,v] of fd.entries()) if (v) sp.append(k, v);
  sp.set('page', page);
  return sp.toString();
}

function escH(s){ return (s||'').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

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
  updateMapPins(items, append);
}

async function loadPage(page, append) {
  const qs = buildQs(page);
  const res = await fetch('/profile-api/v0/directory/members?' + qs, {credentials:'include'});
  const d = await res.json();
  document.getElementById('dir-meta').textContent = `${d.total} member${d.total===1?'':'s'} matching`;
  renderResults(d.items || [], append);
  document.getElementById('dir-more').hidden = !d.has_more;
  curPage = page;
}

document.getElementById('dir-apply').addEventListener('click', () => { loadPage(1, false); window.history.replaceState({}, '', '/directory/members?' + buildQs(1)); });
document.getElementById('dir-more').addEventListener('click', () => loadPage(curPage + 1, true));

// Auto-submit on checkbox change for instant feel.
document.querySelectorAll('#dir-form input[type=checkbox]').forEach(cb =>
  cb.addEventListener('change', () => { loadPage(1, false); window.history.replaceState({}, '', '/directory/members?' + buildQs(1)); }));

// Initial load using the SSR-rendered query string.
loadPage(1, false);

// Map setup — Leaflet + OpenStreetMap (no API key needed).
let dirMap = null, dirMarkers = [];
function initDirMap() {
  if (dirMap) return;
  dirMap = L.map('dir-map', {zoomControl: true, scrollWheelZoom: false})
    .setView([39, -98], 3);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 18,
  }).addTo(dirMap);
}
const pinIcon = L.divIcon({
  className: '',
  html: '<div style="width:14px;height:14px;border-radius:50%;background:#b9450b;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>',
  iconSize: [14, 14], iconAnchor: [7, 7], popupAnchor: [0, -10],
});
function updateMapPins(items, append) {
  if (!dirMap) return;
  if (!append) { dirMarkers.forEach(m => dirMap.removeLayer(m)); dirMarkers = []; }
  const pts = [];
  items.forEach(it => {
    if (!it.location?.lat || !it.location?.lng) return;
    const m = L.marker([it.location.lat, it.location.lng], {icon: pinIcon, title: it.display_name})
      .bindPopup(`<a href="/u/${escH(it.slug)}" style="font-weight:600;text-decoration:none;color:#1f1d1a">${escH(it.display_name)}</a>`
        + (it.location.text ? `<div style="font-size:12px;color:#8a8478">${escH(it.location.text)}</div>` : ''))
      .addTo(dirMap);
    dirMarkers.push(m);
    pts.push([it.location.lat, it.location.lng]);
  });
  if (!append && pts.length) dirMap.fitBounds(pts, {padding: [32, 32], maxZoom: 10});
}

// Initialize map immediately (Leaflet needs no API key callback).
document.addEventListener('DOMContentLoaded', initDirMap);

// Google Places for location filter (autocomplete only — separate from map).
window.lootInitDirPlaces = function () {
  const input = document.getElementById('dir-loc');
  if (!input || !window.google?.maps?.places) return;
  const ac = new google.maps.places.Autocomplete(input, {fields:['geometry','formatted_address'], types:['geocode']});
  ac.addListener('place_changed', () => {
    const p = ac.getPlace();
    if (p?.geometry?.location) {
      document.getElementById('dir-lat').value = p.geometry.location.lat();
      document.getElementById('dir-lng').value = p.geometry.location.lng();
      loadPage(1, false);
    }
  });
};
</script>
<?php if ($placesKey): ?>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($placesKey, ENT_QUOTES) ?>&libraries=places&callback=lootInitDirPlaces"></script>
<?php endif; ?>
<?php lg_shared_render_site_footer(); ?>
</body>
</html>
