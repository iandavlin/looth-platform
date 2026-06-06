# Strangler Chats — Menu

Quick-scan of active chats. Coordinator owns this file — **keep it current.**

**To switch chats:** Claude Code panel on the left of code-server (native session picker). Outliner
titles are auto-generated from each chat's opening turn — when the opener is a file path, the title
echoes that file's topic (the "Find it by" column tells you what to look for). Session IDs are for
coordinator bookkeeping + lineage logging.

> **Refreshed 2026-06-05 PM.** The May-31 table was stale; rebuilt around the **Hub-unification
> project** (archive-poc→Postgres, unified /hub/ feed, comments/reactions). Older lanes from the
> profile/social/cutover era are in [CHAT-LINEAGE.md](CHAT-LINEAGE.md).

## Active lanes

| Lane | Find it by (outliner title) | Session ID | Owns / now |
|---|---|---|---|
| **coordinator** *(this chat)* | *please review my last coord chat…* | `9ed18876-0f64-480f-bab2-e9c6d90b07cf` | cross-cutting contract, routing, dev sysadmin. Successor to `ecafaa30` / `de317117`. |
| **archive-poc (PG)** | *Briefing archive-poc-pg* | `05b7f8d2-9c86-473f-aa7f-b66ca5f35738` | PG read-cutover + `_sync` PG writer + indexer taxonomy populate (a55871e/d97e63d). SQLite retirement HELD. |
| **hub** | *Briefing hub-fold-cpts* | `9645be99-1ac6-42b1-902e-f789a5941da8` | unified /hub/ feed (forums ⋃ discovery), category accordion + content filter, inline comments/reactions wiring. |
| **comments + reactions** | *comments-reactions* | `1c86c753-6716-44cb-b047-e888f09d3bf6` | content comments (`discovery.comments`, WP-free modal) + likes/reactions (`discovery.likes`, `/archive-api/v0/like`, X-Accel gated download). Consolidates comments-db `3df42b5c` + stream `b2bb9043` (both retired → lineage). Spawned 6/5; landed grant-commit (dd248c5) + badge-count-from-store (3dfda18). |
| **login / poller** | *Briefing lifecycle-poller* | `3035fd3f-b46f-428c-bd1f-9f40a54f7277` | Patreon auto-login, tier truth, identity stability (G1/G4/G7). |
| **lifecycle / profile-app** | *Briefing lifecycle-profile-app* | `098c8f85-846d-4530-b756-39dc7aa502f2` | profile-app identity stores, bridge, erase-user. |
| **membership-pages** | *membership-pages / Stripe-standalone lane* | `633f14c7-a66e-4529-9753-8797094c69a0` | 15 membership/purchase pages on the shared shell. |
| **stripe-pages toggle** | *Briefing stripe-pages-toggle* | `825a2c1e-a322-44d5-a876-bfe9eaf65d32` | admin on/off for the purchase pages (prelaunch gate). |
| **git-tsar** | *Briefing git-tsar* | `f14788c1-50f4-474f-8626-f42ce32a17cc` | sole merge/push gateway; pathspec sweeps. Worktree isolation SHELVED. |
| **Buck sub-coord** | *Briefing buck-subcoord* | `b1b940d4-b189-421e-b935-cf18e3a22230` | all Buck profile-app branches (pro-gate, PWA, dropoffs). |
| **perf-czar** | *Briefing perf-czar* | `221bd8d5-44fd-48e2-8921-676fc01bcfca` | perf baseline + regression watch across surfaces. |
| **lg-shell** | *Briefing lg-shell-nav-active* | `dc066cf4-361b-4c36-8f70-efd3e305359e` | canonical site-header + nav active-state. |
| **bb-mirror whoami fastpath** | *Briefing bb-mirror-whoami-fastpath* | `e22ff194-cc3b-4e64-922c-51eec6901b97` | bb-mirror JWT fast `/whoami` path. |
| **conversion** | *Briefing conversion-coord* | `5020d57f-46b6-4bff-873d-17e539fff4fe` | legacy video → v2 → standalone render. |

## Coordinator-held / awaiting Ian
- **The push** — ~27 commits committed-not-pushed on `main`; Ian review → git-tsar pushes. No silent pushes.
- **SQLite retirement** — held until the Hub content-filter is verified on the new taxonomy labels + soaked.
- **Buck pro-gate** — APPROVED 6/5; Buck to re-rebase `0842006`→`d6ba1fb` + merge, report final SHA.
- **profile-app re-rot fix** — provision sets gravatar-placeholder default; needs the prefer-BB-avatar fix or the 496-avatar backfill re-rots (briefing owed to profile-app lane).
- **cutover grant list** — `GRANT SELECT ON discovery.comments TO "bb-mirror"` (committed dd248c5, applied dev) must be re-applied at cut. Same pattern as the `content_item` grant.

## Discipline
At every chat spawn/resume, capture the session ID and pass it to coordinator. Coordinator updates this
menu + appends to [CHAT-LINEAGE.md](CHAT-LINEAGE.md) on replacements. **Don't let this drift.**

## Pointers
- Contract: [STRANGLER-COORDINATION.md](STRANGLER-COORDINATION.md)
- Live board: [LANE-LEDGER.md](LANE-LEDGER.md)
- DB ground truth: [DB-STATE-AUDIT-2026-06-05.md](DB-STATE-AUDIT-2026-06-05.md)
- Coordinator briefing: [briefing-coordinator-successor.md](briefing-coordinator-successor.md)
- Chat lineage log: [CHAT-LINEAGE.md](CHAT-LINEAGE.md)
