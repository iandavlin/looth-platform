# Social layer — build checklist (connections + messaging + notifications)

Plan: `docs/plan-profile-2.0-social-layer.md`. **CUT-DAY-REQUIRED** — joins the
spine as a dev-final migration target; schema must freeze before the crib runs.
Nothing here is executed yet (scaffold turn). Surface each step for reaction.

## Scaffold verification + BB port (2026-05-30, social lane)
Verified scaffold present + consistent; schema deps resolve (`users.uuid` from
`0001_init`, `touch_updated_at()` from `0001_init`, `gen_random_uuid()` already
used by slice-3 → no extension gap). Read-only inspected live BB (`looth_dev`):
- `wp_bp_friends` — **10,978 edges (7,346 accepted / 3,632 pending)**; one row/pair → maps 1:1.
- `wp_bp_messages_messages` — **1,881 msgs / 370 threads / 219 senders** (matches snapshot).
- `wp_bp_messages_recipients` — 639 rows; extra cols `sender_only`, `is_hidden` (map: sender_only→keep as participant row, is_hidden→is_deleted). Message-level `is_deleted` → skip on import.
- `wp_bp_follow` — exists (9,002 rows) but **NOT migrated**: connections are MUTUAL ONLY (Ian, 2026-05-30); follow dropped.
- `wp_bp_notifications` — EXISTS, **49,603 rows** (mostly groups/activity/mentions we don't own) → NOT a history-migration target.
**Schema finalized this turn:** added `notifications` table to `sql/2026-05-30-social-layer.sql`
(BP-envelope shape, looth_id-keyed, typed referents). Still **NOT APPLIED** — review-first.

## Decisions — RULED (Ian, 2026-05-30; STRANGLER → "Social decisions RULED")
- [x] **Who-can-DM → CONNECTIONS-ONLY.** Mutual connection gates DM (connect first,
      then message; mirrors BB). No any-member DM. Baked into `Messaging::send` +
      `Connections::canMessage`.
- [x] **Follow → DROPPED.** Connections MUTUAL ONLY; `follow` type/graph removed,
      `wp_bp_follow` not migrated; auto-derived from an accepted connection if ever needed.
- [x] **Header counts → dedicated `me-social-counts`** (additive; no `/whoami` change).
- [x] **Notifications → start FRESH** (no BB history port); crib seeds current-unread
      DMs + pending connection requests so the bell isn't empty. (49,603 BP rows NOT ported.)
- [x] **Counts display → "9+" cap** (endpoint returns true count) **+ 30-day retention
      cron** auto-deletes old notifications (the DM/connection persists; only the alert prunes).
- [ ] **Contact-reveal hybrid** — STILL OPEN: in pilot or post-pilot? (connection
      doubles as reveal gate for a private WhatsApp/email/phone field.) Post-pilot lean.

## Schema (review → apply on dev)
- [ ] Review `sql/2026-05-30-social-layer.sql` (NOT yet applied).
- [ ] `connections` (requester/addressee uuid, status; mutual-only, no `type`) + indexes.
- [ ] `message_threads` / `messages` / `message_recipients` + bp_* provenance.
- [x] `notifications` (user/actor uuid, type, typed referents, is_read) + indexes — ADDED this turn.
- [ ] Apply on dev; `\d` verify; confirm `users.uuid` FKs resolve.

## Backend
- [ ] `src/Connections.php` — edgeState, request/accept/decline/block, listFor,
      pendingCount, canMessage (connections-only), areConnected (stubs → fill).
      **follow()/unfollow()/`following`/`TYPES` REMOVED this turn (mutual-only).**
- [ ] `src/Messaging.php` — threadsFor, thread(+markRead), send (**connection-gated
      this turn**), unreadCount (stubs).
- [x] `src/Notifications.php` — push(upsert/dedup), listFor, unreadCount, markRead,
      markAllRead, prune(30d) — **SCAFFOLDED this turn** (bodies stubbed). Connections
      + Messaging writes fire `Notifications::push`.
- [ ] API: `me-connections`, `me-messages`, `me-thread`, `me-social-counts` (501 stubs → fill).
- [x] `me-notifications` (GET feed + POST read/read_all) — **SCAFFOLDED this turn** (501 stub).
- [ ] `bin/prune-notifications` cron (30-day retention) — NOT this turn.
- [ ] Every write asserts actor is a participant / not blocked.

## On-/u/ UI (profile-app-rendered)
- [ ] Connect + Message buttons in the header block; state from `Connections::edgeState`.
- [ ] Buttons respect the header ceiling (private header hides them; member header
      join-gates the public). Mocked in `/var/www/dev/mockups/profile-block.html`.

## Header modals (lg-shell lane — CROSS-LANE, don't build here)
- [ ] lg-shell P9 messages / notifications / friends modals call the profile-app
      endpoints. Coordinate the contract via coordinator; profile-app owns data.
- [ ] Shared-header badge lazy-load wired to `me-social-counts`.

### Response shapes lg-shell will read (PROPOSED — for coordinator to relay/ratify)
All GET, auth via `/whoami`; counts are TRUE integers (lg-shell renders the "9+" cap).
```
GET me-social-counts  → { messages_unread: int, requests_pending: int, notifications_unread: int }
GET me-notifications  → { items: [ { id:int, type:'message'|'connection_request'|'connection_accept',
                                     actor:{ uuid, name, avatar_url, slug }|null,
                                     ref:{ kind:'thread'|'connection', id:int },
                                     is_read:bool, created_at:ISO8601 } ],
                          unread:int }
POST me-notifications  body { action:'read', id:int } | { action:'read_all' } → { ok:true }
```
Notes: badge display caps at "9+" in lg-shell (endpoint is uncapped). `notifications_unread`
is additive to the existing `messages_unread`/`requests_pending` the header already lazy-loads.

## Schema sign-off
- [ ] Coordinator declares social schema dev-FINAL with the spine. Only then →

## Crib (one pass, after sign-off) — CUT-DAY-REQUIRED
- [ ] Implement `bin/migrate-social-from-bb.php` (stub today).
- [ ] Dry-run on dev; assert ≈ 1,881 msgs / 370 threads / 219 senders + 10,978
      connection edges (7,346 accepted). (No follow; notifications: no history import.)
- [ ] Spot-check one known thread end-to-end (order, sender, unread).
- [ ] `--commit` on dev; idempotent re-run check (bp_* UNIQUE).
- [ ] (Cutover = coordinator-timed; social layer is a P-list blocker.)

## Hard stops (this turn observed)
No migration run · no schema apply/commit · no deploy · no git commit · no
`Whoami.php`/`config.php` edit (flag coordinator for any `/whoami` shape change).
