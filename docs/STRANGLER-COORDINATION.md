# Strangler Coordination

Shared contract for surfaces that live outside the WordPress monolith but
need to know **who the viewer is** and **what they're entitled to**.

Consumers today (or imminently):

- **lg-patreon-stripe-poller** — sole writer of paid-tier roles (Arbiter)
- **lg-layout-v2** — runs inside WP; reads tier in-process
- **archive-poc** — separate PHP service at `/archive-poc/` (postgres `discovery` schema, see §3i)
- **profile-app** — separate Postgres service, JWT-authed
- **BB-forum strangler** — early planning, no shape yet

> **Status:** v0 draft, 2026-05-27. Open questions called out inline.
> Push back on anything that doesn't fit what you're building.

---

## 1. Tier vocabulary

The user identity has two axes. Don't collapse them into one enum.

### Axis A — Authenticated?

| State | Means |
|---|---|
| `anon` | No WP login cookie. No identity. |
| `auth` | Logged-in WP user. Has identity, profile, can comment, etc. |

### Axis B — Paid tier (gating)

| Role written by Arbiter | Canonical tier | Gating treatment |
|---|---|---|
| (none, anon) | `public` | sees public content only |
| `looth1` | `public` | logged-in but no validated paid entitlement — same content as public |
| `looth2` | `lite` | Lite paying |
| `looth3` | `pro` | Pro paying |
| `looth4` | `pro` | Pro comp (admin / VIP / guest) |

**Why looth1 maps to `public` for gating:** looth1 is the Arbiter's resting
state — every new WP user lands there so the parser has a row to write to,
and a lapsed Pro falls back there when no source reports a paid tier. It
carries no paid entitlement. Identity-aware features (commenting, profile,
BB read access, personalized rails) check `authenticated` instead.

### Provenance (sidecar, not part of the tier enum)

Most consumers don't care. Billing-aware UIs do:

- `provenance: paid` — looth2 / looth3 backed by an active Stripe or Patreon source
- `provenance: comp` — looth4 (admin/VIP/guest)
- `provenance: lapsed` — looth1 with at least one historical source row
- `provenance: new` — looth1 with no source rows yet

Use this to suppress "Manage subscription" for comps, or to surface
"Resubscribe" for lapsed users — not for content gating.

---

## 2. `/whoami` contract

Single canonical identity + entitlement endpoint for strangler consumers
that don't run inside WP. Mints once, every consumer reads the same shape.

**Home:** profile-app, served at `/profile-api/v0/whoami` on the
profile-app service. profile-app owns identity (reads from its own
Postgres from day one — see pre-cutover note below); tier is a lookup
it performs against WP via an internal user-context endpoint. Born
in profile-app from day one — no "start in poller, move to profile-app
later" intermediate step.

**Tier source has two implementations** behind the same internal
`/wp-json/looth-internal/v1/user-context/{id}` contract:

| Environment | Active tier-writer | user-context implementation |
|---|---|---|
| dev | `lg-patreon-stripe-poller` (Stripe + Patreon source-types, Arbiter picks winner) | `InternalRestController` reads from poller's source rows |
| live (cutover day 1) | `lg-patreon-onboard` + `lg-looth4-expiry` + `mu-plugins/looth-roles.php` + code-snippet #44 | **Patreon adapter** (new) reads from those existing writers, emits the same shape |
| live (post-Stripe-enable) | Stripe poller takes over Patreon source-type too; `lg-patreon-onboard` retires | Same poller's `InternalRestController` as dev |

Consumers (`/whoami`, archive-poc, BB-mirror) see the same response
shape regardless. Adapter is owned by the poller chat (same
InternalRestController pattern, just a different read source).

**Pre-cutover identity source:** profile-app reads its own Postgres
from day one (not WP). Slice 2.75 already snapshotted xprofile into
Postgres with adequate fidelity (display_name, slug, location). Any
drift between Postgres and WP usermeta in the pre-cutover window is
bounded and surfaces via the existing visual-audit ritual. Accepting
small drift avoids writing throwaway WP-reader code that gets ripped
out at cutover.

