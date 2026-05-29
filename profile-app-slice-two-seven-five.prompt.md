Slice 2.75 of profile-app — cutover-prep + debt-paydown. No new user-facing
features beyond the location editor rework. You're on the dev box; read
~/.claude/CLAUDE.md and /home/ubuntu/projects/CLAUDE.md first, then read
/home/ubuntu/projects/profile-app-NEXT-SESSION.md for the conversation
context that produced this prompt.

All architectural choices below are decided. Do NOT re-litigate; just build.

## The big idea

Slice 2.5 shipped, an end-to-end cold-walk as Dorothy Parker surfaced bugs
the validation matrix missed, and a long architecture conversation landed
on a much simpler model than what was originally planned:

1. **Location privacy is a visibility gate, not a coordinate-fuzzing math
   problem.** Users pick the granularity by what they type into the picker
   ("Newark, NJ" vs "123 Main St, Newark, NJ"). They control who SEES the
   location via a simple `public | members | private` toggle. No render-side
   rounding. No deterministic jitter. No precision column.

2. **One atomic xprofile → profile-app migration at slice-3 cutover.** No
   dual-write, no ongoing sync. Slice 2.75 WRITES the migration script;
   slice 3 RUNS it. Un-mapped BB fields dump to a new
   `users.legacy_xprofile jsonb` column — lossless, invisible until later
   section types render them.

3. **Cold-walk validation becomes a slice-end ritual.** No slice ships
   green without a fresh-user cold-walk transcript + screenshots.

## Build order

### 1. DB schema additions (foundation — do first)

Add three columns to `users`:
- `location_visibility VARCHAR(16) NOT NULL DEFAULT 'members'`
  check constraint: `('public','members','private')`
- `archived_at TIMESTAMPTZ NULL`
- `legacy_xprofile JSONB NOT NULL DEFAULT '{}'::jsonb`

DROP (if present): `location_precision`. We're not using it. If the column
already exists from a prior slice, drop it and any reads/writes against it.
The privacy model is visibility-gated, not precision-shaved.

Drop the rounding logic in `Profile::renderLocation()` entirely. Location
text and coords are always emitted at full stored precision — *if* the
caller is allowed to see them per visibility check (next section).

Migration goes in `sql/2026-05-27-slice-275.sql`. Apply, verify via
`\d users`, commit.

### 2. Visibility-gated location API

New helper `Profile::canSeeLocation(int $viewerUserId, int $subjectUserId, string $visibility): bool`:
- `public` → true for everyone (even anon)
- `members` → true if `$viewerUserId !== 0` (authed)
- `private` → true only if `$viewerUserId === $subjectUserId` or viewer is admin

Wire into every public read site:
- `api/v0/directory-members.php` — for each row, blank out `lat`, `lng`,
  `location_text`, and `location_*` components when caller fails the check.
  Don't drop the row; just null the location fields.
- `api/v0/typeahead.php` — same blanking rule on location fields if it
  emits them (audit and decide).
- `web/u.php` (public profile render) — if the viewer can't see location,
  the header's pin + location line collapse to nothing. Header layout
  must handle "no location row" cleanly (already does per slice 2.5).
- `web/_render.php` (editor canvas) — owner sees their own location
  regardless of visibility setting. Always.
- The map endpoint (whatever feeds `/mockups/directory-map-live.html`) —
  same blanking rule. Anon callers get a sparse map (`public`-only users);
  authed callers get the dense map (`public` + `members`).

Default for all 1696 existing users at migration time: `members`. Set in
the schema migration via `UPDATE users SET location_visibility='members'
WHERE location_visibility IS NULL` (defensive; the DEFAULT should cover
it but new rows might race).

### 3. Editor: Nominatim autocomplete picker + visibility select

The slice-2.5 editor is a freeform text input that geocodes on save. That's
the architectural mistake that produced the "text says Portland, coords
say NJ" drift. Replace with a real autocomplete picker.

