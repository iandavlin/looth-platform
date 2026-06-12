# LIVE DEPLOY — audit + plan (drafted 2026-06-12, visibility-refactor session)

Ian 6/12: "we are just about ready to start deploying on the live server…
audit and test." This is that audit + the sequenced plan. Companions it does
NOT duplicate: `profile-app/CUTOVER-CHECKLIST.md` (slice-3 data migration
detail, sponsor store re-apply), `docs/STRANGLER-COORDINATION.md` (contracts),
`docs/OWNERSHIP-CUTOVER-AUDIT.md` (file-ownership collapse).

## 0. Decisions Ian owns before a date is set

1. **Cut window + freeze** — the data plan needs ~1–2 quiet hours.
2. **Live routing for `/`** — dev currently 302s `/` → `/hub/`; the bento
   front page is `/front-page/`. What does live's `/` serve at launch?
3. **BB surface retirement order** — which BuddyBoss pages 302 to the new
   surfaces on day one vs linger.
4. **F1 location-section clamp** — still marked "pending Ian" in the at-cut
   security list; rule or explicitly drop.
5. Confirmed staying OUT of the cut: lg-stripe (PARKED 6/11), guitardle
   (decommissioned, fast-follow), practices `/p/` (dormant).

## 1. What ships (and what doesn't)

Ships: profile-app, archive-poc (front/hub/search), bb-mirror (forums),
events, lg-shared (canonical header), the WP mu-plugins they pair with,
poller lane, nginx snippets (repo: `platform/nginx/`), PG databases
`profile_app` + `looth`, media store `/srv/profile-app-media`, thumb-app.
Total footprint measured on dev today: **< 200 MB** (+~400 MB Postgres
packages). **Live disk VERIFIED 6/12 (Ian ran df): 15.3 GB free of 29 GB
(48% used), R2 uploads mounted off-disk → ~15 GB free post-deploy. Disk is
a settled question — no cleanup needed for the cut.**

Does NOT ship: dev cookie gate (but see 2.4!), mailpit (live mail must go to
real SMTP — verify), chrome-dev, code-servers, team accounts, mint-DEV
conveniences (the jwt signing key itself DOES ship — new pair).

## 2. Audit findings (2026-06-12 sweep)

1. **Configs already env-branch** ✓ — profile-app / archive-poc / bb-mirror /
   events all pick `loothgroup.com` hosts automatically off HTTP_HOST.
2. **Hardcoded-dev bugs found + FIXED in this audit**: the profile-edit
   login interstitial pointed at dev absolutely (would have bounced live
   users to the dev box); reports.php mailed From: noreply@dev. Both now
   derive from LG_PROFILE_APP_HOST.
