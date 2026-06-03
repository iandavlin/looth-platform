# Relay — converge on ONE shared header + name lg-shell its keeper

**From:** lg-shell lane · **To:** coordinator (→ archive-poc, bb-mirror, lg-layout-v2 lanes)
**Date:** 2026-06-03 · **Why:** the logged-in site shows up to 3 different header
"looks." Most of it is fixable now; the rest is a finite migration. This relay sets
the end-state (one header), the ownership (lg-shell), and the ordered steps.

---

## 0. Governance decision (Ian, 2026-06-03) — lg-shell is keeper of the header

The canonical site header is **`/srv/lg-shared/site-header.php`** → `lg_shared_render_site_header($ctx)`
(rendered markup: `class="lg-chrome"`). It is **WP-independent** and already proven to work
both standalone (archive-poc/bb-mirror/events/profile-app/membership-pages) **and inside
WordPress** (the `lg-membership-chrome` mu-plugin `require`s it).

**From now on:**
- **lg-shell owns the shared header + footer + its `$ctx` contract.** All changes to
  `/srv/lg-shared/*` go through lg-shell.
- **No lane builds or forks its own header.** If a standalone surface needs chrome, it
  **asks lg-shell**, which either extends `$ctx` or advises how to call the shared render.
- Consumers may only *populate `$ctx`* — they do not restyle or re-mark-up the header.

The goal is **one header everywhere.** Today there are three (migration residue):
1. `lg-chrome` (lg-shared) — all strangler standalone surfaces. ← **the keeper; the target.**
2. `lg-site-header` (lg-layout-v2 plugin) — WP article pages. ← retire onto #1 (step 2).
3. `site-header--bb` (BuddyBoss theme) — leftover un-migrated WP pages. ← dies as the
   strangler finishes (step 3, long tail).

---

## The `$ctx` contract (canonical — what every consumer MUST pass)

The header reads these. **Identity fields must come from `/whoami` verbatim** — not from
cookies, globals, or JWT-claim shortcuts. `/whoami` (profile-app) is the single source of truth:

```
/whoami →  { authenticated:bool, user_uuid, wp_user_id, slug, display_name,
             avatar_url, tier, capabilities{} }     // logged-out: {authenticated:false, tier:'public'}
```

Map it 1:1:

| `$ctx` key        | source (from /whoami)                                  |
|-------------------|--------------------------------------------------------|
| `authenticated`   | `whoami.authenticated`                                 |
| `tier`            | `whoami.tier`            ← **authoritative (poller-derived); NOT lg_tier cookie, NOT a global** |
| `display_name`    | `whoami.display_name`                                  |
| `avatar_url`      | `whoami.avatar_url`      (null → header draws the initial) |
| `capabilities`    | `whoami.capabilities`    (drives admin-gated menu items) |
| `profile_url`     | `whoami.slug ? '/u/'.rawurlencode(slug) : '/profile/edit'` |

**Reference implementation that already does this correctly:** `profile-app/web/_chrome.php`
(and `directory-members.php`). Copy that pattern.

Non-identity `$ctx` (per-surface, fine to differ): `active_nav`, `logo_url`, `search_id`,
`search_placeholder`, `msg_unread`/`notif_unread` (null = lazy-load), `logout_url`, `before_nav`.

---

## Step 1 — standardize consumer `$ctx`  (DO NOW — fixes the visible mismatch)

This is why the same header looks different on `/archive/` vs `/hub/`: the two apps feed it
mismatched identity. Verified divergences:

### → archive-poc lane
```
FILE: archive-poc/web/_chrome.php
BUG:  tier comes from $GLOBALS['LG_VIEWER_TIER'] (defaults 'public') — so the tier pill
      never shows even for PRO members.
FIX:  source tier (and authenticated/display_name/avatar_url/capabilities) from /whoami,
      exactly like profile-app/web/_chrome.php. Drop the LG_VIEWER_TIER global path.
      display_name/avatar_url already read $_whoami — just make tier do the same.
VERIFY: a PRO member sees the PRO pill on /archive/ + /archive-poc/, matching /hub/.
```

### → bb-mirror lane
```
FILE: bb-mirror/web/_chrome.php
BUG:  identity is read from JWT claims + the lg_tier cookie, so it shows the slug
      ("iandavlin") + a letter avatar instead of the real display_name ("Ian Davlin") +
      photo that /whoami returns.
FIX:  source display_name, avatar_url, tier, capabilities from /whoami (same as
      profile-app/web/_chrome.php). Keep the JWT only as the identity *anchor* (sub),
      not as the display source. Drop the lg_tier-cookie tier derivation.
VERIFY: /hub/ shows the same name + photo avatar + tier pill as /archive/ and /u/.
```

### → all other consumers (events, membership-pages) — audit
```
Confirm each sources identity from /whoami (profile-app/_chrome.php is the reference).
Flag any that read tier/name/avatar from cookies or globals.
```

After step 1 the header looks identical on every standalone surface — even before the
header *count* drops.

---

## Step 2 — retire lg-layout-v2's `lg-site-header` onto the shared header

```
→ lg-layout-v2 lane
WHAT: WP article pages render lg-layout-v2's own lg-site-header (src/SiteHeader.php +
      templates/partials/site-header.php). Replace that with a call to the shared header,
      exactly like platform/mu-plugins/lg-membership-chrome does:
        require_once '/srv/lg-shared/site-header.php';
        <link rel="stylesheet" href="/lg-shared/site-header.css">
        lg_shared_render_site_header($ctx);   // $ctx mapped from /whoami per the contract
CARE: article-page CSS currently targets .lg-site-header — re-test the article masthead
      after swap; remove the now-dead lg-site-header.css/.js + partial once green.
NOTE: archive-poc/standalone/engine/src/SiteHeader.php is a byte-identical vendored copy —
      retire it the same way (or have the engine call the shared render).
OWNER NOTE: coordinate the swap with lg-shell (keeper) — $ctx questions route to lg-shell.
```

## Step 3 — finish strangling the BuddyBoss header (long tail)

Not a discrete task — `site-header--bb` disappears page-by-page as remaining WP surfaces
move into the strangler stack. No action beyond "don't add new pages on the BB theme."

---

## Done =
- Step 1 merged + verified (header looks identical across /archive/, /hub/, /u/, /events/,
  /manage-subscription/) — **the immediate win.**
- Step 2 merged: article pages render `lg-chrome`; `lg-site-header` assets removed.
- Step 3 tracked as part of overall BB retirement.
- Going forward: header changes + new-surface header requests route through **lg-shell**.

— lg-shell lane (keeper of the shared header)
