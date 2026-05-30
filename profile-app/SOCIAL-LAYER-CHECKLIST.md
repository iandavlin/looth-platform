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
- `wp_bp_follow` — **EXISTS, 9,002 rows** → `type='follow'` is portable now.
- `wp_bp_notifications` — EXISTS, **49,603 rows** (mostly groups/activity/mentions we don't own) → NOT a history-migration target.
**Schema finalized this turn:** added `notifications` table to `sql/2026-05-30-social-layer.sql`
(BP-envelope shape, looth_id-keyed, typed referents). Still **NOT APPLIED** — review-first.

## Decisions to settle with Ian (block the schema apply / shape)
- [ ] **Who-can-DM** — any member may start a DM (default, blocks hard-stop) vs
      connections-only-can-DM? (`Connections::canMessage` knob)
- [ ] **Follow** — `wp_bp_follow` CONFIRMED on live shape (9,002 rows). Schema already
      has `type='follow'`, so porting the graph is cheap. Real Q is now UI-only:
      surface follow on `/u/` at cut, or import-the-graph-but-hide-UI (friends-first)?
- [ ] **Header counts source** — dedicated `me-social-counts` endpoint (recommended,
      additive) vs folding into `/whoami` (needs coordinator + shim-replacement
      sign-off; `/whoami` is shared).
- [ ] **Notifications history** — start fresh (recommended; bell fills live from
      unread DMs + pending requests) vs seed current-unread message/friends notices
      so the bell isn't empty at cut. (Do NOT bulk-port 49,603 BP rows either way.)
- [ ] **Contact-reveal hybrid** — in pilot or post-pilot? (connection doubles as the
      reveal gate for a private WhatsApp/email/phone field.)

## Schema (review → apply on dev)
- [ ] Review `sql/2026-05-30-social-layer.sql` (NOT yet applied).
- [ ] `connections` (requester/addressee uuid, status, type) + indexes.
- [ ] `message_threads` / `messages` / `message_recipients` + bp_* provenance.
- [x] `notifications` (user/actor uuid, type, typed referents, is_read) + indexes — ADDED this turn.
- [ ] Apply on dev; `\d` verify; confirm `users.uuid` FKs resolve.

## Backend
- [ ] `src/Connections.php` — edgeState, request/accept/decline/block, follow,
      listFor, pendingCount, canMessage, areConnected (stubs → fill).
- [ ] `src/Messaging.php` — threadsFor, thread(+markRead), send, unreadCount (stubs).
- [ ] `src/Notifications.php` — push(upsert/dedup), listFor, unreadCount, markRead
      (**NOT scaffolded yet** — create alongside the notifications table; Connections
      + Messaging writes fire `Notifications::push`).
- [ ] API: `me-connections`, `me-messages`, `me-thread`, `me-social-counts` (501 stubs → fill).
      Add `me-notifications` (GET feed + POST mark-read) — **not scaffolded yet**.
- [ ] Every write asserts actor is a participant / not blocked.

## On-/u/ UI (profile-app-rendered)
- [ ] Connect + Message buttons in the header block; state from `Connections::edgeState`.
- [ ] Buttons respect the header ceiling (private header hides them; member header
      join-gates the public). Mocked in `/var/www/dev/mockups/profile-block.html`.

## Header modals (lg-shell lane — CROSS-LANE, don't build here)
- [ ] lg-shell P9 messages / notifications / friends modals call the profile-app
      endpoints. Coordinate the contract via coordinator; profile-app owns data.
- [ ] Shared-header badge lazy-load wired to `me-social-counts`.

## Schema sign-off
- [ ] Coordinator declares social schema dev-FINAL with the spine. Only then →

## Crib (one pass, after sign-off) — CUT-DAY-REQUIRED
- [ ] Implement `bin/migrate-social-from-bb.php` (stub today).
- [ ] Dry-run on dev; assert ≈ 1,881 msgs / 370 threads / 219 senders + 10,978 friend
      edges (7,346 accepted) + 9,002 follow edges. (Notifications: no history import.)
- [ ] Spot-check one known thread end-to-end (order, sender, unread).
- [ ] `--commit` on dev; idempotent re-run check (bp_* UNIQUE).
- [ ] (Cutover = coordinator-timed; social layer is a P-list blocker.)

## Hard stops (this turn observed)
No migration run · no schema apply/commit · no deploy · no git commit · no
`Whoami.php`/`config.php` edit (flag coordinator for any `/whoami` shape change).
