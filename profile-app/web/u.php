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
use Looth\ProfileApp\Block;   // composable-layout caddy (availableBlocks + LAYOUT_BLOCKS)

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
looth_issue_bounce_if_needed();   // mint looth_id for logged-in WP users who land here without one
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
.lg-shell{max-width:760px;margin:0 auto;padding:24px 20px 48px}
.lg-profile{min-width:0}
/* The profile (with the View-as bar right above it) stays centered on the page; on wide screens
   the block sidebar floats off to the LEFT of that centered column (see the .lg-caddy rule). */

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
.lg-idrow__pic{width:96px;height:96px;border-radius:16px;flex:none;background:var(--lg-sage);color:#fff;
  display:grid;place-items:center;font:700 34px/1 var(--lg-font-serif);position:relative;overflow:hidden}
.lg-idrow__pic img{width:100%;height:100%;object-fit:cover;border-radius:16px}
.lg-idrow__cam{position:absolute;right:0;bottom:0;width:30px;height:30px;border-radius:50%;background:#fff;
  border:1px solid var(--lg-line);cursor:pointer;font-size:14px;line-height:1}
.lg-idrow__name{margin:0;font:800 28px/1.1 var(--lg-font-serif);color:var(--lg-charcoal);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.lg-tierpill{font:800 10px/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;background:var(--lg-amber);color:#4a3c10;border-radius:6px;padding:4px 9px}
.lg-idrow__glance{font-size:16px;margin:12px 0 0;color:var(--lg-ink)}
/* the owner's tagline is also .lg-edit (margin:0 -4px), which would zero the gap above — keep it */
.lg-idrow__body .lg-idrow__glance{margin-top:12px}

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
.lg-loc__empty{font-size:13.5px;color:var(--lg-mute);margin:10px 0 0}
.lg-loc__edit{position:relative;margin-top:12px}
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
.lg-link{display:inline-flex;align-items:center;gap:10px;background:var(--lg-cream);border:1px solid var(--lg-line);border-radius:10px;padding:7px 8px 7px 10px}
.lg-link[draggable="true"]{cursor:grab}
.lg-sort-dragging{opacity:.45}
.lg-link.lg-sort-dragging{cursor:grabbing}
/* whole-block reorder grip (owner/Me) */
.lg-block__grip{display:inline-block;cursor:grab;color:var(--lg-mute);font-size:13px;line-height:1;letter-spacing:-2px;vertical-align:middle;margin-right:8px;user-select:none}
.lg-block__grip:hover{color:var(--lg-sage-d)}
.lg-block.lg-sort-dragging{cursor:grabbing;outline:2px dashed var(--lg-sage-3);outline-offset:2px}
/* per-block remove (owner) — injected next to the grip */
.lg-block__rm{display:inline-block;border:0;background:none;cursor:pointer;color:var(--lg-mute);font:700 15px/1 var(--lg-font-sans);padding:0 4px;vertical-align:middle;margin-left:2px}
.lg-block__rm:hover{color:var(--lg-rust)}
/* drop indicator while dragging a caddy block onto the profile */
.lg-block--drop-before{box-shadow:0 -3px 0 0 var(--lg-sage)}
.lg-block--drop-after{box-shadow:0 3px 0 0 var(--lg-sage)}
/* ✚ Blocks toggle in the View-as bar */
.lg-viewas__caddy{background:var(--lg-amber);color:#4a3c10;border:0;border-radius:999px;padding:6px 14px;font:800 12px/1 var(--lg-font-sans);cursor:pointer}
.lg-viewas__caddy:hover{filter:brightness(1.06)}
/* caddy panel — slide-in from the right on desktop; off-canvas drawer on mobile */
.lg-caddy{position:fixed;top:0;right:0;height:100vh;width:300px;max-width:86vw;background:#fff;border-left:1px solid var(--lg-line);
  box-shadow:-12px 0 36px rgba(0,0,0,.14);transform:translateX(102%);transition:transform .22s ease;z-index:1200;display:flex;flex-direction:column;padding:18px}
.lg-caddy.is-open{transform:none}
.lg-caddy__backdrop{position:fixed;inset:0;background:rgba(20,22,18,.34);z-index:1190;opacity:0;transition:opacity .22s}
.lg-caddy__backdrop.is-open{opacity:1}
.lg-caddy__head{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
.lg-caddy__head strong{font:800 16px/1 var(--lg-font-serif);color:var(--lg-charcoal)}
.lg-caddy__close{border:0;background:none;font-size:24px;line-height:1;color:var(--lg-mute);cursor:pointer}
.lg-caddy__close:hover{color:var(--lg-ink)}
.lg-caddy__hint{font:500 11.5px/1.5 var(--lg-font-sans);color:var(--lg-mute);margin:0 0 14px}
.lg-caddy__list{display:flex;flex-direction:column;gap:8px;overflow-y:auto}
.lg-caddy__item{display:flex;flex-direction:column;align-items:stretch;gap:7px;text-align:left;background:#fff;
  border:1px solid var(--lg-line);border-radius:11px;padding:8px;cursor:grab}
.lg-caddy__item:hover{border-color:var(--lg-sage);box-shadow:0 1px 3px rgba(0,0,0,.05)}
.lg-caddy__item:hover .lg-caddy__preview{background:var(--lg-sage-tint)}
.lg-caddy__item.lg-sort-dragging{opacity:.45}
.lg-caddy__preview{display:block;height:46px;border-radius:7px;overflow:hidden;background:var(--lg-cream);border:1px solid var(--lg-line)}
.lg-caddy__preview svg{display:block;width:100%;height:100%}
.lg-caddy__label{display:flex;align-items:center;gap:8px;font:700 13.5px/1 var(--lg-font-sans);color:var(--lg-ink);padding:0 2px}
.lg-caddy__grip{color:var(--lg-mute);font-size:12px;letter-spacing:-2px}
.lg-caddy__plus{margin-left:auto;color:var(--lg-sage-d);font-weight:800;font-size:15px}
.lg-caddy__empty{color:var(--lg-mute);font:italic 500 13px/1.5 var(--lg-font-sans)}
/* header status lights (availability widgets) */
.lg-lights{position:relative;display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:22px}
.lg-light{display:inline-flex;align-items:center;gap:7px;background:var(--lg-cream);border:1px solid var(--lg-line);border-radius:999px;padding:5px 12px;font:600 12.5px/1 var(--lg-font-sans);color:var(--lg-ink)}
.lg-lights[data-lights-edit] .lg-light{cursor:pointer;padding-right:6px}
.lg-lights[data-lights-edit] .lg-light:hover{border-color:var(--lg-sage-3)}
.lg-light__dot{width:9px;height:9px;border-radius:50%;background:var(--lg-mute);flex:0 0 auto}
.lg-light--go .lg-light__dot,.lg-light__dot--go{background:#3fa34d;box-shadow:0 0 0 3px rgba(63,163,77,.18)}
.lg-light--stop .lg-light__dot,.lg-light__dot--stop{background:#c0492f;box-shadow:0 0 0 3px rgba(192,73,47,.16)}
.lg-light--maybe .lg-light__dot,.lg-light__dot--maybe{background:var(--lg-amber);box-shadow:0 0 0 3px rgba(224,168,60,.2)}
.lg-light__rm{border:0;background:none;cursor:pointer;color:var(--lg-mute);font-size:15px;line-height:1;padding:0 2px}
.lg-light__rm:hover{color:var(--lg-rust)}
.lg-light-add{border:1px dashed var(--lg-sage-3);background:none;cursor:pointer;border-radius:999px;padding:5px 12px;font:700 12px/1 var(--lg-font-sans);color:var(--lg-sage-d)}
.lg-light-add:hover{background:var(--lg-sage-tint)}
.lg-light-menu{position:absolute;top:calc(100% + 4px);display:inline-flex;flex-direction:column;gap:2px;background:#fff;border:1px solid var(--lg-line);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);padding:6px;z-index:1000}
.lg-light-menu button{display:flex;align-items:center;gap:8px;border:0;background:none;cursor:pointer;padding:7px 10px;border-radius:7px;font:600 12.5px/1 var(--lg-font-sans);color:var(--lg-ink);text-align:left;white-space:nowrap}
.lg-light-menu button:hover{background:var(--lg-sage-tint)}
/* wide screens (≥1380px): a 3-column grid — caddy | profile | empty spacer — so the profile
   stays PAGE-centered while the block sidebar sits in the left gutter. The View-as bar spans the
   top, centered over the profile. The caddy is sticky + IN-FLOW (not fixed), so it never overlaps
   the footer. Below 1380 → single centered column + off-canvas drawer. */
@media(min-width:1380px){
  .lg-shell--owner{display:grid;max-width:1376px;column-gap:28px;row-gap:20px;align-items:start;
    grid-template-columns:280px minmax(0,760px) 280px;
    grid-template-areas:"viewas viewas viewas" "caddy profile spacer"}
  .lg-shell--owner .lg-viewas{grid-area:viewas;max-width:760px;width:100%;margin:0 auto}
  .lg-shell--owner .lg-profile{grid-area:profile}
  .lg-shell--owner .lg-caddy{grid-area:caddy;position:sticky;top:24px;left:auto;right:auto;box-sizing:border-box;
    width:auto;height:auto;max-height:calc(100vh - 48px);overflow-y:auto;transform:none;
    border:1px solid var(--lg-line);border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
  .lg-shell--owner .lg-caddy__close{display:none}      /* permanent — no close button */
  .lg-viewas__caddy{display:none}                       /* permanent — no toggle */
  .lg-caddy__backdrop{display:none}
}
.lg-link__grip{color:var(--lg-mute);font-size:13px;line-height:1;letter-spacing:-2px;cursor:grab;user-select:none}
.lg-links--edit .lg-link:not([draggable]) .lg-link__grip,.lg-socrow .lg-link__grip{display:none}
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
/* catalog picker: result rows (pick + admin delete) and the admin "add new" affordance */
.lg-craft-results__row{display:flex;align-items:center}
.lg-craft-results__row .pick{flex:1}
.lg-craft-results .del{width:auto!important;border:0;background:none;cursor:pointer;color:var(--lg-mute);padding:6px 9px;font-size:12px;flex:0 0 auto}
.lg-craft-results .del:hover{color:var(--lg-rust)}
.lg-craft-results .lg-cat-new{display:flex;width:100%;align-items:center;justify-content:space-between;gap:10px;border:0;border-top:1px solid var(--lg-line);
  background:var(--lg-sage-tint);cursor:pointer;padding:9px 12px;text-align:left}
.lg-craft-results .lg-cat-new span:first-child{color:var(--lg-sage-d);font-weight:700;font-size:13px}
.lg-craft-results .lg-cat-new:hover{background:var(--lg-sage-3)}

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
/* freeform titled block — same render model as about, distinct hooks for divergent polish */
.lg-freeform{font-size:14.5px;line-height:1.6;color:var(--lg-ink);white-space:pre-wrap;max-width:680px}
.lg-freeform.lg-edit{min-height:1.5em;display:block;padding:6px 8px;margin:0 -8px}
.lg-block--freeform .lg-bh{display:flex;align-items:center;gap:8px}
.lg-freeform__rm{margin-left:auto;border:0;background:none;cursor:pointer;color:var(--lg-mute);font-size:18px;line-height:1;padding:0 4px}
.lg-freeform__rm:hover{color:var(--lg-rust)}
/* "+ New section" affordance under the profile body — owner only */
.lg-freeform-add{display:flex;justify-content:center;margin:8px 0 16px}
.lg-freeform-add__btn{border:1.5px dashed var(--lg-sage-3);background:none;cursor:pointer;border-radius:14px;padding:14px 22px;font:700 13.5px/1 var(--lg-font-sans);color:var(--lg-sage-d);transition:background .15s,border-color .15s}
.lg-freeform-add__btn:hover:not([disabled]){background:var(--lg-sage-tint);border-color:var(--lg-sage);border-style:solid}
.lg-freeform-add__btn[disabled]{opacity:.5;cursor:not-allowed}
/* gallery block */
.lg-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px}
.lg-gphoto{margin:0;position:relative;aspect-ratio:1;border-radius:10px;overflow:hidden;background:var(--lg-sage-tint)}
.lg-gphoto img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .25s ease}
.lg-gallery--grid .lg-gphoto:hover img{transform:scale(1.04)}
.lg-gphoto figcaption{position:absolute;bottom:0;left:0;right:0;font:600 11px/1.3 var(--lg-font-sans);color:#fff;background:linear-gradient(transparent,rgba(0,0,0,.6));padding:16px 8px 6px}
.lg-gphoto__rm{position:absolute;top:6px;right:6px;width:24px;height:24px;border-radius:50%;border:0;background:rgba(0,0,0,.55);color:#fff;cursor:pointer;font-size:15px;line-height:1;z-index:3}
.lg-gphoto__rm:hover{background:var(--lg-rust)}
.lg-gphoto__add{aspect-ratio:1;border:2px dashed var(--lg-sage-3);background:none;border-radius:10px;cursor:pointer;color:var(--lg-sage-d);font:700 12.5px/1.3 var(--lg-font-sans);display:flex;align-items:center;justify-content:center;text-align:center;padding:6px}
.lg-gphoto__add:hover{background:var(--lg-sage-tint);border-color:var(--lg-sage)}
/* gallery — owner: display-mode toggle */
.lg-gmode{display:inline-flex;gap:0;margin:0 0 12px;border:1px solid var(--lg-line);border-radius:999px;padding:2px;background:#fff}
.lg-gmode__btn{border:0;background:transparent;font:600 12px/1 var(--lg-font-sans);color:var(--lg-mute);padding:6px 14px;border-radius:999px;cursor:pointer}
.lg-gmode__btn:hover{color:var(--lg-ink)}
.lg-gmode__btn[aria-pressed="true"]{background:var(--lg-sage-tint);color:var(--lg-ink)}
/* gallery — carousel display mode */
.lg-gallery--carousel{display:block}
.lg-gallery--carousel.lg-gallery--edit{display:flex;flex-direction:column;gap:8px}
.lg-carousel{position:relative;border-radius:12px;background:var(--lg-sage-tint);overflow:hidden}
.lg-carousel__viewport{overflow:hidden;border-radius:12px}
.lg-carousel__track{display:flex;transition:transform .35s ease;will-change:transform}
.lg-carousel__track > .lg-gphoto{flex:0 0 100%;aspect-ratio:16/9;border-radius:0;background:#000}
.lg-carousel__track > .lg-gphoto img{object-fit:contain}
.lg-carousel__nav{position:absolute;top:50%;transform:translateY(-50%);width:38px;height:38px;border-radius:50%;border:0;background:rgba(255,255,255,.94);color:var(--lg-ink);font-size:20px;line-height:1;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.18);z-index:2;display:grid;place-items:center}
.lg-carousel__nav:hover{background:#fff}
.lg-carousel__nav:disabled{opacity:.35;cursor:not-allowed;box-shadow:none}
.lg-carousel__nav--prev{left:10px}
.lg-carousel__nav--next{right:10px}
.lg-carousel__dots{display:flex;justify-content:center;gap:6px;padding:10px 0 6px}
.lg-carousel__dot{width:8px;height:8px;border-radius:50%;border:0;background:var(--lg-line);cursor:pointer;padding:0;transition:background .15s,transform .15s}
.lg-carousel__dot:hover{background:var(--lg-sage-3)}
.lg-carousel__dot[aria-current="true"]{background:var(--lg-sage-d);transform:scale(1.2)}

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
  <div class="lg-shell<?= $isOwner ? ' lg-shell--owner' : '' ?>">

    <?php if ($isOwner): ?>
      <div class="lg-viewas" role="group" aria-label="Preview your profile as">
        <span class="lg-viewas__label">👁 View as</span>
        <span class="lg-viewas__seg">
          <a href="<?= looth_h($viewLink('public')) ?>" <?= $role==='public'?'aria-current="true"':'' ?>>Public</a>
          <a href="<?= looth_h($viewLink('member')) ?>" <?= $role==='member'?'aria-current="true"':'' ?>>Member</a>
          <a href="<?= looth_h($viewLink('me')) ?>"     <?= $role==='me'?'aria-current="true"':'' ?>>Me</a>
        </span>
        <button type="button" class="lg-viewas__caddy" id="lg-caddy-toggle" aria-expanded="false" aria-controls="lg-caddy">✚ Blocks</button>
        <a class="lg-viewas__edit" href="/profile/edit">Edit details (legacy)</a>
        <span class="lg-viewas__hint">This IS your editor — click any field (name, tagline, the 📷, the privacy chips) to edit it in place. Drag the ⠿ on a block to reorder; the side panel adds or removes blocks. “Edit details (legacy)” is the old form for fields not inline yet.</span>
      </div>
      <?php $available = Block::availableBlocks($subjectId); ?>
      <aside class="lg-caddy" id="lg-caddy" aria-hidden="true" aria-label="Add a block to your profile">
      <div class="lg-caddy__head">
        <strong>Add a block</strong>
        <button type="button" class="lg-caddy__close" id="lg-caddy-close" aria-label="Close">×</button>
      </div>
      <p class="lg-caddy__hint">Tap a block to add it — or drag it onto your profile. Drag the ⠿ on a block to reorder; ✕ removes it back here.</p>
      <div class="lg-caddy__list" id="lg-caddy-list">
        <?php foreach ($available as $key): $b = Block::LAYOUT_BLOCKS[$key]; ?>
          <button type="button" class="lg-caddy__item" draggable="true" data-block="<?= looth_h($key) ?>">
            <span class="lg-caddy__preview" aria-hidden="true"><?= looth_caddy_preview($key) ?></span>
            <span class="lg-caddy__label"><span class="lg-caddy__grip" aria-hidden="true">⠿</span><?= looth_h($b['label']) ?><span class="lg-caddy__plus" aria-hidden="true">＋</span></span>
          </button>
        <?php endforeach; ?>
        <span class="lg-caddy__empty"<?= $available ? ' hidden' : '' ?>>All blocks added ✓</span>
      </div>
    </aside>
    <?php endif; ?>

    <div class="lg-profile">
      <?php looth_render_profile_blocks($subjectId, $role, $tierBadge, $socialActions, $viewer ? (int)$viewer['id'] : null); ?>
      <?php if (!$isOwner): ?>
        <a class="lg-report" href="#" id="report-link">Report this profile</a>
      <?php endif; ?>
    </div>
  </div><!-- /lg-shell -->
</main>

<?php if ($isOwner): ?>
  <div class="lg-caddy__backdrop" id="lg-caddy-backdrop" hidden></div>
<?php endif; ?>

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

<script>
/* Gallery carousel viewer — runs for everyone (visitor + owner). Activates on any
   .lg-carousel inside this page; arrows / dots / touch-swipe navigate the track. */
(function () {
  document.querySelectorAll('.lg-carousel').forEach(function (car) {
    var track = car.querySelector('.lg-carousel__track');
    if (!track) return;
    var slides = track.querySelectorAll('.lg-gphoto');
    if (slides.length < 1) return;
    var prev = car.querySelector('.lg-carousel__nav--prev');
    var next = car.querySelector('.lg-carousel__nav--next');
    var dots = car.querySelectorAll('.lg-carousel__dot');
    var idx = 0;

    function go(n) {
      idx = Math.max(0, Math.min(slides.length - 1, n));
      track.style.transform = 'translateX(-' + (idx * 100) + '%)';
      if (prev) prev.disabled = (idx === 0);
      if (next) next.disabled = (idx === slides.length - 1);
      dots.forEach(function (d, i) { d.setAttribute('aria-current', i === idx ? 'true' : 'false'); });
    }
    prev && prev.addEventListener('click', function () { go(idx - 1); });
    next && next.addEventListener('click', function () { go(idx + 1); });
    dots.forEach(function (d, i) { d.addEventListener('click', function () { go(i); }); });

    // Touch swipe.
    var sx = null;
    track.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
    track.addEventListener('touchend', function (e) {
      if (sx === null) return;
      var dx = e.changedTouches[0].clientX - sx; sx = null;
      if (Math.abs(dx) > 50) go(idx + (dx < 0 ? 1 : -1));
    });

    go(0);
  });
})();
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
/* lgSortable — tiny reusable drag-to-reorder over native HTML5 DnD. Used for links,
   whole blocks, and (later) gallery photos + craft chips.
     container       — the element holding the sortable items
     opts.itemSelector   — CSS for the draggable items
     opts.handleSelector — optional; if set, a drag only starts from this handle
                           (items are non-draggable until the handle is pressed) so
                           clicks/selection inside rich items aren't hijacked
     opts.tailSelector   — optional trailing element to keep last (e.g. a + Add button)
     opts.onDrop(el)     — called after a reorder settles (persist the new order)
   The dragged item follows the cursor live; onDrop fires once on dragend. */
window.lgSortable = function (container, opts) {
  if (!container) return;
  var DCLASS = 'lg-sort-dragging';
  var dragging = null;
  function items() {
    return Array.prototype.slice.call(container.querySelectorAll(opts.itemSelector + ':not(.' + DCLASS + ')'));
  }
  function afterEl(y) {
    var best = { off: -Infinity, el: null };
    items().forEach(function (el) {
      var box = el.getBoundingClientRect(), off = y - box.top - box.height / 2;
      if (off < 0 && off > best.off) best = { off: off, el: el };
    });
    return best.el;
  }
  function clearHandleFlags() {
    Array.prototype.forEach.call(container.querySelectorAll(opts.itemSelector + '[draggable="true"]'), function (el) {
      if (!el.classList.contains(DCLASS)) el.removeAttribute('draggable');
    });
  }
  if (opts.handleSelector) {
    // Handle-gated: items become draggable only while the handle is pressed.
    container.addEventListener('mousedown', function (e) {
      var h = e.target.closest(opts.handleSelector);
      if (!h || !container.contains(h)) return;
      var el = h.closest(opts.itemSelector);
      if (el) el.setAttribute('draggable', 'true');
    });
    container.addEventListener('mouseup', clearHandleFlags);
  }
  container.addEventListener('dragstart', function (e) {
    var el = e.target.closest(opts.itemSelector);
    if (!el || !container.contains(el)) return;
    dragging = el; el.classList.add(DCLASS);
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', ''); } catch (_) {}
  });
  container.addEventListener('dragover', function (e) {
    if (!dragging) return;
    e.preventDefault();
    var ref = afterEl(e.clientY);
    var tail = opts.tailSelector ? container.querySelector(opts.tailSelector) : null;
    container.insertBefore(dragging, ref || tail);
  });
  container.addEventListener('drop', function (e) { if (dragging) e.preventDefault(); });
  container.addEventListener('dragend', function () {
    if (!dragging) return;
    var moved = dragging; dragging = null;
    moved.classList.remove(DCLASS);
    if (opts.handleSelector) clearHandleFlags();
    if (opts.onDrop) opts.onDrop(moved);
  });
};
</script>

<script>
/* Owner layout controls — whole-block reorder (⠿ grip), per-block remove (✕), and the
   ✚ Blocks caddy (tap-to-add, or drag a block from the caddy onto the profile). Order
   persists to /me/layout with NO reload; add & remove reload so the server re-renders the
   affected block(s) + the caddy (and so a newly added block's inline editors wire up). */
(function () {
  var profile = document.querySelector('.lg-profile');
  if (!profile) return;

  function bodyBlocks() {
    return Array.prototype.slice.call(profile.querySelectorAll('.lg-block:not(.lg-block--header)'));
  }
  function order() {
    return bodyBlocks().map(function (s) { return s.getAttribute('data-block'); }).filter(Boolean);
  }
  function putLayout(arr, then) {
    fetch('/profile-api/v0/me/layout', {
      method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order: arr })
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) { if (res.ok) { if (then) then(); } else alert('Save failed: ' + (res.j && res.j.error || '?')); })
      .catch(function () { alert('Network error.'); });
  }

  // Inject a ⠿ grip + a remove ✕ into each body block's heading.
  bodyBlocks().forEach(function (b) {
    var host = b.querySelector('.lg-bh') || b;
    if (!host.querySelector('.lg-block__grip')) {
      var grip = document.createElement('span');
      grip.className = 'lg-block__grip'; grip.setAttribute('title', 'Drag to reorder');
      grip.setAttribute('aria-hidden', 'true'); grip.textContent = '⠿';
      host.insertBefore(grip, host.firstChild);
    }
    if (!host.querySelector('.lg-block__rm')) {
      var rm = document.createElement('button');
      rm.type = 'button'; rm.className = 'lg-block__rm';
      rm.setAttribute('title', 'Remove this block'); rm.setAttribute('aria-label', 'Remove block');
      rm.textContent = '✕';
      var grip2 = host.querySelector('.lg-block__grip');
      host.insertBefore(rm, grip2 ? grip2.nextSibling : host.firstChild);
    }
  });

  // Remove a block → drop its key from the layout, reload (it returns to the caddy; data kept).
  profile.addEventListener('click', function (e) {
    var rm = e.target.closest('.lg-block__rm');
    if (!rm) return;
    var block = rm.closest('.lg-block:not(.lg-block--header)');
    if (!block) return;
    var key = block.getAttribute('data-block');
    putLayout(order().filter(function (k) { return k !== key; }), function () { location.reload(); });
  });

  // Reorder = no reload.
  lgSortable(profile, {
    itemSelector: '.lg-block:not(.lg-block--header)',
    handleSelector: '.lg-block__grip',
    onDrop: function () { putLayout(order()); }
  });

  /* ---- caddy: open / close ---- */
  var caddy    = document.getElementById('lg-caddy');
  var toggle   = document.getElementById('lg-caddy-toggle');
  var backdrop = document.getElementById('lg-caddy-backdrop');
  function openCaddy() {
    if (!caddy) return;
    caddy.classList.add('is-open'); caddy.setAttribute('aria-hidden', 'false');
    if (toggle) toggle.setAttribute('aria-expanded', 'true');
    if (backdrop) { backdrop.hidden = false; requestAnimationFrame(function () { backdrop.classList.add('is-open'); }); }
  }
  function closeCaddy() {
    if (!caddy) return;
    caddy.classList.remove('is-open'); caddy.setAttribute('aria-hidden', 'true');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    if (backdrop) { backdrop.classList.remove('is-open'); setTimeout(function () { backdrop.hidden = true; }, 220); }
  }
  // ≥1380px the caddy is a permanent floating sidebar (CSS); below, it's an off-canvas drawer.
  var deskMq = window.matchMedia('(min-width:1380px)');
  function syncCaddyMode() {
    if (!caddy) return;
    if (deskMq.matches) {                              // permanent: always visible, never a drawer
      caddy.classList.remove('is-open'); caddy.setAttribute('aria-hidden', 'false');
      if (backdrop) { backdrop.classList.remove('is-open'); backdrop.hidden = true; }
    } else if (!caddy.classList.contains('is-open')) {  // drawer, closed
      caddy.setAttribute('aria-hidden', 'true');
    }
  }
  if (toggle && caddy) {
    toggle.addEventListener('click', function () { caddy.classList.contains('is-open') ? closeCaddy() : openCaddy(); });
    var closeBtn = document.getElementById('lg-caddy-close');
    if (closeBtn) closeBtn.addEventListener('click', closeCaddy);
    if (backdrop) backdrop.addEventListener('click', closeCaddy);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !deskMq.matches && caddy.classList.contains('is-open')) closeCaddy(); });
    if (deskMq.addEventListener) deskMq.addEventListener('change', syncCaddyMode);
    if (!deskMq.matches && location.hash === '#caddy') openCaddy();   // re-open the drawer across a mobile add
    syncCaddyMode();
  }

  /* ---- caddy: add a block (tap appends; drag drops at a position) ---- */
  function addBlock(key, atIndex) {
    var cur = order().filter(function (k) { return k !== key; });
    if (typeof atIndex !== 'number' || atIndex < 0 || atIndex > cur.length) atIndex = cur.length;
    cur.splice(atIndex, 0, key);
    putLayout(cur, function () { location.hash = 'caddy'; location.reload(); });
  }
  var list = document.getElementById('lg-caddy-list');
  if (list) {
    list.addEventListener('click', function (e) {
      var item = e.target.closest('.lg-caddy__item');
      if (item) addBlock(item.getAttribute('data-block'));      // tap-to-add (appends)
    });
  }

  /* ---- caddy → profile drag (desktop): drop a caddy block among the blocks to add there ---- */
  var caddyDragKey = null, pendingIndex = null;
  function clearDropMarks() {
    Array.prototype.forEach.call(profile.querySelectorAll('.lg-block--drop-before,.lg-block--drop-after'), function (el) {
      el.classList.remove('lg-block--drop-before', 'lg-block--drop-after');
    });
  }
  function dropIndex(y) {
    var blocks = bodyBlocks();
    for (var i = 0; i < blocks.length; i++) {
      var box = blocks[i].getBoundingClientRect();
      if (y < box.top + box.height / 2) { blocks[i].classList.add('lg-block--drop-before'); return i; }
    }
    if (blocks.length) blocks[blocks.length - 1].classList.add('lg-block--drop-after');
    return blocks.length;
  }
  if (list) {
    list.addEventListener('dragstart', function (e) {
      var item = e.target.closest('.lg-caddy__item');
      if (!item) return;
      caddyDragKey = item.getAttribute('data-block');
      item.classList.add('lg-sort-dragging');
      e.dataTransfer.effectAllowed = 'copy';
      try { e.dataTransfer.setData('text/plain', caddyDragKey); } catch (_) {}
    });
    list.addEventListener('dragend', function () {
      caddyDragKey = null; clearDropMarks();
      Array.prototype.forEach.call(list.querySelectorAll('.lg-sort-dragging'), function (el) { el.classList.remove('lg-sort-dragging'); });
    });
  }
  // Only handle caddy drags here; block-reorder drags are owned by lgSortable (its dragover
  // early-returns when no block is being dragged, so the two never collide).
  profile.addEventListener('dragover', function (e) {
    if (!caddyDragKey) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    clearDropMarks();
    pendingIndex = dropIndex(e.clientY);
  });
  profile.addEventListener('drop', function (e) {
    if (!caddyDragKey) return;
    e.preventDefault();
    var key = caddyDragKey, idx = pendingIndex;
    caddyDragKey = null; clearDropMarks();
    addBlock(key, idx);
  });
})();
</script>

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
    'skills':          { url: BASE + '/me/catalog/skills',      m: 'PUT', k: 'visibility' },
    'services':        { url: BASE + '/me/catalog/services',    m: 'PUT', k: 'visibility' },
    'instruments':     { url: BASE + '/me/catalog/instruments', m: 'PUT', k: 'visibility' },
    'music':           { url: BASE + '/me/catalog/music',       m: 'PUT', k: 'visibility' },
    'connect':         { url: BASE + '/me/connect',  m: 'PATCH', k: 'visibility' },
    'about':           { url: BASE + '/me/about',    m: 'PATCH', k: 'visibility' },
    'gallery':         { url: BASE + '/me/gallery',  m: 'PUT',   k: 'visibility' },
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
/* Location editor (owner/Me) — set / change the actual location. Search OSM Nominatim via
   /me/location/search (server-proxied, IP-biased, no API key), pick a result → PUT
   /me/location {nominatim:<raw>} → reload. Zero results → "save as text" escape hatch. */
(function () {
  var wrap = document.getElementById('lg-loc-edit');
  if (!wrap) return;
  var btn = wrap.querySelector('.lg-loc__change');

  function save(body) {
    return fetch('/profile-api/v0/me/location', { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.ok; });
  }

  btn.addEventListener('click', function () {
    if (wrap.querySelector('.lg-craft-search')) return;
    btn.style.display = 'none';
    var box = document.createElement('span'); box.className = 'lg-craft-search';
    var inp = document.createElement('input'); inp.type = 'text'; inp.placeholder = 'Search a city or address…';
    var res = document.createElement('div'); res.className = 'lg-craft-results'; res.style.display = 'none';
    box.appendChild(inp); box.appendChild(res); wrap.appendChild(box); inp.focus();

    var timer = null, lastQ = '';
    function close() { box.remove(); btn.style.display = ''; }
    function pick(body) { save(body).then(function (ok) { if (ok) location.reload(); else alert('Could not save location.'); }); }

    function run() {
      var q = inp.value.trim();
      if (q === lastQ) return; lastQ = q;
      if (q.length < 3) { res.style.display = 'none'; return; }
      fetch('/profile-api/v0/me/location/search?q=' + encodeURIComponent(q), { credentials: 'include' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (inp.value.trim() !== q) return;                 // stale
          res.innerHTML = '';
          var items = (d && d.items) || [];
          items.slice(0, 6).forEach(function (it) {
            var b = document.createElement('button'); b.type = 'button';
            b.innerHTML = '<span>' + (it.short ? it.short.replace(/[&<>"]/g, '') : '') + '</span>';
            b.title = it.display_name || '';
            b.addEventListener('click', function () { pick({ nominatim: it.raw }); });
            res.appendChild(b);
          });
          if (!items.length) {                                // escape hatch: save freeform text
            var t = document.createElement('button'); t.type = 'button'; t.className = 'lg-cat-new';
            t.innerHTML = '<span>No match — use “' + q.replace(/[&<>"]/g, '') + '” as text</span><span class="t">text</span>';
            t.addEventListener('click', function () { pick({ text_only: q }); });
            res.appendChild(t);
          }
          res.style.display = 'block';
        })
        .catch(function () { res.innerHTML = '<div class="none">Search unavailable.</div>'; res.style.display = 'block'; });
    }
    inp.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 320); });   // debounce (Nominatim politeness)
    inp.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    document.addEventListener('click', function onDoc(e) {
      if (!box.contains(e.target) && e.target !== btn) { close(); document.removeEventListener('click', onDoc); }
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
  function stripScheme(v) { return String(v).replace(/^https?:\/\//i, ''); }
  function rowEl(kind, value) {
    var row = document.createElement('div'); row.className = 'lg-link';
    row.setAttribute('draggable', 'true');
    row.setAttribute('data-kind', kind); row.setAttribute('data-value', value);
    var grip = document.createElement('span'); grip.className = 'lg-link__grip'; grip.setAttribute('aria-hidden', 'true'); grip.textContent = '⠿';
    row.appendChild(grip);
    var k = document.createElement('span'); k.className = 'lg-link__kind'; k.textContent = kind;
    var v = document.createElement('span'); v.className = 'lg-link__val'; v.textContent = stripScheme(value);
    var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'lg-link__rm';
    rm.setAttribute('aria-label', 'Remove'); rm.textContent = '×';
    row.appendChild(k); row.appendChild(v); row.appendChild(rm);
    return row;
  }
  // Re-render rows in place from the server's canonical ordered list — no full-page
  // reload (keeps scroll/place), and reflects the persisted drag order.
  function render(socials) {
    var addBtn = document.getElementById('lg-link-add');
    Array.prototype.forEach.call(wrap.querySelectorAll('.lg-link, .lg-link-form'), function (el) { el.remove(); });
    var f = (socials && socials.fields) || {};
    (f.ordered || []).forEach(function (l) { wrap.insertBefore(rowEl(l.kind, l.url), addBtn); });
  }
  function put(items) {
    fetch('/profile-api/v0/me/socials', { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ items: items }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) { if (res.ok) render(res.j && res.j.socials); else alert('Save failed: ' + (res.j && res.j.error || '?')); })
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

  /* Drag-to-reorder the links (rows are draggable in markup; keep #lg-link-add last). */
  lgSortable(wrap, {
    itemSelector: '.lg-link',
    tailSelector: '#lg-link-add',
    onDrop: function () { put(collect()); }
  });
})();
</script>

<script>
/* Catalog chip pickers (owner/Me) — Skills / Services / Instruments / Music. Each .lg-cat-edit
   block searches its catalog (data-kind), click a result to add / ✕ a chip to remove → PUT the
   id list to /me/catalog/<block>. ADMINS additionally get, in the dropdown: "＋ Add '<term>'"
   to create a not-found catalog item, and a 🗑 on each result to deactivate it for everyone. */
(function () {
  var IS_ADMIN = <?= Auth::isAdmin() ? 'true' : 'false' ?>;
  var catalogs = {};   // kind → Promise<[{id,name,lc}]>
  function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function loadCatalog(kind) {
    if (!catalogs[kind]) {
      catalogs[kind] = fetch('/profile-api/v0/catalogs/' + kind, { credentials: 'include' })
        .then(function (r) { return r.json(); })
        .then(function (j) { return (j.items || []).map(function (x) { return { id: x.id, name: x.name, lc: (x.name || '').toLowerCase() }; }); });
    }
    return catalogs[kind];
  }

  document.querySelectorAll('.lg-cat-edit').forEach(function (wrap) {
    var block  = wrap.getAttribute('data-block');
    var kind   = wrap.getAttribute('data-kind');
    var addBtn = wrap.querySelector('.lg-cat-add');
    if (!addBtn) return;

    function ids() {
      return Array.prototype.map.call(wrap.querySelectorAll('.lg-chip--edit'),
        function (el) { return parseInt(el.getAttribute('data-id'), 10); });
    }
    function put() {
      return fetch('/profile-api/v0/me/catalog/' + block, { method: 'PUT', credentials: 'include',
        headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ids: ids() }) })
        .then(function (r) { return r.ok; });
    }
    function makeChip(id, name) {
      var s = document.createElement('span'); s.className = 'lg-chip lg-chip--edit';
      s.setAttribute('data-id', id); s.textContent = name;
      var b = document.createElement('button'); b.type = 'button'; b.className = 'lg-chip__rm';
      b.setAttribute('aria-label', 'Remove'); b.textContent = '×'; s.appendChild(b);
      return s;
    }
    function addItem(id, name) {
      if (ids().indexOf(id) !== -1) return;
      var chip = makeChip(id, name);
      wrap.insertBefore(chip, wrap.querySelector('.lg-craft-search') || addBtn);
      put().then(function (ok) { if (!ok) { chip.remove(); alert('Add failed'); } });
    }

    wrap.addEventListener('click', function (e) {
      var rm = e.target.closest('.lg-chip__rm'); if (!rm) return;
      var chip = rm.closest('.lg-chip--edit'); chip.remove();
      put().then(function (ok) { if (!ok) { alert('Remove failed'); location.reload(); } });
    });

    addBtn.addEventListener('click', function () {
      if (wrap.querySelector('.lg-craft-search')) return;
      var box = document.createElement('span'); box.className = 'lg-craft-search';
      var inp = document.createElement('input'); inp.type = 'text';
      inp.placeholder = 'Search ' + addBtn.textContent.replace(/^\+\s*Add\s*/i, '') + '…';
      var res = document.createElement('div'); res.className = 'lg-craft-results'; res.style.display = 'none';
      box.appendChild(inp); box.appendChild(res);
      addBtn.parentNode.insertBefore(box, addBtn); addBtn.style.display = 'none'; inp.focus();

      function has(id) { return ids().indexOf(id) !== -1; }
      function render() {
        loadCatalog(kind).then(function (cat) {
          var q = inp.value.trim(), ql = q.toLowerCase();
          res.innerHTML = '';
          if (ql === '') { res.style.display = 'none'; return; }
          var matches = cat.filter(function (c) { return c.lc.indexOf(ql) !== -1; }).slice(0, 40);
          var exact   = cat.some(function (c) { return c.lc === ql; });
          matches.forEach(function (m) {
            var row = document.createElement('div'); row.className = 'lg-craft-results__row';
            var added = has(m.id);
            var pick = document.createElement('button'); pick.type = 'button'; pick.className = 'pick';
            pick.innerHTML = '<span>' + esc(m.name) + '</span><span class="' + (added ? 'added' : 't') + '">' + (added ? '✓ added' : '') + '</span>';
            if (!added) pick.addEventListener('click', function () { addItem(m.id, m.name); render(); inp.focus(); });
            row.appendChild(pick);
            if (IS_ADMIN) {
              var del = document.createElement('button'); del.type = 'button'; del.className = 'del';
              del.title = 'Remove from catalog (admin)'; del.textContent = '🗑';
              del.addEventListener('click', function () {
                if (!confirm('Remove “' + m.name + '” from the catalog for everyone?')) return;
                fetch('/profile-api/v0/catalogs/' + kind + '/' + m.id, { method: 'DELETE', credentials: 'include' })
                  .then(function (r) { if (r.ok) { delete catalogs[kind]; render(); } else alert('Catalog remove failed (admin only).'); });
              });
              row.appendChild(del);
            }
            res.appendChild(row);
          });
          if (IS_ADMIN && q && !exact) {                       // admin: create a not-found item
            var add = document.createElement('button'); add.type = 'button'; add.className = 'lg-cat-new';
            add.innerHTML = '<span>＋ Add “' + esc(q) + '” to catalog</span><span class="t">new</span>';
            add.addEventListener('click', function () {
              fetch('/profile-api/v0/catalogs/' + kind, { method: 'POST', credentials: 'include',
                headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: q }) })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (x) {
                  if (x.ok && x.j.item) { delete catalogs[kind]; addItem(x.j.item.id, x.j.item.name); inp.value = ''; render(); inp.focus(); }
                  else alert('Add to catalog failed (admin only).');
                });
            });
            res.appendChild(add);
          } else if (!matches.length) {
            res.innerHTML = '<div class="none">No matches</div>';
          }
          res.style.display = 'block';
        });
      }
      inp.addEventListener('input', render);
      inp.addEventListener('keydown', function (e) { if (e.key === 'Escape') { box.remove(); addBtn.style.display = ''; } });
      document.addEventListener('click', function onDoc(e) {
        if (!box.contains(e.target) && e.target !== addBtn) { box.remove(); addBtn.style.display = ''; document.removeEventListener('click', onDoc); }
      });
    });
  });
})();
</script>

<script>
/* Header status lights (owner/Me) — click a light to toggle its state, × to remove, + Status
   to add an available one. All persisted to /me/lights; built client-side from the registry. */
window.LG_LIGHTS = <?= json_encode(Block::HEADER_LIGHTS, JSON_UNESCAPED_SLASHES) ?>;
(function () {
  var REG = window.LG_LIGHTS || {};
  var row = document.querySelector('.lg-lights[data-lights-edit]');
  if (!row) return;
  var addBtn = document.getElementById('lg-light-add');

  function put(key, state) {
    return fetch('/profile-api/v0/me/lights', { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ key: key, state: state || '' }) })
      .then(function (r) { return r.ok; });
  }
  function states(key) { return Object.keys(((REG[key] || {}).states) || {}); }
  function present() { return Array.prototype.map.call(row.querySelectorAll('.lg-light'), function (p) { return p.getAttribute('data-key'); }); }
  function applyPill(pill, key, state) {
    var st = REG[key].states[state];
    pill.className = 'lg-light lg-light--' + st.tone;
    pill.setAttribute('data-state', state);
    pill.querySelector('.lg-light__label').textContent = st.label;
  }
  function makePill(key, state) {
    var st = REG[key].states[state];
    var pill = document.createElement('span');
    pill.className = 'lg-light lg-light--' + st.tone;
    pill.setAttribute('data-key', key); pill.setAttribute('data-state', state);
    pill.setAttribute('role', 'button'); pill.setAttribute('tabindex', '0'); pill.title = 'Click to toggle';
    pill.innerHTML = '<span class="lg-light__dot"></span><span class="lg-light__label">' + st.label + '</span>';
    var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'lg-light__rm';
    rm.setAttribute('aria-label', 'Remove status'); rm.textContent = '×';
    pill.appendChild(rm);
    return pill;
  }
  function refreshAdd() {
    if (!addBtn) return;
    var avail = Object.keys(REG).filter(function (k) { return present().indexOf(k) === -1; });
    addBtn.style.display = avail.length ? '' : 'none';
  }

  row.addEventListener('click', function (e) {
    var rm = e.target.closest('.lg-light__rm');
    if (rm) { var p = rm.closest('.lg-light'); var k = p.getAttribute('data-key'); p.remove(); put(k, ''); refreshAdd(); return; }
    var pill = e.target.closest('.lg-light');
    if (pill) {
      var key = pill.getAttribute('data-key'), ss = states(key), cur = pill.getAttribute('data-state');
      var next = ss[(ss.indexOf(cur) + 1) % ss.length];
      applyPill(pill, key, next); put(key, next);
    }
  });
  row.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('lg-light')) { e.preventDefault(); e.target.click(); }
  });

  function closeMenu() { var m = document.getElementById('lg-light-menu'); if (m) m.remove(); document.removeEventListener('click', onDoc); }
  function onDoc(e) { if (!e.target.closest('#lg-light-menu') && e.target !== addBtn) closeMenu(); }
  function openMenu() {
    closeMenu();
    var avail = Object.keys(REG).filter(function (k) { return present().indexOf(k) === -1; });
    if (!avail.length) return;
    var menu = document.createElement('div'); menu.className = 'lg-light-menu'; menu.id = 'lg-light-menu';
    avail.forEach(function (k) {
      states(k).forEach(function (s) {                       // every state pickable → negatives are first-class
        var st = REG[k].states[s];
        var b = document.createElement('button'); b.type = 'button';
        b.innerHTML = '<span class="lg-light__dot lg-light__dot--' + st.tone + '"></span>' + st.label;
        b.addEventListener('click', function () { row.insertBefore(makePill(k, s), addBtn); put(k, s); closeMenu(); refreshAdd(); });
        menu.appendChild(b);
      });
    });
    row.appendChild(menu); menu.style.left = addBtn.offsetLeft + 'px';
    document.addEventListener('click', onDoc);
  }
  if (addBtn) addBtn.addEventListener('click', function (e) { e.stopPropagation(); document.getElementById('lg-light-menu') ? closeMenu() : openMenu(); });
})();
</script>

<script>
/* Gallery editor (owner/Me) — multi-upload (POST me-gallery) + remove (PUT list). */
(function () {
  var wrap = document.getElementById('lg-gallery');
  if (!wrap) return;
  var addBtn = document.getElementById('lg-gallery-add');

  function currentImages() {
    return Array.prototype.map.call(wrap.querySelectorAll('.lg-gphoto'), function (el) {
      var cap = el.querySelector('figcaption');
      return { url: el.getAttribute('data-url'), caption: cap ? cap.textContent : '' };
    });
  }
  function putList(images) {
    return fetch('/profile-api/v0/me/gallery', { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ images: images }) })
      .then(function (r) { return r.ok; });
  }

  wrap.addEventListener('click', function (e) {
    var rm = e.target.closest('.lg-gphoto__rm'); if (!rm) return;
    rm.closest('.lg-gphoto').remove();
    putList(currentImages()).then(function (ok) { if (!ok) { alert('Remove failed'); location.reload(); } });
  });

  var input = document.createElement('input');
  input.type = 'file'; input.accept = 'image/jpeg,image/png,image/webp'; input.multiple = true;
  input.style.display = 'none'; document.body.appendChild(input);
  addBtn && addBtn.addEventListener('click', function () { input.click(); });
  input.addEventListener('change', function () {
    var files = Array.prototype.slice.call(input.files || []); input.value = '';
    if (!files.length) return;
    addBtn.textContent = 'Uploading…';
    var i = 0;
    (function next() {
      if (i >= files.length) { location.reload(); return; }
      var f = files[i++];
      if (f.size > 5 * 1024 * 1024) { alert(f.name + ' is over 5 MB — skipped'); next(); return; }
      var fd = new FormData(); fd.append('image', f);
      fetch('/profile-api/v0/me/gallery', { method: 'POST', credentials: 'include', body: fd })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) { if (!res.ok) alert('Upload failed (' + f.name + '): ' + (res.j && res.j.error || '?')); next(); })
        .catch(function () { alert('Network error on ' + f.name); next(); });
    })();
  });
})();

