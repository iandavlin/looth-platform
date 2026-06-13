# Strangler fix campaign — coordinator INDEX (live board + handoff)

**Successor: start here.** Read (1) `cutover/lanes/RULES.md` (how lanes work + the landmines),
(2) this file, (3) `docs/STRANGLER-FRESH-AUDIT-2026-06-13.md` (the findings). You are the coordinator
for the pre-cut fix campaign on the dev box (50.19.198.38 = you ARE dev; act locally with sudo).
**Routing model:** the lanes are Ian's own chat windows — Ian ferries report-backs to you, you route
logic + do the sysadmin (nginx/secrets/WP). The ONLY teammate on the `msg` CLI is **buck**.
Last updated: 2026-06-13 ~22:00.

## ✅ DONE + PUSHED this session (origin/main `773b0e5..0761d57`, 14 commits)
The audit's cut-blockers are fixed, tested, gated, and pushed:
- **archive-poc** — C1 gated-content leak CLOSED (**verified live as anon**: item 1431 / video
  `V98BrRx0TxE` returns null excerpt/body, no leak), H9 atomic TRUNCATE, H8 `reconcile-pg.php`.
- **poller** — re-enabled the per-campaign Patreon filter (the `false &&` free-membership bug),
  audit_log col, explicit tier-rank, hourly Patreon re-arbitration.
- **profile-app / identity** — H1 loopback-gate `/whoami`, H2 `Mint::subForWpUserId`, H3 CSRF.
- **infra** — bearer-gate bypass, junk-cookie member bypass, `/v2` autoindex, `/cdp` removal,
  rate-limit zones + **loopback exemption** (killed the gate-suite flake).
- **login-identity chain COMPLETE + verified** — poller `_looth_uuid` backfill (1811/1811) + infra
  activated the consume-side patch on `/var/www` (live; backup `profile-auth.php.bak-infra-20260613-212026`,
  one-command rollback). #1 Ian + #1912 verified logging in as real identity, not anon.
- **logo** — coordinator resized `shop-img/loothtool-logo.png` + theme source to 320px (craft-gate green).

**NOT pushed (deliberate):** the audit report (has live vuln detail incl. AWS key → **Ian's call** to
origin or keep local), my `cutover/lanes/` lane-OS docs (uncommitted), a dirty `archive-poc/index.sqlite`.

## 🔄 In flight
- **buck-surfaces audit DONE** → `docs/BUCK-SURFACES-AUDIT-2026-06-13.md`. Mostly solid (no criticals, no
  client-side data leaks, no SQLi, VAPID keys safe). Real fixes: **H1** push-subscribe.php unauth-write +
  subscription-hijack (push is staged, so fix before it goes live/cut), **M1** dead `saved-posts.php` to
  decommission, **M2** shop-feed stored-XSS (esc() single-quote gap). Found the **logo-revert root cause**:
  buck's hourly cron `/home/buck/bin/mirror-vendor-logos.py` re-mirrors the full-res logo every hour, so
  the coordinator's 320px resize reverts hourly — durable fix is in buck's mirror script (flagged via msg).
- **doc-decommission sweep DONE** (task `w2cqpqnwq`) — 203 docs: 9 keep, 150 archive, 1 dead, **43 review**,
  180 salvage notes. NOT yet executed: the archive *move* + salvaging the 43 (several hold REAL cut
  knowledge: `master-path-map.md`, `briefing-live-deploy.md`, `BB-DECOMMISSION-INVENTORY.md`). Full
  result in that task's output file under `/tmp/.../tasks/w2cqpqnwq.output`.

## 📋 Queued — Ian's 3 new fix lanes (PLANNING stage, nothing built; lane-shaping in progress)
1. **Header-by-auth-state** (shared chrome, lg-shared, populated from /whoami): anon → REMOVE the "free"
   framing; logged-in → personalized greeting up top. Open: greeting wording/placement; what "free"
   becomes. Trap: "free" appears in legit non-membership copy — scope to membership wording only.
2. **Desktop card-tops laying out poorly** (Hub feed card per Ian's screenshot). SHARED card markup →
   MUST NOT break mobile (buck layer — we steward it now) or the sponsor cards. Ship desktop (`≥641`)
   WITH its mobile (`≤640`) complement in the SAME change. **Ian requires a FALLBACK** (one-commit
   revertable). Done = CDP visual check passes at desktop + 390px + a feed with a sponsor card.
   Next step: screenshot the real DT render to diagnose before scoping.
3. **Convert unconverted posts — PARKED.** Precondition: **dev DB is NOT current** (Ian, 6/13) — refresh
   first (`tools/topoff-dev-from-live.sh`), THEN count. Targets the CPTs `post-type-videos` (341),
   `post-imgcap` (63), `loothprint`, `banger`, `sponsor` — NOT `post` (only 29). Counting method = 3
   buckets: A=no `_lg_layout_v2`, B=converted-but-broken (placeholder/wrong kind), C=dup-slug. Landmine:
   a conversion RE-RUN creates a duplicate WP post — must be idempotent.
**Then: the CUT conversation** (Ian wants it after these three are shaped).

## 🔒 Decisions locked this session
- **Decision 1** — member-only discussion BODIES stay public; only the AUTHOR is masked. Do NOT gate bodies.
- **Decision 2** — JWT minter = option (b): WP keeps minting, `sub` from the `_looth_uuid` mirror; collapse
  to option (a) (profile-app sole signer) POST-cut.
- **SQLite dual-write → DECOMMISSION** (recommended; Ian to confirm). reconcile-pg.php is the real net.
- **AWS key** — Ian's call: dead key, no action (caveat: still authenticates AWS-side).

## 🧩 Open loose ends
- **bb-mirror C2/H6 still needs its own push** — it's the bespoke-cutover worktree (different branch).
- forum-visibility gate is HELD OUT of run-all but the rate-limit flake is now fixed → it can be
  **re-wired as GATE 4/4** once confirmed stable (3× green). Comment in run-all.sh explains.
- wire `archive-poc/bin/gate-anon-leak.py` into run-all (held while gate suite stabilized).
- systemd timer for `reconcile-pg.php` (dev: looth-dev; live at cut).
- commit + (maybe) push the lane-OS docs + decide audit-report-to-origin.

## ✂️ Cut status (HELD by Ian; resume after the 3 lanes)
- `LIVE-DEPLOY-PLAN.md` is **DEAD** — rebuild the deploy plan from verified box facts, not the doc.
- The cut-plan re-audit was started then STOPPED (resumable run `wf_d8bb4ddb-ea9`) — it verifies which
  cut-plan claims are real-on-box vs future-promises; finish it to feed the new plan.
- The doc sweep surfaced cut knowledge in stale docs (master-path-map, briefing-live-deploy,
  BB-DECOMMISSION-INVENTORY) → raw material for the new plan.
- Six strategic rulings to RE-CONFIRM with Ian (from memory, not the dead doc): in-place/one-DB on old
  live, PG rebuilt from live WP, `/` = new front page, BB → one `/hub/` redirect, F1 = the visibility
  dial decides, cut = idempotent top-off.
- `/run-now` + `/send-gift-codes` IP-lock + `reconcile-setup.sql` schema apply = cut-time tasks.

## Gate suite (`tools/gates/run-all.sh`)
3 wired: visibility-matrix, craft-gate, infra-sec — all GREEN (loopback exemption fixed the flake).
forum-visibility built + green standalone but HELD OUT pending a stability re-confirm + re-wire.
CRAFT law: a defect found twice MUST become a gate before the second fix.
