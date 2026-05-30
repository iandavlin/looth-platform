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
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= looth_h($displayName) ?> · Looth</title>
<link rel="stylesheet" href="/lg-shared/site-header.css">
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
.lg-loc__map,.lg-loc__pin{margin-top:12px;height:120px;border-radius:12px;border:1px solid var(--lg-line);
  background:linear-gradient(0deg,rgba(135,152,106,.08),rgba(135,152,106,.08)),
  repeating-linear-gradient(0deg,var(--lg-line) 0 1px,transparent 1px 24px),
  repeating-linear-gradient(90deg,var(--lg-line) 0 1px,transparent 1px 24px),var(--lg-sage-tint)}

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

    <?php looth_render_profile_blocks($subjectId, $role, $tierBadge); ?>

    <?php if (!$isOwner): ?>
      <a class="lg-report" href="#" id="report-link">Report this profile</a>
    <?php endif; ?>
  </div>
</main>

<?php lg_shared_render_site_footer(); ?>
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
</body>
</html>