/* Gallery display-mode toggle (owner only) — PUT /me/gallery {display_mode}. */
(function () {
  var ctrl = document.querySelector('.lg-block--gallery .lg-gmode');
  if (!ctrl) return;
  ctrl.addEventListener('click', function (e) {
    var btn = e.target.closest('.lg-gmode__btn'); if (!btn) return;
    if (btn.getAttribute('aria-pressed') === 'true') return;
    var mode = btn.getAttribute('data-mode');
    btn.disabled = true;
    fetch('/profile-api/v0/me/gallery', {
      method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ display_mode: mode })
    }).then(function (r) {
      if (r.ok) location.reload();
      else { btn.disabled = false; alert('Could not change mode'); }
    }).catch(function () { btn.disabled = false; alert('Network error'); });
  });
})();
/* Freeform titled block (owner) — create + delete. Title and body inline-edit
   via the generic lg-edit handler (PUT /me/freeform?key=... {title|body}). */
(function () {
  var addBtn = document.getElementById('lg-freeform-add');
  if (addBtn && !addBtn.disabled) {
    addBtn.addEventListener('click', function () {
      addBtn.disabled = true;
      var prev = addBtn.textContent; addBtn.textContent = 'Adding…';
      fetch('/profile-api/v0/me/freeform', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title: '' })
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (res.ok) location.reload();
          else {
            addBtn.disabled = false; addBtn.textContent = prev;
            alert('Could not add section: ' + (res.j && res.j.error || '?'));
          }
        })
        .catch(function () {
          addBtn.disabled = false; addBtn.textContent = prev;
          alert('Network error.');
        });
    });
  }

  // Delete handler — event-delegated since blocks may come/go after reload.
  document.addEventListener('click', function (e) {
    var rm = e.target.closest('.lg-freeform__rm[data-freeform-rm]');
    if (!rm) return;
    var key = rm.getAttribute('data-freeform-rm');
    if (!key) return;
    if (!confirm('Delete this section? This cannot be undone.')) return;
    rm.disabled = true;
    fetch('/profile-api/v0/me/freeform?key=' + encodeURIComponent(key), {
      method: 'DELETE', credentials: 'include'
    })
      .then(function (r) { if (r.ok) location.reload(); else { rm.disabled = false; alert('Delete failed.'); } })
      .catch(function () { rm.disabled = false; alert('Network error.'); });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