3. **VERIFY before cut** (couldn't fully resolve from dev):
   - `_materialize.php` / `_sync.php` wp-load path→host maps include the
     LIVE WP path (`/var/www/looth-live`?) — confirm the live entry.
   - **Scheduling — SHORED UP (Ian 6/12 "do we need to shore that up?" →
     yes, done):** dev already ran `bb-mirror-reconcile.timer` (10 min,
     posts-changed sync — my first sweep missed it by name). The gap it
     left (stale person identity/visibility when a member renames or flips
     a dial without posting) is now covered by
     `platform/systemd/lg-person-vis-refresh.{service,timer}` — 15 min,
     installed + proven on dev (journal: 502 resolved). Live install =
     copy both unit pairs + `systemctl enable --now`; no gate env file on
     live. Remaining for live only: weekly geoipupdate (example conf in
     profile-app/deploy/). Context: the request path IS direct WP↔app API +
     push hooks — timers only converge the pull-based caches; Ian runs the
     full backfills at cut.
   - Live SMTP path for profile-app `@mail()` (reports) + sudo-queue pings.
   - Poller lane standing gaps (memory): profile-app nginx route, discovery
     ownership, audit_log key.
4. **nginx snippets reference `$loothdev_is_authorized`** (the dev cookie
   gate) in dozens of `if` guards. Live's server block must define
   `map … $loothdev_is_authorized { default 1; }` (or the includes 403
   everything). Deliberate choice: keep the variable, neutralize it live —
   zero snippet drift between dev and live.
5. **Secrets to CREATE on live (F6 — never copy from dev, dev's are exposed
   to every team chat):** `/etc/looth/jwt-{private,public}.pem` (new pair),
   `/etc/lg-internal-secret`, `/etc/lg-archive-poc-secret`,
   `/etc/lg-profile-app-secret`, WP `profile_hook_secret` option, R2 token
   (live's own, already exists). Rate-limit conf for /profile-api +
   location-search (checklist item, still unapplied even on dev).
   **These are NOT WordPress salts (Ian 6/12).** wp-config, WP salts, the WP
   DB and the domain are untouched — every member's existing
   `wordpress_logged_in` cookie stays valid through the cut; nobody re-logs
   in. The new apps mint their `looth_id` fast-path token silently off the
   existing WP cookie on first touch (the bounce already live on dev) —
   which is why reconcile-bridge is STEP ONE of the top-off order.
   **No search-replace pass** (asked 6/12): the WP DB doesn't move, and the
   remaining dev-hostname strings in app code are the dev halves of
   env-branches that must stay; the grep audit (clean as of today) is the
   pre-cut check, not a replace.
6. **PG on live**: install PG16, create roles per pool user, apply the two
   databases by RESTORE (see §3), then the ownership question — at cut,
   collapse file+role ownership to www-data and switch peer-auth DSNs to
   password DSNs (OWNERSHIP-CUTOVER-AUDIT.md owns the detail).
7. **GeoLite2-City.mmdb** + geoipupdate cron on live (free MaxMind key).
8. Repo hygiene ✓: nginx snippets, sql/ migrations, all app code tracked;
   buck overlay JS (`/var/www/dev/*.js`) is LIVE-truth on dev — inventory
   which overlays are product (privacy-sheet, directory-desktop, fp pieces,
   app-mobile-fixes) and ship them to live's web root with the pages.

## 3. Data plan — CARRY dev's Postgres, top off from live's WP

**Dev's PG is the canonical product state** — it holds every ruling executed
in data: the 1,896 members-only flips, the location-section repair, person
visibility caches, comments/reactions, discussion-visibility choices. Do NOT
re-derive on live from scratch; that would silently lose rulings.

1. Freeze window starts: announce, stop dev writers.
2. `pg_dump` `profile_app` + `looth` on dev → restore on live.
3. Re-point at live WP (configs do this by hostname) and run the
   **idempotent top-offs** against live's CURRENT WP/BB data, in order:
   a. `reconcile-bridge.php` (wp_user_id ↔ profile user; new live signups
      since the 6/11 dev snapshot get provisioned).
   b. `migrate-from-xprofile.php --commit` (fills the NEW users only).
   c. **FULL person resync** (stale-person-after-reload rule) +
      `backfill-profile-visibility.php` (both flags).
   d. Social/DM top-off — fold the DM strip-HTML/unescape fixes in FIRST
      (standing 6/11 rule), then run.
   e. `backfill-avatars.php` — live HAS the real BB avatar files dev never
      had; expect real URLs to replace Gravatar fallbacks.
   f. Comments/likes top-off (comments-db lane scripts).
4. Hand-jigger the 6 never-geocoded users (checklist) — use
   `bin/fix-divergent-locations.php` semantics (evidence-guarded) not raw
   UPDATEs.
5. `/srv/profile-app-media` rsync dev → live (15 MB).

## 3b. WP-side delta — RECOMMENDED: wire to existing, never copy the WP DB

Ian floated copying dev's WordPress state to live (6/12, undecided —
AWAITING HIS CALL). Recommendation: **live's WP DATABASE is canonical and
is never overwritten** — dev's WP is a 6/11 snapshot, and a dump-over would erase
everything members did on live since (posts, signups, webhook state), ship
QA residue/dev URLs, and break every wp-id-keyed table (bridge, persons).
Copy CODE and CONFIG deliberately instead:

1. **Plugin code**: lg-layout-v2 via the established zip deploy (build in
   /var/www/dev/.well-known/, curl on live, unzip + chown looth-live +
   bundle regen + epoch bump). lg-legacy-import only if conversions run
   from live.
2. **mu-plugins**: from `platform/mu-plugins/` (profile-auth, profile-sync,
   whoami-shim retirement decision rides the consumer-repoint item) + the
   archive-poc feed mu-plugin + showrunner bridge (its own cutover doc).
3. **Converted posts (managed CPTs)**: re-import on LIVE via the conversion
   pipeline (designed for live; dev's duplicate-post quirk doesn't carry).
   Inventory at posts/conversions/.
4. **Options/settings**: scripted `wp option update` list — gate-CTA copy,
   lgms_* member-sync creds (fresh secrets!), sponsor ACF group disable
   (`wp post update 33147 --post_status=acf-disabled`), menus/pages diff.
5. **Form 38 hub-anon snippet is LIVE-only already** — do not port from
   dev; verify it survives plugin updates.

## 4. Cut-day sequence

- **Phase A — prep (days before, zero user impact):** PG + roles + restore
  drill, secrets minted, FPM pools, code deployed from git, media synced,
  GeoLite2, timers installed-but-disabled, nginx snippets staged
  not-included, the `$loothdev_is_authorized` map added, rate limits in.
- **Phase B — freeze + data:** §3 in order; count checks after each step
  (users, bridge rows, person rows, avatar non-gravatar count).
- **Phase C — flip:** include the snippets, apply the `/` routing decision,
  `nginx -t && reload`. CDN/cache purge if fronted.
- **Phase D — TEST GATE (the "test" half of Ian's ask):**
  - `LG_MATRIX_HOST=https://loothgroup.com php profile-app/bin/visibility-matrix.php`
    — the same 66-assert gate that guards dev, against live, with a live QA
    fixture user. **GREEN or roll back.**
  - `bin/walk-onboarding.sh` against live (fresh-user flow).
  - CUTOVER-CHECKLIST post-cutover smokes (directory anon/authed, private
    profile leak check) + sponsor-store smoke (5 slugs round-trip).
  - Whoami latency (profile-api path ~5 ms, not the WP shim), hub feed,
    finder anon = named opt-ins + dots, front-page tile density, search
    anon-mask spot check, one real report email arrives.
- **Phase E — post:** watch FPM/nginx error logs + [admin-edit] audit lines,
  re-run matrix next morning, then schedule the BB-surface retirements.

**Rollback:** un-include the nginx snippets → live reverts to the BB
surfaces instantly; PG keeps running warm; nothing in WP was destructively
changed (ACF sponsor group re-enable is one wp-cli command).

## 5. Standing at-cut items folded in (from the memory ledger)

F6 secret rotation (§2.5) · profile-api rate-limit (§2.5) · renderLocation
2-decimal patch (verified present in `Block::locationDisplay`) · www-data
ownership collapse + password DSNs (§2.6) · poller gaps (§2.3) · matrix as
acceptance gate (§4D) · mail cap/iptables rules are DEV-only, don't port.
