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
.lg-loc__map,.lg-loc__pin{margin-top:12px;height:200px;border-radius:12px;border:1px solid var(--lg-line);
  overflow:hidden;background:var(--lg-sage-tint);
  position:relative;isolation:isolate;z-index:0}   /* contain Leaflet's z-index so it can't cover the header or menus */
.lg-loc__map .leaflet-container,.lg-loc__pin .leaflet-container{height:100%;border-radius:12px;font:inherit}
/* location audience precision controls (owner) */
.lg-loc__line{display:flex;align-items:center;gap:9px;font-size:15px;color:var(--lg-ink)}
.lg-loc__aud{display:flex;flex-wrap:wrap;gap:10px 22px;margin-top:14px;padding-top:13px;border-top:1px dashed var(--lg-line)}
.lg-loc__audrow{display:inline-flex;align-items:center;gap:8px}
.lg-loc__audlabel{font:700 12px/1 var(--lg-font-sans);color:var(--lg-mute)}
.lg-prec{cursor:pointer;border:1px solid var(--lg-line);background:#fff;border-radius:999px;padding:6px 13px;
  font:700 12.5px/1 var(--lg-font-sans);color:var(--lg-ink);display:inline-flex;align-items:center;gap:5px}
.lg-prec:hover{background:var(--lg-sage-tint);border-color:var(--lg-sage)}

/* craft chips */
.lg-chips{display:flex;flex-wrap:wrap;gap:0}
.lg-chip{display:inline-block;background:var(--lg-cream);border:1px solid var(--lg-line);border-radius:8px;padding:5px 12px;margin:0 7px 8px 0;font-size:13.5px}

/* socials / links */
.lg-socrow{display:flex;gap:9px;flex-wrap:wrap}
.lg-socrow__a{display:inline-flex;align-items:center;height:32px;padding:0 12px;border-radius:8px;background:var(--lg-sage-tint);
  color:var(--lg-sage-d);font:700 12.5px/1 var(--lg-font-sans);text-decoration:none}
