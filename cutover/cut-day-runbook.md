# Cut-day runbook (live-deploy lane) — WORKING DRAFT

The sequenced "doesn't ride a git pull" steps for the cut. Each block labels
which box it runs on. Built + verified against dev where possible. **This is a
draft; sections land as they're verified.**

---

## ⭐ HARD REQUIREMENT — LOGGED-IN CONSISTENCY (Ian, 2026-06-09)

**Everyone holding a live `wordpress_logged_in` cookie must STAY logged in
across the cut. No mass logout. Not best-effort — a hard requirement.**

WordPress login cookies are HMAC-signed with the wp-config AUTH keys/salts, are
*named* `wordpress_logged_in_<COOKIEHASH>` where `COOKIEHASH = md5(siteurl)`, and
carry a session token validated against `wp_usermeta.session_tokens`. So the new
box stays "logged in" only if **all three** match live: keys/salts, siteurl,
session tokens.

### Condition 1 — preserve live's wp-config AUTH secrets VERBATIM  ⚠️ CROWN-JEWEL
The 8 constants below SIGN the login cookies. **Verified: they live in
`wp-config.php`, NOT in `/etc`, NOT in git** (wp-config is outside the repo tree).
If the new box generates fresh salts, every cookie's signature is invalid =
everyone logged out.

```
AUTH_KEY   SECURE_AUTH_KEY   LOGGED_IN_KEY   NONCE_KEY
AUTH_SALT  SECURE_AUTH_SALT  LOGGED_IN_SALT  NONCE_SALT
```

**[OLD BOX / live] — extract the 8 defines (Ian runs):**
```bash
grep -E "define\( *'(AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)'" \
  /path/to/live/wp-config.php
```
**Transfer:** these are secrets — **hand-carry (copy-paste) over Ian's secure
channel. NEVER through git or a public `/.well-known/` zip.**

**[NEW BOX] — paste the 8 lines VERBATIM into the new wp-config.php**, replacing
whatever fresh salts the new install generated. (The rest of wp-config — DB
creds — is the new box's own; only these 8 are copied.)

**[verify both boxes match]:**
```bash
# run on each box; the two outputs must be identical
grep -E "define\( *'(AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)'" wp-config.php | sort | sha256sum
```

### Condition 2 — siteurl/home stay `loothgroup.com`
`COOKIEHASH = md5(siteurl)`. The DB clone already carries the correct value.
**⚠️ DO NOT run a domain search-replace at cut** (the normal dev-reload step) —
changing siteurl renames every login cookie = mass logout. Confirm live's EXACT
siteurl string (scheme + www-or-not) and leave it untouched.
```bash
# [NEW BOX] confirm — must equal live's exact value, unchanged:
wp option get siteurl   # expect https://loothgroup.com (live's exact string)
wp option get home
```

### Condition 3 — session tokens carry via the full DB clone
`wp_usermeta.session_tokens` validates the cookie's token. A **full** clone
carries it (dev has 1744 users with sessions). No action if the clone is full;
just don't truncate/skip usermeta.

### Asterisk — login ≠ tier
Staying logged in does NOT make a member resolve to the right TIER. The
**/whoami re-arm after import** step (poller reactivate, lgms creds, BB-gate,
person-resync — see whoami section) is STILL required, else members are
logged-in-but-read-as-anon. Login consistency and tier re-arm are orthogonal;
both required.

### POST-CUT VERIFY (hard gate)
Take a **real existing live cookie**, hit the **new box**, confirm BOTH:
```bash
# [from a browser/curl carrying a real live wordpress_logged_in cookie]
#   → /wp-admin or /whoami on the new box
#   EXPECT: authenticated = true (Condition 1+2+3 working)
#       AND correct tier (whoami re-arm working)
```
If authenticated=false → keys/salts or siteurl mismatch. If authenticated but
tier=public for a paid member → whoami re-arm incomplete.

---

## Secrets-preserve list (provision on new box; NEVER git) — UPDATED
- ⭐ **wp-config.php AUTH block** — the 8 KEYs/SALTs above (login-cookie signing).
  **This was the gap — now first-class.** Hand-carry verbatim.
- `/etc/lg-internal-secret` (poller ↔ profile-app)
- `/etc/lg-archive-poc-secret`, `/etc/lg-profile-app-secret`
- `/etc/lg-events-db`, `/etc/lg-membership-db` (app→DB password files)
- `/etc/looth/jwt-private.pem` + `jwt-public.pem` (profile-app JWT)
- `/etc/lg-vapid/*` (web-push VAPID keys)
- `/etc/nginx/loothdev_token` (dev cookie gate — N/A on live unless gating)
- Stripe / Patreon creds — ship DORMANT (no creds) per coord §3h.

## DB DDL / extensions / grants — VERIFIED applied on dev (apply on cut DB)
- profile_app.users.discussion_visibility (text NOT NULL DEFAULT 'member',
  CHECK public|member — singular 'member')
- forums.person.discussion_visibility (same); forums.topic/reply.is_anon BOOLEAN
- discovery.comments.edited_at TIMESTAMPTZ
- CREATE EXTENSION pg_trgm + 4 GIN trgm indexes (forums.topic.title/author_name,
  discovery.content_item.title/author_name)
- GRANTs: bb-mirror SELECT on discovery.comments + content_item; looth-dev writes;
  archive-poc owner. profile-app SELECT where noted.
- Peer auth over unix socket (same-box model) — confirm new box same-box.

## Theme — RESOLVED: DROP
[NEW BOX / cut DB] `wp theme activate twentytwentyfive`; do not carry BB child/parent.

## Snippets — RESOLVED: carry none
Drop all wp_snippets; code-snippets plugin droppable. lg-snippets (8 folded, git)
stays. LIVE recon = pre-drop safety archive.

## (further sections: nginx/FPM via symlink farm, /whoami re-arm sequence — TBD)
