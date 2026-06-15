# Coordinator handoff — FINISHING THE CUT (2026-06-15, PM)

Successor: the cut is far along. This supersedes earlier handoffs (in git history). Your job =
a short punch-list + the **SEO/search-continuity build below** + executing the runbook.

## Read order
1. **This file.**
2. **`docs/PHASE-11-CUT-RUNBOOK.md`** ← THE executable cut sequence (A→F). Don't re-derive it.
3. **`docs/dev2-build-checklist.md` → the ADJUSTMENTS LOG = THE TRAP CATALOG** (every build/cut gotcha;
   re-apply its CUT-CRITICAL items at the cut).
4. `cutover/lanes/INDEX.md` (board, 6/13-stale) · `cutover/lanes/RULES.md` · `docs/DEPLOY-PLAN.md`.

## State (one paragraph)
Phase 11. dev2 = prod candidate, built + verified. Method = NEW box + DNS flip, carrying live's
**WP salts + JWT keypair + users/sessions** so logins survive. **A.2 is DONE** — `origin/main` =
`f019434` now holds the entire hub fork + map + genre + cut work (one branch). dev2 is deployed on
`main`. Remaining = the SEO continuity build + the runbook flip.

## ✅ Done this session (6/15)
- **A.2 merge DONE** — `origin/main` `3a5817e → f019434` (118-commit hub fork merged; audited clean,
  gates green, ff-only). **Rollback anchor `pre-a2-main` (3a5817e) on origin** — `git revert -m 1 f019434`
  or reset-to-tag if needed.
- **dev2 deployed on `main`** — pulled f019434; overlay `hub-polish.js`/`pwa.js` cp'd to `/var/www/dev`
  (chown to **www-data** on dev2, NOT buck:loothdevs — those are dev1-only users); genre catalog seeded
  (89). dev2 is the **prod-shaped** box (no dev team users, www-data ownership, R2, env=dev2 host-detect).
- **Live WP salts STAGED** → `/home/ubuntu/cut-staging/live-wp-salts.php` (600, hash-confirmed ≠ dev).
  Apply at cut step 5 (cut-window only).
- **Refresh-JWT verified PASS** — `profile-auth.php` heals absent (init mint) / expired (cookie co-expiry
  → re-mint) / reverse (valid JWT, no WP session → bridges session); wrong-key avoided by carrying live's
  keypair. Session preservation robust given salts+keypair carry.
