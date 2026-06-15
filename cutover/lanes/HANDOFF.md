# Coordinator handoff — FINISHING THE CUT (2026-06-15)

You're the successor coordinator. This supersedes the 6/13 handoff (in git history). The cut
is far along — your job is a short punch-list + executing the runbook, NOT re-planning it.

## Read order
1. **This file.**
2. **`docs/PHASE-11-CUT-RUNBOOK.md`** ← THE executable cut sequence (A→F). Don't re-derive it.
3. `cutover/lanes/INDEX.md` (board) · `cutover/lanes/RULES.md` (lane OS + landmines).
4. `docs/DEPLOY-PLAN.md` (strategy).
5. **`docs/dev2-build-checklist.md` → the ADJUSTMENTS LOG = THE TRAP CATALOG.** Every build/cut
   gotcha lives there so the cut doesn't rediscover them (pool env overrides, secrets-in-`.well-known`,
   plugin symlinks, image-serving chain, R2 read/write, SSL). **Re-apply its CUT-CRITICAL items at the cut.**
5. Buck zone (he's OUT, we steward): `cutover/lanes/lane-buck-surfaces.md` + `docs/BUCK-SURFACES-AUDIT-2026-06-13.md`.

## Where the cut is (one paragraph)
Phase 11. dev2 = the prod candidate, built + verified; Phases 7–10 done (anon web sweep,
JWT wrong-key blocker closed, timers/cron). Method = **NEW box + DNS flip**, carrying live's
**WP salts + JWT keypair + users/sessions** so logins survive the flip (same domain). The
A→F runbook is complete and ordered. Remaining = the punch-list below + running the runbook.

## Δ since the 6/13 handoff (this session)
- **Hub punch-list DONE + pushed** (`origin/bespoke-cutover` @ `574c449`): delete-post (desktop
  `#lg-dmodal` + mobile `#looth-rep-sheet` + `api/v0/reply.php` topic-delete endpoint), #5 line
  breaks (render-time `bb_mirror_paragraphs` in `_topic-body.php`, feeds all 3 modals), #2 cover
  fallback to first inline body img, `data-author-id` on card + fragment, the overlay byte-fold.
- **dev2 front-page modal FIXED** — it 404'd because dev2 lacked the `/bb-mirror-api/v0/topic`
  nginx route + `api/` dir was not `o+rx` for the bb-mirror pool + pool config. All three are
  **already runbook step 10 / 11d** (chmod o+x, grant/route re-apply after restore).
- **Map pin-popup + full-map-on-load are COMMITTED** (main, see unpushed below) — `3694657`
  (full card in popup, lazy-fetch by slug), `bacce53` (Connect/Message), `3a5817e` (full map,
  US-center dropped). The map lane re-diagnosed already-done work; the live overlay just needs
  DEPLOY, not rebuild.
- **Genre taxo expanded 22→89** (`f1b6509`) — music = genres; **dev-built DATA, seed on the box**.
- **Desktop card padding fixed** (`77ab5d2`) — closes the "card-tops laying out poorly" cut item.
- **Live WP salts STAGED** → `/home/ubuntu/cut-staging/live-wp-salts.php` (600); hash-confirmed
  they DIFFER from dev. Runbook step 5 is ready — **apply in the cut window only, not now**
  (swapping salts pre-cut logs out dev2's current sessions).

## ⚠️ UNPUSHED — push WITH Ian's review (he reviews every push; no silent pushes)
- **main**: `f1b6509` (genre) · `3694657` + `bacce53` + `3a5817e` (map).
- **bespoke-cutover**: `77ab5d2` (card padding).
- Runbook **A.2** = merge `bespoke-cutover` → `main`, repoint dev2's clone to `main` (prod must
  not track a lane branch). Present the diffstat first.

## Rest-of-cut punch-list (the work NOT already in the runbook)
1. **SEO / sitemap — the one genuine gap (Ian add 6/15).** NOT in the runbook. `robots.txt` is
   still `User-agent:* / Disallow:/` (the landmine — ships → Google indexes nothing). Build
   (lightweight, Rank Math is gone): a custom sitemap + basic meta (title/desc/canonical/OG) on
   PUBLIC surfaces (`/`, `/u/<slug>`, public posts, events, sponsors), `noindex` on gated/member.
   Swap `robots.txt` at runbook **step 13** (gate-off). Submit to Search Console in E/F.
   ⬜ DECISION OPEN with Ian: exact public index surface.
2. **Refresh-JWT verification — load-bearing, NOT done** (runbook step 22). `profile-auth.php`
   (mu-plugin) mints on `wp_login`, on `init` if cookie MISSING, and via `/wp-json/looth/auth/refresh`.
   Confirm a valid WP cookie + **absent / expired / wrong-key** JWT re-mints cleanly. The
   present-but-invalid (expired/wrong-key) heal path is the last bit to verify; carrying live's
   keypair (step 6) makes present tokens stay valid, so the practical risk is low — but confirm.
3. **A.2 merge** (above) + **seed the dev-built catalog**: run
   `profile-app/sql/2026-06-15-genres-expand.sql` on the cut box (it's dev data, NOT in the live
   top-off). Idempotent (`ON CONFLICT DO NOTHING`).
4. **INDEX loose ends**: re-wire forum-visibility as GATE 4/4 (rate-limit flake fixed), wire
   `archive-poc/bin/gate-anon-leak.py` into run-all, `reconcile-pg.php` systemd timer (live at cut),
   archive the 150 stale docs (salvage the 43 cut-knowledge ones first).

## In-flight lanes (Ian's own chat windows; he ferries report-backs)
- **Map lane** — root-caused the bare popup as the overlay↔canonical split-brain (canonical
  uses free-floating `L.popup().openOn()`, not marker-bound; the overlay's hooks miss). The FIX
  is already committed (`3694657` etc.); the lane's job is deploy/verify + the full-map override
  removal — NOT a rebuild. Coordinator corrected its earlier "needs building" read.
- **Profile-app lane** — just spun up for the **gallery block** (`src/Block.php:1131+`,
  `api/v0/me-gallery.php`) + folding Buck's profile work (`buck/profile-p6` in
  `/home/buck/looth-platform`; diff his TIP, divergence risk). Boot brief issued.
- **Hub lane** — DONE.

## Staged / on-box cut artifacts
- `/home/ubuntu/cut-staging/live-wp-salts.php` (600) — live's 8 salt lines (step 5; cut-window only).
- `/etc/looth/jwt-{private,public}.pem` — JWT keypair (carry live's at cut, step 6).
- `backups/looth_import_*.sql.gz` — live DB build copy (final top-off at flip).
- `tools/topoff-dev-from-live.sh` + `docs/CUT-DAY-DATA-TOPOFF.md` — idempotent top-off + keep-list.
- `tools/cut/forums-grant.sql` — the PG grant to re-apply after every restore.

## Landmines / don'ts (standing)
- **DNS + `WP_HOME`/`WP_SITEURL` flip in the SAME window** — never DNS alone (redirect loop / admin lockout).
- **Re-apply after ANY PG restore**: forums-grant + `chmod o+x /home/ubuntu` + pool env overrides
  (`LG_*_ENV=dev`, `LG_*_PUBLIC_HOST=loothgroup.com`). CONFIRMED 6/14: the grant did NOT survive the
  dev2 restore → front page 500'd for every logged-in member.
- **Reindex (archive-poc) AFTER the URL flip** — else it bakes in `dev2`.
- **`wp user delete` = cross-store lifecycle NUKE** — never for test cleanup; direct SQL only.
- **Salts + JWT carry from live = the session-preservation pair** — both, in the cut window.
- One dup-email member (mikelle.davlin wp `1848`/`1905`) — whitelist at bridge-backfill so step 11a doesn't red.
- **Overlay deploys go LIVE→git, never the reverse** — do NOT `cp` the `hub-overlay-flag` fork over
  live JS (it's stale, it loses). Capture the live webroot INTO git, then deploy git→webroot.
- **A desktop `≥641`-only CSS rule LEAKS onto mobile** (shared card markup) — ship its `≤640`
  complement in the SAME change; verify at 390px. (Bit us repeatedly.)
- **A post-conversion RE-RUN creates a DUPLICATE WP post** (same slug) — the conversion job MUST be
  idempotent. Counting buckets: A=no `_lg_layout_v2`, B=converted-but-broken, C=dup-slug.
- **Never stage dumps/secrets in `.well-known`** (gate-exempt → publicly fetchable) — clean it after any deploy.
- **loothtool dev = out of scope** (Ian: zero worry there).
- No push over a RED gate; **no push without Ian's review**. The 3 gates (matrix/craft/infra-sec) are green.

## Rollback
DNS is the master switch — revert `loothgroup.com` A → old-live (TTL is low); old-live's `WP_HOME`
is unchanged so it serves immediately. Investigate on the new box out of the hot path. (Runbook §Rollback.)
