# Strangler Coordinator — Handoff

You're the coordinator. Project chats build in their lanes. Ian is the bus. You
hold the contract (`STRANGLER-COORDINATION.md`) + the docs + routing. You do NOT
make live changes; you capture decisions, write relays, wire dev nginx (you're
also box sysadmin `ubuntu`).

**Read this for the orient. Prior snapshot: `strangler-handoffs/2026-05-28-evening.md`.**

---

## LATEST — 2026-05-30 build phase (refresh; ~3/4 context, 1 compaction)

**Moved from design into BUILDING the profile spine.** Everything in the "morning"
section below is still canon; this is what's new + the live process state.

### Profile spine — increment 1 DONE + tested; schema APPLIED to dev
- **Schema dev-final AND applied** to the dev `profile_app` DB — the 3 adds
  (`at_a_glance`, `location_exact_visibility` default private, `practices.type`),
  header-vis on the `profile_sections` row (no column), no approx-coord column,
  `members` literal. Idempotent. `profile-app/sql/2026-05-30-block-system-spine.sql`.
- **profile-header (identity) block built + logic-tested GREEN**: ceiling math
  (`effectiveVisibility = min(header,block)`), `loadHeader` assemble, all 3
  render/gate branches (private→nothing / members→gate / public→card), write +
  validation + `member↔members` normalize round-trip. Files: `Block.php`,
  `_render_blocks.php`, `api/v0/me-header.php`, `Profile.php`. Fixture seeded:
  user id 3 ("Profile App Test", wp 1918).
- **BLOCKED: the authed HTTP round-trip** — can't mint a `looth_id` on dev (JWT key
  `/etc/looth/jwt-private.pem` is `looth-dev`-group; the DB is `profile-app`-peer;
  no user has both). → **shim-replacement's `/mint-token` now gates testing the
  WHOLE profile `/me` surface.** Relayed `reply-to-shim-mint-dev-priority.md`.
### Profile spine — increment 2 (LOCATION) DONE + tested; schema APPLIED (2026-05-30)
- **Location block built end-to-end + logic-tested GREEN**, committed `f8d91b2`,
  schema applied. Two-tier: **approximate** (city/region + coarse-from-city dot —
  `Block::coarsen` rounds the stored pin, NO approx column) governed by
  `location_visibility`; **exact** (gated `lat/lng` pin + address) governed by
  `location_exact_visibility`. **User-managed pin**: placement + `location_pin_precision`
  (exact|neighborhood|city — `precision='city'` folds the exact tier away) + per-tier vis.
  Ceiling-capped via `Block::effectiveVisibility`, now **fail-closed** (`on_request`→private).
  Built ON existing `me-location.php` (+GET, +exact/precision/pin). Tested: loadLocation
  two-tier assemble, truth table, render public/member/me, precision=city fold. Fixture user 3.
- **One new idempotent col** `location_pin_precision` (NOT NULL default `exact`, CHECK) —
  `profile-app/sql/2026-05-30-location-pin-precision.sql`, APPLIED. Separate file from the
  increment-1 migration (never edited the applied one).
