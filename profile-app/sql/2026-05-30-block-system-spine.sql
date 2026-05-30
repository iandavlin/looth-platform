-- profile-app — Phase 1 block-system SPINE schema deltas.
--
-- ⚠️ STUB — NOT YET APPLIED. The spine is the ONE migration target and must be
-- reviewed dev-FINAL before the slice-4 crib runs (one migration, never two).
-- Do not run until Ian approves docs/plan-profile-2.0-phase1-build.md §1.
--
-- Confirmed adds (spec-block-identity-location.md "Schema adds"):
--   1. users.at_a_glance              — header summary line (person)
--   2. users.location_exact_visibility — exact-address tier vis
--   3. practices.type                 — repair|build|touring_tech|retail|…
--
-- Already shipped by the retiring chat (commit 23fe81b) — DO NOT REDO:
--   users.location_address, profile_socials kind 'linktree'.
--
-- Avatar single-source: users.avatar_version (bump-on-edit, drives cache-bust +
--   identity purge). Store layout in the build plan §5.
--
-- DECISION A/B/C are flagged inline for Ian — see build plan §1.

BEGIN;

-- 1. Header summary line (person). Practice uses practices.tagline (exists).
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS at_a_glance text;

-- 2. Exact-address tier visibility. Approximate tier stays users.location_visibility.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS location_exact_visibility text NOT NULL DEFAULT 'private';
ALTER TABLE users
    DROP CONSTRAINT IF EXISTS users_location_exact_visibility_ck;
ALTER TABLE users
    ADD CONSTRAINT users_location_exact_visibility_ck
    CHECK (location_exact_visibility IN ('members','private','on_request'));
-- exact must never be looser than approximate (location_visibility). Enforced in
-- app-validation (Block.php) — a CHECK across two columns needs a trigger; defer.
-- -- DECISION B: tighten location_visibility enum to ('public','members')? Today
-- -- it allows 'private' too. Left as-is; approximate-vis clamp done in app layer.

-- 3. Avatar single-source version (cache-bust + identity-purge trigger).
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS avatar_version integer NOT NULL DEFAULT 0;

-- -- DECISION A: two coordinate pairs for the geo facet.
-- -- Repurpose users.lat/lng as EXACT (gated by location_exact_visibility, never
-- -- indexed); add coarse city-centroid pair for proximity search ("near me"),
-- -- gated by location_visibility. Uncomment once Ian rules.
-- ALTER TABLE users
--     ADD COLUMN IF NOT EXISTS location_approx_lat numeric(9,6),
--     ADD COLUMN IF NOT EXISTS location_approx_lng numeric(9,6);
-- CREATE INDEX IF NOT EXISTS idx_users_approx_geo ON users (location_approx_lat, location_approx_lng);

-- 4. Typed practices. Required at creation (app-enforced); existing dev rows need
--    a one-time backfill value before a NOT NULL is added (flag: few on dev).
ALTER TABLE practices
    ADD COLUMN IF NOT EXISTS type text;
ALTER TABLE practices
    DROP CONSTRAINT IF EXISTS practices_type_ck;
ALTER TABLE practices
    ADD CONSTRAINT practices_type_ck
    CHECK (type IS NULL OR type IN ('repair','build','touring_tech','retail','teaching','other'));
-- (After backfilling existing rows, a follow-up can SET NOT NULL.)
ALTER TABLE practices
    ADD COLUMN IF NOT EXISTS avatar_version integer NOT NULL DEFAULT 0;

-- DECISION C: header visibility (the ceiling) is stored as a profile_sections /
-- practice_sections row key='header' — NO new column (keeps the vis model schema-
-- unchanged per the relay). If Ian prefers a dedicated column instead:
--   ALTER TABLE users     ADD COLUMN header_visibility text NOT NULL DEFAULT 'members';
--   ALTER TABLE practices ADD COLUMN header_visibility text NOT NULL DEFAULT 'members';

COMMIT;
