# BB-mirror — Session Handoff (2026-06-03 — /hub rebrand, palette, header convergence, reply-write endpoint)

Prior handoff rotated to `handoffs/2026-06-03-pre-stream-replies.md`.

> **Context shift:** /hub/ is now the **reader**; the new **/stream/** is the destination
> that subsumes it. Do NOT invest in /hub/ as a standalone destination. New write work
> goes through the reply-write endpoint (below); /stream/ wires its UI to it.

## Master spec (NEW — use this to judge "is X a regression?")
`docs/HUB-EXPECTED-BEHAVIOR.md` — every /hub/ behavior, tagged ✅ harness-covered / ⬜ gap.
Harness `bin/test-features.sh` (~27 checks) predates half this session, so the ⬜ items
(esp. **R8 reply-author-has-a-name**, **H1 logged-in header identity**, R3 5-at-a-time,
R5 deferred reply images) are NOT yet asserted — they're the next thing to add and would
have caught this week's regressions automatically.

## Shipped this session (newest → oldest)
- **34a61bf** Replies paginate by **5 ROWS** (was 5 top-level threads → showed 11+); flatten
  thread DFS, "Load N more replies" reveals next 5. + started HUB-EXPECTED-BEHAVIOR.md.
- **b386ea7** **Reply-write endpoint** `POST /bb-mirror-api/v0/reply` (WP pool, cookie auth) —
  the owned write path for /stream/ + /hub/. Reuses BB REST in-process; handles flood
  (429+Retry-After), moderation (202), published (200 w/ author+permalink+content_html).
  Contract: `docs/reply-write-endpoint.md`. nginx route added (repo snippet + live, reloaded).
- **05e36b6** **Header convergence Step 1**: header identity from /whoami (not JWT claims +
  lg_tier cookie); JWT kept only as anchor; avatar passed verbatim (dropped safe_avatar d=mp).
- **1c4f0b8** Subtle pill borders; Suggestion Box = own pill (pulled from General); sticky
  sidebar offset top:69px (clears 61px lg-chrome header — was obscured).
- **7c561f2 / c7262f0** Category colour: ONE palette shared by sidebar/feed/banner; 9 distinct
  colours via brand tokens + **coral #c66845 (Builds) + slate #6f8fa6 (Business)**.
- **812e669** stronger nav contrast → then dialled back to subtle in 1c4f0b8.
- **6cad626** Image lightbox on all forum images. **e05dde2** bigger chevron arrows.
- **338dc10** dropped header nav pills (nav is sidebar's job) + filled chevron buttons.
- **49aeb55 / c448fe0** two-tier header category nav → later removed (pills out of header).
- **e92d6f6** **/forum → /hub rebrand** (+ "The Hub"), active_nav 'hub'. Coordinator flipped nginx.

## OPEN — needs attention (the "regressions" thread, 2026-06-03)
Symptoms reported on /hub/: logged-out header + a reply author with no name ("T user") +
"replies missing functionality." **Investigation conclusion: NOT caused by my UI commits.**
- **Logged-out header** = `/whoami` returning anon. My header-convergence change made the
  header trust /whoami fully (removed the JWT fast-path that used to mask a flaky whoami).
  → Candidate fix (needs lg-shell OK, they own the header contract): use the verified
  looth_id JWT as the **authenticated anchor** so a valid session stays logged-in when
  whoami blips, while still sourcing display from /whoami. See also `docs/handoff-hub-userdb-drift.md`.
- **Nameless reply authors** = 17 replies reference author_ids (1509, 205…) with **no row in
  `forums.person`** (dev has only **501 person rows — a partial fixture**). Per dev-fixtures
  rule this may be expected on dev; widening the person fixture would restore names.
- A **backfill/person-sync ran today ~17:38** (sync_state last_backfill_at, person sync_at) —
  likely the trigger for both. Confirm whether the dev DB was reloaded/re-synced.
- **Reply read-path verified WORKING** (live): View N replies → 5 rows + Load-more, nesting,
  sort, deferred images, reply chips. So the "missing functionality" is the auth/data layer,
  not the reply code.

## Reply-write endpoint quick-ref (for /stream/ wiring)
`POST /bb-mirror-api/v0/reply` body `{topic_id, content, reply_to?, media_ids?}`; same-origin
WP login cookie = author. 200 published / 202 pending / 429 flood (Retry-After) / 401/403/404.
Full contract + UI handling: `docs/reply-write-endpoint.md`.

## Cross-lane follow-ups
- **coral #c66845 + slate #6f8fa6** are a LOCAL brand extension (forums.css only) — NOT in
  `lg-layout-v2/src/theme/tokens.json`. To make official, lg-layout-v2 adds them.
- Header contract questions route to **lg-shell** (header keeper) via coordinator.

## Test accounts (dev)
admin uid 1 (iandavlin, keymaster — bypasses reply flood throttle); regular uid 1081
(subscriber — throttles). claude_admin (1904) may not exist → wp-cli falls back to first admin.

## Key files
- `web/forums/_topic-replies.php` — lazy thread fragment (5-row pagination, flatten, sort).
- `web/forums/_reply-render.php` — `bb_mirror_render_reply_stub()` (chips, deferred img, ↪).
- `web/forums/_feed.php` — feed + teaser + banner "+ New post".
- `web/_chrome.php` — sidebar nav (pills/chevrons/solo pill) + header $ctx (whoami identity).
- `web/forums.css` — palette (`--cat-*` + `--on-*`), pills, lightbox, sticky sidebar.
- `web/forums.js` — clickable card, lightbox, reply modal (reply_to), load-more, single-expand.
- `api/v0/reply.php` — reply-write endpoint. `bin/test-features.sh` — harness.
- `docs/HUB-EXPECTED-BEHAVIOR.md` — master behavior spec. `docs/reply-write-endpoint.md` — contract.
