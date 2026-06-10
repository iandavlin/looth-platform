# Bespoke Cutover — Charter, Survey & Launch Plan

**Author:** fable, session `3edb904c` — 2026-06-10
**Branch:** `bespoke-cutover` (worktree `~/worktrees/bespoke-cutover/`)
**Baseline:** tag `flag-pre-hub-refactor` on main + overlay snapshot in `hub-overlay-flag/`
**Ratified by Ian (this session):**
1. WordPress stays as the **private admin interface** (headless CMS); the public site
   is 100% bespoke. WP is fenced to admins, never publicly rendered.
2. Ownership consolidated: one coordinator chat (fable) runs the whole refactor with
   agents — lanes retired. Ian reviews all commits; git-tsar pushes.
3. **Launching WITH the Hub.** Launch scope = content site + events + billing + Hub.
4. Hub fix = finish the mobile/desktop engine split (no more collisions) — detail in
   `docs/hub-architecture-audit.md` §7 (~2 weeks, overlaps live wiring → ~2.5 weeks
   to launch).
5. Front page needs layout cleanup + tweaks (small; punch list TBD with Ian).

---

## 1. Where the strangler actually stands (survey, 2026-06-10)

~85% of public surface is bespoke and dev-proven. Four parallel surveys (WP install,
nginx routing, bespoke apps, data/identity) — key facts:

### Dev-proven bespoke surfaces
| Surface | App | Pool | Store |
|---|---|---|---|
| Front page `/front-page/`, archive `/archive/`, search | archive-poc | archive-poc | PG `discovery` (+SQLite hot cache) |
| Article/video/sponsor/event/document pages | archive-poc standalone renderer | archive-poc | PG `article_blobs` |
| Hub `/hub/` + APIs | bb-mirror | bb-mirror | PG `forums` (+`discovery`) |
| Profiles `/u/`, `/p/`, directory | profile-app | profile-app | PG `profile_app` |
| Events `/events/` | events app | events | WP postmeta via direct PDO |
| Billing `/billing/` | lg-stripe-billing (Slim) | lg-billing-dev | PG + Stripe |
| Membership pages (2 of 9 surfaces) | membership-pages | membership | WP options via PDO |
| Shared header/footer | /srv/lg-shared (lg-shell) | — | consumes /whoami |

Routing: nginx is the strangler boundary — every bespoke surface has its own FPM
pool + path prefix; WP (`looth-dev` pool) is the catch-all fallback. `/ ` already
302s to `/hub/`.

### Identity chain (works today)
WP login → `profile-auth.php` mu-plugin mints RS256 JWT (`looth_id` cookie,
UUIDv5-of-email sub) → all bespoke apps verify against `/etc/looth/jwt-public.pem`
→ profile-app `/profile-api/v0/whoami` is canonical; tier comes from WP roles via
the poller's internal user-context endpoint; `lg_tier` cookie is a hint only.

### What WP keeps (by decision #1 — and it's fine)
- **Admin/editorial:** lg-layout-v2 authoring, media library, ACF, user admin.
- **Auth root:** WP checks passwords + mints the JWT. Stays indefinitely.
- **Tier authority:** poller Arbiter writes WP roles (looth1–4). Stays; the
  billing-svc extraction (design-membership-rebuild.md) is post-launch.
- **Content storage:** wp_posts/postmeta feed the bespoke stores via sync hooks.

