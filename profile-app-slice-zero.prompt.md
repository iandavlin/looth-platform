Stand up "profile-app" as a new sibling service to archive-poc — slice zero (identity
only, no UI). You're on the dev box (claude.loothgroup.com, 50.19.198.38). Read
~/.claude/CLAUDE.md and /home/ubuntu/projects/CLAUDE.md first if you haven't.

## What this is

profile-app is the new member profile / directory system, designed to live OUTSIDE
WordPress and eventually back a native app. Same architectural pattern as archive-poc:
own FPM pool, own datastore, own deploy. WP is the legacy auth + write surface;
profile-app is a read/write service that mirrors a slim user shell.

Slice zero builds ONLY the identity backbone. No editor, no rail, no directory UI.
The goal is to prove identity reconciliation works end-to-end before any UI gets
built on top.

## Precedents to study before designing anything

1. **archive-poc** — /home/ubuntu/projects/archive-poc/SESSION-HANDOFF.md
   Mirror its deploy pattern: source on dev → `/srv/<service>/` on live, separate
   FPM pool, mu-plugin bridge, stage-on-dev + curl-on-live deploy via the
   loothdev_auth cookie.

2. **lg-stripe-billing** — /srv/lg-stripe-billing/db/schema.sql
   Read the schema header comments. We are COPYING its identity pattern:
   `customers` table with `uuid` + `wp_user_bridge` transitional table.
   Quote from its schema: "Identity separated from entitlement. WP coupling
   lives in its own bridge table so it can be dropped at cutover."
   profile-app's `users` table should follow the exact same shape.

## The architectural decisions already made (do NOT re-litigate)

- **Postgres** (not MySQL, not SQLite). New DB on the dev box. Mirror to live later.
- **UUIDv5 from normalized email as identity seed.** Generate one namespace UUID
  ONCE and hardcode it as `LOOTH_IDENTITY_NAMESPACE` in a shared config. Both
  profile-app and lg-stripe-billing will compute the same v5 UUID for the same
  email — that's how cross-service identity matching works without coordination.
- **Email is mutable.** UUIDv5 is the BOOTSTRAP, not the rule. Once stored on a
  user row, the UUID is frozen forever even if email changes. Track email aliases
  in a separate table.
- **billing_email and contact_email** — two columns on users. contact_email
  defaults to billing_email until a user splits them.
- **Lazy profiles.** `users` table always exists per WP user (webhook-fed).
  `profiles` table only exists when a user has actually built a profile. Slice
  zero only deals with `users` — `profiles` table comes later.
- **WP user_id is a bridge column, not a primary identifier.** Use a dedicated
  `wp_user_bridge` table. It gets dropped someday.
- **Slug fallback** = `u/<users.id>` (internal id, NOT wp_user_id). Vanity slugs
  come later via a settings editor.
- **Avatar** = just a text URL column, populated from existing WP/BB avatar URL.
  Defer any upload story.

## What to build

1. **Skeleton.** /home/ubuntu/projects/profile-app/ — directory layout mirroring
   archive-poc/ (src/, bin/, sql/, web/, deploy/, SESSION-HANDOFF.md). Postgres
   on the dev box (install if not present). New PHP-FPM pool socket at
   /run/php/php8.3-fpm-profile-app.sock. Nginx path /profile-api/* added to the
   dev.loothgroup.com vhost.

2. **Schema** (sql/0001_init.sql). Tables:
   - `users` — id BIGSERIAL pk, uuid UUID unique, primary_email text unique,
     billing_email text, contact_email text, display_name text, slug text unique,
     avatar_url text, location_text text, place_id text, lat numeric, lng numeric,
     location_country text, location_region text, location_city text,
     location_postcode text, location_precision text default 'address',
     tier text, member_since timestamptz, created_at, updated_at.
   - `email_aliases` — email_normalized text pk, user_id fk → users, source text
     ('wp'|'stripe'|'patreon'|'manual'), first_seen_at.
   - `wp_user_bridge` — user_id bigint pk fk → users, wp_user_id bigint unique,
     synced_at.

3. **Identity helper** (src/Identity.php or similar). One function:
   `compute_uuid(string $email): string` — normalize (trim + lowercase), then
   UUIDv5 with the hardcoded namespace. PHP's ramsey/uuid lib has uuid5().
   Write a tiny test that asserts the same email → same UUID, two equivalent
   emails (case + whitespace) → same UUID.

4. **Webhook endpoint.** `POST /profile-api/v0/hooks/user-created` —
   verifies an X-Hook-Secret header, takes `{wp_user_id, email, display_name}`,
   computes v5 UUID, INSERTs into users (ON CONFLICT (uuid) DO NOTHING),
   INSERTs into wp_user_bridge, INSERTs into email_aliases.

5. **Read endpoint.** `GET /profile-api/v0/user/<uuid>` — returns identity +
   location as plain JSON. Public-shape, no WP-isms. Returns 404 cleanly.

6. **mu-plugin** (live at /var/www/dev/wp-content/mu-plugins/profile-sync.php).
   Hooks user_register, fires non-blocking wp_remote_post to the webhook.
   Stores PROFILE_HOOK_SECRET in wp_options.

7. **Backfill script** (bin/backfill.php). Reads:
   - wp_users (id, user_email, display_name, user_registered)
   - wp_bp_xprofile_data WHERE field_id = 96 (location strings — confirmed live
     they're Google-Places-formatted; can be re-geocoded cleanly)
   - lg_membership.customers (email, uuid, stripe_customer_id) — note this is
     MySQL, profile-app is Postgres; cross-database read needed
   For each WP user: compute v5 UUID, upsert into users, bridge to wp_user_id,
   record email alias. If the same UUID matches an existing lg-stripe-billing
   customer (because we computed v5 the same way), great — they reconcile
   automatically.
   DO NOT geocode in slice zero. Just store the raw location_text from xprofile.
   Geocoding pass is slice one.

## What to NOT do in slice zero

- No editor, no rail, no UI of any kind. Slice zero is curl-testable only.
- No Google Places integration yet. Just store the raw xprofile string.
- No `profiles` table. No sections. No credentials. No directory.
- No avatar upload. Just store the existing WP avatar URL.
- No JWT auth. Webhook uses a shared secret. Read endpoint is public for now
  (no auth gate). Auth becomes slice one when we add edit endpoints.

## Deploy

Deploy to live when slice zero works on dev — same stage-on-dev + curl-on-live
pattern as archive-poc. Backfill runs against live data once the dev plumbing
is verified. Move fast; this is greenfield, nothing to break.

## Validate before declaring done

After backfill runs on BOTH dev and live, paste these counts in the handoff:
- Total users seeded (dev / live)
- How many had a non-empty xprofile location (dev / live)
- How many reconciled to an existing lg-stripe-billing customer via v5 UUID
- Any rows that failed to insert (and why)

## Deliverables

- Working slice zero on dev AND live (curl-testable read endpoint, webhook
  firing on new WP user registration, backfill completed on both)
- SESSION-HANDOFF.md in /home/ubuntu/projects/profile-app/ following the same
  conventions as archive-poc's (TL;DR, key files table, what changed this
  session, quick-start for next session)
- A 5-line summary of what surprised you during the build (xprofile data
  weirdness, lg-stripe-billing schema mismatches, anything that didn't match
  the design assumptions above) — this is the most valuable output

Don't ask permission to start. Read the precedents, then build. Use sudo
freely (you're ubuntu on the dev box). Ask only if you hit a real ambiguity
that the precedents don't resolve.