.lg-socrow__a:hover{background:var(--lg-sage-3)}
/* links editor (owner) */
.lg-links{display:flex;flex-direction:column;gap:8px;align-items:flex-start}
.lg-link{display:inline-flex;align-items:center;gap:10px;background:var(--lg-cream);border:1px solid var(--lg-line);border-radius:10px;padding:7px 8px 7px 12px}
.lg-link__kind{font:800 9px/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;color:var(--lg-sage-d);background:var(--lg-sage-tint);border-radius:5px;padding:3px 6px}
.lg-link__val{font:600 13px/1 var(--lg-font-sans);color:var(--lg-ink)}
.lg-link__rm{border:0;background:none;cursor:pointer;color:var(--lg-mute);font-size:18px;line-height:1;padding:0 4px}
.lg-link__rm:hover{color:var(--lg-rust)}
.lg-link__add{align-self:flex-start;border:1px dashed var(--lg-sage-3);background:none;cursor:pointer;border-radius:999px;padding:6px 14px;font:700 12.5px/1 var(--lg-font-sans);color:var(--lg-sage-d)}
.lg-link__add:hover{background:var(--lg-sage-tint);border-color:var(--lg-sage)}
.lg-link-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.lg-link-form select,.lg-link-form input{border:1px solid var(--lg-line);border-radius:8px;padding:7px 10px;font:600 13px/1 var(--lg-font-sans)}
.lg-link-form button{border:0;border-radius:8px;padding:8px 14px;font:700 12.5px/1 var(--lg-font-sans);cursor:pointer}
.lg-link-form .ok{background:var(--lg-sage);color:#fff}
.lg-link-form .cancel{background:var(--lg-cream);border:1px solid var(--lg-line);color:var(--lg-ink)}
/* craft editor (owner) — removable chips + search multiselect */
.lg-chip--edit{display:inline-flex;align-items:center;gap:6px;padding-right:7px}
.lg-chip__rm{border:0;background:none;cursor:pointer;color:var(--lg-mute);font-size:15px;line-height:1;padding:0 2px}
.lg-chip__rm:hover{color:var(--lg-rust)}
.lg-craft-search{position:relative;display:inline-block;margin:0 0 8px}
.lg-craft-search input{border:1px solid var(--lg-sage);border-radius:999px;padding:7px 14px;font:600 13px/1 var(--lg-font-sans);min-width:250px;outline:none}
.lg-craft-results{position:absolute;z-index:1000;top:calc(100% + 4px);left:0;min-width:290px;max-height:300px;overflow:auto;
  background:#fff;border:1px solid var(--lg-line);border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,.14);padding:6px}
.lg-craft-results button{display:flex;width:100%;align-items:center;justify-content:space-between;gap:10px;border:0;background:none;cursor:pointer;
  padding:8px 10px;border-radius:7px;text-align:left;font:600 13px/1.2 var(--lg-font-sans);color:var(--lg-ink)}
.lg-craft-results button:hover{background:var(--lg-sage-tint)}
.lg-craft-results .t{font:700 9px/1 var(--lg-font-sans);text-transform:uppercase;letter-spacing:.06em;color:var(--lg-mute)}
.lg-craft-results .added{font:700 9px/1 var(--lg-font-sans);text-transform:uppercase;letter-spacing:.06em;color:var(--lg-sage-d)}
.lg-craft-results .none{padding:8px 10px;color:var(--lg-mute);font-size:12.5px}

/* connect block */
.lg-connect__count{display:inline-block;background:var(--lg-sage-tint);color:var(--lg-sage-d);font:800 11px/1 var(--lg-font-sans);border-radius:999px;padding:3px 9px;margin-left:4px;vertical-align:middle}
.lg-connect__pending{display:inline-block;margin:0 0 10px;font:700 12.5px/1 var(--lg-font-sans);color:var(--lg-rust);text-decoration:none}
.lg-connect__mutual{margin:0 0 10px;font:600 13px/1.3 var(--lg-font-sans);color:var(--lg-sage-d)}
.lg-connect__grid{display:flex;flex-wrap:wrap;gap:8px}
.lg-connect__person{text-decoration:none}
.lg-connect__av{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;overflow:hidden;
  background:var(--lg-sage);color:#fff;font:700 15px/1 var(--lg-font-serif)}
.lg-connect__av img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.lg-connect__empty{margin:0;font-size:13.5px;color:var(--lg-mute)}

/* inline content editing (owner/Me view) */
.lg-edit{cursor:text;border-radius:6px;outline:none;transition:background .12s,box-shadow .12s;padding:0 4px;margin:0 -4px}
.lg-edit:hover{background:var(--lg-sage-tint);box-shadow:0 0 0 3px var(--lg-sage-tint)}
.lg-edit:hover::after{content:" ✎";font-size:.7em;color:var(--lg-sage-d);opacity:.7}
.lg-edit--empty{color:var(--lg-mute);font-style:italic;font-weight:500}
.lg-edit.editing{background:#fff;box-shadow:0 0 0 2px var(--lg-sage);font-style:normal;color:var(--lg-ink)}
.lg-edit.editing::after{content:none}
.lg-edit.saved{box-shadow:0 0 0 2px var(--lg-sage-3)}
.lg-about{font-size:14.5px;line-height:1.6;color:var(--lg-ink);white-space:pre-wrap;max-width:640px}
.lg-about.lg-edit{min-height:1.5em;display:block;padding:6px 8px;margin:0 -8px}

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
.lg-pmp-menu{position:absolute;z-index:1000;min-width:210px;background:#fff;border:1px solid var(--lg-line);
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
        <a class="lg-viewas__edit" href="/profile/edit">Edit details (legacy)</a>
        <span class="lg-viewas__hint">This IS your editor — click any field (name, tagline, the 📷, the privacy chips) to edit it in place. “Edit details (legacy)” is the old form, still needed for fields that aren’t inline yet (location, skills, links).</span>
      </div>
    <?php endif; ?>

    <?php looth_render_profile_blocks($subjectId, $role, $tierBadge, $socialActions, $viewer ? (int)$viewer['id'] : null); ?>

    <?php if (!$isOwner): ?>
      <a class="lg-report" href="#" id="report-link">Report this profile</a>
    <?php endif; ?>
  </div>
</main>

<?php lg_shared_render_site_footer(); ?>

<script>
/* Real map for the location block — Leaflet + OSM tiles (CDN, no WP, no API key).
   ONE map per location block; data-kind="exact" plots the precise pin (marker),
   data-kind="approx" plots the coarse town-level dot (circle). Which one renders
   follows the viewer's permission (use View-as to preview each audience). */
window.addEventListener('load', function () {
  if (typeof L === 'undefined') return;
  document.querySelectorAll('.lg-loc__map[data-lat]').forEach(function (el) {
    var lat = parseFloat(el.getAttribute('data-lat')), lng = parseFloat(el.getAttribute('data-lng'));
    if (isNaN(lat) || isNaN(lng)) return;
    var exact = el.getAttribute('data-kind') === 'exact';
    var zoom  = parseInt(el.getAttribute('data-zoom'), 10) || (exact ? 15 : 11);
    var map = L.map(el, { zoomControl: false, scrollWheelZoom: false, dragging: false,
      doubleClickZoom: false, boxZoom: false, keyboard: false }).setView([lat, lng], zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
      { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
    if (exact) { L.marker([lat, lng]).addTo(map); }
    else {
      var rad = zoom <= 8 ? 35000 : 1500;   // state-level vs town-level blur
      L.circle([lat, lng], { radius: rad, color: '#87986a', fillColor: '#87986a', fillOpacity: 0.18, weight: 1 }).addTo(map);
    }
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
    'connect':         { url: BASE + '/me/connect',  m: 'PATCH', k: 'visibility' },
    'about':           { url: BASE + '/me/about',    m: 'PATCH', k: 'visibility' },
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
/* Location precision pickers (owner/Me) — "Members see" / "Public sees", each set
   to private|state|city|street, persisted via PUT /me/location, then reload. */
(function () {
  var LEVELS = ['private', 'state', 'city', 'street'];
  var LABEL = { private: 'Private', state: 'State', city: 'City', street: 'Street address' };
  var open = null;
  function close() { if (open) { open.remove(); open = null; } }
  document.addEventListener('click', function (e) {
    if (open && !open.contains(e.target) && !e.target.closest('.lg-prec')) close();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

  function save(aud, value) {
    var body = {}; body[aud + '_precision'] = value;
    fetch('/profile-api/v0/me/location', { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) { if (res.ok) location.reload(); else alert('Failed: ' + (res.j && res.j.error || '?')); })
      .catch(function () { alert('Network error.'); });
  }

  document.querySelectorAll('.lg-prec').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      var wasOpen = open && open._owner === btn; close(); if (wasOpen) return;
      var aud = btn.getAttribute('data-prec-aud'), cur = btn.getAttribute('data-prec');
      var menu = document.createElement('div'); menu.className = 'lg-pmp-menu'; menu.setAttribute('role', 'menu');
      menu.innerHTML = '<div class="lg-pmp-menu__head">What ' + aud + ' see</div>';
      LEVELS.forEach(function (lv) {
        var b = document.createElement('button'); b.type = 'button';
        if (lv === cur) b.setAttribute('aria-current', 'true');
        b.innerHTML = '<span>' + LABEL[lv] + '</span>';
        b.addEventListener('click', function () { if (lv === cur) { close(); return; } save(aud, lv); });
        menu.appendChild(b);
      });
      menu._owner = btn; document.body.appendChild(menu);
      var r = btn.getBoundingClientRect();
      menu.style.top = (window.scrollY + r.bottom + 6) + 'px';
      menu.style.left = (window.scrollX + Math.min(r.left, document.documentElement.clientWidth - 230)) + 'px';
      open = menu;
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

<script>
/* Inline content editing (owner/Me view) — the start of the composer. Click any
   .lg-edit field → it becomes contentEditable → Enter/blur saves via the field's
   own /me/* endpoint (already green); Esc cancels. Empty fields show a placeholder. */
(function () {
  function caretEnd(el) {
    var r = document.createRange(); r.selectNodeContents(el); r.collapse(false);
    var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
  }
  function restorePlaceholder(el) {
    var ph = el.getAttribute('data-edit-placeholder') || '';
    if (ph && el.textContent.trim() === '') { el.textContent = ph; el.classList.add('lg-edit--empty'); }
  }
  function finish(el) { el.contentEditable = 'false'; el.classList.remove('editing'); }

  function save(el, val, orig) {
    var field = el.getAttribute('data-edit-field');
    if (field === 'display_name' && val === '') { el.textContent = orig; finish(el); return; } // name required
    var body = {}; body[field] = val;
    fetch(el.getAttribute('data-edit-url'), {
      method: el.getAttribute('data-edit-method') || 'PATCH', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        finish(el);
        if (res.ok) { el.classList.add('saved'); setTimeout(function () { el.classList.remove('saved'); }, 900); }
        else { el.textContent = orig; alert('Save failed: ' + (res.j && res.j.error || '?')); }
        restorePlaceholder(el);
      })
      .catch(function () { finish(el); el.textContent = orig; restorePlaceholder(el); alert('Network error.'); });
  }

  function valOf(el) { return (el.hasAttribute('data-edit-multiline') ? el.innerText : el.textContent).trim(); }
  function onKey(e) {
    var el = e.target;
    if (e.key === 'Enter' && !el.hasAttribute('data-edit-multiline')) { e.preventDefault(); el.blur(); } // multiline keeps Enter as newline
    else if (e.key === 'Escape') {
      e.preventDefault();
      el.removeEventListener('keydown', onKey);
      el.textContent = el.dataset.orig || ''; finish(el); restorePlaceholder(el);
    }
  }

  document.querySelectorAll('.lg-edit[data-edit-field]').forEach(function (el) {
    el.setAttribute('title', 'Click to edit');
    el.addEventListener('click', function () {
      if (el.classList.contains('editing')) return;
      var wasEmpty = el.classList.contains('lg-edit--empty');
      el.dataset.orig = wasEmpty ? '' : valOf(el);
      if (wasEmpty) { el.textContent = ''; el.classList.remove('lg-edit--empty'); }
      el.classList.add('editing'); el.contentEditable = 'true'; el.focus(); caretEnd(el);
      el.addEventListener('keydown', onKey);
      el.addEventListener('blur', function onBlur(e) {
        el.removeEventListener('keydown', onKey); el.removeEventListener('blur', onBlur);
        var val = valOf(el), orig = el.dataset.orig || '';
        if (val === orig) { finish(el); restorePlaceholder(el); } else { save(el, val, orig); }
      });
    });
  });
})();
</script>

<script>
/* Links editor (owner/Me) — add/remove links; PUT the whole list to me-socials → reload. */
(function () {
  var KINDS = ['web','instagram','youtube','x','facebook','tiktok','bandcamp','patreon','linktree','email','phone'];
  var wrap = document.getElementById('lg-links-edit');
  if (!wrap) return;

  function collect() {
    return Array.prototype.map.call(wrap.querySelectorAll('.lg-link'), function (el, i) {
      return { kind: el.getAttribute('data-kind'), value: el.getAttribute('data-value'), sort_order: i };
    });
  }
  function put(items) {
    fetch('/profile-api/v0/me/socials', { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ items: items }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) { if (res.ok) location.reload(); else alert('Save failed: ' + (res.j && res.j.error || '?')); })
      .catch(function () { alert('Network error.'); });
  }

  wrap.addEventListener('click', function (e) {
    var rm = e.target.closest('.lg-link__rm');
    if (rm) { rm.closest('.lg-link').remove(); put(collect()); }
  });

  var addBtn = document.getElementById('lg-link-add');
  addBtn && addBtn.addEventListener('click', function () {
    if (document.querySelector('.lg-link-form')) return;
    var form = document.createElement('div'); form.className = 'lg-link-form';
    var sel = document.createElement('select');
    KINDS.forEach(function (k) { var o = document.createElement('option'); o.value = k; o.textContent = k; sel.appendChild(o); });
    var inp = document.createElement('input'); inp.type = 'text'; inp.placeholder = 'handle or URL';
    var ok = document.createElement('button'); ok.className = 'ok'; ok.textContent = 'Add';
    var cancel = document.createElement('button'); cancel.className = 'cancel'; cancel.textContent = 'Cancel';
    form.appendChild(sel); form.appendChild(inp); form.appendChild(ok); form.appendChild(cancel);
    addBtn.parentNode.insertBefore(form, addBtn);
    inp.focus();
    cancel.addEventListener('click', function () { form.remove(); });
    ok.addEventListener('click', function () {
      var v = inp.value.trim(); if (!v) { inp.focus(); return; }
      var items = collect(); items.push({ kind: sel.value, value: v, sort_order: items.length });
      put(items);
    });
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') ok.click(); else if (e.key === 'Escape') cancel.click(); });
  });
})();
</script>

<script>
/* Craft editor (owner/Me) — removable chips + a search MULTISELECT over the skill
   + instrument catalogs. Click results to add (chips appear instantly, picker stays
   open); ✕ on a chip removes. Each change PUTs the full list to me-skills / me-instruments. */
(function () {
  var wrap = document.getElementById('lg-craft-edit');
  if (!wrap) return;
  var addBtn = document.getElementById('lg-craft-add');
  var CATALOG = null;

  function idsOf(type) {
    return Array.prototype.map.call(wrap.querySelectorAll('.lg-chip--edit[data-type="' + type + '"]'),
      function (el) { return parseInt(el.getAttribute('data-id'), 10); });
  }
  function put(type) {
    var url = type === 'skill' ? '/profile-api/v0/me/skills' : '/profile-api/v0/me/instruments';
    var key = type === 'skill' ? 'skill_id' : 'instrument_id';
    var items = idsOf(type).map(function (id, i) { var o = {}; o[key] = id; o.sort_order = i; return o; });
    return fetch(url, { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ items: items }) })
      .then(function (r) { return r.ok; });
  }
  function makeChip(type, id, name) {
    var s = document.createElement('span'); s.className = 'lg-chip lg-chip--edit';
    s.setAttribute('data-type', type); s.setAttribute('data-id', id); s.textContent = name;
    var b = document.createElement('button'); b.type = 'button'; b.className = 'lg-chip__rm';
    b.setAttribute('aria-label', 'Remove'); b.textContent = '×'; s.appendChild(b);
    return s;
  }
  function loadCatalog() {
    if (CATALOG) return Promise.resolve(CATALOG);
    return Promise.all([
      fetch('/profile-api/v0/catalogs/skills', { credentials: 'include' }).then(function (r) { return r.json(); }),
      fetch('/profile-api/v0/catalogs/instruments', { credentials: 'include' }).then(function (r) { return r.json(); })
    ]).then(function (res) {
      var map = function (arr, t) { return (arr || []).map(function (x) { return { type: t, id: x.id, name: x.name, lc: (x.name || '').toLowerCase() }; }); };
      CATALOG = map(res[0].items, 'skill').concat(map(res[1].items, 'instrument'));
      return CATALOG;
    });
  }

  wrap.addEventListener('click', function (e) {
    var rm = e.target.closest('.lg-chip__rm'); if (!rm) return;
    var chip = rm.closest('.lg-chip--edit'); var type = chip.getAttribute('data-type');
    chip.remove();
    put(type).then(function (ok) { if (!ok) { alert('Remove failed'); location.reload(); } });
  });

  addBtn.addEventListener('click', function () {
    if (document.querySelector('.lg-craft-search')) return;
    var box = document.createElement('span'); box.className = 'lg-craft-search';
    var inp = document.createElement('input'); inp.type = 'text'; inp.placeholder = 'Search skills & instruments…';
    var res = document.createElement('div'); res.className = 'lg-craft-results'; res.style.display = 'none';
    box.appendChild(inp); box.appendChild(res);
    addBtn.parentNode.insertBefore(box, addBtn);
    addBtn.style.display = 'none'; inp.focus();

    function has(type, id) { return idsOf(type).indexOf(id) !== -1; }
    function render() {
      loadCatalog().then(function (cat) {
        var q = inp.value.trim().toLowerCase();
        res.innerHTML = '';
        if (q === '') { res.style.display = 'none'; return; }
        var matches = cat.filter(function (c) { return c.lc.indexOf(q) !== -1; }).slice(0, 40);
        if (!matches.length) { res.innerHTML = '<div class="none">No matches</div>'; res.style.display = 'block'; return; }
        matches.forEach(function (m) {
          var added = has(m.type, m.id);
          var b = document.createElement('button'); b.type = 'button';
          b.innerHTML = '<span>' + m.name + '</span><span class="' + (added ? 'added' : 't') + '">' + (added ? '✓ added' : m.type) + '</span>';
          if (!added) b.addEventListener('click', function () {
            var chip = makeChip(m.type, m.id, m.name);
            box.parentNode.insertBefore(chip, box);
            put(m.type).then(function (ok) { if (!ok) { chip.remove(); alert('Add failed'); } });
            render(); inp.focus();
          });
          res.appendChild(b);
        });
        res.style.display = 'block';
      });
    }
    inp.addEventListener('input', render);
    inp.addEventListener('keydown', function (e) { if (e.key === 'Escape') { box.remove(); addBtn.style.display = ''; } });
    document.addEventListener('click', function onDoc(e) {
      if (!box.contains(e.target) && e.target !== addBtn) { box.remove(); addBtn.style.display = ''; document.removeEventListener('click', onDoc); }
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
