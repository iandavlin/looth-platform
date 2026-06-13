# Coordinator handoff — Looth strangler / pre-cut (2026-06-13, session decommission)

You're the successor coordinator. This is your pickup. Read this first, then the docs it points to.

## Where you are
- Dev box: `curl ifconfig.me` → **50.19.198.38 = you ARE dev.** Act locally with sudo. Never SSH to "dev."
- You coordinate the pre-cut fix campaign + the cut. **Lanes = Ian's own chat windows** — Ian ferries
  report-backs to you; you route logic + do the sysadmin. The ONLY teammate on the `msg` CLI is **buck**.

## Read these (all committed AND pushed to origin/main)
1. `cutover/lanes/RULES.md` — how lanes work + the landmines (esp. `wp user delete` = cross-store NUKE).
2. `cutover/lanes/INDEX.md` — the live board (status, open items, decisions, gate suite).
3. `docs/DEPLOY-PLAN.md` — the cut plan (new-box + DNS flip, session preservation, the live-read path).
4. `cutover/lanes/lane-buck-surfaces.md` — run buck's whole zone (he's away; we steward it).
5. `docs/STRANGLER-FRESH-AUDIT-2026-06-13.md` + `docs/BUCK-SURFACES-AUDIT-2026-06-13.md` — the findings.

## State (one paragraph)
The audit's cut-blockers are FIXED, tested, gated, and pushed: the gated-content leak (verified closed
live — item 1431 / video `V98BrRx0TxE` returns nothing to anon), the Patreon free-membership bug, the
bearer/cookie perimeter holes, and the login-identity chain (LIVE + verified — #1 Ian and #1912 log in as
real identity, not anon). Both audits done; buck's zone mapped; the deploy plan written. Everything is
committed and pushed — durable through a box loss.

## ✅ Ian's todo list — the active work queue

**1. Header by auth state** (shared chrome `lg-shared`, populated from `/whoami`)
- **Anon → remove the "free" framing** (the site offers little for free members). **Logged-in →
  personalized greeting at the top.**
- Calls Ian still owes: greeting wording + placement; what "free" becomes. Trap: "free" appears in legit
  non-membership copy — scope to membership wording only. Small enough to run inline as coordinator + a gate.

**2. Desktop card-tops are laying out poorly** (the Hub feed card)
- SHARED card markup. **Must NOT break mobile (buck's layer — we steward it) or the sponsor cards.**
- Ian's rules: **be careful, test it all, HAVE A FALLBACK** (one-commit revertable). Ship the desktop
  (`≥641`) change WITH its mobile (`≤640`) complement in the SAME change (a `≥641`-only rule leaks to
  mobile — has bitten before). Done = a CDP visual check passes at desktop + 390px + a feed with a sponsor card.
- Next step: screenshot the real desktop render to diagnose the breakage before scoping the fix.

**3. Convert the unconverted posts** — **PARKED on a DB refresh**
- Precondition: **dev DB is NOT current** (Ian, 6/13) — refresh first (`tools/topoff-dev-from-live.sh`
  or a fresh live dump), THEN count.
- Targets the CPTs: `post-type-videos` (341), `post-imgcap` (63), `loothprint`, `banger`, `sponsor` —
  NOT `post` (only 29).
- Counting = 3 buckets (the errors last time): **A** = no `_lg_layout_v2`, **B** = converted-but-broken
  (placeholder / wrong kind), **C** = dup-slug. Landmine: a conversion RE-RUN creates a DUPLICATE post —
  must be idempotent.

## 🔴 Immediate open items
- **bb-mirror lane: commit + `git push origin bespoke-cutover`.** Their security fixes (C2/H6/H7/SSRF) +
  a LIVE uncommitted `_feed.php` edit exist only in the worktree. ROUTED to the lane. Coordinator took a
  durable snapshot → `/home/ubuntu/coord-snapshots/`. **Do NOT commit it from the coordinator seat — it's
  the lane's** (you can't see if it's finished).
- **Verify the refresh-JWT** — load-bearing for the cut's session-preservation: confirm it re-mints
  cleanly for *expired / wrong-key / absent* JWT + a valid WP cookie. Offered, not done.
- INDEX loose ends: re-wire the forum-visibility gate (the rate-limit flake is fixed), wire
  `gate-anon-leak.py`, a `reconcile-pg` systemd timer, and archive the doc-sweep's 150 stale docs
  (salvage the 43 flagged ones first — they hold cut knowledge).

## ✂️ The cut → `docs/DEPLOY-PLAN.md`
**New box + DNS flip** (NOT in-place — reverses the old "no second box" ruling). New box = dev's CODE +
**live's WP secret keys + the JWT key + live's current users/sessions** (to respect logged-in state).
**Direct MySQL read to live** (`/etc/lg-topoff.conf`, no SSH); a fresh copy is already on disk. Wire-swaps,
sequence, and rollback are in the plan. OPEN: the refresh-JWT test; re-confirm the strategic rulings with Ian.

## 🔒 Decisions locked
Decision 1 (discussion bodies public, author-masked only) · Decision 2 (JWT minter option b: WP mints,
sub from the `_looth_uuid` mirror) · SQLite dual-write → decommission (recommended) · AWS key (dead, no action).

## Don'ts
- Don't commit the bb-mirror worktree edit from the coordinator seat — it's the lane's.
- Don't `cp` the `hub-overlay-flag` fork over live JS (it's stale, it loses).
- Don't `wp user delete` for test cleanup (cross-store nuke — use direct SQL).
- Don't push over a RED gate; don't push without Ian's review.
- loothtool dev is out of scope (Ian: zero worry there).