**Picker UX:**
- Debounce keystrokes 250ms before firing a query
- Query: `https://<nominatim-host>/search?q=<typed>&format=json&addressdetails=1&limit=5&viewbox=<ip-biased-bbox>&bounded=0`
- Display each result as `display_name` trimmed to `"city, region, country"`
  (parse from the `address` object; fall back to first 3 comma-segments of
  `display_name`).
- Dropdown caps at 5 rows. Loading spinner during in-flight query. Keyboard
  nav (up/down arrows, Enter selects). Click outside dismisses.
- On select: store `{location_text: display_name, lat, lng,
  location_city, location_region, location_country, location_postcode}`
  as one atomic save. NO post-hoc geocoding. The picker is the source of
  truth; freeform typing that bypasses the picker doesn't save.
- Zero-result state: show "No matches — try a more general place" with a
  "Save anyway as text only" escape hatch that saves text with null coords.
  These users will be invisible on the map but visible in the directory
  list.

**IP biasing:** server-side endpoint takes the caller's IP, looks up
approximate lat/lng via GeoLite2 (install: `apt-get install
geoipupdate`, free MaxMind account for the database). Pass a ~500km
viewbox around that point as `viewbox` to Nominatim. Result: typing
"Ridge" in NJ returns Ridgefield/Ridgewood NJ before Connecticut.
GeoLite2 download script + cron in `deploy/` so the DB stays fresh.

**Nominatim host:** we already host one for the slice-2.5 geocoding. Reuse.
Confirm rate-limit headroom (`Nominatim-Usage-Policy` allows 1 req/sec on
the public instance; ours is local so no external cap — but throttle to
5 req/sec per IP at our nginx layer so a runaway autocomplete client can't
melt it).

**Visibility select** sits below the picker as a single labeled control:
```
Who can see your location?
[ • ] Just members (default)
[   ] Everyone (public)
[   ] Nobody (private)
```
Saves to `users.location_visibility` immediately on change (no separate
save button needed — single-field auto-save, same as other editor inline
controls).

### 4. Full-snapshot rebackfill

The existing `bin/regeocode-from-bb.php` only writes lat/lng. Expand to a
full snapshot script `bin/snapshot-location-from-bb.php` (overwrite the
existing partial version):

For every user with a `wp_bp_xprofile_data.value` for field_id=96:
- Read BB text + `wp_usermeta.geocode_96` (lat,lng).
- Write to `users`: `location_text` = BB text (html_entity_decode first),
  `lat`/`lng` = BB geocode, and reverse-geocode via Nominatim to populate
  `location_city`, `location_region`, `location_country`, `location_postcode`.
- Idempotent: skip if `lat` and `location_text` already match BB source.
- Log per-row: `updated`, `skipped`, `no_bridge`, `no_source`.

For the 6 known no-coord cases (IDs 342, 880, 889, 1076, 1163, 1347):
Don't try to fix them in code. Document them in the cutover checklist
(see section 9) for hand-jigger at slice 3.

### 5. Avatar URL backfill

New script `bin/backfill-avatars.php`:

For every user, populate `users.avatar_url` if NULL:
1. **BB upload exists** → use that URL. Check `wp_bp_xprofile_data` for
   uploaded avatar attachments + the BuddyBoss avatar dir convention
   (`/wp-content/uploads/avatars/<user_id>/`). Find the `*-bpfull.*` file.
2. **Otherwise → Gravatar URL** with fallback to Looth default:
   `https://www.gravatar.com/avatar/<md5(lowercase trim user_email)>?d=<urlencoded looth default url>&s=400`
   Looth default url:
   `https://dev.loothgroup.com/wp-content/uploads/avatars/0/674d94a75ed58-bpfull.jpg`
3. Always store an absolute URL string. The rendering layer doesn't have
   to know how it was derived.

Idempotent. Log per-row: `bb`, `gravatar`, `skipped`. Total ~1696 rows.

### 6. `no_bridge` reconciler

Walk `wp_users`, ensure every WP user has a `users` row in profile-app —
including empty-email ghosts (115 found in slice 1, plus 41 more in the
geocode rebackfill).

