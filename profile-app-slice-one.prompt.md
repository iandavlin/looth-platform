Slice ONE of profile-app — the editor. Builds on slice zero's identity backbone.
You're on the dev box (claude.loothgroup.com, 50.19.198.38). Read
~/.claude/CLAUDE.md and /home/ubuntu/projects/CLAUDE.md first if you haven't.

## What slice zero left you

- profile-app skeleton at /home/ubuntu/projects/profile-app/, own FPM pool, own
  Postgres database, nginx path /profile-api/* on dev.loothgroup.com
- `users`, `email_aliases`, `wp_user_bridge` tables, 1694 users seeded from WP
- 663 have a non-empty `location_text` (raw xprofile string, ungeocoded)
- 68/70 lg-stripe-billing customers reconciled via v5-from-email UUID
- Webhook from WP user_register working
- Read endpoint: GET /profile-api/v0/user/<uuid> returning identity JSON
- Read what slice zero shipped before adding anything: read its
  SESSION-HANDOFF.md, walk the schema, hit the endpoint with curl.

## What slice one ships

A working front-end editor at /profile/edit on dev — the side-rail editor
from the mockup brought to life. About + Location + Socials only. Other
sections (Credentials, Practices, directories) come in later slices.

End-to-end loop to prove: log into WP → visit /profile/edit → edit your
About / Location / Socials → save → re-visit → changes persist → another
viewer sees what the privacy grants allow them to see.

## The mockup is the design reference

/var/www/dev/mockups/profile-v2.html — read it, run it, study the rail +
viewer-role toggle + section visibility chips. That's the UX you're
building. You don't need to match the HTML byte-for-byte; you need to
match its *feel*: click-to-edit inline, sections with visibility
indicators, viewer-role preview that dims hidden sections in real time.

## Architectural decisions already made (do NOT re-litigate)

- **Auth: JWT in a cookie, signed by WP, verified by profile-app.**
  This sets the auth precedent for every future service (profile-app,
  archive-poc, lg-stripe-billing, future native app). Do it right now,
  copy it elsewhere later.

  Mechanics:
  - One-time setup: generate an RSA keypair (2048-bit). Private key lives
    in WP at /etc/looth/jwt-private.pem (root:www-data 640). Public key
    lives in profile-app at /etc/looth/jwt-public.pem (readable by the
    profile-app FPM user).
  - WP-side mu-plugin (profile-auth.php) hooks `wp_login` and `init`.
    On any authenticated request that lacks the `looth_id` cookie, mints
    a JWT with claims `{sub: <v5_uuid>, wp_user_id, email, exp: now+30d}`,
    signed RS256, drops it as a cookie `looth_id` scoped `.loothgroup.com`
    (path=/, HttpOnly, Secure, SameSite=Lax).
  - profile-app verifies the JWT against the public key. firebase/php-jwt
    via composer. No DB lookup needed for auth — `sub` IS the user uuid.
  - Refresh: tiny WP endpoint `/wp-json/looth/auth/refresh` that re-mints
    the cookie from the current WP session. profile-app's JS calls it if
    a 401 comes back from an authed endpoint.
  - Future native app does its own login flow against WP, gets a JWT in
    the same shape, sends it as `Authorization: Bearer <token>`. Same
    verifier, same code path.

  Why not cookie-sniff: couples profile-app to WP's cookie+salts forever,
  doesn't work for native apps, every future service would re-implement
  it. JWT is one extra day of setup that all future services inherit.

- **Auth scope: read-public, write-self.** Unauthed callers can hit the
  public read endpoint. Authed callers can PATCH their own profile via a
  /me endpoint. No cross-user writes yet. No admin-impersonation yet.

- **Lazy profile creation.** On first authenticated visit to /profile/edit,
  if no `profiles` row exists for the user, create one with sensible
  defaults (About empty, default visibility grants, no socials). This is
  the "claim" moment — distinguishable from never-touched users.

- **Sections stored as `profile_sections` jsonb rows for the small ones.**
  About is a single `profile_sections` row with key='about', data={text:...}.
  Socials get their own typed table (`profile_socials` with kind+value+sort)
  because they're a list and will be filterable later.

- **Location stays on the `users` table** (added in slice zero). Editor writes
  back the full Google Places result: location_text, place_id, lat, lng,
  parsed components, AND user-chosen precision_grants.

- **Per-viewer precision for location.** Three grants on the users table:
    location_grant_public   text default 'city'
    location_grant_members  text default 'city'
    location_grant_friends  text default 'address'
  Values: 'address' | 'city' | 'region' | 'country' | 'hidden'.
  Render: walk the parsed components, assemble from most-specific to the
  granted precision. Apply same to lat/lng (city → coarse, address → exact).

- **Viewer-role toggle is real, not cosmetic.** Editor renders three preview
  modes: me / member / public. Switching the role re-renders all sections
  with the correct precision/visibility applied. This is the privacy story
  made visible. Ship it day one.

- **Google Places Autocomplete via JS widget** on the location field. Store
  the full PlaceResult JSON in addition to the parsed components, so we can
  re-render or re-parse later without re-hitting Google. API key in a
  config file outside web root, read by the PHP that injects it into the
  page. (Get a key from the Google Cloud project the team already uses;
  ask Ian if you don't know which.)

- **Visibility model: public / members / private** on a per-section basis.
  Sections store their visibility as a column on `profile_sections`. About
  defaults to 'members'. Socials default to 'public'. Location is special
  because it has per-audience precision instead of a single visibility.

## What to build

1. **Auth shim** (src/Auth.php). Reads JWT from either the `looth_id`
   cookie or `Authorization: Bearer` header, verifies RS256 against
   /etc/looth/jwt-public.pem via firebase/php-jwt. Helper:
   `current_user(): ?array` returns the profile-app user row keyed by the
   `sub` claim (UUID) — no DB join needed for the auth step itself, just
   a single SELECT users WHERE uuid = ?. Cache for the request lifetime.
   Plus the companion WP-side mu-plugin that mints the JWT on login +
   refresh endpoint as described above.

2. **Editor page** (web/edit.php). Server-renders the rail + sections with
   the viewer's current data. Pulls JS + CSS from web/edit.js + web/edit.css.
   Page is gated: unauthenticated visitors see a small "log in to edit your
   profile" interstitial linking to WP login with a return-to param.

3. **Lazy claim.** On GET /profile/edit, if no `profiles` row for current
   user, INSERT one and continue.

4. **Schema additions** (sql/0002_profiles_slice_one.sql):
   - `profiles` — user_id pk fk → users, claimed_at, updated_at.
   - `profile_sections` — id pk, user_id fk, key text ('about'|...),
     visibility text default 'members', data jsonb, sort_order int,
     enabled_at timestamptz, UNIQUE(user_id, key).
   - `profile_socials` — id pk, user_id fk, kind text, value text,
     sort_order int, created_at. (kinds: instagram|youtube|bandcamp|web|
     email|phone|x|tiktok|facebook|patreon)
   - Add to `users`: location_grant_public/members/friends text columns
     with defaults as above. Also place_result jsonb for the raw Google
     response.

5. **Edit endpoints.** All require auth, all scoped to current_user.
   - `GET  /profile-api/v0/me` — returns full profile for editing
     (everything, regardless of viewer-role, since you're the owner)
   - `PATCH /profile-api/v0/me/about` — { text, visibility }
   - `PUT   /profile-api/v0/me/location` — { place_result, precision_grants }
     (PUT because it's a full replace of the location block)
   - `PUT   /profile-api/v0/me/socials` — { items: [{kind, value, sort_order}] }
     full list replace; validate per-kind format
   - `GET   /profile-api/v0/me/preview?as=public|member|friend` — returns
     what that viewer-role would see; used by the toggle in the editor

6. **Public read endpoint extension.** Existing GET /profile-api/v0/user/<uuid>
   gains viewer-role awareness:
   - If no auth cookie → public role
   - If auth cookie resolves to a user → member role (friend logic later)
   - If auth cookie resolves to the same user as the requested uuid → me role
   Returns only what that role is allowed to see, with location at the
   precision granted to that role.

7. **Google Places integration.** edit.js loads the Places library, wires it
   to the location input, on selection saves the PlaceResult via PUT /me/location.
   PHP parses place_result.address_components into the typed columns on save.

## What to NOT do in slice one

- No credentials editor (slice two)
- No practices (slice three)
- No directory (slice two)
- No avatar upload (still using WP avatar URL)
- No friend graph — "friend" viewer role exists as a concept but resolves to
  "member" for now; revisit when friend data is in Postgres
- No reorder of sections (rail is fixed-order)
- No tier-locked sections (no Pro features yet)
- No OAuth (JWT-in-cookie is in scope, OAuth flows are not)
- No live deploy. Dev only.
- No section-add UI yet — only About / Location / Socials exist, and all
  three are auto-enabled on profile claim. The "○ click to enable" rail
  pattern lands in slice two when there's a real second section to enable.

## Validate before declaring done

- Log in to WP on dev with a real account, visit /profile/edit, edit each
  of About / Location / Socials, save, reload, confirm persistence
- Open /profile-api/v0/user/<own uuid> in incognito (no auth cookie),
  confirm only public-visible fields and city-precision location are
  returned
- Switch viewer-role to "public" in the editor, confirm About section dims
  with the "hidden from this viewer" treatment, location collapses to city
- Pick a location via Google Places autocomplete, save, confirm
  place_result jsonb + parsed components + lat/lng landed correctly in
  Postgres
- Confirm lazy claim: pick a user who's never visited /profile/edit, check
  Postgres has no `profiles` row; visit /profile/edit; check it now does

## Deliverables

- Working editor on dev at https://dev.loothgroup.com/profile/edit
- Updated SESSION-HANDOFF.md with: what slice one added, key files table,
  what's still ahead, quick-start for next session
- The 5-line "what surprised you" summary — especially anything about
  the JWT mint/verify plumbing (keypair perms, cookie scope, refresh flow),
  the Google Places integration, or the section-storage model

Don't ask permission to start. Read the precedents (slice zero handoff,
the mockup, archive-poc, lg-layout-v2's FeEditor.php for inspiration on
the FE-edit feel), then build. Ask only if you hit a real ambiguity that
the precedents don't resolve.
