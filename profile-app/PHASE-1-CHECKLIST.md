# Phase 1 — spine build checklist (profile-2.0)

Plan: `docs/plan-profile-2.0-phase1-build.md`. The spine is the migration target —
**steps 1–8 must be dev-FINAL before step 9 (the crib) runs.** Surface each step
for reaction. Nothing here is executed yet (scaffold turn).

## Decisions — RESOLVED dev-final (plan-profile-block-system.md "Schema — RESOLVED")
- [x] **A.** NO approx-coord column — coarse "near me" comes from the city/state
      centroid the geocoder already returns; exact `lat/lng` stays the gated pin.
- [x] **B.** No enum tighten — approximate-vis clamp is app-layer (Block).
- [x] **C.** Header vis = the profile's OWN vis = section cap, on `profile_sections`
      key='header' row. NO column.
- [x] **Vocab.** `members` DB literal kept; one normalize point `Block::normalizeVis`.
- [ ] **Header default** (member vs public) — still Ian's open knob; NON-BLOCKING.

## Schema (review → apply on dev) — increment 1
- [x] `sql/2026-05-30-block-system-spine.sql` finalized to the resolved schema
      (3 adds; members literal; no approx col; idempotent). **NOT applied — coordinator runs it.**
- [x] Adds: `users.at_a_glance`, `users.location_exact_visibility` (default private),
      `practices.type` (+ CHECK). (avatar_version deferred to the avatar-edit increment.)
- [ ] Apply on dev; `\d users` / `\d practices` verify (test plan §0–1).

## Pilot block — profile-header (identity), increment 1 — DONE (write-only)
- [x] `src/Block.php` — block sets, normalize point, `effectiveVisibility`,
      `headerCeiling`, `gateDecision`, `canSee`, `isCappedByHeader`, `loadHeader`, `saveHeader`.
- [x] `web/_render_blocks.php` — header-as-ceiling gate + profile-header card + members-gate.
- [x] `api/v0/me-header.php` — GET assembled header; PATCH at_a_glance + ceiling vis
      (+ WP `description` mirror, whoami purge). `members`→`member` normalize wired.
- [x] `at_a_glance` added to `Profile::loadFull` read shape.
- [x] Test plan: `PHASE-1-INCREMENT-1-TEST.md`.
- [ ] **Coordinator: apply schema + run the test plan.**

## Pilot block — location (two-tier, user-managed pin), increment 2 — DONE (write-only)
- [x] `src/Block.php` — `loadLocation` (two tiers from spine), `coarsen` (no approx
      column — round stored pin), `EXACT_VIS_VALUES`/`PRECISION_VALUES`,
      `exactVisFromInput`, `visRank` fail-closed (on_request → private).
- [x] `api/v0/me-location.php` — built ON the existing endpoint: + GET (assembled
      block), + `location_exact_visibility`, + `precision`, + user-managed `pin`
      placement (conflict-guarded), PUT returns the re-assembled block.
- [x] `web/_render_blocks.php` — `looth_render_location_block` (ceiling-capped per
      tier; exact hidden to non-permitted; coarse approx dot; precision-aware pin).
- [x] `src/Profile.php` — address/exact_visibility/pin_precision in `loadFull`.
- [x] New idempotent schema: `sql/2026-05-30-location-pin-precision.sql` (NOT applied).
- [x] Test plan: `PHASE-1-INCREMENT-2-TEST.md` (HTTP authed pass noted BLOCKED on shim mint).
- [ ] **Coordinator: apply precision schema + run the test plan.**

## Spine blocks — craft + socials/links, increment 3 — DONE (write-only)
- [x] `src/Block.php` — `loadCraft` (instruments+skills+highlights via loadFull),
      `loadSocials` (website + links), generic `blockVisibility`/`saveBlockVisibility`,
      `CRAFT_KEY`/`SOCIALS_KEY`. No new schema (vis on profile_sections key).
- [x] `api/v0/me-craft.php` — NEW: GET assembled craft / PATCH visibility.
- [x] `api/v0/me-socials.php` — extended: +GET assembled block, +`visibility` in PUT
      (items path preserved, now optional).
- [x] `web/_render_blocks.php` — `looth_render_craft_block` + `looth_render_socials_block`,
      ceiling-capped, wired after location.
- [x] Test plan `PHASE-1-INCREMENT-3-TEST.md` (incl. inc1/inc2/inc3 HTTP curls, now
      unblocked) + runnable `PHASE-1-HTTP-TESTS.sh` (mints token, hits every /me block).
- [ ] **Coordinator: add nginx route + allowlist for `me-craft` (test §4); run HTTP tests.**
- [ ] **Ruling needed:** socials render in BOTH the inc-1 header (inline row) and the new
      socials block — keep both, or drop the header's inline row? (didn't touch header.)

## Spine blocks — next increments
- [ ] practice-header maps practices.{name,avatar_url,tagline,website,type}.
- [ ] connect block → then the migration crib (profiles-only, practices greenfield).
- [ ] "View as: Public / Member / Me" owner toggle (render layer).
- [ ] Match the mockups: `/var/www/dev/mockups/profile-block.html`, `practice-repair.html`.

## Remaining spine blocks (additive)
- [ ] craft (catalogs), socials, practices-card (`/u/`), staff/bench (`/p/`).

## Avatar single-source + media store
- [ ] Decide store layout `media/avatars/<uuid>/<version>.<ext>` (build plan §5).
- [ ] Editor upload → write bytes + bump `avatar_version` + fire identity-purge.
- [ ] Serve via profile-app pool (not wp-content). Initials fallback intact.
- [ ] ⚠️ Coordinate with shim-replacement (`d9380b73`) before any `/whoami` shape
      or `config.php` change — flag the coordinator.

## Spine sign-off
- [ ] Coordinator declares the spine dev-FINAL. Only then →

## Slice-4 crib (one pass, after sign-off)
- [ ] Implement `bin/migrate-crib-slice4.php` (stub today).
- [ ] Dry-run on dev; diff vs slice-3.5 rehearsal counts.
- [ ] `--commit` on dev; walk-onboarding green.
- [ ] (Cutover = coordinator-timed; outside Phase 1.)

## Hard stops (this turn observed)
No migration run · no schema apply/commit · no deploy · no git commit · no
`Whoami.php`/`config.php` edit.
