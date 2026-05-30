# Phase 1 — spine build checklist (profile-2.0)

Plan: `docs/plan-profile-2.0-phase1-build.md`. The spine is the migration target —
**steps 1–8 must be dev-FINAL before step 9 (the crib) runs.** Surface each step
for reaction. Nothing here is executed yet (scaffold turn).

## Decisions to settle with Ian first (block the schema apply)
- [ ] **A.** Two-coord geo facet — add `location_approx_lat/lng` (coarse) + repurpose
      `lat/lng` as exact? (stub has it commented `-- DECISION A`)
- [ ] **B.** Tighten `location_visibility` enum to `(public,members)`, or clamp in app only?
- [ ] **C.** Header vis home — `*_sections` row `key='header'` (recommended, no schema
      change) vs a dedicated `header_visibility` column?
- [ ] **Vocab.** Keep DB `members` literal + map to `member` in JSON/UI (recommended)?
- [ ] **Header default** — member vs public out-of-box (the one open knob; decide on
      next mockup; NON-BLOCKING for the schema).

## Schema (review → apply on dev)
- [ ] Review `sql/2026-05-30-block-system-spine.sql` (NOT yet applied).
- [ ] `users.at_a_glance`, `users.location_exact_visibility`, `users.avatar_version`.
- [ ] `practices.type` (+ CHECK), `practices.avatar_version`.
- [ ] (Decision A) approx coords + index, if approved.
- [ ] Backfill `practices.type` for existing dev rows before any NOT NULL.
- [ ] Apply on dev; `\d users` / `\d practices` verify.

## Pilot blocks (identity → headers + location)
- [ ] `src/Block.php` — fill `paletteFor`, `headerCeiling`, `gateDecision` (stubs).
- [ ] Header-ceiling unit checks: `effectiveVisibility` = min(header, block) truth table.
- [ ] `web/_render_blocks.php` — header gate + block loop + members-gate.
- [ ] profile-header maps users.{display_name,avatar_url,at_a_glance} + socials.
- [ ] practice-header maps practices.{name,avatar_url,tagline,website,type}.
- [ ] location two-tier: approx vis + exact vis; exact never in search index.
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
