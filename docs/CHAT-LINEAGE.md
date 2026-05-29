# Chat Lineage Log

Append-only log of chat handoffs. When one chat is replaced by another for the same workstream (context burn, compaction, fresh start preferred, etc.), record the transition here.

**Format per entry:**

```
## YYYY-MM-DD HH:MM — <workstream>: <reason>

- **Previous:** <session-id> (last active: YYYY-MM-DD)
- **New:** <session-id>
- **Carried over:** what state crossed the boundary (handoff doc, key decisions, etc.)
- **Lost:** anything that didn't make the handoff and would be useful to know
```

Keep entries terse. The chats menu shows current state; this log shows history.

---

## 2026-05-28 10:53 — poller: promoted from terminal to tracked chat

- **Previous:** terminal sessions (ephemeral, multiple, none tracked)
- **New:** `7c518e34-15b9-44a6-a2f7-8cadcf41e3c4`
- **Carried over:** `docs/SESSION-HANDOFF.md` (current with all coordination addenda); shipped code on dev (user-context endpoint, looth_tier_changed action, PurgeNotifier); coordination contract awareness
- **Lost:** any in-conversation context from the original terminal sessions that wasn't promoted to the handoff doc

## 2026-05-28 ~11:30 — coordinator: clean handoff to successor

- **Previous:** `7deff0ff-4cf1-450b-9a5c-1e59ec7d5025`
- **New:** *(Ian to spawn fresh + capture ID)*
- **Reason:** context was getting full; clean handoff before forced compaction
- **Carried over:** all coordination canon in `STRANGLER-COORDINATION.md`, fresh `STRANGLER-SESSION-HANDOFF.md` snapshot, complete `CHATS-MENU.md` + `CHAT-LINEAGE.md`, two memory entries for relay formats + mobile lens canon in §3j just landed
- **Lost:** in-conversation context from prior coordinator session (~150 turns of negotiation, decision history, debugging). All material decisions are in the durable docs; lost context is the "how we got there" reasoning, not the destinations
- **Successor briefing:** `/home/ubuntu/projects/docs/briefing-coordinator-successor.md` (drafted alongside this rotation)

## 2026-05-28 11:11 — archive-poc: fresh session

- **Previous:** `e1421b41-c84f-419d-8b4a-1e424fbdb824` (FE editor design session originally, then absorbed coordination + postgres prep)
- **New:** `aec4f10b-e5b6-4db0-993b-75e0ee39233c`
- **Carried over:** SESSION-HANDOFF.md (postgres migration plan, schema.pg.sql shipped, dev pg up, backfill-pg dry-run clean); coord doc awareness via briefing-archive-poc-postgres.md as opener
- **Lost:** in-conversation context from prior session; pending the P3 reversal note ([reply-to-archive-poc-p3-reversal.md](reply-to-archive-poc-p3-reversal.md)) and UX request bundle ([reply-to-archive-poc-ux-requests.md](reply-to-archive-poc-ux-requests.md)) to be re-relayed since they may have only been pasted into the old session

---

## 2026-05-28 — BB-mirror: rotation mid-P5

- **Previous:** rotated session from mass rotation (ID pending)
- **New:** session ID pending — outliner title unchanged: *Reskin BB Forums and plan mobile app …*
- **Reason:** context burn mid-session
- **Carried over:** SESSION-HANDOFF.md; all hooks wired (topics, replies, edit, trash, merge/split, group hooks); live UI rehearsal + reconciliation cron are the outstanding P5 items
- **Lost:** in-conversation context; pushback exchange on P5 scope (hooks already wired, real work = live rehearsal + recon cron). Not in docs — coordinator has it.

## 2026-05-28 ~14:30 — coordinator: clean handoff to successor

- **Previous:** `c047417b-6581-4b1a-b2ae-62496b785bca`
- **New:** *(Ian to spawn fresh + capture ID)*
- **Reason:** context growing; clean handoff
- **Carried over:** all coordination canon in `STRANGLER-COORDINATION.md`, fresh `STRANGLER-SESSION-HANDOFF.md`, complete `CHATS-MENU.md` + `CHAT-LINEAGE.md`
- **Key facts not in prior docs:** live WP DB = `wp_loothgroup`; BB-mirror table names singular; profile-app needs fresh BUILD session (coordination chat is idle); messages alive on live (135/30d → full modal); setfacl pattern for secret file
- **Successor briefing:** `/home/ubuntu/projects/docs/briefing-coordinator-successor.md`

## 2026-05-28 evening — coordinator: successor session active (ID uncaptured)

