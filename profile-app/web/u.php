<?php
declare(strict_types=1);

/**
 * /u/<slug> — public profile, BLOCK-MODEL render (spine inc 1–3).
 *
 * Replaces the slice-3.5 form/render path: the page is now assembled by
 * looth_render_profile_blocks() (header-as-ceiling gate + per-block renderers),
 * the SAME gate the /me endpoints round-trip through. Header default = member
 * (RULED): a profile with no explicit header vis is members-only; logged-out
 * hits the members-gate; public blocks under a public header peek through.
 *
 * View-as (owner only): the owner previews Public / Member / Me by driving the
 * one renderer with the selected effective role — no forked render path.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_render_blocks.php';   // looth_render_profile_blocks + Block + looth_h/initials

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Social;

$slug = $_GET['slug'] ?? '';
if (!is_string($slug) || $slug === '') { http_response_code(404); echo 'not found'; exit; }

$pg = Db::pg();
$q = $pg->prepare('SELECT id, uuid, display_name, slug FROM users WHERE slug = :s');
$q->execute([':s' => $slug]);
$row = $q->fetch();
if (!$row && ctype_digit($slug)) {
    $q = $pg->prepare('SELECT id, uuid, display_name, slug FROM users WHERE id = :i');
    $q->execute([':i' => (int)$slug]);
    $row = $q->fetch();
}
if (!$row) { http_response_code(404); echo 'not found'; exit; }

$subjectId = (int)$row['id'];
$viewer    = Auth::currentUser();
$isOwner   = $viewer && strtolower((string)$viewer['uuid']) === strtolower((string)$row['uuid']);

// Effective viewer role. Owner gets View-as (?view=public|member|me, default me);
// everyone else is member (signed-in) or public (logged-out). The SAME role flows
// into the one gate — looth_render_profile_blocks() does the rest.
if ($isOwner) {
    $view = $_GET['view'] ?? 'me';
    $role = in_array($view, ['public', 'member', 'me'], true) ? $view : 'me';
} else {
    $role = $viewer ? 'member' : 'public';
}

// Subject tier badge: not resolvable from the spine post tier-drop — needs a
// membership-tier lookup. Passed null for now (header renders no badge). FLAG.
$tierBadge = null;

$displayName = (string)($row['display_name'] ?: 'Member');
$slugSafe    = (string)($row['slug'] ?: (string)$subjectId);
$viewLink = fn(string $v): string => '/u/' . rawurlencode($slugSafe) . '?view=' . $v;

// Social actions (Connect / Message) — server-rendered widget from the social lane.
// Self-suppresses for the owner viewing their own page; auth-gated when logged out.
// Rendered inside the header card (threaded through the block renderer).
$socialActions = Social::renderProfileActions($viewer['uuid'] ?? null, (string)$row['uuid']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= looth_h($displayName) ?> · Looth</title>
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<!-- Leaflet from CDN (standalone shell has no WP head to enqueue from) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="" defer></script>
<style>
/* Block-model /u/ render. Tokens (--lg-*) come from site-header.css. */
body{margin:0;background:var(--lg-cream);color:var(--lg-ink);font-family:var(--lg-font-sans);font-size:15px;line-height:1.6}
.lg-profile{max-width:760px;margin:0 auto;padding:24px 20px 48px}

/* View-as toggle (owner only) */
.lg-viewas{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--lg-charcoal);color:#cfd3cb;
  border-radius:12px;padding:10px 14px;margin:0 0 20px;font:600 12.5px/1 var(--lg-font-sans)}
