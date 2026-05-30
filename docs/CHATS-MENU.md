# Strangler Chats — Menu

Quick-scan of active chats. Updated by coordinator. Open this when you need to know who's doing what.

**To switch chats:** use the Claude Code panel on the left of code-server (native session picker). The session IDs below are recorded for coordinator bookkeeping + chat-lineage logging.

| # | Chat | Outliner title (left panel) | Session ID | Owns | Now |
|---|---|---|---|---|---|
| 1 | **coordinator** | *Review briefing coordinator successor …* | `34c73878-3c14-41f6-b56f-8d5195ea47e4` | cross-cutting contract, doc, routing | active — successor chain from `7deff0ff` → `c047417b` → this; confirmed via transcript 2026-05-28 |
| 2 | **profile-2.0** | *profile-2.0 — block-model composer + profile/practice mockups* (auto-title resolves on open) | `1c98b564-ae29-4bc2-af2d-b06f80498aa4` | identity, `/whoami`, profile pages, directory, profile-2.0 block system | **SPAWNED 2026-05-29** (fresh; `a847d1aa` RETIRED — shipped slice 0→3.5, auth bridge, cross-lane coord, backfills `23fe81b`/handoff `76052eb`). Opener constrained to **Phase 0 — MOCKUPS FIRST** (design-confirm before build): composer sidebar-palette editor + block-model profile page + typed practice page → `/var/www/dev/mockups/`, surface for reaction. Bootstrap `bootstrap-profile-2.0.md`. Then Phase 1 spine (migration target, dev-final first) → Phase 2 composable storefront → Phase 3 JSON+LLM. Profile block system is SEPARATE from layoutv2. Coord checkpoint: flag before editing `Whoami.php`/`config.php` (shared w/ shim-replacement `d9380b73`). |
| 3 | **BB-mirror** | *Reskin BB Forums and plan mobile app …* | `ed723d17-00e9-4d6c-8ca5-9dafe057f49d` | forum threads (read mirror), forum render | activity feed + left nav shipped; idle waiting on `/whoami` for group gating |
| 4 | **poller** | *(rotating — new ID pending)* | `0981c23e` → pending | tier truth, role writes, Arbiter, Patreon adapter (live) + membership-pages shell task | ROTATING for context. New chat opener: briefing-membership-pages → notes → SESSION-HANDOFF. Active task: 15 membership pages onto shared shell (PoC on /membership-guide/ first). Still owed: P8 dormant smoke |
| 5 | **archive-poc** | *Briefing archive POC with Postgres* | `aec4f10b-e5b6-4db0-993b-75e0ee39233c` | discovery / front page / activity engine | ✅ step 2 complete — /whoami-backed gating live; idle waiting on cutover |
| 6 | **cutover** | *Review briefing cutover …* | `c4e655f8-8279-4466-8a60-b8b7153c7df6` | live-execution orchestrator, CUTOVER-PLAN.md, rollback playbook | BATCH-04 landed; awaiting briefing with findings |
| 7 | **lg-shell** | *Review LG shell briefing document* | `1d248347-7f2f-4539-ad72-d0b3aa607e8d` | shared header partial + modals (notifications/friends/follow/messages/photos) + auth reskin + design tokens | ✅ P3 header shipped (/srv/lg-shared/); building P9 modals (notification bell + REST first); account-dropdown relay pending (`reply-to-lg-shell-account-menu.md`) |
| 9 | **shim-replacement** | *shim-replacement* (lane-reported; code-server auto-title resolves on open) | `d9380b73-df4d-4836-8d54-735c0bf09b33` | mint looth_id at WP login, retire per-page whoami loopback | **IN BUILD** — design closed (caught the JWT claim-shape gap pre-code); new-1+new-2 ratified → §0c canon (e97af1b). Now: commit addendum + mint endpoint (signing add to profile-app + POST /mint-token). Gated next: WP hooks → bb-mirror inline-verify → roll pattern → Ian key-flip (new-4) → soak → retire. Checkpoints: flag coord before Whoami.php (profile-2.0 shares profile-app/); role→tier coord w/ poller. **PRE-CUT REQUIRED** (Ian: fast first experience, no slow site day-one). Bootstrap `bootstrap-shim-replacement.md`. Owns: mint endpoint (profile-app) + WP login hooks + consumer-verify pattern + bb-mirror proof. Dev-built+soaked before flip (auth invariant). |
| 10 | **social** | *social — connections + messaging + notifications* | `e9fd24ab-be3e-4018-9c67-6742be16541d` | connections + messaging + notifications (in profile-app) | **SPAWNED 2026-05-30** (re-briefed after a mis-seed). Schema finalized as a reviewable migration target, grounded vs live BB: **friends 10,978 edges** (7,346 acc/3,632 pend), **wp_bp_follow EXISTS 9,002**, messages 1,881/370/219, **wp_bp_notifications 49,603**. Added a notifications table (3rd pillar had none). **NOTHING applied/committed by it** (coord committed its sql+checklist). **Holding for dev-FINAL sign-off + 4 decisions** (follow-UI · notif-history start-fresh · who-can-DM · counts endpoint). Owes nothing; profile-2.0 owes it the `Social::renderProfileActions()` slot; lg-shell must agree `me-notifications`/`me-social-counts` shapes. Bootstrap `bootstrap-social-messaging.md`. **CUT-DAY-REQUIRED.** |
| 8 | **events** | *events — event page → v2 + landing → shared shell* | `8d852dda-54b5-41fc-8308-84cffe16e770` | event CPT → v2, event-header block, events landing | ✅ **LANE COMPLETE** (`934ea7c`). Post pages v2 (event-header block, default_event_layout, zoom-only gating) + landing = **standalone** `/events/` (own FPM pool, reads WP MySQL read-only, shared shell, no WP boot, verified). **Cutover carry: `Plugin.php` MANAGED_CPTS+=event · events post-deploy (create `events` user, `/etc/lg-events-db`) · Sheet patch `events/sheets-zoom-url-patch.gs` · TZ · `_ame_cpe_post_policy` confirm.** Flags: header doesn't consume `active_nav` yet (lg-shell §0a render change); `/calendar/`→`/events/` redirect optional. |

