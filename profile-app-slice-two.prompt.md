Slice 2 of profile-app — taxonomy, catalogs, header highlights,
first directory, and two foundation cleanups. Builds on slice 1.5.
You're on the dev box (claude.loothgroup.com, 50.19.198.38). Read
~/.claude/CLAUDE.md and /home/ubuntu/projects/CLAUDE.md first.

## What slice 1.5 left you

Read profile-app/SESSION-HANDOFF.md first. Live-look + modal editor,
explicit claim, `/u/<slug>` public read-only, schema endpoint with
import/export-shaped JSON, WP admin bar entry. Three sections live
(About / Location / Socials), three placeholder cards (Credentials,
Practices, Skills — wait, Practices is slice 3 and Skills/Instruments/
Credentials/Scenes are this slice). The editor is the home base; slice 2
fills in the section library and lights up the first directory.

## What slice 2 ships

In rough build order:

1. **Two foundation cleanups** before any taxonomy work:
   - Resolve active-section semantics: **no row = inactive** (option 1).
     Drop empty `profile_sections` rows; editor stops auto-seeding; About
     becomes active only on first non-empty save.
   - One-time backfill: every user with non-empty `location_text` from
     the slice-zero xprofile import gets a `profiles` row with
     `claimed_via='backfill_location'`. ~663 users go live.

2. **Four new catalogs**, curated, with free-text fallback where it makes
   sense (credentials only — instruments/skills/scenes are pick-from-list).

3. **Four new editable sections**: Instruments, Skills, Credentials, Scenes.
   All use the live-look + modal pattern from slice 1.5.

4. **Header highlights picker** — capped 3 selections drawn across
   Instruments + Skills catalogs, rendered as clickable chips in the header
   that route to the corresponding directory filters.

5. **First directory** at `/directory/members` — paginated list of claimed
   profiles, filterable by location radius + instrument + skill + scene +
   credential. SSR with progressive enhancement.

6. **Report-abuse plumbing** — simple table + report button on `/u/<slug>`
   pages. No admin UI yet; you read the table by hand when reports trickle in.

7. **Schema endpoint v2** — includes catalog references, new section schemas,
   highlights structure.

## Architectural decisions already made (do NOT re-litigate)

