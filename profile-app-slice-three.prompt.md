Slice 3 of profile-app — Practices: org-style entities with their own
public page, attached to one or more users. Plus the identity cleanup
that 2.75 deferred. You're on the dev box; read ~/.claude/CLAUDE.md,
/home/ubuntu/projects/CLAUDE.md, and
/home/ubuntu/projects/profile-app/SESSION-HANDOFF.md first.

All architectural choices below are decided. Do NOT re-litigate; just build.

## The big idea

Slice 2.75 + the follow-up audit cleaned up the data model and made
`/u/<slug>` the canonical profile page. BB profile pages are hijacked to
redirect there. The current model is: **one user, one profile page**, with
inline catalog items (instruments, skills, scenes, credentials).

Slice 3 introduces **practices** as first-class entities:

- A practice has its own public page at `/p/<slug>` (luthier shop,
  workshop, repair org, school).
- A user can be attached to **zero or more** practices. Most have 0 or 1;
  multi-shop luthiers exist.
- A practice can have **one or more attached users** (staff roster).
- `users.business_name` is **kept** as a free-text "primary affiliation"
  for sole proprietors who don't want a separate page. Practices are
  *additive*, not a replacement.

## Locked decisions (in case the prompt is ambiguous)

1. Keep `users.business_name` text column. Surfaces in the editor as a
   simple "Business name" field. Used today on the public header.
2. Practices are a separate entity in their own table, linked via a join
   table. Many-to-many between users and practices.
3. `/p/<slug>` ships day one as a public read-only page.

## Build order

### 1. DB schema (`sql/2026-05-28-slice-3-practices.sql`)

```sql
CREATE TABLE practices (
    id            bigserial PRIMARY KEY,
    uuid          uuid       NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    slug          text       NOT NULL UNIQUE,
    name          text       NOT NULL,
    tagline       text,
    about         text,
    website       text,
    location_text text,
    lat           numeric(9,6),
    lng           numeric(9,6),
    location_country  text,
    location_region   text,
    location_city     text,
    location_postcode text,
    location_visibility text NOT NULL DEFAULT 'public'
        CHECK (location_visibility IN ('public','members','private')),
    avatar_url    text,
    archived_at   timestamptz,
    created_by    bigint REFERENCES users(id),
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_practices_slug ON practices(slug);
CREATE INDEX idx_practices_archived ON practices(archived_at) WHERE archived_at IS NULL;

CREATE TABLE practice_members (
    practice_id bigint NOT NULL REFERENCES practices(id) ON DELETE CASCADE,
    user_id     bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role        text   NOT NULL DEFAULT 'staff'
        CHECK (role IN ('owner','staff')),
    sort_order  integer NOT NULL DEFAULT 0,
    added_at    timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (practice_id, user_id)
);
CREATE INDEX idx_practice_members_user ON practice_members(user_id);

CREATE TRIGGER practices_touch BEFORE UPDATE ON practices
  FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
```

Catalog additions: insert **Retail Sales** and **Tool Maker** rows into
the appropriate catalog (likely `skill_catalog`, but check current rows
first — these may belong in a new "specialties" catalog if `skill_catalog`
is exclusively hand-skills).

### 2. Public page: `/p/<slug>`

`web/p.php` (mirror `web/u.php`):
- Reads practice + attached users
- Renders: avatar, name, tagline, about, location (gated by
  `location_visibility`), website, staff roster (list of `/u/<slug>` links)
- Default visibility=public so anyone past the cookie gate sees it (vs
  users which default to members)

`web/_render_practice.php` (mirror `_render_public.php`):
- Header: avatar + name + tagline + location
- About block
- Staff roster with avatar + display_name + role

Nginx route (mirrors `/u/<slug>`):
```
location ~ "^/p/([\w\-]+)/?$" {
    if ($loothdev_is_authorized != 1) { return 403; }
    include fastcgi.conf;
    fastcgi_pass unix:/run/php/php8.3-fpm-profile-app.sock;
    fastcgi_param SCRIPT_FILENAME /home/ubuntu/projects/profile-app/web/p.php;
    fastcgi_param QUERY_STRING    slug=$1;
}
```

### 3. Editor: my practices section on `/profile/edit`

A new card-style section on the editor:
- Lists practices the current user is attached to (queries
  `practice_members` joined to `practices`)