.lg-viewas__label{font-weight:700}
.lg-viewas__seg{display:flex;border:1px solid rgba(255,255,255,.18);border-radius:999px;overflow:hidden}
.lg-viewas__seg a{padding:6px 14px;color:#cfd3cb;text-decoration:none;font:700 12px/1 var(--lg-font-sans)}
.lg-viewas__seg a[aria-current="true"]{background:var(--lg-amber);color:#4a3c10}
.lg-viewas__edit{margin-left:auto;background:#fff;color:var(--lg-ink);border-radius:999px;
  padding:7px 15px;text-decoration:none;font:700 12.5px/1 var(--lg-font-sans)}
.lg-viewas__hint{flex-basis:100%;font:500 11px/1.4 var(--lg-font-sans);color:#9aa091}

/* Block shell */
.lg-block{position:relative;background:#fff;border:1px solid var(--lg-line);border-radius:16px;padding:22px 24px;margin:0 0 16px}
.lg-bh{margin:0 0 12px;font:800 16px/1 var(--lg-font-serif);color:var(--lg-charcoal)}
/* vis chip: inline by default (location/craft/socials emit it within text);
   the header's direct-child chip is corner-positioned. */
.lg-vchip{display:inline-block;vertical-align:middle;font:800 9px/1 var(--lg-font-sans);letter-spacing:.06em;
  text-transform:uppercase;border-radius:5px;padding:3px 7px;margin-left:6px}
.lg-block--header>.lg-vchip{position:absolute;top:14px;right:16px;margin:0}
.lg-vchip--public{background:var(--lg-sage-tint);color:var(--lg-sage-d)}
.lg-vchip--member{background:#fdf0d8;color:#8a6326}
.lg-vchip--private{background:#f0e6e2;color:var(--lg-rust)}

/* header / identity card */
.lg-idrow{display:flex;gap:20px;align-items:center}
.lg-idrow__pic{width:96px;height:96px;border-radius:50%;flex:none;background:var(--lg-sage);color:#fff;
  display:grid;place-items:center;font:700 34px/1 var(--lg-font-serif);position:relative;overflow:hidden}
.lg-idrow__pic img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.lg-idrow__cam{position:absolute;right:0;bottom:0;width:30px;height:30px;border-radius:50%;background:#fff;
  border:1px solid var(--lg-line);cursor:pointer;font-size:14px;line-height:1}
.lg-idrow__name{margin:0;font:800 28px/1.1 var(--lg-font-serif);color:var(--lg-charcoal);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.lg-tierpill{font:800 10px/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;background:var(--lg-amber);color:#4a3c10;border-radius:6px;padding:4px 9px}
.lg-idrow__glance{font-size:16px;margin:6px 0 0;color:var(--lg-ink)}

/* location */
.lg-loc__line{display:flex;align-items:center;gap:9px;font-size:15px;color:var(--lg-ink)}
.lg-loc__exact{font-size:14.5px;color:var(--lg-ink);margin-top:8px}
.lg-loc__exact-note{font-size:13px;color:var(--lg-mute);margin-top:8px;font-style:italic}
.lg-loc__map,.lg-loc__pin{margin-top:12px;height:160px;border-radius:12px;border:1px solid var(--lg-line);
  overflow:hidden;background:var(--lg-sage-tint)}
.lg-loc__map .leaflet-container,.lg-loc__pin .leaflet-container{height:100%;border-radius:12px;font:inherit}

/* craft chips */
.lg-chips{display:flex;flex-wrap:wrap;gap:0}
.lg-chip{display:inline-block;background:var(--lg-cream);border:1px solid var(--lg-line);border-radius:8px;padding:5px 12px;margin:0 7px 8px 0;font-size:13.5px}

/* socials / links */
.lg-socrow{display:flex;gap:9px;flex-wrap:wrap}
.lg-socrow__a{display:inline-flex;align-items:center;height:32px;padding:0 12px;border-radius:8px;background:var(--lg-sage-tint);
  color:var(--lg-sage-d);font:700 12.5px/1 var(--lg-font-sans);text-decoration:none}
.lg-socrow__a:hover{background:var(--lg-sage-3)}

/* members-only gate */
.lg-gate{text-align:center;background:#fff;border:1px solid var(--lg-line);border-radius:18px;padding:48px 30px;margin:0 0 16px}
.lg-gate__lock{width:64px;height:64px;border-radius:50%;background:var(--lg-sage-tint);display:grid;place-items:center;margin:0 auto 16px;font-size:28px}
.lg-gate h2{margin:0 0 8px;font:800 22px/1.2 var(--lg-font-serif);color:var(--lg-charcoal)}
.lg-gate p{margin:0 auto 20px;max-width:420px;color:var(--lg-mute);font-size:14.5px}
.lg-gate__cta{display:inline-flex;gap:10px}
.lg-gate__join{background:var(--lg-amber);color:#4a3c10;text-decoration:none;font:800 14px/1 var(--lg-font-sans);border-radius:999px;padding:12px 22px}
.lg-gate__signin{border:1px solid var(--lg-line);color:var(--lg-ink);text-decoration:none;font:700 14px/1 var(--lg-font-sans);border-radius:999px;padding:12px 22px}

.lg-report{display:inline-block;margin-top:8px;font-size:12.5px;color:var(--lg-mute)}

/* interactive pmp control (owner/Me view) */
.lg-pmp{cursor:pointer;border:0;font-family:inherit;display:inline-flex;align-items:center;gap:4px}
.lg-pmp:hover{filter:brightness(.95)}
.lg-pmp:focus-visible{outline:2px solid var(--lg-sage);outline-offset:1px}
.lg-pmp__caret{font-size:8px;opacity:.8}
.lg-pmp--capped{box-shadow:inset 0 0 0 1px var(--lg-rust)}
.lg-pmp-menu{position:absolute;z-index:60;min-width:210px;background:#fff;border:1px solid var(--lg-line);
  border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,.14);padding:6px}
.lg-pmp-menu__head{font:700 10px/1.3 var(--lg-font-sans);text-transform:uppercase;letter-spacing:.06em;color:var(--lg-mute);padding:7px 9px 5px}
.lg-pmp-menu button{display:flex;width:100%;align-items:center;justify-content:space-between;gap:10px;
  border:0;background:none;cursor:pointer;padding:8px 9px;border-radius:7px;text-align:left;
  font:600 13px/1.2 var(--lg-font-sans);color:var(--lg-ink)}
.lg-pmp-menu button:hover{background:var(--lg-sage-tint)}
.lg-pmp-menu button[aria-current="true"]{font-weight:800;color:var(--lg-sage-d)}
.lg-pmp-menu button[aria-current="true"]::after{content:"✓";color:var(--lg-sage-d)}
.lg-pmp-menu .cap{font:600 10px/1.2 var(--lg-font-sans);color:var(--lg-rust)}

@media(max-width:560px){.lg-idrow{flex-direction:column;text-align:center;align-items:center}}
</style>
</head>
<body class="mode-view">
<?php require __DIR__ . '/_chrome.php'; ?>

<main class="main" id="lg-main">
  <div class="lg-profile">

    <?php if ($isOwner): ?>
      <div class="lg-viewas" role="group" aria-label="Preview your profile as">
        <span class="lg-viewas__label">👁 View as</span>
        <span class="lg-viewas__seg">
          <a href="<?= looth_h($viewLink('public')) ?>" <?= $role==='public'?'aria-current="true"':'' ?>>Public</a>
          <a href="<?= looth_h($viewLink('member')) ?>" <?= $role==='member'?'aria-current="true"':'' ?>>Member</a>
          <a href="<?= looth_h($viewLink('me')) ?>"     <?= $role==='me'?'aria-current="true"':'' ?>>Me</a>
        </span>
        <a class="lg-viewas__edit" href="/profile/edit">Edit profile</a>
        <span class="lg-viewas__hint">Preview how your profile looks to each audience. “Public” shows the members-gate when your header is members-only.</span>
      </div>
    <?php endif; ?>

    <?php looth_render_profile_blocks($subjectId, $role, $tierBadge, $socialActions); ?>

    <?php if (!$isOwner): ?>
      <a class="lg-report" href="#" id="report-link">Report this profile</a>
    <?php endif; ?>
  </div>
</main>

<?php lg_shared_render_site_footer(); ?>

<script>
/* Real maps for the location block — Leaflet + OSM tiles (CDN, no WP, no API key).
   The renderer already emits the MANAGED coords on each map div; .lg-loc__map is
   the coarse approximate dot (circle), .lg-loc__pin is the exact pin (marker). */
window.addEventListener('load', function () {
  if (typeof L === 'undefined') return;
  document.querySelectorAll('.lg-loc__map[data-lat], .lg-loc__pin[data-lat]').forEach(function (el) {
    var lat = parseFloat(el.getAttribute('data-lat')), lng = parseFloat(el.getAttribute('data-lng'));
    if (isNaN(lat) || isNaN(lng)) return;
    var exact = el.classList.contains('lg-loc__pin');
    var prec  = el.getAttribute('data-precision');
    var zoom  = exact ? (prec === 'neighborhood' ? 13 : 15) : 11;
    var map = L.map(el, { zoomControl: false, scrollWheelZoom: false, dragging: false,
      doubleClickZoom: false, boxZoom: false, keyboard: false }).setView([lat, lng], zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
      { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
    if (exact) L.marker([lat, lng]).addTo(map);
    else L.circle([lat, lng], { radius: 1500, color: '#87986a', fillColor: '#87986a', fillOpacity: 0.18, weight: 1 }).addTo(map);
    setTimeout(function () { map.invalidateSize(); }, 80);   // standalone-shell sizing fix
  });
});
</script>

<?php if (!$isOwner): ?>
<script>
document.getElementById('report-link')?.addEventListener('click', function (e) {
  e.preventDefault();
  var reason = prompt('Reason (short)?'); if (!reason) return;
  var body = prompt('Details? (optional)') || '';
  fetch('/profile-api/v0/reports', {method:'POST', credentials:'include',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({target_type:'profile', target_id: <?= $subjectId ?>, reason: reason, body: body})})
    .then(function(r){return r.json();}).then(function(d){ alert(d.ok ? 'Thanks — report logged.' : ('Error: ' + (d.error||'?'))); });
});
</script>
<?php endif; ?>

<?php if ($isOwner): ?>
<script>
/* Inline per-block privacy (pmp) control — owner/Me view. The chips rendered by
   looth_pmp_control() are <button.lg-pmp> carrying the block id, current vis, and
   the header ceiling. Clicking opens a menu; selecting persists via the existing
   /me endpoints, then reloads so the server re-derives ceilings + the gate (keeps
   View-as honest). Server stays the source of truth (validation + the gate). */
(function () {
  var BASE = '/profile-api/v0';
  // endpoint + method + payload key per block.
  var EP = {
    'header':          { url: BASE + '/me/header',   m: 'PATCH', k: 'visibility' },
    'craft':           { url: BASE + '/me/craft',    m: 'PATCH', k: 'visibility' },
    'socials':         { url: BASE + '/me/socials',  m: 'PUT',   k: 'visibility' },
    'location-approx': { url: BASE + '/me/location', m: 'PUT',   k: 'location_visibility' },
    'location-exact':  { url: BASE + '/me/location', m: 'PUT',   k: 'location_exact_visibility' }
  };
  // tiers per block, as DB-literal values (what every endpoint accepts).
  var TIERS = {
    'location-exact': ['members', 'private', 'on_request'],
    '_default':       ['public', 'members', 'private']
  };
  var LABEL = { 'public': 'Public', 'members': 'Member', 'private': 'Private', 'on_request': 'On request' };
  // restrictiveness rank; on_request is treated as restrictive as private for capping.
  var RANK = { 'public': 0, 'members': 1, 'private': 2, 'on_request': 2 };

  var openMenu = null;
  function closeMenu() { if (openMenu) { openMenu.remove(); openMenu = null; } }
  document.addEventListener('click', function (e) {
    if (openMenu && !openMenu.contains(e.target) && !e.target.closest('.lg-pmp')) closeMenu();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });

  function tiersFor(block) { return TIERS[block] || TIERS._default; }

  function buildMenu(btn) {
    var block   = btn.getAttribute('data-pmp-block');
    var current = btn.getAttribute('data-pmp-vis');
    var ceiling = btn.getAttribute('data-pmp-ceiling') || '';
    var menu = document.createElement('div');
    menu.className = 'lg-pmp-menu';
    menu.setAttribute('role', 'menu');
    menu.innerHTML = '<div class="lg-pmp-menu__head">Who can see this</div>';
    tiersFor(block).forEach(function (tier) {
      var capped = ceiling && RANK[tier] < RANK[ceiling];   // more open than the header allows
      var b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', 'menuitemradio');
      if (tier === current) b.setAttribute('aria-current', 'true');
      b.innerHTML = '<span>' + LABEL[tier] + '</span>' +
        (capped ? '<span class="cap">limited by header</span>' : '');
      b.addEventListener('click', function () {
        if (tier === current) { closeMenu(); return; }
        save(btn, block, tier);
      });
      menu.appendChild(b);
    });
    return menu;
  }

  function save(btn, block, tier) {
    var ep = EP[block]; if (!ep) return;
    var body = {}; body[ep.k] = tier;
    btn.disabled = true;
    fetch(ep.url, { method: ep.m, credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok) { location.reload(); }
        else { btn.disabled = false; alert('Could not change visibility: ' + (res.j && res.j.error || '?')); }
      })
      .catch(function () { btn.disabled = false; alert('Network error.'); });
  }

  document.querySelectorAll('.lg-pmp').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      var wasOpenFor = openMenu && openMenu._owner === btn;
      closeMenu();
      if (wasOpenFor) return;
      var menu = buildMenu(btn);
      menu._owner = btn;
      document.body.appendChild(menu);
      var r = btn.getBoundingClientRect();
      menu.style.top  = (window.scrollY + r.bottom + 6) + 'px';
      menu.style.left = (window.scrollX + Math.min(r.left, document.documentElement.clientWidth - 230)) + 'px';
      openMenu = menu;
    });
  });
})();
</script>

<script>
/* Avatar single-source uploader (owner/Me). The header renders a 📷 affordance
   (.lg-idrow__cam); clicking opens a file picker → POST the image to
   /me/avatar → the endpoint stores bytes, bumps avatar_version, sets the versioned
   served URL, and purges /whoami so mirrors re-pull. Reload to show the new image. */
(function () {
  var cam = document.querySelector('.lg-idrow__cam');
  if (!cam) return;
  var input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/jpeg,image/png,image/webp';
  input.style.display = 'none';
  document.body.appendChild(input);

  cam.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); input.click(); });
  input.addEventListener('change', function () {
    if (!input.files || !input.files[0]) return;
    var f = input.files[0];
    if (f.size > 5 * 1024 * 1024) { alert('Image too large (max 5 MB).'); input.value = ''; return; }
    var fd = new FormData();
    fd.append('avatar', f);
    cam.textContent = '…';
    fetch('/profile-api/v0/me/avatar', { method: 'POST', credentials: 'include', body: fd })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok) { location.reload(); }
        else { cam.textContent = '📷'; alert('Upload failed: ' + (res.j && res.j.error || '?')); }
      })
      .catch(function () { cam.textContent = '📷'; alert('Network error.'); });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
