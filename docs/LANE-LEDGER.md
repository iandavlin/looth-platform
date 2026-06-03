# Lane Ledger — coordinator view (2026-06-03)

Status of every dispatched lane. Coordinator chat owns this file; lanes report back, coordinator updates.

Legend: 🟢 green/done · 🟡 in-flight · ⚪ dispatched, not started · 🔵 queued/blocked

## Lanes

| Lane | Scope | Status | Notes |
|------|-------|--------|-------|
| **conversion** | legacy videos → v2 → standalone render | 🟡 in-flight | briefing-conversion-coord.md; one-at-a-time; 3D Club + 70899 done |
| **whoami/gating** | /whoami SSOT + poller tier + gating reads | 🟡 in-flight | ROOT CAUSE = poller plugin was inactive → universal `public`. Activating + verifying. bridge mapping CLEAN (1612 rows). |
| **header Step 1 — bb-mirror** | `$ctx` from /whoami in bb-mirror/_chrome.php | 🟢 done | 05e36b6 pushed. JWT→anchor only; dropped lg_tier path + d=mp avatar rewrite. Tier *value* correctness pends poller lane. |
| **header Step 1 — archive-poc** | `$ctx` from /whoami; drop LG_VIEWER_TIER | 🟢 done | _chrome.php now byte-parallel w/ profile-app ref; LG_VIEWER_TIER gone. Tier *value* pends poller. **Committed a9e130c, awaiting push sign-off.** |
| **archive-poc — events pulled from archive** | keep events in index, hide from /archive/ search | 🟢 done | search.php `ci.kind!='event'`; suggest `NOT IN(discussion,event)`; 9 events back on front rail. **Committed b9b61bf (+breakout 5b39a09), awaiting push sign-off.** |
| **header Step 2 — lg-layout-v2** | retire lg-site-header onto shared render | 🟢 done | SiteHeader.php delegates to lg_shared_render_site_header; lg-site-header.css/js/partial git-rm'd; vendored archive-poc engine copy synced. WP render path now lg-chrome. Uncommitted. |
| **profile_url parity** | account chip → same dest everywhere | 🟢 decided + half-done | Ian ruled `/u/<slug>` (fallback /profile/edit). membership-chrome FIXED+deployed (coordinator). lg-layout-v2 lane to thread user_nicename. archive-poc/bb-mirror/contract already correct. |
| **token promotion — lg-layout-v2** | coral/slate → src/theme/tokens.json | 🟡 in-flight | tokens live at src/theme/tokens.json (registry in Theme.php); lane matching naming/scale conventions |
| **header Step 3 — BB theme** | site-header--bb long tail | 🔵 tracked | no discrete task; dies as strangler finishes |

## Cross-lane / coordinator items
- 🟢 **CPT standalone-header identity** — `render.php` called /whoami but hardcoded `avatar_url=null`/`capabilities=[]`, so ALL CPT headers showed initial-avatar + no admin (vs /archive/). Now sources avatar/caps/profile_url from /whoami (mirrors _chrome.php). Live (FPM reloaded). **Uncommitted — fold into archive-poc commits.** Was the 4th consumer Step 1 missed.
- 🟢 **siteurl fix** — dev WP DB had live URLs (CF reload); search-replace + cache flush. Logout/login fixed.
- 🟢 **internal tier channel lockdown** — nginx `/wp-json/looth-internal/` now localhost-only (allow 127.0.0.1/::1; deny all) atop PHP shared-secret.
- 🔵 **reconciler → DB-reload runbook** (not cron); enhancement (re-validate email per wp_user_id) flagged to cutover lane.
- 🔵 **coral #c66845 + slate #6f8fa6** — bb-mirror local brand extension; pending decision to promote into lg-layout-v2 tokens.json (Ian's brand call).
- 🔴 **secrets to rotate** — CF creds (pasted in chat) + plaintext AWS key (AKIA…) in /var/www/dev/wp-config.php.

## Pushed this session (on main)
- bb-mirror: 05e36b6 (Step 1), 1c4f0b8 (pill borders/suggestion-box/sticky offset), 7c561f2 (9-color palette), c7262f0 (palette unification).
- Coordinator: siteurl (DB, not git), nginx lockdown (conf + .bak).
