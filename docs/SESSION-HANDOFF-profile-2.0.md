# profile-2.0 lane — Session Handoff

> Lane: the block-model profile/practice system replacing slice-0→3.5
> profile-app surfaces. Bootstrap: `docs/bootstrap-profile-2.0.md`.
> Retired predecessor: `profile-app/SESSION-HANDOFF.md` (chat a847d1aa —
> social+location backfills DONE @ `23fe81b`, don't redo). This file is the
> ACTIVE profile-2.0 chat's state.

## Status — Phase 1 GREENLIT; this turn = PLAN + SCAFFOLD only (2026-05-29).

Ian approved Phase 1 (relay `docs/reply-to-profile-2.0-phase1-go.md`); visibility
model is FINAL (commit `0641744`): **header is the ceiling**, effective block vis =
min(header, block); header private→profile private, header member→members-only
(logged-out hit join-gate, a 'public' block caps to member), header public→public
blocks peek through. Header DEFAULT (member vs public) = the one deferred knob
(next mockup; non-blocking).

**This turn produced PLAN + SCAFFOLD STUBS only — nothing applied/run/committed.**
Real build is interactive with Ian next. Deliverables:
- Build plan: `docs/plan-profile-2.0-phase1-build.md` (schema deltas + decisions
  A/B/C, pilot blocks, spine blocks, slice-4 crib, avatar store, build order).
- Checklist: `profile-app/PHASE-1-CHECKLIST.md`.
- Schema stub (NOT applied): `profile-app/sql/2026-05-30-block-system-spine.sql`.
- `profile-app/src/Block.php` (skeleton — block SETS + header-ceiling
  `effectiveVisibility`/`gateDecision`, stubbed).
- `profile-app/web/_render_blocks.php` (skeleton render loop + members-gate).
- `profile-app/bin/migrate-crib-slice4.php` (skeleton; exits 2, refuses to write).

**Key reuse insight:** the existing `profile_sections(key, visibility, data jsonb,
sort_order)` already holds per-block pmp — blocks map onto it; header vis lives in
a `key='header'` row (DECISION C). DB enum is `members` (plural) vs posts' `member`
(singular) — `Block::normalizeVis` bridges; keep the DB literal.

### Open decisions blocking the schema apply (Ian)
A. two-coord geo facet (coarse approx + exact). B. tighten approximate enum to
(public,members). C. header-vis home (sections row vs column). + vocab + header
default. See plan §1 / checklist top.

### Mockups (cookie-gated; `/var/www/dev/mockups/`, throwaway design artifacts)
- `profile-composer.html` — sidebar-palette block editor (the centerpiece).
- `profile-block.html` — `/u/` profile on the block model.
- `practice-repair.html` — typed practice (`practices.type=repair`).
View: `https://dev.loothgroup.com/mockups/<file>.html`.

### Iteration 2 applied two coordinator inputs (both now canon)
From `docs/reply-to-profile-2.0-block-sets.md` (APPROVED) +
`docs/reply-to-...` avatar contract (`plan-profile-block-system.md` "Avatar =
single source", `STRANGLER-COORDINATION.md` "Avatar / author-identity — SINGLE
SOURCE", `marching-orders` slice-4 image backfill):

1. **Entity-aware palette / overlapping block sets.** Composer is one tool, two
   libraries: `/u/` palette = shared + profile blocks; `/p/` palette = shared +
   practice blocks. Shared = location/about/gallery. **Storefront (hours,
   services, turnaround) = practice-only**; pulled off `/u/`. Composer has an
   entity switch that swaps palette filter + canvas.
2. **Separate headers.** Single identity-block-with-subject-toggle RETIRED →
   distinct **profile-header** ("me at a glance") + **practice-header** (name /
   type / tagline / location) block types.
3. **Only the header is REQUIRED**; everything else optional. Composer shows the
   header-only minimal state ("Preview minimal" → "Add your first block").
4. **pmp baseline = MEMBER** (the old `identity → public` default is RETIRED).
   Header comes in Member; opt up to Public (storefronts will) or down to
   Private. Profile mockup's viewer-switch: a Member-baseline profile shows a
   **members-only gate** to the public/logged-out web (profiles are
   members-community by default; public visibility is opt-in, per block).
5. **Avatar = single source.** Profile-header avatar is a spine-owned,
   user-editable field (initials-circle empty state), with an in-mock note that
   it's the one platform-wide image (header/forum/archive/bylines), edited only
   here. Practice "bench" notes staff faces are the same single-source avatars.

## Spine build · increment 1 — profile-header block (2026-05-30, WRITE-ONLY)

Schema is **dev-final** (canon: plan "Schema — RESOLVED dev-final"). Built the
profile-header (identity) block end-to-end, **write-only** — coordinator applies
the schema + runs the test plan next.

Resolved schema reflected: 3 adds (`users.at_a_glance` = single-source author bio,
backfill from WP `description`; `users.location_exact_visibility` default private;
`practices.type`, greenfield/user-set, NOT backfilled). Header vis = the profile's
OWN vis = section cap on `profile_sections` key='header' — NO column. NO approx-coord
column (centroid from geocoder). `members` DB literal kept; `Block::normalizeVis` is
the one DB↔UI ('members'↔'member') point. Migration default = everyone members-only
at cut (crib is a later turn, profiles-only).

Files written/finalized:
- `profile-app/sql/2026-05-30-block-system-spine.sql` — apply-ready, idempotent, NOT applied.
- `profile-app/src/Block.php` — block sets + header-ceiling rule
  (`effectiveVisibility`=more-restrictive, `headerCeiling`, `gateDecision`, `canSee`,
  `isCappedByHeader`) + `loadHeader`/`saveHeader` (pilot block from spine).
- `profile-app/web/_render_blocks.php` — `looth_render_profile_blocks` gate +
  `looth_render_header_block` (author-identity card) + members-gate.
- `profile-app/api/v0/me-header.php` — GET assembled header / PATCH at_a_glance +
  ceiling vis; WP `description` mirror + whoami purge (best-effort).
- `profile-app/src/Profile.php` — `at_a_glance` added to `loadFull` (additive).
- `profile-app/PHASE-1-INCREMENT-1-TEST.md` — apply cmd + truth-table/gate/round-trip tests.

⚠️ **config.php gap (flag):** config.php `require_once`s each src class but is
shared w/ shim-replacement (hard stop — didn't edit). `me-header.php` +
`_render_blocks.php` therefore `require_once .../src/Block.php` themselves.
Coordinator should add `Block.php` (and later social classes) to config.php's
require list for consistency, then the per-file requires can drop.

## Social-layer round — PLAN + SCAFFOLD + mockup iter3 (2026-05-30)

Ian locked the **social LAYER** (connections + messaging) into profile-app's scope
(`STRANGLER-COORDINATION.md` social block) + finalized the visibility model. This
turn = plan + scaffold + mockup only (same hard stops). Scope: build-thin in-house
on postgres, home = profile-app, **CUT-DAY-REQUIRED** (P-list blocker with the
spine), seed history from `wp_bp_friends` + `wp_bp_messages_*`. UI split: Connect/
Message buttons on `/u/` (profile-app) + header modals (lg-shell P9) → one
profile-app backend.

Deliverables:
- Plan: `docs/plan-profile-2.0-social-layer.md` (connections + threads/messages/
  recipients schema, crib, API, gating, build order).
- Checklist: `profile-app/SOCIAL-LAYER-CHECKLIST.md`.
- Schema stub (NOT applied): `profile-app/sql/2026-05-30-social-layer.sql`.
- `src/Connections.php`, `src/Messaging.php` (skeletons).
- API stubs (501): `api/v0/me-connections.php`, `me-messages.php`, `me-thread.php`,
  `me-social-counts.php`.
- Crib skeleton: `bin/migrate-social-from-bb.php` (exits 2, refuses to write).
- Mockup iter3: `profile-block.html` now shows Connect + Message buttons,
  View-as Public/Member/Me, a **header-ceiling toggle (Member vs Public)** to
  compare Ian's deferred default, and **peek-through** (public header → public
  blocks peek + "members see more — join"); effective vis = more-restrictive
  (header, block).

### Visibility model — FINAL (commit `0641744`, render/UI logic; schema unchanged)
Header is the CEILING; effective block vis = more-restrictive of (header, block).
header private→profile private; header member→members-only (logged-out join-gate,
'public' block caps to member); header public→public blocks peek through.
**Header DEFAULT (member vs public) = the one deferred knob** — Ian rules on the
mockup; non-blocking.

### Social-layer open decisions (Ian)
who-can-DM (any-member vs connections-only) · ship follow now? (verify
`wp_bp_follow` on live) · header counts via dedicated `me-social-counts` (rec.)
vs `/whoami` (shared — needs sign-off) · contact-reveal hybrid pilot timing.

## Locked decisions carried forward (don't regress)
- Block-level pmp (no per-row socials vis). **Baseline now MEMBER** (changed this
  iteration; was public for identity).
- Location = the one visibility×specificity block: approximate (public|member) /
  exact (member|private|on_request); coarse-coord geo; exact never in search idx.
- `tier_badge` derived from /whoami — never user-authored / LLM-drafted.
- Only the header is required per entity.
- Avatar single source = profile spine owns `users.avatar_url` + image store +
  versioned per-uuid URL; slice-4 adds the avatar IMAGE backfill.

## Next (after reaction-confirm) — Phase 1 SPINE
Migration target; must be dev-FINAL before the data crib (one migration). The
profile-header / practice-header split + the **member pmp baseline** must be
reflected in the spine schema before it's frozen. Schema adds still owed:
`users.at_a_glance`, `users.location_exact_visibility`, `practices.type`
(`users.location_address` already shipped @ `23fe81b`).

## Coordination
- profile-app/ shared with shim-replacement chat (`d9380b73`). **Flag coordinator
  before editing `Whoami.php` / `config.php`.** Phase 0 touched neither.
- Cross-lane / contract changes route through the coordinator.
- §0 commit discipline: edit in repo, stage by pathspec, commit + push. Mockups
  live outside the repo (`/var/www/...`) and are throwaway.

## Lineage
This chat session ID: `1c98b564-ae29-4bc2-af2d-b06f80498aa4`. Spawned by
coordinator. Phase 0 iter 1 (21:31) → iter 2 (this turn) per Ian relay.