- **Avatars on dev2**: dry-run = 31 fixable (274 placeholder, rest have no BB photo → stay on Optimum
  default, correct). `--apply` pending Ian. Re-run at cut after the live top-off (idempotent, keep-list #1).
- **Snapshot branch `dev-snapshot-2026-06-15`** (origin) — captured ALL uncommitted multi-lane WIP
  (`9cece1c`) so nothing's lost. Excluded `archive-poc/index.sqlite` (runtime DB). Promote real items to
  main deliberately. The `profile-app/config.php` dev2-env branch is in there (NOT cut-critical — at cut
  the box is loothgroup.com → 'live' branch; dev2 branch never fires).

## 🔍 SEO / SEARCH CONTINUITY (NEW 6/15 — Ian: "don't lose our current search") ★ build before cut
**Risk:** the cut changes URL structure; any old indexed URL that 404s loses its Google ranking.
**GSC data:** `live-bundle/loothgroup.com-Coverage-2026-06-15.zip` (summary) +
`lg-snippets/loothgroup.com-Coverage-Valid-2026-06-15.zip` → **`Table.csv` = the 999-URL indexed list**.
- ~**1,208 indexed**, ~**270 impressions/day**, growing. Mostly-gated site (5,225 not-indexed = 2,590 thin
  + 1,344 intentional noindex + 629 already-redirecting + 372 canonical-dupes + 151 already-404).
- **Of the 999 valid URLs, 69% are old bbPress forum/group topics with NO redirect:**
  - **372** `/all-forums-all-topics/topic/<slug>/`
  - **316** `/groups/<group>/forum/topic/<slug>/`
  - → would **404 at the cut = lose ~69% of search.** THIS IS THE #1 CONTINUITY ITEM.
- **Already fine:** content CPTs slug-preserved & unchanged (post-type-videos 116, loothprint 66,
  post-imgcap 14, sponsor-post 7, event 17, etc.); forums→hub is `301` ✓.
- **Members:** only **6** indexed; their redirect is **302 (should be 301)**.

**The fix (build, then it goes in the cut box's nginx — flat per-box, replicate):**
1. **Slug-resolver redirect (the big save).** Old topic slugs ARE preserved in `forums.topic`
   (verified 4/5; e.g. `reverend-club-...` → `quick-questions/reverend-club-...`). Build a bb-mirror
   endpoint: take `<slug>` → `SELECT f.slug,t.slug FROM forums.topic t JOIN forums.forum f ... WHERE
   t.slug=:s` → **301 → `/hub/<forum>/<slug>`**; not-found → **301 → `/hub`** (NEVER 404).
2. **nginx rules** → `^/all-forums-all-topics/topic/([^/]+)` and `^/groups/.+/forum/topic/([^/]+)`
   → the resolver. Also map `/topic-tag/`, `/groups/` landing → `/hub`.
3. **Members 302→301** in `strangler-profile-app.conf`: `/members/<slug>` → `301 /u/$1`,
   `/members/` → `301 /directory/members`. (Keep `/members/me` + `profile/edit` as 302 — login convenience.)
4. **Sitemap** (new canonical URLs), hosted in **archive-poc** (serves the front). Sitemap-index →
   profiles (`/u/<slug>`, ~1,904, **respect `users.profile_visibility='public'`**), content
   (`discovery.content_item.url` where tier public/null, **exclude `cpt='sponsor-product'`**), static
   (front, events). Dynamic PHP at `/sitemap.xml`. No SEO plugin (Rank Math removed → custom + lightweight).
5. **robots.txt**: STAGED at `/home/ubuntu/cut-staging/robots-live.txt` — swap in at **cut step 13**
   (replaces dev's `Disallow: /`); points at the sitemap.
6. **noindex** stays on gated/member content (1,344 already noindexed — keep).
**Verify post-cut:** sample the 999 `Table.csv` URLs → each 200 or 301 (zero 404); submit sitemap to GSC.

## ⚠️ Remaining punch-list (priority order)
1. **SEO continuity build (above) — #1+#2 (slug-resolver + nginx) is the priority** (protects 69%).
2. Sitemap + robots swap (above #4/#5).
3. The runbook flip (A→F): salts/JWT carry, freeze+top-off, gotcha re-apply, URL+DNS flip, verify.
4. Re-run avatar backfill + the data top-offs at the cut window (keep-list).
5. Promote `profile-app/config.php` dev2-branch to main IF you want dev2 testable as 'dev2' (not cut-critical).
6. INDEX loose ends: forum-visibility gate 4/4, reconcile-pg timer, doc archive.

## Staged / on-box artifacts
- `/home/ubuntu/cut-staging/live-wp-salts.php` (600) — live salts (step 5, cut-window only).
- `/home/ubuntu/cut-staging/robots-live.txt` — live robots.txt (step 13).
- `/etc/looth/jwt-{private,public}.pem` — JWT keypair (carry live's at cut, step 6).
- `backups/looth_import_*.sql.gz` · `tools/topoff-dev-from-live.sh` · `docs/CUT-DAY-DATA-TOPOFF.md` ·
  `tools/cut/forums-grant.sql` (re-apply after every PG restore).
- GSC exports: `live-bundle/…Coverage…zip`, `lg-snippets/…Coverage-Valid…zip` (Table.csv = indexed URLs).

## Landmines / don'ts (standing — full catalog in the ADJUSTMENTS LOG)
- **DNS + `WP_HOME`/`SITEURL` flip in the SAME window** — never DNS alone.
- **Re-apply after ANY PG restore:** forums-grant + `chmod o+x /home/ubuntu` + pool env overrides
  (CONFIRMED 6/14: grant didn't survive dev2 restore → member 500s).
- **Reindex archive-poc AFTER the URL flip** (else bakes `dev2`).
- **SEO redirects must be `301`, not `302`** — 302 keeps Google on the OLD URL (no equity transfer).
- **Overlay deploys go LIVE→git, never reverse** (don't cp the stale `hub-overlay-flag` fork over live).
- **`≥641`-only CSS leaks onto mobile** (shared card markup) — ship the `≤640` complement same change.
- **Conversion re-run = duplicate post** (must be idempotent). **No secrets in `.well-known`.**
- **`wp user delete` = cross-store NUKE** — direct SQL only. **loothtool = out of scope.**
- One dup-email member (mikelle.davlin wp 1848/1905) — whitelist at bridge-backfill. **No push without Ian's review.**

## Rollback
DNS is the master switch (revert `loothgroup.com` A → old-live, TTL low; old-live's `WP_HOME` unchanged).
A.2 rollback: `git revert -m 1 f019434 && git push`, or reset to tag `pre-a2-main`. dev2: `git checkout` back.