New script `bin/reconcile-bridge.php`:
- `SELECT ID, user_login, user_email FROM wp_users`
- For each: if no `users` row exists, INSERT one with minimal fields
  (`wp_user_id`, `display_name` from WP, `slug` from `user_nicename`).
- `location_visibility` defaults to `members`.
- `archived_at` stays NULL — slice-3 triage decides what to do with ghosts.
- Idempotent. Logs `created`, `existing`.

### 7. Server-side bug fixes

- `api/v0/directory-members.php`: honor `?page_size=N` query param,
  hard cap at 200. Currently hardcoded to 20. Update the schema endpoint
  docs to reflect actual behavior.
- `api/v0/typeahead.php`: drop the silent skip on queries <2 chars. Either
  return zero results (preferred) or return a 400 with a clear error.
  Silently swallowing input is the worst option.

### 8. Tooling

**`bin/triage-accounts.php`** — read-only report:
- Duplicate accounts: same `user_email` across multiple `wp_users` rows,
  or same `display_name` with similar emails (Ian has ~15 such).
- Ghost accounts: `user_email = ''` OR `user_registered + N days` with no
  activity (no posts, no profile saves, no last_login).
- Output: TSV with columns `[ID, login, email, display, signals, would_archive]`
  where `would_archive` is the script's preview suggestion (yes/no). Ian
  pipes to `less`, eyeballs, picks what to archive interactively. Does
  NOT mutate the DB.

**`bin/walk-onboarding.sh`** — scripted CDP cold-walk:
- Creates a fresh WP user with a random email (e.g. `coldwalk-<timestamp>@looth.test`)
- Waits for the webhook to fire and confirms a `users` row was created
- Triggers JWT mint via the `/wp-json/looth/auth/issue` endpoint
- Loads `/profile/edit`, takes a screenshot
- Enters a location via the picker, picks a row, screenshots before/after
- Toggles visibility, screenshots
- Loads `/u/<slug>` as the same user (should 302 to /profile/edit)
- Loads `/u/<slug>` as anon (curl, no cookies), confirms only public-visible
  fields render
- Loads `/directory/members`, confirms the new user appears
- Loads `/directory/members?loc=<city>&radius=50`, confirms geo-filter
  includes the new user
- Reports any divergence from spec as a non-zero exit + stderr message
- Becomes the slice-end ritual. No slice green-lit without running this
  and pasting the transcript into the handoff.

Use the existing `chrome-dev-login` skill for the CDP plumbing — load it
via `Skill chrome-dev-login` at the start of the bash script, follow its
patterns for cookie setup + DOM event dispatch.

**`bin/migrate-from-xprofile.php`** — the BIG one. Written in 2.75, NOT
run yet. This is the slice-3 cutover script.

Per the locked field map:

| BB field | profile-app target | notes |
|---|---|---|
| 1 Full Name | `users.display_name` | only if current value is empty or obviously worse |
| 2 Business Name | `users.legacy_xprofile.business_name` | parked; slice 3+ surfaces in practices |
| 3 Handle | `users.slug` | only for the 598 where it differs from `user_nicename`; mirror back to `wp_users.user_nicename` so BB @-mentions resolve unchanged |
| 84–87 Shop Pictures | `users.legacy_xprofile.shop_pictures` (array of URLs, resolved from BB attachment IDs) | |
| 89 Resume file | `users.legacy_xprofile.resume_url` | |
| 91 Phone | `profile_socials` kind=phone | dedupe if existing |
| 92 Website | `profile_socials` kind=web | dedupe if existing |
| 96 Location + geocode_96 | already handled by snapshot script in 2.75 — re-run idempotently | |
| 97/98 References | `users.legacy_xprofile.references` | |
| 120–167 Employment History | `users.legacy_xprofile.employment_history` (array of objects) | |
| 132–135 Education | `profile_credentials` kind=education | |

