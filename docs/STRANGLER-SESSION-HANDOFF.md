# Strangler Coordinator — Handoff

You're the coordinator. Project chats build in their lanes. Ian is the bus. You
hold the contract (`STRANGLER-COORDINATION.md`) + the docs + routing. You do NOT
make live changes; you capture decisions, write relays, wire dev nginx (you're
also box sysadmin `ubuntu`).

**Read this for the orient. Prior snapshot: `strangler-handoffs/2026-05-31-evening.md`.**

---

## LATEST — 2026-06-01 session (game-day functionality + housekeeping)

Tree is **clean + pushed to origin/main**. All lane work committed. One dirty file:
`profile-app/web/directory-members.php` (map chat in progress — leave alone).

### What shipped this session (all committed, verified, live on dev)

**Forum → "The Hub" rebrand (COMPLETE)**
- `/hub/` is the canonical forum URL. `LG_BB_MIRROR_PUBLIC_PATH='/hub'` in bb-mirror.
- 301s: `/forum/`, `/forums/`, `/forums-poc/` → `/hub/` (nginx `51a15ec`).
- bb-mirror chrome + labels: "Forum"/"Forums" → "The Hub", `active_nav='hub'`.
- archive-poc: 1169 stored URLs flipped `/forum/` → `/hub/`; all rails emit `/hub/`.
- lg-shell: header nav + footer "The Hub" → `/hub/`. `ec8a5a5`.
- Verified: `/hub/` 200, title "The Hub — Looth Group", 0 stray `/forum/` links.

**`/manage-subscription/` standalone (COMPLETE, DEPLOYED)**
- Read-only Patreon membership view (poller DB direct PDO). Anon → sign-in card;
  member → Patreon tier/status; admin → Patreon + Stripe iframe (`/__lg-stripe-panel/`).
- Clickjacking headers on the stripe panel (`X-Frame-Options: SAMEORIGIN` + CSP).
- nginx route live. WP fallback intact. Committed `f7ca461`.
- mu-plugin mirror committed to `platform/mu-plugins/` (`ddbe50a`).

**Social modals (lg-shell) — FIXED + VERIFIED**
- `social-modals.js` rebuilt against real endpoint shapes (was guessing stale paths).
- All endpoints correct: `/me/social-counts/`, `/me/connections/`, `/me/messages/`, `/me/notifications/`.
- Notifications: user-controlled mark-read (no auto-clear on open); bell = connection events only.
- Message button on connections dispatches `lg:open-dm`. Search in connections modal.
- Mirror in `lg-shell/lg-shared/` — versioned. `6e6245f`.
- **Unified modal ticket pending (shell):** Messages + Connections → one tabbed modal.
  Relay: `docs/relay-to-shell-unified-social-modal.md`.

**Footer cleanup (COMPLETE)**
- Removed BB-themed links: Membership (`/lgjoin/`), Billing & Refund (`/request-refund/`), Shops.
- `/members/` → `/directory/members/` (pending shell nav ticket).
- Privacy + Terms → loothtool.com (already done). `c1457ca`, `9e72dff`.

**Poller — CUTOVER READY**
- `/membership-guide/` ✅ + `/manage-subscription/` ✅ standalone.
- P4 (`LG_PROFILE_APP_URL`) ✅, P8 (dormant-mode smoke) ✅.
- 8 remaining money pages: **Stripe-A-later** (not launch-blocking).
- Nonce-strategy (Q1) still open — gates the form-heavy pages, not needed at cut.

**lg-shell — My Profile fix**
- "My Profile" → `$profile_url` (= `/u/<slug>`) — the new profile page, not the legacy editor.
- `$ctx` doc correction: `profile_url` = public `/u/` profile (consumers were right).
- Relay: `docs/relay-to-shell-profile-url-doc.md`.

**Social layer — message-notif removed**
- `Messaging::insertMessage()` no longer pushes `message`-type notifications.
- DMs → message badge only; bell = connection events only. Committed `5697e3e`.

**Cutover plan — step 7h added**
- Bulk-set `location_visibility='members'` + `location_pin_precision='city'` for existing
  members at cut (where both are still at old defaults). `6d9e7f3`.

