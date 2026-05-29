# profile-2.0 lane — Session Handoff

> Lane: the block-model profile/practice system replacing slice-0→3.5
> profile-app surfaces. Bootstrap: `docs/bootstrap-profile-2.0.md`.
> Retired predecessor: `profile-app/SESSION-HANDOFF.md` (chat a847d1aa —
> social+location backfills DONE @ `23fe81b`, don't redo). This file is the
> ACTIVE profile-2.0 chat's state.

## Status — Phase 0, iteration 2 (2026-05-29). MOCKUPS, awaiting reaction.

Still pre-build. **No profile-app code / schema / migrations written.** Phase 1
(spine) does not start until Phase 0 is reaction-confirmed.

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
