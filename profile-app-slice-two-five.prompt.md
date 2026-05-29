Slice 2.5 of profile-app — fixes uncovered by an end-to-end onboarding
test as a brand-new user (Dorothy Parker, wp_user_id=1919, users.id=1698,
created 2026-05-26). No new features; this slice is debt-paydown before
practices land in slice 3. You're on the dev box; read ~/.claude/CLAUDE.md
and /home/ubuntu/projects/CLAUDE.md first.

## Context

Ian ran the onboarding flow as Dorothy via headless Chrome. The good news:
the data pipes work — webhook → users row → claim → save → directory
presence is all functioning. The bad news: a handful of bugs that don't
show up in any single-user dev loop become visible when you actually walk
a stranger through. They're not slice-3 work; they're bugs against the
slice 1.5 + slice 2 specs that slipped past validation.

Screenshots of each bug at `https://dev.loothgroup.com/mockups/onboard-*.png`
(read those before fixing — they're worth more than my prose).

All architectural choices below are decided. Do NOT re-litigate; just build.

## Bugs to fix, in build order

### 1. Geocode backfill (foundation — fix first)

660 of 663 claimed profiles have `location_text` from slice-zero xprofile
import but no `lat`/`lng`. They're invisible to the directory's
location-radius filter, which makes the directory functionally broken for
the "find a tech near me" use case.

Build:
- `bin/geocode.php` — iterate `users WHERE location_text IS NOT NULL AND
  lat IS NULL`, call Google Geocoding API (NOT autocomplete — cheaper for
  batch, ~$5/1000), parse the response into the existing typed columns
  (lat, lng, place_id, country, region, city, postcode); overwrite
  `location_text` with Google's canonical `formatted_address`.
- API key already lives at `wp_options.wpgmza_google_maps_api_key` (per
  slice-1.5 surprise #5). Read it the same way.
- Rate limit: sleep 20ms between calls (50 req/sec, well under Google's
  cap). Total run for 660 rows: ~15 seconds + network.
- Log per-row: success / failed (with reason) / skipped. Print a summary.
- Idempotent. Re-runs only hit rows still missing lat.
- Default precision = 'address' since imported strings are full-address-
  shaped per slice-zero. Users can coarsen via their precision_grants.
- On `ZERO_RESULTS` from Google: leave row unchanged, log as `no_match`.
- On `OVER_QUERY_LIMIT`: backoff exponentially up to 3 retries, then
  abort the run with a clear message.

Report seeded / failed / no_match counts in the handoff.

### 2. JWT auto-mint on direct navigation

**The bug:** mu-plugin mints the JWT on `wp_login` and `init` hooks. Both
only fire on WP-served pages. A user clicking a direct link to
`/profile/edit` (profile-app, not WP) gets "Sign in to edit your profile"
even though they ARE logged into WP — they have a WP session cookie and
no looth_id cookie yet, and have to hit a WP page first to mint.

This kills the email-link flow, the bookmark flow, and any cross-site
deep link.

**Fix:** profile-app detects WP session and triggers a mint via 302 hop.

When profile-app sees an unauthed request (no valid `looth_id`) that
DOES carry a `wordpress_logged_in_*` cookie, it does NOT render the
"Sign in" interstitial. Instead it 302s to
`/wp-json/looth/auth/issue?return=<original_url>`. The mu-plugin's
existing `/wp-json/looth/auth/refresh` endpoint is generalized (or a
sibling added) to:
1. Verify the WP session
2. Mint the JWT and drop the `looth_id` cookie
3. 302 back to the `return` URL

Round-trip is invisible to the user. The original "Sign in to edit your
profile" interstitial stays as the fallback for users who genuinely have
no WP session at all.

Implementation notes:
- `return` URL must be validated as same-origin to avoid open-redirect.
  Whitelist: must start with `https://dev.loothgroup.com/`.
- The issue endpoint's REST callback uses `permission_callback =>
  'is_user_logged_in'` (same as refresh). If that fails, 302 to WP
  login with the original URL preserved as `redirect_to`.

### 3. `/u/<slug>` public view leaks editor chrome

**The bug:** `/u/1698` rendered for an anon viewer returns the side rail,
the viewer-role toggle, all six section cards (including `data-active="0"`
inactive ones with "+ Add your X" prompts), and 11 pencil buttons in the
DOM. The editor empty-state leaked into the public view. And Dorothy
hitting her own `/u/1698` while logged in didn't 302 to `/profile/edit`.

The slice 1.5 spec was explicit: "No pencils, no grips, no role toggle."
That wasn't honored.

**Fix:**

- Public view (`web/u.php`) must NOT include:
  - The side rail (`.rail`)
  - The viewer-role segmented control
  - Any `.pencil` or `.grip` element
  - Inactive section placeholders (the dashed-border `+ Add your X`
    cards). If a section has no content visible to the current viewer,
    don't render the section card at all.
- Sections with `visibility = members` are HIDDEN from anon (not just
  CSS-hidden — fully absent from the HTML payload).
- If the request resolves to the same user as the JWT subject, 302 to
  `/profile/edit`. This applies to the bare `/u/<slug>`, not just
  `/u/<slug>/edit`.

**Architecture: split into two templates.** `web/_render.php` stays as
the canvas (editor) shell. Create `web/_render_public.php` as the
public-view shell — no rail, no toggle, no inactive-section affordances,
no pencils. The header block (avatar, name, location, socials, highlights)
and section-card body partials stay shared because their *content* is
identical between views; only the page shell differs. This split prevents
future editor chrome from leaking into the public view by accident.

### 4. BuddyBoss member-nav entry point

**The bug (known but unfixed):** WP admin bar is hidden on the front-end
for members (`show_admin_bar_front=false` is system default). The "My
Profile" admin bar item from slice 1.5 doesn't appear where members
browse. Direct URL is currently the only discovery path.

**Fix:** add a "My Profile" entry to the BuddyBoss member nav, which
DOES render on the front-end.

In the existing `profile-auth` mu-plugin:

```php
add_action('bp_setup_nav', function() {
    if (!is_user_logged_in() || !function_exists('bp_core_new_nav_item')) return;
    bp_core_new_nav_item([
        'name'                    => 'My Profile 2.0',
        'slug'                    => 'profile-2',
        'position'                => 5,
        'default_subnav_slug'     => 'profile-2',
        'show_for_displayed_user' => false,  // only show on own profile
        'item_css_id'             => 'looth-profile-2',
        'screen_function'         => function() {
            wp_redirect('/profile/edit'); exit;
        },
    ]);
});
```

Use exactly: slug `profile-2`, label `My Profile 2.0`, position `5`.
"My Profile 2.0" as the label is a deliberate placeholder so it doesn't
collide with BB's existing "Profile" nav during the cutover window. Rename
later when legacy BB profile is deprecated.

Keep the slice-1.5 admin bar item too — still useful for wp-admin users.

## Smaller cleanups in the same slice

These came out of the onboarding walk; bundle them in:

- **Synthetic `.click()` is blocked on save buttons.** Required real CDP
  `Input.dispatchMouseEvent` to fire the save handler. Investigate the
  root cause (likely an `isTrusted` check from BB, a security plugin,
  or the modal's own JS). If a fix lands cheaply, ship it. If not,
  document the requirement (use real input events for any scripted test
  driving the editor) in the handoff and move on.

- **Empty header location reads "no location"** for users with no
  `location_text`. Replace with italic muted "+ add your location"
  affordance, same per-field pencil behavior as a populated location.
  The pin glyph stays.

- **About visibility leak on anon `/u/<slug>`** is the same root cause as
  fix #3 but call it out separately in the validation matrix: a
  `visibility=members` section must be 100% absent from anon HTML, not
  just CSS-hidden.

## What to NOT do in slice 2.5

- No practices (slice 3)
- No new section types
- No catalog expansion (slice 3 handles the luthier-schools web-search
  pass + tsvector full-text search)
- No avatar upload
- No deactivate-section UI
- No touch DnD
- No live deploy

## Validate before declaring done

- **Geocode:** after running `bin/geocode.php`, sample 5 random rows that
  had `location_text` set, confirm `lat`/`lng`/`place_id` now populated.
  Try `/directory/members?loc=portland&radius=50` and confirm backfilled
  users appear in the filter.

- **JWT auto-mint:** log into wp-admin as Dorothy (or use her existing
  WP session from the earlier test), clear the `looth_id` cookie, navigate
  directly to `https://dev.loothgroup.com/profile/edit`. Confirm she
  lands in the editor with no "Sign in" interstitial — should be a 302
  hop through `/wp-json/looth/auth/issue?return=...` and back, invisible.

- **Public view fix:** in incognito (no cookies at all, then with only
  cookie-gate), hit `/u/1698`. Confirm:
  - Zero `.rail` in DOM
  - Zero `.seg` / viewer-role toggle
  - Zero `.pencil` elements
  - Zero `data-active="0"` section cards
  - About section (members-visibility) entirely absent
  Then with Dorothy's auth, hit `/u/1698` and confirm a 302 to
  `/profile/edit`.

- **BB nav item:** log into dev.loothgroup.com as Dorothy on the
  front-end, browse to ANY non-wp-admin page, confirm a "My Profile 2.0"
  item appears in the BB user nav. Click it → lands on `/profile/edit`.

- **Cleanups:** Header for a no-location user shows "+ add your location"
  affordance (not "no location"). Public anon view of Dorothy:
  `curl https://dev.loothgroup.com/u/1698 | grep -ci about` returns zero.
  Synthetic-click investigation conclusion documented in the handoff.

## Deliverables

- All four bugs fixed and validated end-to-end on dev
- Updated SESSION-HANDOFF.md with what changed + geocode counts
- 5-line "what surprised you" summary — especially anything about the
  WP REST issue-cookie hop (CORS? sameSite?), the public/canvas template
  split, the BB nav hook quirks, or Google Geocoding edge cases
  (multi-result addresses, ambiguous strings, failures)

Don't ask permission to start. Read the screenshots, the slice 1.5 +
slice 2 handoffs, then build. Ask only if a fix requires an
architectural choice that this prompt and the prior handoffs don't
constrain.