A thin WP shim at `GET /wp-json/looth/v1/whoami` proxies to profile-app
for any WP-side consumer that finds it more convenient (lg-layout-v2
generally doesn't need it — it has `$current_user` in-process).

**Endpoint:** `GET /profile-api/v0/whoami` (canonical) or
`GET /wp-json/looth/v1/whoami` (WP shim, same shape)

**Auth:** WP login cookie OR `Authorization: Bearer <JWT>` (profile-app style).

**Response (anon):**
```json
{
  "authenticated": false,
  "tier": "public"
}
```

**Response (authed):**
```json
{
  "authenticated": true,
  "user_uuid": "f20ad778-1e5e-5508-853b-ad928c499f2f",
  "wp_user_id": 1234,
  "slug": "evan-gluck",
  "display_name": "Evan Gluck",
  "avatar_url": "https://.../bpfull.jpg",
  "tier": "lite",
  "provenance": "paid",
  "capabilities": {
    "edit_posts": false,
    "manage_options": false,
    "edit_archive_poc": false
  },
  "cache": {
    "etag": "w/\"abc123\"",
    "max_age": 30
  }
}
```

`user_uuid` is the canonical identity primitive — consumers should key
off it, not `wp_user_id`. `wp_user_id` stays in the response as a
legacy bridge (WP shim consumers may need it) but is deprecated for new
code.

`avatar_url` ships in the same response so header renders don't need a
second round-trip.

**Stub-tier transition state:** profile-app may ship `/whoami` before
the poller's user-context endpoint lands. In that window, `/whoami`
returns `tier: "public"` for everyone and a `tier_unavailable: true`
flag. Consumers MUST treat `tier_unavailable: true` as "don't gate on
this response yet" — render as public, skip premium UI, but don't
permanently deny. Cleared one-line when the poller endpoint ships.

**Caching:**
- Server-side: Redis (or Postgres unlogged table fallback if profile-app
  isn't already running Redis), 30s TTL, key by `user_uuid` (or session
  token for anon).
- Response: `Cache-Control: private, max-age=30` + ETag.
- Invalidation: two triggers — (a) WP fires `do_action('looth_tier_changed', ...)`
  which POSTs purge to profile-app, (b) profile-app self-purges when any
  write to `/profile-api/v0/me/*` mutates identity for the same user.

**Internal-channel auth (poller ↔ profile-app):**

- Secret file: `/etc/lg-internal-secret` (root-readable, deploy-provisioned, single key used both directions)
- PHP constant: `LG_INTERNAL_SECRET`
- Header: `X-LG-Internal-Auth` (matches archive-poc's `X-LG-` prefix convention)
- Verify with `hash_equals()` (constant-time)

Same shape applies to both the poller's `user-context` endpoint
(profile-app calls) and profile-app's `purge-whoami` endpoint
(poller calls).

**Cache invalidation — centralized via WP action:**

Rather than wiring purges into Arbiter alone, every WP-side writer of
tier state fires a single action:

```php
do_action('looth_tier_changed', $user_id, $old_role, $new_role, $provenance);
```

Writers that fire it: Arbiter, UserProvisioner (signup grant), admin
role edits, refund/cancel paths. The purge handler subscribes to that
single action and POSTs `/profile-api/v0/internal/purge-whoami` with
`wp_remote_post` (blocking=false, 1s timeout, no retry — fire-and-forget).

This catches non-Arbiter writes that would otherwise leave stale cache.

**Capabilities map currently in scope:**

- `edit_posts` (lg-layout-v2 admin)
- `manage_options` (admin gates anywhere)
- `edit_archive_poc` (archive-poc FE editor)
- `moderate_forums` (BB-mirror mod actions) — computed from WP's
  bbp_moderator / bbp_keymaster / administrator capability set

The capability set is intentionally narrow — only flags a consumer
actually checks. Add new ones as consumers need them; don't speculate.

**Companion endpoint — batch identity lookup:**

`GET /profile-api/v0/users?uuids=<csv>` →
`[{ "user_uuid": "...", "slug": "...", "display_name": "...", "avatar_url": "..." }, ...]`

BB-mirror (and any future consumer rendering a feed/thread with many
author identities) needs this — calling `/whoami` per author is wrong;
that returns the current viewer's identity, not a third party's. Ships
alongside `/whoami` in the same profile-app slice.

**Cookie fast-path (optional):**
- `lg_tier` cookie keeps current archive-poc behavior — a fast hint so the
  first paint doesn't have to wait on `/whoami`.
- Consumers MUST treat `/whoami` as truth and reconcile if the cookie
  disagrees. Cookie is a hint, not authority.
- See §3 for the staleness problem this leaves open.

**Capabilities map:**
- Start narrow: only flags a consumer actually checks.
- archive-poc needs `edit_archive_poc` (currently planned as cookie-gated;
  fold into this instead).
- profile-app needs `edit_own_profile` + `manage_options` for admin tools.
- BB-mirror TBD.

---

## 3. Open seams

### 3a. Cookie staleness on mid-session role change

Today `lg_tier` is set at login. If Arbiter flips a role mid-session
(gift redeem, subscription canceled, refund-and-block), the cookie lies
until next login. archive-poc and the BB-mirror will both gate on stale
data.

**Options:**
1. Arbiter writes the cookie via a small REST endpoint the user's browser
   pokes on next request (needs a sentinel).
2. Strangler consumers always call `/whoami` (30s cache absorbs the cost).
3. Short cookie TTL (e.g. 5 min) + revalidate.

**Recommendation:** option 2. Cookie stays as a first-paint hint; `/whoami`
is the truth on every request that actually gates anything sensitive.

### 3b. Identity provider for strangler services

profile-app uses JWT minted by a WP webhook. archive-poc uses the WP login
cookie directly (same domain). BB-mirror is undecided.

**Recommendation:** every strangler hits `/whoami` regardless of auth
mechanism. The endpoint accepts both cookie and JWT. The consumer doesn't
care which the viewer presented — it gets the same response shape.

### 3c. looth1 semantics — placeholder, do not gate on

looth1 exists so the Arbiter has a row to write. New signups + lapsed
ex-Pros both land there. **Do not introduce gating logic that treats
looth1 as anything other than `public`.** If a future feature needs to
distinguish "never paid" from "lapsed", read `provenance` from `/whoami`,
not the role.

---

## 3d. BuddyBoss surface inventory + roadmap

The cohesion problem at cutover is that BB-themed pages (groups, profile,
old activity) wear different chrome than lg-layout-v2 posts and
archive-poc. Solving it doesn't require ditching BB entirely — it
requires ditching the BB *theme* while keeping BB *plugin* features that
are still in use.

### Group inventory (dev, 2026-05-27)

| Pattern | Groups | Real usage? | Disposition |
|---|---|---|---|
| **Regional "Local Looths"** (9) | SoCal (770), Tri State NYC (772), DMV (284), SW Ontario (282), PNW (285), Middle TN (279), Basque Country (268), Ohio (11), Ireland (10) | **Yes — only real group usage on the site** | Reskin at cutover. Long-term: durable communities, eventually overlap with profile-app location data (auto-derive membership?) — but well post-cutover. |
| **Auto-enroll topic groups** (5) | Business, Market Place, New Builds, Repair & Restoration, Tools/Spaces/Robots/Widgets — all ~1784 members | **No — vestigial from an old per-forum activity-feed display scheme** | Delete after cutover. Frees ~9000 junk memberships. |
| **Small conversational topic** (4) | General Chat (97), Dank Memes (53), Music (36), Charla General (14) | Light | Reskin at cutover; revisit later. |
| **Internal/admin** (2) | The Jannies (2, hidden), Looth Group Partners (5, private) | Internal | Reskin or leave. Negligible. |

### Reskin approach (cutover scope)

CSS-only. Capture BB's rendered group-page HTML + relevant CSS rules, drop
into our own templates so groups inherit the unified header/footer/
typography. BB plugin keeps running the group machinery (membership,
posting, forum threads) underneath. BB is GPL'd — no licensing concern.

Same approach applies to any other BB-rendered surface that's lightly
used but still needed: messages, notifications, member-typing area.
Reskin to match site chrome; don't reimplement.

### Group inventory — correction (2026-05-28)

**Earlier framing said the 5 big "auto-enroll" topic groups were vestigial
and should be deleted. That's wrong** — those entries (Repair and
Restoration, New Builds, Tools, Business, Market Place) are **parent
forums whose subforums hold the actual content**. They look empty
because `topic_count` is direct-children-only; `total_topic_count`
shows hundreds-to-thousands of topics in subforums. They stay.

The 1786-member-each count comes from auto-enrolling everyone for
visibility at the parent level; topics live one level deeper. BB-mirror
initially missed this; now corrected.

**Real group categorization, updated:**

| Pattern | Status |
|---|---|
| Parent forums with subforums underneath (Repair, New Builds, Tools, Business, Market Place) | **Stay — functional hierarchy, not vestigial** |
| Regional "Local Looths" groups (9) | The only true "group" usage. Collapse into forum-with-decoration at cutover (see §3f BB-mirror updated scope) |
| Small conversational topic forums (General Chat, Dank Memes, Music, Charla General) | Stay as forums |
| Internal / admin (The Jannies, Looth Group Partners) | Stay, visibility-filtered out of public list |

### Group-as-forum-with-decoration (collapse, 2026-05-28)

Ian's call: BB-groups primitive collapses into "forum with extra
decoration" at cutover. The word "group" stays as UX label. Underneath:

- Each Local Looths group becomes a forum (each already has one
  attached anyway)
- Forum schema adds `avatar_url` (+ keep existing `description`)
- "Subscribe to forum" semantics relabeled to "join group" in UI
- Custom header per group-forum (e.g. "SoCal Looths" header above the
  forum topic list)
- No separate `/groups/` surface needed — directory of groups becomes
  a "Local Looths" category in the forum index
- Per-group activity, photos, docs, etc. — handled per BB-DECOMMISSION-INVENTORY

What we lose: very little. The word stays; member-list semantics stay
(via subscriptions relabeled); custom header preserves visual identity.
What gets simpler: no archive-poc group-landing composer needed, no
group-directory rail, no `/groups/` surface, no group-scoped activity
filter (group is just a forum).

### Decision rule for BB features not yet surveyed

### Decision rule for BB features not yet surveyed

When you find a BB feature in use post-cutover, pick one:

- **Reskin** if it works fine but looks wrong (cheap, days)
- **Replace** if it's central enough to invest in a strangler version (weeks)
- **Drop** if the inventory shows nobody uses it (free)

Don't replace what reskinning solves. Don't reskin what dropping solves.

---

## 3f. BB-mirror scope (confirmed 2026-05-27)

Read-side strangler for forum threads only. Reskin everything else.

| Surface | BB-mirror? | Where it lives instead |
|---|---|---|
| Forum threads (forums, topics, replies, pagination, search) | **yes — primary** | — |
| Forum subscriptions (read state) | yes | — |
| Activity feed | no | archive-poc |
| Group membership (user ↔ group) | consume only | profile-app Postgres post-cutover |
| Group home pages / member directory | no | BB plugin + reskinned CSS |
| Messages, notifications, presence | no | BB REST authoritative; reskin only |

**Service shape:** own FPM pool, own nginx location `/forums/*`,
mu-plugin sync. Storage = `forums` schema in the shared postgres
instance (see §3i). Failure isolation comes from schema separation,
not from a separate DB engine.

**Write path:** all writes (post reply, new topic, subscribe) round-trip
through BB REST (`/wp-json/buddyboss/v1/{reply,topics,forums/subscribe}`)
so mentions, moderation, notifications, and presence keep working
unchanged. Pattern is JS → fetch BB REST → reload, same as
`lg-fe-editor.js`. BB-mirror is read-side strangler only.

**Sync:** mu-plugin `bb-mirror-sync.php` on `bbp_new_topic`,
`bbp_new_reply`, `bbp_edit_*`, trash/spam, merge/split. Reconciliation
cron walks `wp_posts WHERE post_type IN (forum,topic,reply) AND modified
> last_reconcile` as belt-and-suspenders for missed webhooks.

**Forum visibility data:** mirrored into BB-mirror's `forums` schema
at sync time (BB postmeta `_bbp_forum_visibility` etc). Forum
visibility changes near-never; mirroring it locally avoids per-request
WP calls and keeps the mirror's database the source of truth for its
queries.

**Logged-out anonymizer coordination (flagged 2026-05-28 by Ian):**
There's an anonymizer plugin on live that handles what logged-out
viewers see. Forum-privacy logic in BB-mirror needs to check in with
that plugin's behavior — i.e. an anon viewer hitting `/forums/<slug>/`
should see whatever the anonymizer says they should see, not bypass
it. Plugin name + location TBD — Ian to point at it when BB-mirror
gets to anon-visibility work. Until then: render conservative
(don't expose anything to anon that isn't already exposed via the
existing WP rendering path).

**Single-mod model:** Ian moderates everything. No per-forum mod
migration needed. BB-mirror's open Q on `forum_moderator` table is
formally closed — defer indefinitely. Mod actions stay sitewide
through BB admin until/unless that changes.

**Search:** BB-mirror owns its own postgres FTS index (tsvector + GIN)
for forum content. archive-poc indexes editorial posts (events,
articles), not forum replies — different domain, different schema.
Don't cross-couple. Cross-schema queries are available if a feature
genuinely wants them.

**Cutover-day routing fallback:** when nginx flips `/forums/*` to the
BB-mirror upstream, keep BB's native templates available behind
`?bb_native=1` for the first week as a kill-switch. Cheap escape hatch;
production routing flips always need one.

**Group-scoped views:** the 9 regional Local Looths groups read
membership from profile-app post-cutover (not from BB's
`bp_groups_member`). Pre-cutover, mock with a hardcoded membership
table or no-op the group-scoped view.

---

## 3j. Mobile considerations — lens for every decision

Mobile app is in the immediate horizon (Ian: "we are def going to produce a mobile app"). No mobile codebase exists yet, but every cross-cutting decision should pass through the mobile lens.

**Questions every cross-cutting decision answers:**

1. **Latency budget** — does this introduce per-request latency that won't fit mobile UX expectations (sub-200ms perceived)?
2. **Offline behavior** — does this assume an always-on connection, or can the mobile client cache + reconcile?
3. **API shape for non-browser consumers** — is the contract usable from a non-browser HTTP client (clean JSON, no cookie-only auth dependency, no SSR-only data)?
4. **Push notification fit** — does the data have a natural event-stream shape that could surface as push later?
5. **Concurrency under fan-out** — does this scale to N mobile clients reading simultaneously without serializing?
6. **Auth pattern** — does it support JWT/bearer-token auth alongside cookie auth, since mobile won't have a browser cookie jar?
7. **Read/write split** — can mobile read directly from data sources without going through full WP-render pipelines?

**Already baked-in (record):**
- Postgres-everywhere chosen because mobile concurrency is the binding constraint (SQLite writer-lock model doesn't scale to fan-out)
- `/whoami` contract includes `user_uuid`, `avatar_url`, `capabilities` — all mobile-friendly shapes
- BB-mirror data model designed for mobile read API patterns (cached + queryable, not BB-REST-only)
- lg-shell modal layer naturally translates to mobile sheets/drawers (same UX primitive)
- profile-app JWT auth + cookie auth dual-supported

**When mobile is being built:** spawn a real "mobile" workstream chat (similar to BB-mirror, lg-shell). It becomes the mobile-decisions authority by owning the codebase + API contract. No standing "mobile warden" needed — the lens lives in this canon + chat awareness.

---

## 3i. Storage architecture — one postgres, three schemas

**Primary driver: mobile is imminent.** Not a "someday" feature — Ian
expects it soon at current pace. Storage decisions are made against
that constraint, not against today's "single-user dev box" workload.

All three strangler surfaces share **one postgres server** (the existing
instance currently hosting `profile_app`). Each gets its own schema:

| Strangler | Schema | Datastore today |
|---|---|---|
| profile-app | `profile_app` | postgres (already there) |
| BB-mirror | `forums` (or `bb_mirror` — chat's call) | SQLite — **migrates at cutover** |
| archive-poc | `discovery` (or `archive_poc` — chat's call) | SQLite — **migrates at cutover** |

**Why postgres, not SQLite (per-surface):**
- Mobile = concurrent reads + writes from many clients. SQLite's
  single-writer model becomes a real bottleneck; postgres's MVCC
  doesn't.
- Mobile UX wants composite views ("forum activity in my groups,"
  "events near me with friends attending") — those are cross-schema
  joins, trivial in postgres, painful across SQLite files.
- Mobile-native tooling (PostgREST, RLS, realtime via NOTIFY/LISTEN,
  Supabase-style patterns) only exists for postgres.
- Schema iteration velocity matters as mobile features evolve;
  postgres's DDL is more permissive than SQLite's.
- Production observability (pg_stat_statements, pg_stat_activity)
  exists in postgres, opaque in SQLite.

**Trade-off being made:** archive-poc's individual search latency may
move from ~2ms (SQLite FTS5 in-process) to ~8ms (postgres FTS over a
socket) — still well under user-perception threshold (~100ms). The
loss at zero-load is offset under mobile concurrency: SQLite serializes
writes, postgres scales linearly with cores.

**Why one server, not three:**
- Combined workload is tiny relative to postgres capacity even with
  mobile. Three servers would compete for the same RAM anyway, with
  the overhead of three postmasters + three autovacuums.
- Cross-schema queries possible when wanted. Across separate servers
  needs foreign data wrappers (slow + fragile).
- One backup, one monitoring target, one set of credentials to manage.
- Splitting later is straightforward if any one workload outgrows
  shared hosting (`pg_dump` the schema, restore elsewhere, swap
  connection string). Don't pre-split.

**Why each strangler keeps its own schema:**
- Failure isolation at the schema level (bad migration in `forums`
  doesn't touch `profile_app`)
- Each chat owns its own schema migrations / DDL in its own code
- Clear data-ownership boundaries — no accidental shared tables

**Migration to postgres is a cutover-day task** for BB-mirror and
archive-poc. Both use `pgloader sqlite:///path/to/file.sqlite
postgresql:///dbname` for the data move. SQLite datasets are small;
migrations run in seconds. Each chat owns its own schema design + the
adapter from current SQLite shape to new postgres shape.

**Per-strangler DSN provisioning (canon, surfaced by archive-poc 2026-05-28):**

Mirrors the per-strangler nginx-snippet + per-strangler secret-file
patterns. Each strangler gets:

- Postgres role named after the OS service user (e.g. `archive-poc`,
  `bb-mirror`, `profile-app`) — hyphenated to match the OS user for
  peer auth. Owns its own schema. Granted `USAGE` on other schemas per
  cross-schema discipline below. Role names with hyphens require
  quoting in SQL (`"archive-poc"`); acceptable trade-off for clean
  peer auth.
- Password file at `/etc/lg-<strangler>-db` mode 640
  `root:<strangler-unix-user>`
- FPM pool env var `LG_<STRANGLER>_DSN` exported via `env[]` in
  `/etc/php/8.3/fpm/pool.d/<strangler>.conf`
- DSN format: `pgsql:host=/var/run/postgresql;dbname=looth` (Unix
  socket peer-auth, no user/password needed — pg role identity comes
  from the FPM pool's OS user)

Cutover checklist must include `apt install php8.3-pgsql && systemctl
reload php8.3-fpm` — easy to forget, breaks every strangler.

**Shared write-side role (`looth-dev`, surfaced by BB-mirror 2026-05-28,
extended by archive-poc 2026-05-28):**

Each strangler's web pool runs as its own pg role (e.g. `archive-poc`,
`bb-mirror`) — that role owns the schema and handles READS for page
renders. WRITES go through a shared `looth-dev` role:

- **Loopback `_sync.php` endpoint** runs on the `looth-dev` FPM pool
  because it needs `$wpdb` access
- **Backfill** runs as `sudo -u looth-dev wp eval-file ...` for the
  same reason (WP read + matching peer-auth role)

So `looth-dev` is the strangler's write-side role; the strangler's own
role is the read-side / schema-owner role.

Pattern:
- pg role `looth-dev` — used by the looth-dev FPM pool only (peer-auth
  match to `looth-dev` OS user)
- Granted `USAGE` on each strangler schema (`forums`, `discovery`,
  `profile_app` as applicable) + INSERT/UPDATE/DELETE on the specific
  tables the sync path writes to. Plus matching `ALTER DEFAULT
  PRIVILEGES` so future tables inherit (per archive-poc's
  2026-05-28 implementation).
- NOT granted SELECT-everywhere — minimum needed for sync
- Each strangler's own role still owns the schema (no ownership
  transfer); `looth-dev` is just an additional grant

When standing up a new strangler that has a `_sync` endpoint on the
looth-dev pool, **add the equivalent GRANT statements at schema-creation
time** so the sync writer can write. BB-mirror's pattern (sql/grants.sql
or equivalent) is the reference.

**Cross-schema discipline (canon, surfaced by profile-app 2026-05-28):**

> When schema A needs data from schema B, **A reads from B's schema or
> calls B's endpoints. B does not reach into A.**

Concretely:
- BB-mirror wants "topic author display info" → BB-mirror queries
  `profile_app.users` (read-only) or calls profile-app's
  `/users?uuids=` endpoint. profile-app does NOT add a query that
  reaches into `forums`.
- archive-poc wants "post author display info" → same pattern.
- profile-app exposes data through its own surfaces (schema reads or
  REST endpoints). It doesn't pull data from other schemas to enrich
  its responses.

**Why:**
- Data-flow direction is one-way per consumer (clear in code review)
- Ownership boundaries match call boundaries (the chat that owns the
  data owns the contract)
- Schema migrations in one lane don't break queries in another
- Future split-to-separate-server stays clean (each consumer already
  knows where its dependencies live; not silently coupled by JOINs)

The schema owner's contract is: stable column names, stable
relationships, deprecation warnings before structural changes. Same
discipline as REST APIs — schema is API.

---

## 3h. Stripe shipped dormant on live (cutover-day pattern)

`lg-patreon-stripe-poller` ships to live at cutover, but **disabled by
absence of credentials**. The Stripe poll tick runs but exits cleanly
when no Stripe creds are present; no `stripe` source rows get written;
Arbiter sees only Patreon-source rows (from the Patreon adapter, §2).
Effectively a no-op on the Stripe side until Ian's pricing decisions
land and Stripe creds get added.

**Why this pattern over a feature flag:**
- Disabled-state is *absence*, not a code branch — no "what if Stripe
  is enabled but in safe mode" bug surface
- Existing dev code ships unchanged; no flag-handling to add or test
- When Stripe is ready: add creds → polling lights up → real
  transactions begin. No deploy.

**Cutover-day checklist for the poller plugin:**
- Plugin code deployed to `wp-content/plugins/lg-patreon-stripe-poller/`
- `LG_INTERNAL_SECRET` define in wp-config (reads `/etc/lg-internal-secret`)
- `LG_PROFILE_APP_URL` define in wp-config (per §3g)
- `LG_PROFILE_APP_URL` populated with live profile-app host
- **NO Stripe API key, NO Stripe webhook secret** — these come later when Ian flips Stripe on
- nginx route `^~ /wp-json/looth-internal/` added (mirrors lg-member-sync exempt pattern)

**Stripe-enable later:**
- Add Stripe credentials (test-mode first)
- Run low-cash real transactions to verify Arbiter promotes from `stripe` source rows
- When clean: switch new signups to Stripe checkout flow
- lg-patreon-onboard retires gradually as Patreon-paying users migrate or churn

---

## 3k. Membership-page IA + account menu (2026-05-29)

**Decision:** the membership/Stripe pages stay **poller-rendered standalone
pages**, surfaced through the **shared header's account dropdown** — NOT folded
into profile-app's UI.

**Rationale:** authority separation. profile-app owns *identity*; the poller
owns *tier/billing/subscription* truth (profile-app does not store tier, §1/§2).
Putting "Manage Subscription" *inside* profile-app's surface would imply it owns
billing. IA ≠ code ownership: the unified header is the layer that makes
cross-app pages feel like one site, so account items can route to poller pages
and profile-app pages from one menu without merging codebases.

**Page buckets + homes:**
- **Account self-service** (manage-subscription, my-gifts, request-refund,
  affiliate-earnings) → shared-header **account dropdown**, next to "Edit Profile".
- **Acquisition** (join `/lgjoin/`, gift-buy, redeem-gift) → public CTA. "Join"
  is the header's anon CTA; lg-layout-v2 gate-cta block throws join CTAs on
  paywalled content. Can't live "in profile" — public users need them.
- **Informational** (membership-guide, billing/refund policy) → footer + content links.

**Consequences:**
- **lg-shell** converts `.lg-chrome__account` from a plain link into a dropdown
  (canonical sitewide account menu + sign-out, which the header currently lacks).
- **poller** membership pages drop their own `[lg_member_nav]` strip once on the
  shell — the header dropdown is the account nav now.

## 3e. Stripe poller out of WordPress (post-cutover)

The poller currently lives partly in WP (`lg-patreon-stripe-poller`
plugin) and partly in `/srv/lg-stripe-billing/`. The WP-side piece
carries Stripe API keys + webhook secret inside the WordPress filesystem
alongside arbitrary plugin/theme code. Any WP RCE → Stripe key exfil →
real money.

**Direction (not blocking cutover):**

Shrink the WP plugin to a thin shim. Move out of WP:
- Stripe webhook reception (own endpoint, own service)
- Polling loop
- Customer/subscription state cache
- Gift code logic
- Stripe API key + webhook secret storage

Keep in WP (necessary minimum):
- `wp_capabilities` writer (Arbiter — small mu-plugin, receives "user X
  is now tier Y" from the external service over an internal channel)
- Welcome modal footer hook (no secrets)
- Admin UI for now (eventually migrate to strangler dashboard)

External service runs as its own systemd unit, own user
(`stripe-poller`), no read access to `wp-config.php`. WP RCE no longer
equals Stripe compromise.

**Why not blocking cutover:** no specific threat triggered this; it's
hygiene. Same direction-of-travel as BB removal: clear path, no urgency,
queue it.

**Move it up the list if:** a security audit demands it, a PCI-adjacent
requirement lands, a near-miss happens, or the WP plugin surface grows
enough that the blast radius becomes uncomfortable.

---

## 3g. nginx organization

Strangler nginx routes are extracted into per-app snippet files:

- `/etc/nginx/snippets/strangler-profile-app.conf`
- `/etc/nginx/snippets/strangler-archive-poc.conf`
- `/etc/nginx/snippets/strangler-bb-mirror.conf`

Each is `include`d from `dev.loothgroup.com.conf` between the cookie-gate
exempt paths and the WP fallback `location /`. The main conf stays
scannable; new strangler = new snippet + one include line.

**Source-of-truth pattern:** each project's repo carries a
`nginx-snippet.conf` matching its deployed copy. Update flow:

1. Project chat edits its own `nginx-snippet.conf`
2. Ubuntu sysadmin (this coordinator) `sudo cp`s it to `/etc/nginx/snippets/`
3. `sudo nginx -t && sudo systemctl reload nginx`
4. Smoke-curl the affected routes

No more "edit the giant shared conf and pray" merges.

**Pre-cutover hardcoded URLs that need to become config:**

- `PurgeNotifier` in poller hardcodes `https://dev.loothgroup.com/profile-api/v0/internal/purge-whoami`.
  Needs `LG_PROFILE_APP_URL` constant (or wp-option) before live cutover
  so the call routes to the correct host.

---

## 4. Cutover sequence

> **⚠️ MODEL CHANGED 2026-05-28 — blue-green, not in-place.**
> Ian's decision: cutover is now **stand up a fresh EC2, build the full
> stack, backfill with current production data, swing DNS.** NOT in-place
> surgery on 54.157.13.77. Relaxed pace (build can take days); old box stays
> up through DNS propagation as natural rollback.
>
> **The authoritative execution plan is `/home/ubuntu/projects/cutover/CUTOVER-PLAN.md` (v0.3, 12-step blue-green).**
> Killed at launch: CF cache-purge (natural miss post-swing), user-visible
> comms (DNS swing is the only event), DNS-01 cert (HTTP-01 post-swing — no
> CF token). On-box postgres confirmed (migrate to RDS later if mobile load
> demands).
>
> The numbered list below is retained as the **dependency ordering** (what
> must exist before what) — NOT as the execution runbook. As of this
> session, steps 1–5 of the dependency chain are ✅ (`/whoami`, archive-poc
> gating, shared header all shipped on dev). What remains is migration
> scripts + dormant smoke + lg-shell modals, then the new-box build.

> **🔒 AUTH INVARIANT (2026-05-29, Ian):** Cutover ships ONLY dev-proven auth.
> **No first-time identity/auth changes on cut day.** Whatever auth model we cut
> over to must already be running and tested on dev before the flip. Corollary:
> the login-*authority* inversion (profile-app owning credentials, WP demoted to
> consumer) is a SEPARATE post-cut project with its own dev rehearsal — it does
> NOT ride the big cut.
>
> **xprofile wording fix:** the `migrate-from-xprofile.php` step is a *slim,
> non-clobbering data backfill* (field 1 → display_name, field 2 → business_name;
> location done separately in 2.75; socials via BATCH-06 backfill). It is NOT an
> "identity authority transfer" — earlier §4 phrasing oversold it. Identity DATA
> crib (dev-proven, run-once at cut) ≠ login AUTHORITY (deferred). Keep them separate.
>
> **In flight (pre-cut, dev-tested):** "mint looth_id at wp_login, retire the
> shim + per-page whoami loopback" — design doc commissioned from profile-app
> (lead) + lg-shell (`briefing-shim-replacement-design.md`). Issues our token at
> WP's login moment; WP still verifies passwords. Satisfies this invariant.

**profile-app cutover is the unifying event.** Templating fragmentation
between BB pages, lg-layout-v2 posts, and archive-poc has pushed this
from "slice 3 someday" to "the coordination event everything else keys
off of." Window: when ready, not scheduled.

Dependency order (NOT the execution runbook — see CUTOVER-PLAN.md v0.3):

**Cutover-day target architecture on live:** B-now/A-later (§2). Strangler
surfaces ship to live now with the Patreon adapter feeding `/whoami`.
Stripe poller ships dormant in same cutover. Stripe-enable is a later
config change (add creds), not a deploy.

1. **Postgres provisioned on live** (on-box install matches dev; ops
   simplicity). `profile_app`, `forums`, and `discovery` schemas all
   created here (§3i). One server, three schemas.
2. **`/whoami` ships in profile-app on dev.** Pre-req for everything
   else. Born in profile-app, not the poller. Returns identity (from
   profile-app's Postgres post-cutover) + tier (read from WP roles via
   poller on dev; via Patreon adapter on live).
3. **archive-poc migrates SQLite → postgres** (`discovery` schema).
   pgloader run in seconds. Application code updated to use postgres
   PDO; nginx route unchanged.
4. **BB-mirror migrates SQLite → postgres** (`forums` schema). Same
   pattern. Schema extended with `reply` + `attachment` tables for
   image and threading support.
5. **archive-poc switches from cookie-only to `/whoami`-backed** for
   any gate decision more sensitive than first-paint. `lg_tier` cookie
   stays as a first-paint hint only.
3. **Shared header partial** included by BB-theme replacement,
   lg-layout-v2, and archive-poc. Solves the visual-fragmentation
   problem at cutover without depending on full BB removal.
4. **profile-app slice 3 cutover** — runs `bin/migrate-from-xprofile.php`.
   This is the moment WP stops being identity authority. Reskinned BB
   group pages, archive-poc, lg-layout-v2 all pointing at `/whoami`
   before this fires.
5. **BB-mirror first read** — only after profile-app cutover so it can
   read identity from profile-app + tier from `/whoami` without ever
   touching xprofile directly.
6. **Post-cutover cleanup** — see §3d roadmap (delete vestigial groups,
   reskin remaining BB surfaces, eventually strangler-replace groups).
7. **Poller role-shape changes** (if any) — last. Every consumer reads
   through `/whoami`, so role renames become a one-place change.

---

## 5. "Who depends on whom" — at a glance

```
                    ┌──────────────────────────┐
                    │  lg-patreon-stripe-poller│
                    │   (Arbiter, sole writer  │
                    │    of looth1..4 roles)   │
                    └────────────┬─────────────┘
                                 │ writes roles
                                 ▼
                    ┌──────────────────────────┐
                    │     WordPress core       │
                    │   wp_users + wp_caps     │
                    └────────────┬─────────────┘
                                 │ reads
                                 ▼
                    ┌──────────────────────────┐
                    │  /wp-json/looth/v1/whoami│  ◄── single canonical
                    │  (identity + tier + caps)│      contract
                    └────┬────────┬─────────┬──┘
                         │        │         │
              ┌──────────┘        │         └───────────┐
              ▼                   ▼                     ▼
     ┌────────────────┐  ┌─────────────────┐  ┌──────────────────┐
     │  archive-poc   │  │   profile-app   │  │   BB-mirror      │
     │  (Postgres+PHP)│  │   (Postgres+JWT)│  │   (Postgres+PHP) │
     └────────────────┘  └─────────────────┘  └──────────────────┘

     lg-layout-v2 runs inside WP — reads tier from $current_user directly,
     does not need /whoami. But the gate-tier values it checks against
     (public/lite/pro) MUST match this table.
```

---

## 6. Open questions for each chat

**Stripe-poller chat:**
- Will you write the `/whoami` endpoint, or should it live in its own
  mu-plugin? (Poller owns the tier truth; reasonable home.)
- Arbiter invalidation hook for the `/whoami` Redis cache — agree to add it?

**profile-app chat:**
- Confirm profile-app does NOT store tier locally — always reads via
  `/whoami` (or carries it in JWT claims, refreshed every N min).
- Confirm cutover timing constraint with BB-mirror plans.

**BB-mirror chat:**
- Read identity from `/whoami` + profile-app, not from BB directly.
- Confirm `public | lite | pro` is enough for forum-read gating, or
  flag if you need looth4-vs-looth3 distinction (probably don't).

**archive-poc:**
- Switch admin-edit gate from `lg_edit_capable` cookie plan to
  `capabilities.edit_archive_poc` from `/whoami` — already noted as
  the cleaner option in the FE-editor handoff.