**Outliner titles** are auto-generated by Claude Code from the opening turn — when the opener is a file path, the title references the file's topic. Cross-reference column above tells you which row in the outliner matches which menu chat.

## Pending relays (sitting in `/home/ubuntu/projects/docs/`)

| To | File | What |
|---|---|---|
| BB-mirror | [reply-to-bb-mirror-render-bugs.md](reply-to-bb-mirror-render-bugs.md) | 6-issue bundle on `/forums-poc/` page |
| archive-poc | [reply-to-archive-poc-p3-reversal.md](reply-to-archive-poc-p3-reversal.md) | P3 reversal — off your plate, going to lg-shell; acks prep + syncwriter |
| archive-poc | [reply-to-archive-poc-ux-requests.md](reply-to-archive-poc-ux-requests.md) | 3 UX requests from Ian: bare /archive-poc/ landing, search-modal with author+kind detection, deletable tag pills |
| lg-shell (when spawned) | [briefing-lg-shell.md](briefing-lg-shell.md) | Initial brief on spawn |
| poller (when spawned) | [briefing-poller-promote-to-chat.md](briefing-poller-promote-to-chat.md) | Promote terminal sessions to tracked chat |
| archive-poc · bb-mirror · lg-shell | [reply-to-consumers-post-shim-identity-contract.md](reply-to-consumers-post-shim-identity-contract.md) | Post-shim identity contract (§0c) — build inline-verify to JWT+lg_tier shape; non-blocking, deliver when their inline-verify turn comes |
| cutover | [briefing-cutover-refocus.md](briefing-cutover-refocus.md) | B-now/A-later + storage architecture (probably already delivered) |
| profile-2.0 | [reply-to-profile-2.0-block-sets.md](reply-to-profile-2.0-block-sets.md) | Block-sets design (`c88ede9`): split block sets profile/practice, separate headers, member-default pmp. **Landed AFTER its 21:31 Phase-0 mockups** — deliver as the lead input for the mockup-iteration turn. APPROVED as-is (Ian). |
| lg-shell | [reply-to-lg-shell-header-fixes.md](reply-to-lg-shell-header-fixes.md) | 🔴 Forum nav repoint /forums-poc→/forum (closes the 301 loop) + logo default un-404-able + active_nav key='forum'. From /forum CDP audit 2026-05-29. |
| bb-mirror | [reply-to-bb-mirror-nav-fixes.md](reply-to-bb-mirror-nav-fixes.md) | 🔴 duplicate category slug collisions (acoustic/finish/folk/amps share one slug) + pass active_nav='forum' + avatar→single-source (batch lookup + image w/ initials fallback, supersedes the gravatar patch). From /forum CDP audit. |
| archive-poc · lg-layout-v2 · lg-shell | [reply-to-consumers-avatar-single-source.md](reply-to-consumers-avatar-single-source.md) | Avatar single-source contract (STRANGLER §"Avatar / author-identity"): every surface (header/forum/archive banner/post author-header+footer/directory) reads the SAME spine avatar via /whoami + batch lookup, initials fallback, edited once in profile-2.0. Pre-cut dep: profile-app avatar store+versioned URL. |

