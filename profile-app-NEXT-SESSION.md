# profile-app — handoff for next session (2026-05-26)

Previous Claude burnt a long thread on slice 2.5 validation, rebackfill, map
mockups, and a systemic-issues survey. This note hands you the conclusions
so you don't have to re-derive them. Don't re-litigate; **just confirm the
decisions and draft slice 2.75 from them.**

## State of profile-app

- **Slice 2.5 shipped** — JWT auto-mint, public-view fix, BB nav item, geocode
  via Nominatim, plus header "+ add your location" affordance. Validation
  matrix green per its own terms.
- **An out-of-band rebackfill ran**: `bin/regeocode-from-bb.php` now exists.
  It re-set lat/lng for 654 users from `wp_usermeta.geocode_96` (BB's
  autocomplete-time coords). Net: 660 of 663 location-bearing users have
  coords now (was 596 via Nominatim). +64 net new, plus precision upgrade
  on the overlap. Idempotent.
- **Map mockups live on dev**:
  - https://dev.loothgroup.com/mockups/profile-v2.html (editor concept)
  - https://dev.loothgroup.com/mockups/directory-map.html (map concept, 53 fake)
  - https://dev.loothgroup.com/mockups/directory-map-live.html (real data, 664)
- **Onboarding walked end-to-end as Dorothy Parker** (wp_user_id=1919,
  pa users.id=1698). Screenshots at `…/mockups/onboard-*.png` and
  `map-*.png`. The walk surfaced bugs slice 2.5 missed.

## Three new bugs (slice 2.5 ship debt)

1. **City-precision rounding too coarse.** `Profile::renderLocation()` rounds
   lat/lng to 1 decimal for `'city'` grant → 11km grid at 40°N. Guitar
   Quackery (40.7530, -73.9920 = Garment District) rounds to (40.8, -74.0)
   = Hudson River. Same artifact the legacy widget has. **Fix: round to 2
   decimals (~1.1km, neighborhood-accurate, still privacy-safe).**
2. **`page_size` query param silently ignored.** `api/v0/directory-members.php`
   has hardcoded `$pageSize = 20`; schema endpoint claims it's configurable.
   Live mockup had to page through 34 requests to load 664 users. **Fix:
   honor param, cap 200.**
3. **My rebackfill only updated lat/lng, not `location_text` or components.**
   So users who'd typed something into the editor (like Ian's "123 Test St,
   Portland") now have lat=NJ but text=Portland. The rebackfill should
   either do full-snapshot OR skip rows where text was user-edited.
   Subsumed by the bigger realization below.

## The realization that simplifies cutover

Ian asked: *"when we cut over can you just look at each xprofile field and
populate the new postgres field?"* — and yes, that's the right model.

**One atomic migration script. BB authoritative pre-cutover. profile-app
authoritative post-cutover. No dual-write. No ongoing sync. No drift to
manage.** The complexity I'd been worrying about — sync rules, authority
per field, full-snapshot vs partial — collapses into a single one-shot.

### xprofile → profile-app field map

| BB field | profile-app target | status |
|---|---|---|
| 1 Full Name | `users.display_name` | already populated |
| 2 Business Name | first auto-created practice (if user opts in) | needs decision + slice 3 |
| 3 Handle | `users.slug` (vanity) | populate at cutover |
| 84–87 Shop Pictures | gallery section | not built; → legacy_xprofile jsonb |
| 89 Resume (file) | doc upload | not built; → legacy_xprofile jsonb |
| 91 Phone | `profile_socials` kind=phone | wire up at cutover |
| 92 Website | `profile_socials` kind=web | wire up at cutover |
| 96 Location + `geocode_96` | `users.location_*` | mostly done; full snapshot in 2.75 |
| 97/98 References | references section | not built; → legacy_xprofile jsonb |
| 120–167 Employment History | work_history section | not built; → legacy_xprofile jsonb |
| 132–135 Education | `profile_credentials` kind=education | maps cleanly |

**Rule for un-mapped fields: dump raw into a new `users.legacy_xprofile jsonb`
column.** Lossless. Rendered when sections exist later. No cutover-blocking
dependency on building 4 new section types just for back-compat.

## Three decisions still owed by Ian

Ask him before drafting the slice 2.75 prompt:

1. **Un-mapped fields handling** — confirm "dump to `legacy_xprofile` jsonb"
   (my recommendation) vs build-the-sections-first (slice 3 dependency)
   vs skip-and-make-users-re-enter.
2. **Business Name (field 2)** — confirm "first auto-created practice
   prefilled with this value" (my recommendation, depends on practices
   existing → slice 3) vs new `users.business_name` column vs keep merged
   into display_name.
3. **Slug from Handle** — confirm "populate `users.slug` from BB handle
   field at cutover" (preserves their URL identity).

## Slice 2.75 scope (cutover-prep, no new features)

After Ian confirms the three decisions, draft the prompt with:

**Data fixes**
- Patch `Profile::renderLocation()` city precision: 1 → 2 decimal
- Full-snapshot rebackfill rewrite: replace partial-update with snapshot of
  (lat, lng, location_text, location_city/region/country/postcode,
  precision) from BB xprofile+geocode_96. The existing
  `bin/regeocode-from-bb.php` becomes the lat/lng portion; expand it.
- Avatar URL backfill on `users.avatar_url`: priority (BB upload exists →
  use that URL) → Gravatar URL with `d=<looth fallback>` → Looth default.
  Pre-compute for all 1696 users. Looth fallback path:
  `https://dev.loothgroup.com/wp-content/uploads/avatars/0/674d94a75ed58-bpfull.jpg`
- `no_bridge` reconciler: walk BB users, ensure profile-app row exists for
  every WP user including empty-email ghosts (115 of them per slice 1
  surprise + 41 found in the rebackfill).
- New column: `users.archived_at` + filter from directory/map/typeahead.
- New column: `users.legacy_xprofile jsonb` (will hold un-mapped fields at
  cutover; empty until then).

**Server bugs**
- Honor `?page_size=N` param on directory endpoint (cap 200)
- Drop the silent <2-char skip in typeahead endpoint

**Tooling**
- `bin/triage-accounts.php` — print duplicate accounts (Ian has 15)
  and ghost accounts (empty email or never-claimed), with "would archive"
  preview. Ian runs it interactively, picks what to archive.
- `bin/walk-onboarding.sh` — scripted CDP cold-walk: creates a fresh WP
  user, lets webhook fire, JWT-mints, hits /profile/edit, /u/<slug> anon
  + auth, /directory/members, takes screenshots, reports every divergence
  from spec. **Becomes slice-end ritual** — no slice green-lit without
  running this and posting the transcript.
- `bin/migrate-from-xprofile.php` — the BIG one, but only **written**
  in 2.75, **run** at slice 3 cutover. Walk every WP user, port xprofile
  fields per the mapping table, dump rest to legacy_xprofile.

**Scope cap**
- No practices, no skill-pack, no live deploy, no legacy widget changes,
  no new section types. Pure debt-paydown + cutover-prep tooling.

**Validation gate**
- Add to the prompt: deliverable MUST include
  `bin/walk-onboarding.sh` transcript + screenshots showing a fresh
  user can register → claim → edit → see themselves in directory →
  see themselves at correct map location. No green ✅ without it.

## Slice 3 (after 2.75)

Practices + run `bin/migrate-from-xprofile.php` + production cutover, as
one coherent slice. Cutover is no longer "just a deploy" — it's the data
migration moment. Don't separate them.

## Things this thread learned the hard way

- **Validation matrix ✅ ≠ user experience works.** Three slices in a
  row passed their matrices and immediately leaked bugs on a real
  cold-walk. The cold-walk is the only honest QA. Hence the
  walk-onboarding.sh ritual in 2.75.
- **City-precision rounding is math-disguised-as-privacy.** Designed it
  cleanly, implemented it sloppily, broke the user-facing UX. The
  2-decimal patch is the floor; smarter approaches (city centroid
  lookup, polygon jitter) are future work but unnecessary for v1.
- **Multiple sources of truth produce drift.** Single atomic cutover
  eliminates the class of bug, not just one instance. The Ian-instinct
  ("just walk the fields once at cutover") is architecturally cleaner
  than the "BB-authoritative-until-cutover-with-syncs" model I was
  proposing. Listen to that instinct.
- **Test account sprawl** — Ian has 15 accounts (test users, +plus
  aliases, .fart-TLD jokes). Triage tool in 2.75 lets him clean up
  before any of them become real public profiles.

## Pointers

- profile-app code: `/home/ubuntu/projects/profile-app/`
- Current SESSION-HANDOFF: `…/SESSION-HANDOFF.md` (slice 2.5)
- Rotated handoffs: `…/handoffs/2026-05-25-slice-{zero,one,one-five,two}.md`
- Prompt files: `/home/ubuntu/projects/profile-app-slice-*.prompt.md`
- Map mockups: `/var/www/dev/mockups/directory-map*.html`
- Onboarding screenshots: `/var/www/dev/mockups/onboard-*.png`
- Live legacy widget reference: Ian's screenshots in the prior thread
  (see his "719 MEMBERS" image — the visual language we're stealing for
  the production map: avatars-as-pins, colored cluster badges, modal frame)

## Next-session opening move

1. Read this file.
2. Ask Ian to confirm the three decisions above.
3. Draft `/home/ubuntu/projects/profile-app-slice-two-five.prompt.md` →
   already exists, but pre-2.75 conception. Either rename the old one
   to `…-slice-two-five-legacy.prompt.md` or rewrite in place reflecting
   the simpler one-shot-migration model. The latter.
4. Paste to terminal Claude.

Good luck. The bones of the system are good. The work left is integration
seams, drift management (which the cutover eliminates), and the moments
where specs become reality. Keep walking the cold path before declaring
anything done.
