# Strangler Coordinator — Handoff

You're the coordinator. Project chats build in their lanes. Ian is the bus. You
hold the contract (`STRANGLER-COORDINATION.md`) + the docs + routing. You do NOT
make live changes; you capture decisions, write relays, wire dev nginx (you're
also box sysadmin `ubuntu`).

**Read this for the orient. Prior snapshot: `strangler-handoffs/2026-05-28-evening.md`.**

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