- **Active-section rule is "no row = inactive."** Slice 1.5 left empty
  `profile_sections` rows from slice one's auto-seeding. Migrate by dropping
  rows where `data` is empty/null. Going forward, `profile_sections` rows
  are created on first non-empty save. Directory queries against "active
  members" become trivial: `EXISTS (SELECT 1 FROM profile_sections WHERE
  user_id = users.id)` OR has location/socials/credentials/etc.

- **Auto-claim backfill from location.** Users with non-empty
  `location_text` get `profiles` rows with `claimed_via='backfill_location'`.
  Idempotent (`INSERT ... ON CONFLICT (user_id) DO NOTHING`). No first-visit
  interstitial for these users — they're already claimed; section cards do
  the onboarding via their `+ Add your X` affordances.

- **Skills and Services share `skill_catalog`.** Same vocabulary, two
  relation tables: `profile_skills` on persons ("I can do this"),
  `practice_services` later in slice 3 ("we sell this"). Slice 2 only
  ships `profile_skills` since practices aren't here yet.

- **Three primary taxonomy axes: Instruments, Skills, Scenes.** Plus
  Credentials as its own first-class catalog. Each catalog is curated by
  the project; users can request additions to credentials via free-text
  but pick from list for instruments/skills/scenes.

- **Scene tags use string slugs as primary keys** (not numeric IDs).
  Small flat set, no hierarchy, slugs are stable. Easier joins.

- **Header highlights are polymorphic across catalogs.**
  `profile_highlights (user_id, kind, ref_id, sort_order)` where `kind` is
  `'instrument'` or `'skill'`, `ref_id` is the catalog row id. Cap of 3
  enforced at write time. Use a CHECK constraint on `kind`.

- **Credentials are self-attested, full stop.** No verification ladder, no
  badges. Optional expiration date triggers nightly email reminder to user
  (no auto-hide). Catalog match is preferred but free-text always accepted.
  `profile_credentials.catalog_id` is nullable; `raw_issuer` + `raw_program`
  always populated.

- **Credentials table is polymorphic-prep for practices.** Column
  `owner_type text NOT NULL DEFAULT 'profile'` + `owner_id bigint`.
  Slice 2 only writes `'profile'` rows. Slice 3 adds `'practice'` and
  the linking UI without a schema change.

- **Directory v1 is intentionally barebones.** List of cards, faceted
  filters across location-radius + instrument + skill + scene + credential.
  Load-more pagination, no numbered pages. Query string is the source of
  truth (`/directory/members?loc=portland&radius=50&skill=fretwork&...`).
  SEO-friendly path-shaped URLs wait until later.

- **Report button is on `/u/<slug>` only (not on /profile/edit).**
  Writes to `reports` table. Email notification to a hardcoded admin
  address (Ian). No admin UI in slice 2 — the table IS the UI for now.

- **Schema endpoint version bumps to 2.** Same shape from 1.5, expanded
  to include the new sections, catalog references (as `{slug, name}` lists
  embedded into the schema so import/export consumers can validate without
  separate catalog fetches), and the highlights structure.

## What to build

### Foundation (do these first, in order)

1. **Migration** `sql/0004_active_semantics_and_backfill.sql`:
   ```sql
   -- Drop empty rows
   DELETE FROM profile_sections
   WHERE data IS NULL OR data::text = '{}' OR data->>'text' IS NULL
      OR data->>'text' = '';
   -- (Adjust the where clause to match your slice-one seeding shape)

   -- Auto-claim users with location
   INSERT INTO profiles (user_id, claimed_at, claimed_via)
   SELECT id, NOW(), 'backfill_location'
   FROM users
   WHERE location_text IS NOT NULL AND location_text != ''
   ON CONFLICT (user_id) DO NOTHING;
   ```

2. **Editor update**: `Profile::claim()` no longer seeds an empty About row.
   `loadFull()` returns sections only where rows exist. UI logic for
   "inactive" is now solely "no row in `profile_sections` for this key" —
   no more empty-string special case.

### Catalogs and schema

3. **Schema** `sql/0005_catalogs.sql`:
   ```
   instrument_catalog  (id bigserial pk, slug text unique, name text,
                        type text, subtype text, active bool default true,
                        sort_order int default 100)
   skill_catalog       (id bigserial pk, slug text unique, name text,
                        category text, parent_id bigint null,
                        active bool default true, sort_order int default 100)
   credential_catalog  (id bigserial pk, slug text unique,
                        category text,        -- 'warranty'|'certification'|'education'|'membership'|'license'
                        issuer text, program text, logo_url text,
                        active bool default true)
   scene_tags          (slug text pk, name text, active bool default true,
                        sort_order int default 100)

   profile_instruments (user_id bigint fk, instrument_id bigint fk,
                        sort_order int, PRIMARY KEY (user_id, instrument_id))
   profile_skills      (user_id bigint fk, skill_id bigint fk, note text,
                        PRIMARY KEY (user_id, skill_id))
   profile_scenes      (user_id bigint fk, scene_slug text fk,
                        PRIMARY KEY (user_id, scene_slug))
   profile_credentials (id bigserial pk,
                        owner_type text not null default 'profile'
                          check (owner_type in ('profile','practice')),
                        owner_id bigint not null,
                        catalog_id bigint null fk → credential_catalog,
                        raw_issuer text not null,
                        raw_program text not null,
                        identifier text,
                        issued_at date, expires_at date,
                        evidence_url text,
                        visibility text not null default 'members',
                        sort_order int,
                        created_at timestamptz default now())
   profile_highlights  (user_id bigint fk, kind text
                          check (kind in ('instrument','skill')),
                        ref_id bigint not null, sort_order int,
                        PRIMARY KEY (user_id, kind, ref_id))
   ```

4. **Catalog seeds** `sql/0006_seed_catalogs.sql`:
   - ~25 instruments — acoustic guitar (flat-top, archtop, classical),
     electric guitar (solidbody, semi-hollow, hollow), bass (electric,
     upright), mandolin, banjo (5-string, tenor, plectrum), dobro/resonator,
     pedal steel, lap steel, fiddle/violin, viola, cello, ukulele, autoharp,
     hurdy-gurdy. Use your domain knowledge; ask Ian if uncertain.
   - ~50 skills — fret leveling, refret, neck reset, setup, electronics,
     pickup install, wiring, soldering, intonation, action, finish touchup,
     full refinish, vintage restoration, crack repair, binding repair,
     bridge replacement, headstock break, fret crowning, nut work,
     saddle fitting, top crack, brace repair, recording engineering,
     CAD design, machinist work, fixture making, jig design, tour tech,
     ON-SITE field repair, etc. Group with `category` (repair / build /
     electronics / tour / fabrication / studio).
   - ~60 credentials — Taylor / Martin / Fender / Gibson / Gretsch / PRS /
     Rickenbacker / Collings / Larrivée / Yamaha authorized warranty
     services; Plek Pro / Plek Standard certifications; Berklee Guitar
     Repair, Roberto-Venn, Galloup, Bryan Galloup Master, Galaxy, Summit,
     MI schools. Categorize.
   - ~14 scenes — bluegrass, rock, country, jazz, classical, gospel, world,
     electronic, theater, session, vintage, boutique, studio, touring.

5. **Schema endpoint v2** (`api/v0/schema.php` updated):
   - Bump version to 2
   - Embed catalog rows (`{id, slug, name, category?}` lists) so import/export
     consumers can validate without separate calls
   - Add `instruments`, `skills`, `credentials`, `scenes`, `highlights`
     section schemas
   - Highlights schema documents the polymorphic shape with `kind` discriminator
   - Cache-Control bumped to `max-age=300` (catalogs change less often than v1
     data; 5 min cache is fine)

### Editor

6. **Four new section cards** in `web/edit.php` + `web/edit.js`:
   - Instruments: typeahead picker (datalist or custom) against
     `instrument_catalog`, multi-select with sort-order. Chips render in
     section body. Modal is a search input + checkbox grid + drag-reorder.
   - Skills: same pattern as instruments, with optional per-skill `note`
     (one-line text alongside each picked skill).
   - Credentials: typeahead against `credential_catalog` ("Taylo…" matches
     "Taylor Authorized Warranty Service"). If no match, prompt
     "Add as new" → free-text issuer + program. Each credential row has
     identifier, issued/expires dates, visibility. Render as a list with
     per-item pencil to edit + per-item delete.
   - Scenes: simple multi-select pill picker from the curated scene_tags
     list. No free-text. Render as small pills in the section.

7. **Header highlights picker**:
   - In Me view, the header chip row shows existing highlights (or
     "+ Add highlights" if none).
   - Pencil on the chip row opens a modal: search/select across
     instruments + skills (deduped from user's existing instruments/skills,
     with a "browse catalog" toggle to pick beyond what they've added),
     drag to reorder, cap of 3 enforced client- and server-side.
   - Saved highlights render as small clickable pills under the name in
     the header. Clicking a pill goes to the directory filtered on that
     instrument/skill.

8. **Endpoints** for the new sections:
   - `PUT /profile-api/v0/me/instruments` — `{items: [{instrument_id, sort_order}]}`
   - `PUT /profile-api/v0/me/skills` — `{items: [{skill_id, note?, sort_order}]}`
   - `PUT /profile-api/v0/me/scenes` — `{slugs: ["bluegrass","vintage"]}`
   - `PUT /profile-api/v0/me/highlights` — `{items: [{kind, ref_id, sort_order}]}`
     (max 3, validated)
   - `POST /profile-api/v0/me/credentials` — create one
   - `PATCH /profile-api/v0/me/credentials/<id>` — update one
   - `DELETE /profile-api/v0/me/credentials/<id>`
   - `GET /profile-api/v0/catalogs/instruments` — full catalog (cacheable)
   - `GET /profile-api/v0/catalogs/skills` — full catalog (cacheable)
   - `GET /profile-api/v0/catalogs/credentials?q=<typeahead>` — searchable
   - `GET /profile-api/v0/catalogs/scenes` — full list

### Directory

9. **`/directory/members`** SSR page:
   - Query string drives state: `?loc=<place_id>&radius=<mi>&inst=<slug>&inst=<slug>&skill=<slug>&scene=<slug>&cred=<slug>&page=<n>`
   - Render: filter sidebar (Location radius via Google Places autocomplete,
     instrument multi-select, skill multi-select, scene multi-select,
     credential multi-select) + results grid
   - Results card: avatar, name, location (rendered at the *viewer's*
     precision — public viewers see city only), highlights chips,
     "View profile →"
   - Load-more pagination (button at bottom; appends results without reload)
   - No numbered URLs yet
   - Distance calculated server-side via `point(lat, lng) <-> point(target)`
     using cube/earthdistance extension (install if not present) or
     simple haversine in SQL

10. **`/directory/members` endpoint** at
    `GET /profile-api/v0/directory/members` returning JSON for the load-more
    pagination + initial SSR data fetch.

### Reports

11. **Report table + endpoint:**
    ```sql
    reports (id bigserial pk,
             target_type text not null check (target_type in ('profile','practice','credential')),
             target_id bigint not null,
             reason text not null,
             body text,
             reporter_user_id bigint null fk → users (null = anon),
             status text not null default 'open'
               check (status in ('open','actioned','dismissed')),
             admin_note text,
             created_at timestamptz default now(),
             actioned_at timestamptz)
    ```
    `POST /profile-api/v0/reports` — `{target_type, target_id, reason, body?}`.
    Rate-limit by IP (~5/hour). Email Ian on insert.

12. **Report button on `/u/<slug>`**: small unobtrusive link at the bottom
    of public profiles ("Report this profile"). Opens a modal with reason
    dropdown + free-text body + email (auto-populated if logged in).

## What to NOT do in slice 2

- No practices, no `practice_services`, no `/p/<slug>` route (slice 3)
- No skill-pack zip endpoint (slice 3)
- No admin UI for reports (just the table)
- No verification UI for credentials (intentionally self-attested only)
- No avatar upload (the avatar pencil placeholder from 1.5 stays)
- No SEO-friendly directory URLs like `/directory/members/portland/repair`
- No federation, no portable profile import
- No "deactivate a section" UI (still only activation)
- No friend graph (Friend role still resolves to Member)
- No touch-DnD fix (defer; reorder is desktop-only as it was in 1.5).
  Slice 2 instruments/skills/highlights modals use button-based reorder
  (up/down arrows) so touch users can reorder *those* lists, even though
  the section-card grip is still desktop-only.
- No live deploy. Dev only.

## Validate before declaring done

- Foundation: run the migration; confirm empty About rows are gone and
  662–663 new `profiles` rows exist with `claimed_via='backfill_location'`.
  Spot-check three of those users via `/u/<id>` — confirm they render as
  live profiles with their location, no other content.
- Each catalog has its seed rows loaded; `curl /catalogs/instruments` etc.
  returns the expected counts.
- Editor: each of Instruments / Skills / Credentials / Scenes opens its
  modal, picks from catalog (or free-text for credentials), saves,
  re-renders as active section.
- Header highlights: pick 3, save, confirm chips appear in header. Try to
  save 4 → 400 error. Click a chip → goes to directory with that filter
  pre-applied.
- Directory: visit `/directory/members` and confirm initial load shows
  recent profiles. Add a location filter, an instrument filter, a skill
  filter — each narrows the result set. Load-more appends without reload.
- Report a profile (any user's `/u/<slug>`) → confirm row in `reports`
  + email arrives at Ian's address.
- `GET /schema` returns version 2 with embedded catalogs.

## Deliverables

- Editor with five activatable sections (About + Instruments + Skills +
  Credentials + Scenes; Location/Socials in header as before)
- Header highlights picker + clickable chips
- `/directory/members` SSR + faceted filters + load-more pagination
- Report button on `/u/<slug>` + reports table
- Schema v2 with embedded catalogs
- Updated SESSION-HANDOFF.md
- The 5-line "what surprised you" summary — especially anything about the
  catalog seeding process (did the curated lists hold up against real
  use?), the polymorphic credentials owner pattern, the directory query
  performance under realistic data, or any UX gotchas in the typeahead +
  free-text fallback pattern

Don't ask permission to start. Read the slice 1.5 handoff, the mockup,
and the schema endpoint v1 doc; then build. Ask only if you hit a real
ambiguity. Catalog content (the actual entries in each seed) is the one
place to ask Ian if your domain knowledge feels thin — he'd rather give
you the canonical list once than rename rows later.