**Profile-app schema default relay (pending)**
- New members: `location_visibility` default → `'members'`, `location_pin_precision` → `'city'`.
- Relay written: `docs/relay-to-profile-app-location-default.md`. Not yet applied.

### Next session — priority order

**1. nginx catch-all for CPT renderer (NEXT — planned, not yet done)**
Replace 9 near-identical CPT location blocks with one catch-all:
```nginx
location ~ ^/([a-z0-9][a-z0-9_-]+)/([a-z0-9][a-z0-9_-]*)/?$ {
    fastcgi_pass unix:/run/php/php8.3-fpm-archive-poc.sock;
    fastcgi_param LG_POST_TYPE $1;
    fastcgi_param LG_SLUG      $2;
    ...
}
```
- render.php already has WP fallback on blob-miss (`X-Accel-Redirect`).
- `^~` prefix blocks (hub, archive, events, profile, membership) are immune — they always beat `~`.
- **Also add:** `error_log()` in render.php on blob-miss (visibility into uncovered posts).
- This unblocks: sponsor-page, sponsor-product, and any future CPT automatically.

**2. Lanes with open tickets (hand to their chats)**
- **lg-shell:** unified Messages+Connections tabbed modal (`relay-to-shell-unified-social-modal.md`)
  + My Profile fix (`relay-to-shell-profile-url-doc.md`) + nav-to-loothtool (`relay-to-shell-nav-loothtool.md`).
- **profile-app:** location default change (`relay-to-profile-app-location-default.md`)
  + `?wp_ids=` endpoint for author bio (`relay-to-profile-app-users-wpids.md`).
- **map chat:** `profile-app/web/directory-members.php` in progress (leave alone).
- **editor chat:** profile page gap between View-as bar + header card (profile-app CSS, not shell).

**3. Sponsor content conversion (authoring work)**
- sponsor-post: 1/13 have v2 layouts. Use `write-article-v2` skill to convert the rest.
- sponsor-page (5), sponsor-product (16): 0 blobs, not in materializer's managed CPT list yet.
- `/sponsors/` listing page: already standalone ✅.

**4. Standalone launch inventory (remaining builds)**
See `docs/standalone-launch-inventory.md` for the full list. Key remaining:
- `docs/relay-to-standalone-launch-batch.md` — calendar/sponsors/about, video→WP fallback, weekly-email archive.
- Archive-poc sidebar: remove "Add Forum Post" + "Member Map"; add "Report a Bug" (modal with form); update "Weekly Email" link.

### Architecture notes (from audit this session)
- **Biggest dumb thing:** 9 identical nginx CPT blocks → fix is the catch-all (item #1 above).
- **2nd:** 3 separate whoami implementations → post-cutover cleanup (not worth mid-migration).
- **3rd:** blob-miss fallback is silent → fix alongside the catch-all (one `error_log()` line).
- `/tmp` activity cache, host constants, dead bb-mirror files → LOW, ignore for now.

### Ops reminders
- **Commit by pathspec always** — shared tree, multiple lanes.
- **Resume UUID gotcha:** `claude --resume` with `--print` needs full UUID, not short id.
- **idle-hold:** `touch /tmp/no-idle-shutdown` before a lane turn, `rm` after.
- **Never two profile-app turns at once** — map + editor chats are currently both live.
- **nginx snippets:** repo copy + deployed copy can drift. Always diff before deploying.
- **`/srv/lg-shared/*`** is www-data-owned, NOT in git. Mirror to `lg-shell/lg-shared/` after every edit.

### Lane roster (current)
| Lane | Chat/Status |
|---|---|
| profile-app map | active — `directory-members.php` dirty |
| profile-app editor | active — gap fix in progress |
| lg-shell | `1d248347` — unified modal + My Profile + nav-loothtool queued |
| archive-poc/standalone | active — launch batch in flight |
| poller/membership | cutover-ready, idle |
| bb-mirror | idle (rebrand done) |
| social/profile-2.0 | active — `?wp_ids=` + location default pending |