- **Note:** `Block.php` still self-`require`d — needs adding to `config.php`'s require list
  (config.php is shim-shared, so left untouched by the write-only lane; coordinator's call).
- **BLOCKED (same as inc1): authed HTTP round-trip** on shim `/mint-token`. Logic tested
  via `sudo -u profile-app php`; HTTP pass closes when shim unblocks.

### Social lane — CONFIRMED (e9fd24ab) + ruled + LANDED
- Schema finalized + grounded vs live BB (friends **10,978**, `wp_bp_follow` EXISTS
  9,002, messages 1,881/370/219, notifications 49,603).
- **4 decisions RULED (Ian):** drop follow (mutual-only; auto-on-connect; don't
  migrate wp_bp_follow) · DM **connections-only** · notifications start-fresh +
  seed-unread · counts 9+ badge + 30-day prune, dedicated `me-social-counts`.
  Canon: STRANGLER-COORDINATION "Social decisions RULED."
- **✅ Social turn (`bywl7ob3o`) LANDED** — committed `ff23ba4` (drop-follow→dev-final,
  scaffold `Notifications.php` + `me-notifications.php`). Tree freed → increment 2 ran after.

### Live process state (successor: READ THIS)
- Lane turns: `claude --resume <id> --print --permission-mode acceptEdits` via Bash
  `run_in_background`. WRITE-ONLY (sandbox blocks their git/apply/`php -l`/screenshot).
  **Coordinator commits by pathspec + applies schema + tests after** = the "tested" gate.
- **idle-hold `/tmp/no-idle-shutdown` currently NOT set** (released after inc2). `touch`
  it before launching a lane turn, `rm` after it lands.
- **Resume gotcha:** `claude --resume` with `--print` needs the FULL session UUID, not the
  short id — the lane "ids" in this doc (`1c98b564`) are UUID prefixes. Full UUID lives at
  `~/.claude/projects/-home-ubuntu-projects/<uuid>.jsonl` (e.g. profile-2.0 =
  `1c98b564-ae29-4bc2-af2d-b06f80498aa4`). Short id errors out.
- Apply/test recipe: `sudo -u profile-app psql -d profile_app -f <sql>`; run PHP as
  `sudo -u profile-app php` (peer-auth DB). me/* routes in
  `/etc/nginx/snippets/strangler-profile-app.conf` (repo copy DRIFTED — don't deploy-clobber).

### Open / next
- Ian: **header default** (member vs public) — last visibility knob.
- Spine: inc1 (header) ✅ + inc2 (location) ✅ → **NEXT: craft + socials blocks** →
  crib (profiles-only, gated dev-final) → View-as toggle render.
- Coordinator chore: add `Block.php` to `config.php`'s require list (config.php shim-shared).
- shim: unblock dev `/mint-token` → then close the profile `/me` HTTP tests (inc1 + inc2).
- Backlog: tutorial/tour modal (lg-shell).

---

## Where we are (2026-05-30 morning)

This session pivoted from cutover-plumbing to **building the profile side of the
cut** — Profile 2.0 + the social layer became the main body of work. Forum URL/nav
cleanup landed; profile design is now fully locked and scaffolded (not yet built
for real).

### Profile 2.0 — design LOCKED, scaffold built, real build NOT started
Canon: `plan-profile-block-system.md` (design), `plan-profile-2.0-phase1-build.md`
+ `plan-profile-2.0-social-layer.md` (build plans + stubs), `marching-orders-profile-2.0.md`.
Lane `1c98b564` produced Phase-0 mockups (iter3) + Phase-1 spine scaffold +
social-layer scaffold — all **review-first, NOTHING applied/run/deployed**.

Locked decisions (all in the canon):
- **Two block sets** profile vs practice (overlapping; storefront = practice-only); **split profile-header / practice-header**; only the header is required.
- **Visibility = header is the CEILING.** Three tiers (public/member/private), unified with posts (public = a public post = open web). Effective vis = more-restrictive(header, block). Member baseline; public peeks through *beneath a public header*; private header → whole profile private. Hover on the block pmp control when the header overrides.
- **Avatar = single source** (profile spine; profile-app stores+serves a versioned per-user URL; read via `/whoami` + batch `users` lookup; EVERY surface — header, forum, archive banner, post author bylines, directory; initials fallback; image backfill at slice-4). Cross-cutting contract in `STRANGLER-COORDINATION.md`.
- **Media (avatars, forum reply images, galleries) = app-owned storage, not wp-content.**
- **View-as Public/Member/Me** = shipped owner control. **Edit happens ON the live `/u/`** (FE edit) — no separate composer/settings page; palette = overlay; per-block privacy set inline.
- **Social layer (connections + messaging) lives in profile-app, CUT-DAY-REQUIRED.** Build thin in-house on postgres; seed history from `wp_bp_friends` + `wp_bp_messages_*`; UI = Connect/Message buttons on `/u/` (profile-app) + header modals (lg-shell), one profile-app backend. WhatsApp considered, not a backend fit.
- **Name backfill → profile name ONLY** (business fills at practice level). **Location = user-managed pin** (placement + precision + per-tier visibility); directory map plots the managed pin.

### Open decisions awaiting Ian (none blocking)
- **header default** member vs public · **who-can-DM** any-member vs connections-only · ship **follow** now (verify `wp_bp_follow` on live) · header counts via dedicated `me-social-counts` (recommended) vs `/whoami` · contact-reveal hybrid timing.

### Forum / nav (DONE this session)
- `/forum/` is canonical; `/forums-poc/` + `/forums/` 301 to it (I wired nginx). bb-mirror nav fixes committed `bf35589` (unique slugs, `active_nav='forum'`, non-gated avatar default). lg-shell header repoint + host-relative logo `69f8570`. Dev test images seeded (real logo from live + placeholders; throwaway).
- Pending relay for profile: `reply-to-profile-directory-location.md` (shared header on directory + members map + location pin-manager + name backfill).

## Lane states
- **profile-2.0 `1c98b564`** — design locked, Phase-1 + social-layer scaffold done; awaiting Ian on open decisions + the real spine build. Directory/location relay queued.
- **bb-mirror `ed723d17`** — `/forum` cleanup + nav fixes done; Ian driving **reply-image upload** directly.
- **lg-shell `1d248347`** — header fixes shipped `69f8570`; still on P9 modals (which now include the social backend's messages/friends UI).
- **archive-poc `aec4f10b`**, **events** (COMPLETE), **shim-replacement `d9380b73`**, **poller** — per `CHATS-MENU.md`.

## Mechanics (learned this session — keep doing)
- **Commit by PATHSPEC, never `git add -A`** — shared working tree; `add -A` swept a neighbor's work into `d657ce8`. §0 updated.
- **Background lane turns**: `claude --resume <id> --print --permission-mode acceptEdits < seed` (run via Bash `run_in_background` for a completion ping). They CANNOT git-commit / `php -l` / CDP-screenshot (sandbox approval gate) — so **coordinator commits their output by pathspec + screenshots after**. This handoff pattern is the safety valve vs cross-lane bleed; keep it (don't loosen git/sudo for autonomous turns).
- **Idle-shutdown** (`idle-shutdown.service`, 30-min) WILL kill detached turns. Hold with `touch /tmp/no-idle-shutdown` for the run; `rm` after (else the box never sleeps / burns money).
- **Driving Chrome**: `chrome-dev-login` skill; cookies via `/claim` + wp-cli; mockups/screenshots to `/var/www/dev/mockups/` (gated, Ian has the cookie).

## Key docs
- Contract: `STRANGLER-COORDINATION.md` (avatar single-source, media-storage, social-layer sections added this session)
- Roster + pending relays: `CHATS-MENU.md`
- Profile design canon: `plan-profile-block-system.md`
- Build plans: `plan-profile-2.0-phase1-build.md`, `plan-profile-2.0-social-layer.md`, `marching-orders-profile-2.0.md`
- Lane handoff: `SESSION-HANDOFF-profile-2.0.md`