## Outstanding Ian actions

| Priority | What | Why |
|---|---|---|
| 🔥 | Relay archive-poc switchover briefing | Step 2 of cutover sequence — unblocks step 3 (shared header) |
| 🔥 | Relay poller Arbiter stripe guard green-light | 3-line fix, safe, prevents silent user downgrade |
| 🔥 | Relay BATCH-04 findings to cutover chat | Cutover plan needs Patreon role-writer detail |
| ⏳ | Confirm stale dev.loothtool cron removal landed | Hygiene |
| ⏳ | CF API token → `/etc/lg-cloudflare-token` 0600 | Cutover step 3, 10, 12 cache purge |
| ⏳ | Point at anonymizer plugin name/location | BB-mirror anon-visibility logic |

## Cutover-eligibility (P1–P11) at-a-glance

- P1 `/whoami` (profile-app) — ✅
- P2 Patreon adapter (poller) — ✅
- P3 Shared header partial (lg-shell) — ✅
- P6 archive-poc `/whoami`-backed gating — ✅
- P7c `edit_archive_poc` cap — ✅
- **P12 shim-replacement (mint looth_id at login, kill whoami loopback) — 🆕 PRE-CUT REQUIRED** (Ian: fast first experience). Dedicated chat. Dev-proven+soaked before flip.
- P3 Shared header partial — ⏳ moved to lg-shell
- P4 `LG_PROFILE_APP_URL` (poller) — ⏳
- P5 BB-mirror mu-plugin live rehearsal — ⏳
- P7c `edit_archive_poc` cap mu-plugin — ✅
- P6 archive-poc /whoami-backed gating — ⏳
- P7 pgloader-or-rebackfill scripts — ⏳
- P8 Poller dormant-mode dev smoke — ⏳
- P9 lg-shell modals (notifications, friends, follow, messages, photos) — 🆕
- P10 Group-as-forum-with-decoration (subsumed into BB-mirror work) — ⏳
- P11 BP unused-surface kill decisions (post live audit) — ⏳

When all ✅, cutover-eligible.

## Backlog / parked ideas
- **Tutorial / product-tour modal** (Ian, 2026-05-30) — a guided coachmark/tour
  overlay to onboard users into the new live-edit profile + social features.
  lg-shell's domain (owns modals). Not cut-critical; revisit after the spine.

## Chat lineage

When a chat gets replaced by another for the same workstream (context burn, fresh start, etc.), log the handoff in [CHAT-LINEAGE.md](CHAT-LINEAGE.md). This menu shows current; lineage log shows history.

**Discipline:** at every chat spawn/resume, capture the session ID and pass it back to coordinator. Coordinator updates menu + appends to lineage log if it's a replacement.

## Pointers

- Contract: [STRANGLER-COORDINATION.md](STRANGLER-COORDINATION.md)
- Session handoff (coordinator): [STRANGLER-SESSION-HANDOFF.md](STRANGLER-SESSION-HANDOFF.md)
- BB decommission: [BB-DECOMMISSION-INVENTORY.md](BB-DECOMMISSION-INVENTORY.md)
- Cutover plan: [../cutover/CUTOVER-PLAN.md](../cutover/CUTOVER-PLAN.md)
- Chat lineage log: [CHAT-LINEAGE.md](CHAT-LINEAGE.md)

> **Coordinator: update this file whenever a chat status changes materially. Don't let it drift.**
