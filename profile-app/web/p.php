<?php
declare(strict_types=1);

/**
 * /p/<slug> — practice page, BLOCK-MODEL render (parallel to web/u.php).
 *
 * Renders via looth_render_practice_blocks() (practice-header gate + block loop),
 * the same header-as-ceiling model as profiles. View-as (owner only) previews
 * Public / Member / Me by driving the one renderer with the selected role.
 * Practice owner = practices.created_by (or practice_members role='owner').
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_render_blocks.php';   // looth_render_practice_blocks + Block + looth_h/initials

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;
use Looth\ProfileApp\Db;

$slug = $_GET['slug'] ?? '';
if (!is_string($slug) || $slug === '') { http_response_code(404); echo 'not found'; exit; }

$pg = Db::pg();
$q = $pg->prepare('SELECT id, name, slug FROM practices WHERE slug = :s AND archived_at IS NULL');
$q->execute([':s' => $slug]);
$row = $q->fetch();
if (!$row && ctype_digit($slug)) {
    $q = $pg->prepare('SELECT id, name, slug FROM practices WHERE id = :i AND archived_at IS NULL');
    $q->execute([':i' => (int)$slug]);
    $row = $q->fetch();
}
if (!$row) { http_response_code(404); echo 'not found'; exit; }

$practiceId = (int) $row['id'];
$viewer     = Auth::currentUser();
$isOwner    = $viewer && Block::isPracticeOwner($practiceId, (int) $viewer['id']);

if ($isOwner) {
    $view = $_GET['view'] ?? 'me';
    $role = in_array($view, ['public', 'member', 'me'], true) ? $view : 'me';
} else {
    $role = $viewer ? 'member' : 'public';
}

$tierBadge   = null;   // practice tier badge n/a (no per-subject tier source yet)
$name        = (string) ($row['name'] ?: 'Practice');
$slugSafe    = (string) ($row['slug'] ?: (string)$practiceId);
$viewLink = fn(string $v): string => '/p/' . rawurlencode($slugSafe) . '?view=' . $v;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= looth_h($name) ?> · Looth</title>
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<style>
body{margin:0;background:var(--lg-cream);color:var(--lg-ink);font-family:var(--lg-font-sans);font-size:15px;line-height:1.6}
/* The View-as bar and the first practice-header block are direct children of
   .lg-profile here (no wrapping shell like u.php). Establishing flow-root
   localises margin-collapse to the children and lets the viewas's margin-bottom
   actually render — fixes the same gap bug noted in briefing-profile-editor.md. */
.lg-profile{max-width:760px;margin:0 auto;padding:24px 20px 48px;display:flow-root}

/* View-as toggle (owner only) — margin-bottom now reliable because .lg-profile is a flow-root. */
.lg-viewas{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--lg-charcoal);color:#cfd3cb;
  border-radius:12px;padding:10px 14px;margin:0 0 20px;font:600 12.5px/1 var(--lg-font-sans)}