- **Previous:** `c047417b` (the ~14:30 successor)
- **New:** `34c73878-3c14-41f6-b56f-8d5195ea47e4` (confirmed via transcript grep — this session's .jsonl is the one that ran the doc audit)
- **Work done this session:** drove P1/P2/P3/P6/P7c to ✅; ratified blue-green
  cutover model (fresh EC2 + DNS swing); cut CF-purge + user-comms at launch;
  set `dev.loothgroup.com/` front page → `/archive-poc/`; drafted legacy-post
  → lg-layout-v2 gating pointers; ran full doc audit (archived 33 consumed
  relays to `relays-archive/2026-05-28/`, rotated this handoff).
- **⚠️ Open:** coordinator session ID needs capturing in `CHATS-MENU.md` row 1
  + here. Ian to provide.

## 2026-05-28 evening — membership-pages: assigned to poller (NOT a new lane)

Briefly considered a separate chat; corrected to **poller's purview**. The
poller owns `Shortcodes.php` + `Pages.php` and already drove these pages
through the test-checklist — UI included. A separate chat would have
triple-coordinated (lg-shell + poller + cutover); poller owning it removes the
shortcode-markup boundary entirely.

- **Task:** put the Stripe/membership WP pages on the unified `/srv/lg-shared/`
  header (mu-plugin `template_include` swap), dev-testable, cutover-ready.
- **Briefing (now a poller task doc):** `/home/ubuntu/projects/docs/briefing-membership-pages.md`
- **If poller chat context is full:** rotate it (fresh poller chat carrying
  this task), not a new lane.

## 2026-05-29 — poller: rotation for context (carries membership-pages task)

- **Previous:** `0981c23e-ab73-47ba-9065-aa9d542c94fb`
- **New:** *(pending — Ian to spawn + capture)*
- **Reason:** context full after shipping user-context endpoint, action+purge,
  P4, Patreon adapter, Arbiter stripe guard, round-trip verify + backlog burn.
  Fresh chat carries the new membership-pages task cleanly.
- **Carried over:** refreshed `SESSION-HANDOFF.md` (top summary: active task +
  P8 pending + shipped-this-lane index + open security findings; original
  2026-05-17 content preserved below); `briefing-membership-pages.md` (task);
  `notes-for-rotated-chat-membership-pages.md` (135 lines tacit knowledge —
  PAGES registry shape, BB allowlist coupling, fragile-vs-clean shortcodes,
  `[lg_member_nav]` cleanup landmine, body-class chrome deps, CDP
  submit_button() shadowing, PoC sequence rationale).
- **Still owed on the lane:** P8 dormant smoke (not started); the 4 open
  security findings (subscriber author-caps, Fluent SMTP plaintext key, etc.).
- **Opener (in order):** `briefing-membership-pages.md` →
  `notes-for-rotated-chat-membership-pages.md` → `SESSION-HANDOFF.md`.

## 2026-05-29 — profile-app: chat a847d1aa RETIRED (clean), profile-2.0 → fresh chat

- **Previous:** `a847d1aa-8252-4c06-8d90-3e470d3cc265` — carried slice 0→3.5,
  `/whoami` + WP-session auth bridge, cross-lane coordination, and the
  cutover-prep backfills.
- **New:** *(profile-2.0 — Ian to spawn fresh on `marching-orders-profile-2.0.md`; ID pending)*
- **Reason:** profile-2.0 is a multi-week arc; clean break from the dense
  slice-history chat. Retired-not-resumed.
- **Carried over (committed):** backfills `23fe81b` — `bin/migrate-socials.php`
  (xprofile-266 primary + ACF author_* fallback, three-tier precedence, mapping
  twitter→x / reddit→web / linktree-new-kind, kind+url only), `location_address`
  fold into `snapshot-location-from-bb.php`, schema `2026-05-29-block-system-precursors.sql`
  (location_address + linktree CK). Retirement handoff `76052eb` at
  `profile-app/SESSION-HANDOFF.md` — slice-4 prod checklist + 4 carry-forward
  surprises (xprofile camelCase `youTube`; dual-column same-source location;
  per-kind not all-or-nothing precedence; no-per-row-vis is a settled invariant).
  Dev rehearsal green: walk `20260529T194240Z` (165 xprofile + 45 ACF + 2 kept, 4 linktree).
- **Lost:** in-conversation context; substance is in the handoff + committed code.

## Entries below this line should be appended chronologically as handoffs happen.

---

## 2026-05-28 ~12:00 — mass rotation: all active chats refreshed

All 7 chats rotated within the same session (context management). Session IDs for new sessions not yet captured — Ian to provide UUIDs when available.

| Workstream | Previous ID | New outliner title | New ID |
|---|---|---|---|
| coordinator | `7deff0ff-4cf1-450b-9a5c-1e59ec7d5025` | *Review briefing coordinator successor …* | pending |
| profile-app | `a847d1aa…` | *Profile app next session planning* | pending |
| BB-mirror | `ed723d17…` | *Reskin BB Forums and plan mobile app …* | pending |
| poller | `7c518e34-15b9-44a6-a2f7-8cadcf41e3c4` | *Promote briefing poller to chat* | pending |
| archive-poc | `aec4f10b-e5b6-4db0-993b-75e0ee39233c` | *Briefing archive POC with Postgres* | pending |
| cutover | unknown | *Review briefing cutover …* | pending |
| lg-shell | *(first session)* | *Review LG shell briefing document* | pending |

- **Carried over:** all durable docs (STRANGLER-COORDINATION.md, each chat's SESSION-HANDOFF.md, CHATS-MENU, CHAT-LINEAGE). Relay queue delivered before rotation.
- **Lost:** in-conversation context from prior sessions. Substance is in the handoff docs.

---

## 2026-05-28 ~11:10 — workstream rename: lg-bp-mirror → lg-shell

Not a chat replacement (no prior chat existed), but a scope expansion + rename worth logging.

- **Previous identity:** `lg-bp-mirror` (modal layer + REST + auth reskin)
- **New identity:** `lg-shell` (everything above + the shared header partial previously assigned to archive-poc as P3)
- **Why:** the modals attach to the header (bell, message icon are IN the header), share design tokens, share data sources. One chat owning the whole shell = one coordination point. archive-poc gets P3 off their plate and stays content-focused.
- **Artifacts renamed:** `briefing-lg-bp-mirror.md` → `briefing-lg-shell.md`; `lg-bp-mirror/` dir → `lg-shell/`; coord doc + menu updated
- **Side effect:** P3 reversal note sent to archive-poc ([reply-to-archive-poc-p3-reversal.md](reply-to-archive-poc-p3-reversal.md))