- "Create new practice" button → modal with name + slug + tagline +
  location picker (reuse the Nominatim picker)
- "Join existing practice" button — defer to slice 3.5; ship without it
- Per-row "Edit" → modal to update name/tagline/about/website/location/avatar
- Per-row "Leave" → removes from `practice_members`

`api/v0/me/practices.php` — list + create + update + leave.
`api/v0/practice/<uuid>.php` — public-read another practice by UUID.

`/profile/edit` server-side check: if creating a practice and the user is
not yet attached to it, `role='owner'` on insert. Owners can edit; staff
can leave but not edit (slice 3.5 will refine role permissions).

### 4. `business_name` editor field

The 2.75 follow-up added `users.business_name` and surfaced it on the
public header, but the editor has no field to edit it. Add a simple text
input in the header-edit modal next to display_name:

```
<label>Business name (optional)
  <input id="f-biz" maxlength="120">
</label>
```

`api/v0/me/name.php` — accept `business_name` in the same call that
updates display_name. Stay backwards-compatible.

### 5. Identity cleanup (deferred from 2.75)

profile-app becomes sole identity source post-cutover:

- mu-plugin: `wp_users.display_name` becomes a one-way mirror from
  `users.display_name`. When `/profile-api/v0/me/name` writes a new
  display_name, also UPDATE `wp_users.display_name` on the bridged
  wp_user_id. wp-admin author bylines stay sane.
- Stop reading first_name / last_name / nickname from `wp_usermeta`.
  Grep the codebase for `first_name`, `last_name`, `nickname` references
  in any read path; if found, replace with `users.display_name`.
- Document the cut in CUTOVER-CHECKLIST.

### 6. `/u/<slug>` integration

Update `web/_render_public.php` to render the user's practices below
the header:

```
PRACTICES
  • [Avatar] The Looth Group — Ridgefield, NJ  →/p/the-looth-group
```

Click-through to `/p/<slug>`. Sort by `practice_members.sort_order`.

### 7. Run the slim cutover migration on the production-shape DB

The slim `bin/migrate-from-xprofile.php` (written in 2.75) was committed
on dev. Slice 3 doesn't re-run it; the prod cutover slice (post-3) will.
But add a verification step: confirm 0 users have empty display_name +
empty slug after a fresh run, and that 0 slug collisions occurred.

## What to NOT do in slice 3

- No live deploy
- No app-ready APIs / webhooks (that's slice 3.5)
- No catalog editor UI for adding more catalog rows (use SQL inserts for now)
- No "join existing practice" flow (slice 3.5)
- No staff role permissions UI (owner/staff distinction lives in the DB
  but enforcement is "owner can edit, staff can leave")
- No archived-practice browsing (just hide them from public/listings)

## Validate before declaring done

Run `bin/walk-onboarding.sh` first — confirm baseline still passes.

Then a new cold-walk for practices (extend the walk script):

1. Fresh user creates a practice ("Bench Test Guitars") via the editor.
2. Confirm `/p/bench-test-guitars` renders publicly (anon + cookie-gate)
   with the user listed in the staff roster.
3. Confirm `/u/<slug>` for that user now shows the practice in the
   "Practices" section, linking to `/p/bench-test-guitars`.
4. Update the practice tagline, reload, confirm change.
5. Leave the practice. Reload `/u/<slug>` — practice section gone.
6. Reload `/p/bench-test-guitars` — staff roster shows zero members.
   (Slice 3.5 may add "orphan practice cleanup"; for now they persist.)

DOM-presence assertions in the walk: practice section card visible after
create, gone after leave. No silent pass.

## Deliverables

- Schema + indexes + triggers applied to dev
- `web/p.php`, `web/_render_practice.php`, nginx route
- Editor: my-practices section + business_name field
- API endpoints: `me/practices`, `practice/<uuid>`
- Identity cleanup: one-way mirror to `wp_users.display_name`,
  grep-and-replace usermeta name reads
- `/u/<slug>` renders attached practices
- Catalog: Retail Sales + Tool Maker rows
- Updated walk script with practice flow
- Updated SESSION-HANDOFF.md and CUTOVER-CHECKLIST.md
- 5-line "what surprised you" summary at top of handoff

Don't ask permission to start. Read the prior handoff, then build. Ask
only if a fix requires an architectural choice that this prompt and the
prior handoffs don't constrain.
