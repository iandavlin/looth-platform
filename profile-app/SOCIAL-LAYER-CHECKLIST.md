# Social layer — build checklist (profile-2.0: connections + messaging)

Plan: `docs/plan-profile-2.0-social-layer.md`. **CUT-DAY-REQUIRED** — joins the
spine as a dev-final migration target; schema must freeze before the crib runs.
Nothing here is executed yet (scaffold turn). Surface each step for reaction.

## Decisions to settle with Ian (block the schema apply / shape)
- [ ] **Who-can-DM** — any member may start a DM (default, blocks hard-stop) vs
      connections-only-can-DM? (`Connections::canMessage` knob)
- [ ] **Follow** — ship `type='follow'` now, or friends-only at cut? (depends on
      whether `wp_bp_follow` exists on live — verify.)
- [ ] **Header counts source** — dedicated `me-social-counts` endpoint (recommended,
      additive) vs folding into `/whoami` (needs coordinator + shim-replacement
      sign-off; `/whoami` is shared).
- [ ] **Contact-reveal hybrid** — in pilot or post-pilot? (connection doubles as the
      reveal gate for a private WhatsApp/email/phone field.)

## Schema (review → apply on dev)
- [ ] Review `sql/2026-05-30-social-layer.sql` (NOT yet applied).
- [ ] `connections` (requester/addressee uuid, status, type) + indexes.
- [ ] `message_threads` / `messages` / `message_recipients` + bp_* provenance.
- [ ] Apply on dev; `\d` verify; confirm `users.uuid` FKs resolve.

## Backend
- [ ] `src/Connections.php` — edgeState, request/accept/decline/block, follow,
      listFor, pendingCount, canMessage, areConnected (stubs → fill).
- [ ] `src/Messaging.php` — threadsFor, thread(+markRead), send, unreadCount (stubs).
- [ ] API: `me-connections`, `me-messages`, `me-thread`, `me-social-counts` (501 stubs → fill).
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
- [ ] Dry-run on dev; assert ≈ 1,881 msgs / 370 threads / 219 senders + friend graph.
- [ ] Spot-check one known thread end-to-end (order, sender, unread).
- [ ] `--commit` on dev; idempotent re-run check (bp_* UNIQUE).
- [ ] (Cutover = coordinator-timed; social layer is a P-list blocker.)

## Hard stops (this turn observed)
No migration run · no schema apply/commit · no deploy · no git commit · no
`Whoami.php`/`config.php` edit (flag coordinator for any `/whoami` shape change).