.lg-viewas__label{font-weight:700}
.lg-viewas__seg{display:flex;border:1px solid rgba(255,255,255,.18);border-radius:999px;overflow:hidden}
.lg-viewas__seg a{padding:6px 14px;color:#cfd3cb;text-decoration:none;font:700 12px/1 var(--lg-font-sans)}
.lg-viewas__seg a[aria-current="true"]{background:var(--lg-amber);color:#4a3c10}
.lg-viewas__edit{margin-left:auto;background:#fff;color:var(--lg-ink);border-radius:999px;padding:7px 15px;text-decoration:none;font:700 12.5px/1 var(--lg-font-sans)}
.lg-viewas__hint{flex-basis:100%;font:500 11px/1.4 var(--lg-font-sans);color:#9aa091}

/* block shell */
.lg-block{position:relative;background:#fff;border:1px solid var(--lg-line);border-radius:16px;padding:22px 24px;margin:0 0 16px}
.lg-bh{margin:0 0 12px;font:800 16px/1 var(--lg-font-serif);color:var(--lg-charcoal)}
.lg-vchip{display:inline-block;vertical-align:middle;font:800 9px/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;border-radius:5px;padding:3px 7px;margin-left:6px}
.lg-block--practice-header>.lg-vchip{position:absolute;top:14px;right:16px;margin:0}
.lg-vchip--public{background:var(--lg-sage-tint);color:var(--lg-sage-d)}
.lg-vchip--member{background:#fdf0d8;color:#8a6326}
.lg-vchip--private{background:#f0e6e2;color:var(--lg-rust)}

/* practice identity card */
.lg-idrow{display:flex;gap:20px;align-items:center}
.lg-idrow__pic{width:96px;height:96px;border-radius:18px;flex:none;background:var(--lg-sage);color:#fff;
  display:grid;place-items:center;font:700 34px/1 var(--lg-font-serif);overflow:hidden}
.lg-idrow__pic img{width:100%;height:100%;object-fit:cover;border-radius:18px}
.lg-idrow__name{margin:0;font:800 28px/1.1 var(--lg-font-serif);color:var(--lg-charcoal);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.lg-ptype{font:800 10px/1 var(--lg-font-sans);letter-spacing:.08em;text-transform:uppercase;background:var(--lg-charcoal);color:#fff;border-radius:999px;padding:5px 10px}
.lg-tierpill{font:800 10px/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;background:var(--lg-amber);color:#4a3c10;border-radius:6px;padding:4px 9px}
.lg-idrow__glance{font-size:16px;margin:6px 0 0;color:var(--lg-ink)}
.lg-loc__line{display:flex;align-items:center;gap:9px;font-size:15px;color:var(--lg-ink)}
.lg-idrow__web{font:600 13.5px/1 var(--lg-font-sans);color:var(--lg-rust);margin-top:9px;display:inline-block;text-decoration:none}

/* members gate */
.lg-gate{text-align:center;background:#fff;border:1px solid var(--lg-line);border-radius:18px;padding:48px 30px;margin:0 0 16px}
.lg-gate__lock{width:64px;height:64px;border-radius:50%;background:var(--lg-sage-tint);display:grid;place-items:center;margin:0 auto 16px;font-size:28px}
.lg-gate h2{margin:0 0 8px;font:800 22px/1.2 var(--lg-font-serif);color:var(--lg-charcoal)}
.lg-gate p{margin:0 auto 20px;max-width:420px;color:var(--lg-mute);font-size:14.5px}
.lg-gate__cta{display:inline-flex;gap:10px}
.lg-gate__join{background:var(--lg-amber);color:#4a3c10;text-decoration:none;font:800 14px/1 var(--lg-font-sans);border-radius:999px;padding:12px 22px}
.lg-gate__signin{border:1px solid var(--lg-line);color:var(--lg-ink);text-decoration:none;font:700 14px/1 var(--lg-font-sans);border-radius:999px;padding:12px 22px}

/* interactive pmp control */
.lg-pmp{cursor:pointer;border:0;font-family:inherit;display:inline-flex;align-items:center;gap:4px}
.lg-pmp:hover{filter:brightness(.95)}
.lg-pmp__caret{font-size:8px;opacity:.8}
.lg-pmp-menu{position:absolute;z-index:60;min-width:210px;background:#fff;border:1px solid var(--lg-line);border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,.14);padding:6px}
.lg-pmp-menu__head{font:700 10px/1.3 var(--lg-font-sans);text-transform:uppercase;letter-spacing:.06em;color:var(--lg-mute);padding:7px 9px 5px}
.lg-pmp-menu button{display:flex;width:100%;align-items:center;justify-content:space-between;gap:10px;border:0;background:none;cursor:pointer;padding:8px 9px;border-radius:7px;text-align:left;font:600 13px/1.2 var(--lg-font-sans);color:var(--lg-ink)}
.lg-pmp-menu button:hover{background:var(--lg-sage-tint)}
.lg-pmp-menu button[aria-current="true"]{font-weight:800;color:var(--lg-sage-d)}
.lg-pmp-menu button[aria-current="true"]::after{content:"✓";color:var(--lg-sage-d)}
@media(max-width:560px){.lg-idrow{flex-direction:column;text-align:center;align-items:center}}
</style>
</head>
<body class="mode-view">
<?php require __DIR__ . '/_chrome.php'; ?>

<main class="main" id="lg-main">
  <div class="lg-profile">

    <?php if ($isOwner): ?>
      <div class="lg-viewas" role="group" aria-label="Preview your practice as">
        <span class="lg-viewas__label">👁 View as</span>
        <span class="lg-viewas__seg">
          <a href="<?= looth_h($viewLink('public')) ?>" <?= $role==='public'?'aria-current="true"':'' ?>>Public</a>
          <a href="<?= looth_h($viewLink('member')) ?>" <?= $role==='member'?'aria-current="true"':'' ?>>Member</a>
          <a href="<?= looth_h($viewLink('me')) ?>"     <?= $role==='me'?'aria-current="true"':'' ?>>Me</a>
        </span>
        <a class="lg-viewas__edit" href="/profile/edit">Edit profile</a>
        <span class="lg-viewas__hint">Preview how this practice looks to each audience. “Public” shows the members-gate when the header is members-only.</span>
      </div>
    <?php endif; ?>

    <?php looth_render_practice_blocks($practiceId, $role, $tierBadge); ?>

  </div>
</main>

<?php lg_shared_render_site_footer(); ?>

<?php if ($isOwner): ?>
<script>
/* Inline pmp control for the practice-header (owner/Me). Same menu pattern as
   u.php; only the practice-header block exists on /p/ this turn. Persists via
   PATCH /me/practice-header?practice=<id>, then reloads to keep the gate honest. */
(function () {
  var BASE = '/profile-api/v0', PID = <?= $practiceId ?>;
  var URL = BASE + '/me/practice-header?practice=' + PID;
  var TIERS = ['public', 'members', 'private'];
  var LABEL = { 'public': 'Public', 'members': 'Member', 'private': 'Private' };

  var openMenu = null;
  function close() { if (openMenu) { openMenu.remove(); openMenu = null; } }
  document.addEventListener('click', function (e) {
    if (openMenu && !openMenu.contains(e.target) && !e.target.closest('.lg-pmp')) close();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

  function save(btn, tier) {
    btn.disabled = true;
    fetch(URL, { method: 'PATCH', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ visibility: tier }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) { if (res.ok) location.reload(); else { btn.disabled = false; alert('Could not change visibility: ' + (res.j && res.j.error || '?')); } })
      .catch(function () { btn.disabled = false; alert('Network error.'); });
  }

  document.querySelectorAll('.lg-pmp[data-pmp-block="practice-header"]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      var wasOpen = openMenu && openMenu._owner === btn; close(); if (wasOpen) return;
      var current = btn.getAttribute('data-pmp-vis');
      var menu = document.createElement('div'); menu.className = 'lg-pmp-menu'; menu.setAttribute('role', 'menu');
      menu.innerHTML = '<div class="lg-pmp-menu__head">Who can see this practice</div>';
      TIERS.forEach(function (tier) {
        var b = document.createElement('button'); b.type = 'button';
        if (tier === current) b.setAttribute('aria-current', 'true');
        b.innerHTML = '<span>' + LABEL[tier] + '</span>';
        b.addEventListener('click', function () { if (tier === current) { close(); return; } save(btn, tier); });
        menu.appendChild(b);
      });
      menu._owner = btn; document.body.appendChild(menu);
      var r = btn.getBoundingClientRect();
      menu.style.top = (window.scrollY + r.bottom + 6) + 'px';
      menu.style.left = (window.scrollX + Math.min(r.left, document.documentElement.clientWidth - 230)) + 'px';
      openMenu = menu;
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
