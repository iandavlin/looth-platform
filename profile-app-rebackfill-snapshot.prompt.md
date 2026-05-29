# profile-app — full-snapshot location rebackfill (test prompt)

Replace the current `bin/regeocode-from-bb.php` (lat/lng-only patch) with a
**full snapshot rebackfill** that treats BB as authoritative for both the
text and the coordinates, and drops the Nominatim-derived prose that
slice 2.5 left lying around.

This is a standalone test — not yet slice 2.75. We want to validate the
simpler model before promoting it into the full slice.

## The model

For every WP user that has a `wp_usermeta.geocode_96` row OR a
`wp_bp_xprofile_data` row with `field_id=96`, snapshot:

| target column | source |
|---|---|
| `users.location_text` | `wp_bp_xprofile_data.value` where `field_id=96` (the literal user-typed string) |
| `users.lat`, `users.lng` | parsed from `wp_usermeta.geocode_96` (format `"lat,lng"`) |
| `users.location_city` | NULL |
| `users.location_region` | NULL |
| `users.location_country` | NULL |
| `users.location_postcode` | NULL |
| `users.location_precision` | `'city'` (conservative default — users opt to address-precision via the editor) |
| `users.place_id` | NULL |
| `users.place_result` | NULL |

**Rationale:** Slice 2.5's geocode pass forward-geocoded the typed string
through Nominatim and overwrote `location_text` with Nominatim's verbose
`display_name` (e.g. Evan Gluck's `"NYC, NY, USA"` became
`"New York, United States"`; Guitar Quackery's
`"260 W 36th St., New York, NY 10018, USA"` became a 12-comma Nominatim
prose string). Derived components are only useful for features we haven't
shipped (filter-by-city, region facets). When those features land we'll
populate components by **reverse**-geocoding the authoritative pin, not
forward-geocoding the loose string. Until then: NULL is honest.

If a user has since edited their location in the slice-2.5 editor, they
should NOT be clobbered. Detect this by checking whether
`users.updated_at > users.created_at + interval '1 minute'` AND
`users.location_text` differs from BB's xprofile-96 — skip those rows
and report them. (Adjust the heuristic if you have a better signal —
e.g. a `last_edited_by_user` timestamp if one exists.)

## Implementation

- New script: `bin/snapshot-location-from-bb.php` (don't delete the old
  `regeocode-from-bb.php` yet — keep it as historical reference; we'll
  retire it when 2.75 ships).
- Idempotent. Re-running on the same DB state should produce zero updates.
- Single transaction per user; commit per row is fine, the count is
  small (~660).
- Stats output on completion:
  - `updated` — row changed
  - `unchanged` — already matched the snapshot
  - `skipped_user_edited` — text differed from BB and updated_at suggests user edit
  - `no_bridge` — wp_user has no profile-app row (report wp_user_id list, ≤20)
  - `invalid_geocode` — malformed geocode_96 value
  - `no_text_no_coords` — neither xprofile-96 nor geocode_96 present (shouldn't happen but count it)

## Validation after running

Run these three checks and paste the output in your response:

1. **Evan Gluck** (wp_user_id=114) should end up with:
   - `location_text = 'NYC, NY, USA'`
   - `lat ≈ 40.825279`, `lng ≈ -73.947614`
   - `location_city/region/country/postcode` all NULL
   - `location_precision = 'city'`

2. **Guitar Quackery** (wp_user_id=727) should end up with:
   - `location_text = '260 W 36th St., New York, NY 10018, USA'`
   - `lat ≈ 40.753009`, `lng ≈ -73.992006`
   - components NULL

3. **Coverage summary:**
   ```sql
   SELECT
     COUNT(*) FILTER (WHERE location_text IS NOT NULL AND location_text <> '') AS has_text,
     COUNT(*) FILTER (WHERE lat IS NOT NULL) AS has_coords,
     COUNT(*) FILTER (WHERE location_text IS NOT NULL AND lat IS NULL) AS text_no_coords,
     COUNT(*) FILTER (WHERE lat IS NOT NULL AND (location_text IS NULL OR location_text = '')) AS coords_no_text,
     COUNT(*) AS total
   FROM users;
   ```
   Pre/post snapshot. We expect `has_text` and `has_coords` to roughly
   match (660 ± a few), and `coords_no_text` to be near zero.

4. **Idempotency check:** run the script a second time, confirm
   `updated=0` and all stats roll into `unchanged`.

## Out of scope

- No Nominatim. No external API calls. Pure DB → DB.
- No changes to the editor, the renderer, the API, or the directory.
- No `Profile::renderLocation()` precision patch (that's a separate fix
  in slice 2.75).
- No avatar backfill, no triage tool, no walk-onboarding.sh. Just the
  rebackfill rewrite.

## Deliverable

A short transcript:
- The new script content (or its diff vs the old one)
- First-run stats output
- The three validation outputs above
- Second-run idempotency stats

Then we'll judge whether the simpler model holds up before promoting it
into the slice 2.75 prompt.