### What still must be BUILT (the real gap list, post-decision)
| # | Gap | Notes |
|---|---|---|
| G1 | **Hub render split** (the collisions/flash) | audit §7; ~2 weeks; launch-blocking |
| G2 | **Front page layout cleanup** | small; punch list from Ian |
| G3 | Hub write path off BuddyBoss REST | NOT launch-blocking (WP+BB stay installed; writes keep riding BB REST at launch) |
| G4 | Social migration: DMs (2,177), friends (~500), notifications → profile-app | post-launch; interim = legacy BB pages linked for logged-in members, or absent |
| G5 | WP fencing: lock /wp-admin + catch-all to admins; inventory remaining public WP URLs → redirect/own | post-launch (dev-gate hides this today; live needs it) |
| G6 | Sync hardening: reconcile job for `article_blobs` staleness; deploy `bb-mirror-sync.php` (still DRAFT — 10-min reconcile timer is the only forum sync) | pre- or at-launch (cheap) |
| G7 | Identity repair: 199 orphaned profile-app users (Patreon OAuth gap, synthetic `@invalid` emails) + 191 unbridged WP users — `reconcile-bridge.php` exists, needs rehearsal | at cutover |
| G8 | Decisions, not code: shop (Woo: drop/move?), FluentForms public forms (Form 38 lives on LIVE only — inventory before touching live WP), weekly digest | Ian |

### Data-migration risks (carry into every deploy plan)
1. Orphaned identity links (G7) — run + verify reconcile-bridge before flip.
2. `article_blobs` staleness — fire-and-forget 1s webhook, no reconcile (G6).
3. Forum sync skew — webhook mu-plugin is draft; 10-min timer only (G6).
4. Secrets sprawl — LG_INTERNAL_SECRET, profile_hook_secret, JWT keys,
   gate tokens live in 4 places; map + provision on live before flip.
5. Tier arbitration is implicit in poller code — document the authority chain;
   extraction is post-launch.

---

## 2. Launch plan

**Phase A+B combined (per decision #3): content site + Hub live — target ~2.5 weeks.**

Track 1 — Hub engine split (G1, ~2 wks): see audit §7 day-by-day. Front-page
cleanup (G2) slots into this track's tail.

Track 2 — live wiring (parallel, ~1 wk of work + rehearsal):
1. PG on live: DDL + roles/grants for `discovery` / `forums` / `profile_app`
   (schema files exist per-app); `php8.3-pgsql`; FPM pool envs (DSNs).
2. nginx: port the strangler confs to live (minus dev cookie gate, minus dev-only
   blocks: mailpit, cdp, code-server subdomains); keep the head-injection
   sub_filter (minus the lg-feed-booting hold once Track 1 lands).
3. Backfills, rehearsed on dev first: `backfill-pg.php` (content), person sync,
   avatar + location backfills, `reconcile-bridge.php` (G7).
4. Plumbing: JWT keypair on live, internal secrets, poller user-context route
   exempted, `lg-member-sync` callbacks, R2/media paths, mail (real SMTP — dev's
   mailpit pattern does NOT carry over).
5. Smoke suite: front page, article render (gated + ungated), Hub read + post +
   reply + react + save, login → whoami → tier, billing webhook, mobile 390px +
   desktop 1280px paint checks.
6. Soak + cut: flip is nginx routing on live; revert = restore old routes
   (WP untouched underneath). Post-reload checklist from memory applies
   (poller active, lgms creds, REST gate, bridge).

**Post-launch tail (3–4 wks, behind a live site):** G3 → G4 → G5 → tier
extraction → BuddyBoss retirement.

**Open dependency:** live-box access for fable (SSH) or Ian as deploy hands.
**Open decisions for Ian:** G8 trio (shop / forms / digest); front-page punch list;
interim DM story at launch (legacy BB link vs absent).

---

## 3. Working agreement (consolidated-ownership mode)

- All work on branch `bespoke-cutover`; small commits by pathspec; **no pushes
  without Ian's review** (git-tsar pushes).
- Served dev Hub = `/srv/bb-mirror → /home/ubuntu/projects/bb-mirror` (main tree).
  Fork changes are tested by merging increments to main after Ian review, or by
  temporarily pointing the symlink at the worktree during a verification session
  (flip back after).
- Overlay files (`/var/www/dev/*.js`) are now version-controlled via
  `hub-overlay-flag/`; edits to the live overlay get mirrored into the fork so
  history exists from here on.
- Agents do bounded reads/builds; this chat holds the architecture; continuity
  lives in this charter + the audit (successor sessions start here).
