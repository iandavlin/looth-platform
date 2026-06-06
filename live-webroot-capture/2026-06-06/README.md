# Live-webroot capture — 2026-06-06 (Buck-as-ubuntu overnight work)

**What this is:** a verbatim snapshot of files Buck created/changed directly in `/var/www/dev`
(the WordPress webroot) overnight 2026-06-05→06, operating the `ubuntu` account (authorized by Ian).
`/var/www/dev` is **NOT a git repo**, so this work was live-only — protected solely by Buck's manual
`.bak-*` files. This capture puts it under version control so a DB/file reload or overwrite can't lose it.

**Captured at:** 2026-06-06 (coordinator). Paths mirror their location under `/var/www/dev/`.
These are COPIES for safekeeping — the live originals still serve from `/var/www/dev`.

## Inventory + where each really belongs

| File(s) | What it is | Real home / next step |
|---|---|---|
| `push/subscribe.php`, `push-subscribe.php`, `sw.js`, `manifest.json`, `icons/` | **Push notifications** — the POST /push/subscribe endpoint Ian greenlit + SW push handlers. Table `wp_lg_push_subscriptions` exists (built, 1 test row). | Push lane. The web-push **sender + 2 triggers** (new-content, event-reminder) are still unbuilt — coordinator's queued task. VAPID keys at `/etc/lg-vapid/`. |
| `hub-polish.js` (v47, ~1536 lines), `app-mobile-fixes.js`, `hub-infinite.js`, `bottom-nav.js` | **Hub/mobile shadow layer** — theme, fast-filters, reply fix, reaction stub, infinite scroll, mobile bottom nav. Injected via `/pwa.js`. | Mobile-czar convergence → canonical `forums.css`/`forums.js` + lg-shell; retire the JS stop-gaps. See [[project_standalone_vendors_no_assets]] pattern + `docs/hub-up-to-speed.md`. |
| `shop-bubble.js`, `shop-feed.json`, `shop-img/*.webp` (30) | **Shop feature (NEW scope)** — a product "shop bubble". Undiscussed/unscoped. | Needs Ian sign-off + a lane home before it's a real feature. |
| `loothalong.js`, `loothalong.php` | **Loothalong** CTA (client + server). | Events lane (the entitled-member→Zoom gate route). |
| `app-settings.js` | Mobile settings UI. | Mobile-czar / hub. |
| `pwa.js` | PWA bootstrap (loader for the above). Served `no-cache` now (`?v=2`). | Shared PWA scaffolding. |
| `cdp_tab.py` | CDP tooling helper. | `tools/`. |
| `mockups/*.png` | Hub card/category-color design mockups. | Reference. |

## nginx changes (separate — already captured in the live conf + .bak)
`dev.loothgroup.com.conf`: `pwa.js?v=2` in the `</head>` sub_filter; `no-cache` locations for
`/pwa.js` and `/shop-feed.json`. These reconcile cleanly with the coordinator's manifest/icons
gate-exemption edits (no clobber).

## Status flags
- This is a SAFEKEEPING snapshot, not the canonical home. Each item above still needs routing to its
  lane and proper absorption (esp. the shop feature = needs a scope decision).
- The live originals remain authoritative until absorbed; if you edit, edit the live file AND refresh
  this capture, or absorb to canonical and retire the live copy.
