<?php
declare(strict_types=1);

/**
 * Slice-4 migration CRIB — one pass into the dev-FINAL spine. ⚠️ SKELETON, does
 * nothing yet. The body is TODO and `--commit` is hard-guarded off.
 *
 * RUNS ONLY AFTER the spine (sql/2026-05-30-block-system-spine.sql + Block render)
 * is reviewed dev-final and approved. One migration into the final shape, never
 * two. Orchestrates the existing slice scripts (it does NOT re-implement them):
 *   - bin/migrate-from-xprofile.php   — name / business (xprofile field 1/2)
 *   - bin/snapshot-location-from-bb.php — location_text + location_address (field 96)
 *   - bin/migrate-socials.php          — field 266 + ACF author_* (locked mapping)
 *   - bin/backfill-avatars.php         — EXTENDED slice-4: copy avatar IMAGE bytes
 *                                        into the app-owned store, set versioned URL
 *
 * Precedence (per locked design): existing profile_socials > xprofile > ACF > skip.
 * Three-tier is per (user, kind); cross-kind is additive (surprise #3, retired chat).
 * NOT swept: at_a_glance (no clean BB source — user authors / LLM drafts later),
 * tier_badge (derived), brand_* (practice-side, separate).
 *
 * Usage (once implemented):  php bin/migrate-crib-slice4.php [--commit]
 * Default = DRY RUN: walk, count, diff vs slice-3.5 rehearsal (1812 users,
 * 165 xprofile + 45 ACF social inserts, 2 kept_existing). No writes without --commit.
 */

require __DIR__ . '/../config.php';

$COMMIT = in_array('--commit', $argv, true);

fwrite(STDERR, "migrate-crib-slice4: SKELETON — not implemented. No-op.\n");
fwrite(STDERR, $COMMIT
    ? "  (--commit passed, but the crib body is a stub; refusing to write.)\n"
    : "  (dry-run; nothing to do yet.)\n");
exit(2);

/* ---- TODO: build order once spine is approved -------------------------------
 * 1. assert spine schema present (at_a_glance, location_exact_visibility,
 *    practices.type, avatar_version) — abort if a column is missing.
 * 2. for each bridged user (reuse reconcile-bridge first):
 *      a. name / business     ← migrate-from-xprofile (slim)
 *      b. location             ← snapshot-location-from-bb (approx/exact split,
 *                                DECISION A coords) + geocode
 *      c. socials              ← migrate-socials (precedence, locked mapping)
 *      d. avatar IMAGE         ← backfill-avatars (bytes → app store, version=1)
 *      e. header section row   ← seed key='header' vis = baseline (member)
 * 3. tally + diff vs rehearsal numbers; print per-user precedence for spot users
 *    (Ian wp=46, wp=269) as the slice-3.5 rehearsal did.
 * 4. only on --commit: wrap in a single transaction per user; idempotent re-run.
 * ---------------------------------------------------------------------------- */