Dedup rules:
- For `users.slug` collision (two BB handles → same slug after our normalization): loser keeps `user_nicename`, log the collision.
- For socials: don't double-insert if a row with same kind+value already exists.
- For credentials: same — kind+value uniqueness.

Dry-run mode by default. Requires `--commit` flag to actually write.
Print a per-user diff in dry-run so Ian can spot-check.

### 9. Cutover checklist (write but don't run)

Create `/home/ubuntu/projects/profile-app/CUTOVER-CHECKLIST.md`. This is
slice 3's working doc, not slice 2.75's, but populate it now so we don't
lose the context. Sections:

- Pre-cutover sanity: run `walk-onboarding.sh`, run `triage-accounts.php`
  output review, confirm Nominatim host is up + GeoLite2 DB is <30 days old.
- Hand-jigger the 6 unresolved locations (IDs + suggested fixes per the
  NEXT-SESSION analysis).
- Run `migrate-from-xprofile.php --dry-run`, eyeball diff, then `--commit`.
- Identity cleanup: stop reading first_name/last_name/nickname/display_name
  from `wp_usermeta` — profile-app is sole identity source post-cutover.
  `wp_users.display_name` becomes a one-way mirror from `users.display_name`
  (so wp-admin author bylines stay sane).
- Add Retail Sales + Tool Maker to practices catalog (slice 3 work, but
  the rows belong here on the checklist for tracking).
- Set everyone's `location_visibility = 'members'` (already the default,
  but confirm in production DB).
- Bake the BB @-mention compatibility: sync `users.slug` → `wp_users.user_nicename`
  post-migration for any user whose slug changed.
- Run `walk-onboarding.sh` again post-cutover — should still pass.

## What to NOT do in slice 2.75

- No practices, no skill-pack, no catalog expansion
- No live deploy
- No legacy widget changes
- No new section types (galleries, work history, references) — their
  data parks in `legacy_xprofile` until they exist
- Don't RUN `migrate-from-xprofile.php` — just write it
- Don't drop/archive any accounts in `triage-accounts.php` — it reports only
- No Photon swap — that's the slice 3.5 fallback if Nominatim feels janky
- No Google Places re-integration — Nominatim with slickening for launch

## Validate before declaring done

Run `bin/walk-onboarding.sh` against a fresh test user. The transcript
+ screenshots are the deliverable, full stop. No green ✅ without it.

In addition:
- Hit `/api/v0/directory-members` as anon. Confirm location fields are null
  on members-visibility rows.
- Hit same endpoint with a valid `looth_id` cookie. Confirm location
  fields are populated for both public and members rows; null for
  private rows.
- Hit `/api/v0/directory-members?page_size=100` and confirm 100 rows returned.
- Hit `/api/v0/directory-members?page_size=500` and confirm capped at 200.
- Editor: type into location picker, confirm dropdown appears within
  ~400ms of stopping typing. Pick a row, save, reload, confirm location
  renders correctly. Change visibility to "private", reload in incognito,
  confirm the location is gone from your `/u/<slug>` public page.
- Run `bin/snapshot-location-from-bb.php` twice in a row, second run
  should report only `skipped` for all rows.
- Run `bin/backfill-avatars.php`, sample 5 rows: confirm `avatar_url` is
  a real fetchable URL (curl -I returns 200).

## Deliverables

- All scripts in `profile-app/bin/`, all migrations in `profile-app/sql/`
- New `users` columns live in dev DB
- `walk-onboarding.sh` transcript + screenshots committed to
  `profile-app/handoffs/2026-05-27-slice-275-walk.md`
- Updated SESSION-HANDOFF.md with what changed + script run counts
- `CUTOVER-CHECKLIST.md` populated and committed
- 5-line "what surprised you" summary at the top of the handoff —
  especially anything about Nominatim quirks, GeoLite2 install, the
  visibility-gate API audit, or BB xprofile field types we missed in
  the mapping table

Don't ask permission to start. Read the NEXT-SESSION.md context, the
slice-2.5 handoff, then build. Ask only if a fix requires an
architectural choice that this prompt and the prior handoffs don't
constrain.
