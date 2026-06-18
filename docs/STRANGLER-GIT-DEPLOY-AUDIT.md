# Strangler / bespoke robustness audit — for the `git pull` deploy model

**Date:** 2026-06-18 · **Auditor:** ubuntu (coordinator) · **Scope:** everything that
serves a live surface on this box, judged against the target model:

> **Target:** ONE git checkout. Edit in repo → push → `git pull main` on the box →
> it's live. The working tree (via a symlink farm) IS what serves. Config zones
> (nginx/FPM) take one `reload` after pull. Source of truth = `looth-platform` repo.

This is the post-cut FAST-FOLLOW Ian deferred on 6/16 (dev2 cut ships on the
rsync/`/projects` model; this audit is the path to the real `git pull` model).

---

## Bottom line

**The box is NOT safe for "deploy = git pull main" today.** Not because code is
missing from git — most of it is now captured — but because the **serving topology
is divorced from git**: live surfaces are served from real-file copies and a lane
branch, not from a single main-tracking checkout. A naive `git pull main` right now
would *revert 55 commits of live behaviour* and *silently no-op* on the surfaces that
serve from real files instead of symlinks.

Five risk classes, ranked. R1–R3 are blockers; R4–R5 are hygiene.

| # | Risk | Severity | One-line fix |
|---|------|----------|--------------|
| R1 | Box serves a **lane branch**, not `main` | BLOCKER | Make `main` the integration branch; box checks out `main` |
| R2 | **Real-file copies** where symlinks must be (webroot, /srv, nginx, mu-plugins) | BLOCKER | Wire the symlink farm; delete the copies |
| R3 | **Ownership + worktree sprawl** breaks `git pull` as ubuntu | BLOCKER | Collapse ownership; retire stray worktrees |
| R4 | **Hidden wiring** that never rides git (pwa.js injection, DB snippets) | HIGH | Move loader injection into a tracked mu-plugin |
| R5 | **Clutter** — 36 webroot `.bak`, 26 nginx `.bak`, dup captures | MEDIUM | Sweep; gitignore `*.bak*` |

---

## R1 — The serving branch is not `main` (BLOCKER)

- Main worktree `/home/ubuntu/projects` is on **`lane-profile-app`**, **55 commits
  ahead of local `main`**. Local `main` is itself **9 commits behind `origin/main`**.
- Live surfaces served from inside this worktree (`/srv/archive-poc`,
  `/srv/profile-app`, `/srv/events`, `/srv/lg-shared` all → `projects/*`) therefore
  serve **lane-profile-app code**, not main.
- A `git pull main` here would check out main = **revert all 55 commits** = break live.

**Why it exists:** lanes burn work on lane branches and the box was parked on whichever
branch was last active. The repo has **~16 branches** (`lane/*`, `bespoke-cutover`,
`consolidate-*`, `lane-wp-auth`, buck/* remotes) and **none of the live checkouts track
main.**

**Fix:** Establish `main` as the single integration branch. Merge/land the live lane
branches into `main` (`lane-profile-app`'s 55 commits first — that's what's live now),
fast-forward local `main` to `origin/main`, then `git checkout main` in the serving
worktree. From then on lanes merge → main, box pulls main. (See remediation Phase 1.)

---

## R2 — Real-file copies where the model needs symlinks (BLOCKER)

The model deploys *in place* via a symlink farm: each live path is a symlink into the
repo, so `git pull` is instantly live with no copy step. Today the farm is **half-built**
— some surfaces are symlinked (and safe), others are independent real-file copies that
drift and that a pull cannot touch.

**Already symlinked into the repo (SAFE — these ride a pull):**
- `/srv/archive-poc`, `/srv/events`, `/srv/lg-shared`, `/srv/profile-app` → `projects/*`
- nginx: `strangler-bb-mirror.conf`, `strangler-events.conf`,
  `strangler-profile-app.conf` → `projects/platform/nginx/*`

**Captured in git but NOT wired (drift guaranteed — the trap):**
- **Webroot front-end assets.** `/home/ubuntu/projects/webroot/` is a tracked capture
  (40 files, README + .gitignore) of the ~26 active loose assets (hub-polish.js,
  bottom-nav.js, pwa.js, directory-*.js, mobile-hub.css, the sheets, sw.js …). But the
  *live* files in `/var/www/dev/*.js` are **real independent copies**, not symlinks into
  `webroot/`. Edits happen on the box (ubuntu just wrote hub-polish.js + bottom-nav.js
  6/17) → repo capture goes stale → a pull would silently not change the live files (no
  symlink) *or*, once symlinked, would clobber un-captured box edits. There is ALSO a
  second, older capture `projects/live-webroot-capture/` (54 files) — two captures, no
  single source of truth.
- **/srv code apps as real dirs:** `/srv/lg-push`, `/srv/lg-stripe-billing`,
  `/srv/thumb-app` are real directories with monorepo/separate-repo copies but are **not
  symlinked**. (`lg-stripe-billing` + `thumb-app` are *separate* git repos — submodule or
  fold-in decision needed. Stripe is parked, so `lg-stripe-billing` is deferrable.)
- **nginx real-file snippets:** `strangler-archive-poc.conf` (375 lines) and
  `lg-shared.conf` (40 lines) have exact git copies but are deployed as real files, not
  symlinks. `strangler-membership.conf` and `preview-buck-profile-app.conf` (220 lines)
  have **no git copy** — box-only.
- **mu-plugins:** mostly tracked under `projects/platform/mu-plugins/`, but **box-only:**
  `buddyboss-performance-api.php`, `burst_rest_api_optimizer.php`, the `looth-vendor/`
  dir, and the `lg-membership-chrome/` dir. Minor drift: `bb-mirror-sync.php` live is
  **18 lines behind** git; `archive-poc-sync.php` live is **5 lines ahead** of git.

**Legitimately box-only / runbook layer (NOT a code-in-git failure):**
- `/srv/profile-app-media` (user avatars/banners/resumes — data) and `/srv/lg-sudo-queue`
  (runtime logs). Excluded by design.
- `/etc/nginx/sites-available/dev.loothgroup.com.conf` (the **299-line** main vhost —
  earlier tool runs mis-measured this as "15k lines"; it is not). Vhost + salts + secrets
  live in the live-deploy runbook, not git, by the mandate. **But** the strangler
  *snippets* it `include`s must all be symlinks into the repo (see above).

**Fix:** Finish the symlink farm. For every captured surface: delete the real file →
symlink the live path to the repo path. Pick ONE webroot capture (`webroot/`), delete
`live-webroot-capture/`. Fold in the 4 box-only mu-plugins + 2 box-only nginx snippets.
`projects/cutover/symlink-farm.sh` + `projects/deploy/deploy.sh` already exist as a
starting point — extend them to cover webroot + the real-dir /srv apps, make idempotent,
dry-run first.

---

## R3 — Ownership + worktree sprawl breaks the pull (BLOCKER)

- **Non-ubuntu-owned tracked subdirs:** `archive-poc/` (owned by user `archive-poc`,
  ~21 items), `bb-mirror/` (owned by `bb-mirror`, + `root` on `bb-mirror/lib`),
  `live-webroot-capture/` (root). When ubuntu runs `git pull`/`checkout`, writes into
  these trees **fail on permission** — the pull half-completes and leaves them stale,
  every time. (Known pattern: never `sudo git` here; clean via `cat-file | sudo tee`.)
- **Worktree sprawl:** `git worktree list` shows **10 worktrees**, almost all on `lane/*`
  or custom branches (`bespoke-cutover`, `consolidate-*`). `/srv/bb-mirror` symlinks to
  the **`bespoke-cutover`** worktree's `bb-mirror/` — so the live forum mirror serves
  `bespoke-cutover`, a branch a `git pull main` never touches. There is *also* a separate
  declared `lane/bb-mirror` worktree the live symlink bypasses → shadow state.

**Fix:** (a) Decide ONE ownership model — the mandate says collapse to `www-data` at cut;
for the git-pull model pick a single owner the deploy user can write (ubuntu or www-data
with ubuntu in-group), and `chown` the tracked trees to it. (b) Land `bespoke-cutover`
(bb-mirror) into main and repoint `/srv/bb-mirror` at the main checkout; retire the lane
worktrees once merged so there is exactly one serving checkout on main.

---

## R4 — Hidden wiring that never rides git (HIGH)

- **`pwa.js` is the loader** that injects every other webroot asset (page-path + device
  gated). But **no mu-plugin or theme file injects `pwa.js` itself** — its `<script>` tag
  is wired via a **DB code-snippet / theme option**, i.e. the "doesn't ride git" DB-state
  layer. If the box is rebuilt from git alone, **none of the front-end loads** until that
  snippet is re-created. This is the single most fragile dependency.
- Same class: which plugins are active, the active theme, `wp_snippets` — all DB state,
  all runbook items. Fine *if catalogued*; today the pwa.js injector is not.

**Fix:** Move the pwa.js (and any sibling) injection into a tracked mu-plugin
(`platform/mu-plugins/lg-frontend-loader.php` or similar) so the loader wiring rides git.
Everything else DB-stored stays runbook — but each must be listed in
`docs/ROTATE-SECRETS.md` / the cut runbook. Audit `wp_snippets` for any other
live-feature snippet not yet catalogued.

---

## R5 — Clutter that obscures the source of truth (MEDIUM)

- **36** `hub-polish.js.bak-*` / sibling backups in `/var/www/dev/`.
- **26** `.bak-*` / `.pre-*` files in `/etc/nginx/snippets/`.
- The duplicate webroot capture (R2).
- `.gitignore` does **not** cover `*.bak*` (an untracked `lg-front.js.bak-*` is in the
  working tree right now).

**Fix:** Sweep the `.bak` farms (the manual rollback habit is replaced by git history once
the symlink farm lands). Add `*.bak*` / `*.bak-*` to `.gitignore`.

---

## The irreducible "doesn't ride git" layer (keep in the runbook, not the repo)

Per the 6/9 mandate, these never become files-in-git and are NOT audit failures —
they are the live-deploy runbook's job. Re-confirmed present:
- **Secrets:** `/etc/lg-*`, `/etc/looth/jwt-private.pem`, `/etc/lg-vapid/*`. (.gitignore
  already blocks `*.pem`/`*.key`/`.env*` — good.)
- **DB state:** active plugins, active theme, `wp_snippets` (incl. the pwa.js injector
  until R4 moves it), MySQL per-app reader users (the dev2 rebuild trap #14).
- **Data stores:** `/srv/profile-app-media`, uploads (R2), SQLite/PG runtime files
  (already gitignored).

---

## Remediation plan (phased — each phase independently shippable)

**Phase 0 — Freeze & branch reconcile (no serving change yet)**
1. Land live lane branches into `main`, starting with `lane-profile-app` (the 55 commits
   currently live). Fast-forward local `main` to `origin/main` first.
2. Land `bespoke-cutover` (bb-mirror) into `main`.
3. Result: `main` == everything currently live. Verify with the gates
   (`tools/gates/run-all.sh`) + a diff of serving surfaces before/after.

**Phase 1 — One checkout on main**
4. `git checkout main` in the serving worktree; retire the merged lane worktrees so there
   is a single main-tracking checkout. Repoint `/srv/bb-mirror` at the main checkout.

**Phase 2 — Finish the symlink farm**
5. Pick ONE webroot capture (`webroot/`); delete `live-webroot-capture/`. Reconcile the
   live `/var/www/dev/*.js` edits INTO `webroot/`, then replace each live file with a
   symlink. Extend `symlink-farm.sh` to cover it (dry-run, idempotent).
6. Symlink the real-dir /srv apps (`lg-push`, `thumb-app`; defer parked `lg-stripe-billing`)
   and the real-file nginx snippets (`strangler-archive-poc.conf`, `lg-shared.conf`).
7. Fold the 4 box-only mu-plugins + 2 box-only nginx snippets into `platform/`; reconcile
   the bb-mirror-sync / archive-poc-sync line drift.

**Phase 3 — Ownership & hidden wiring**
8. Collapse tracked-tree ownership to one deploy-writable owner (`archive-poc/`,
   `bb-mirror/`, `live-webroot-capture/` removal).
9. Move pwa.js injection into a tracked mu-plugin (R4). Catalogue remaining DB snippets.

**Phase 4 — Hygiene & guardrail**
10. Sweep `.bak` farms; gitignore `*.bak*`.
11. Add a **deploy gate**: a script that asserts (a) serving worktree is on `main` &
    clean, (b) every live path that should be a symlink IS one (no real-file drift), (c)
    pwa.js injector mu-plugin present. Run it pre-pull. This is the durable defence —
    "defect class found twice → encode as a gate" (CRAFT-STANDARD).

---

## What already exists to build on (don't rebuild)

- `projects/platform/{nginx,fpm,mu-plugins,systemd}/` — the canonical homes are scaffolded.
- `projects/cutover/symlink-farm.sh` + `projects/deploy/{deploy.sh,MANIFEST.md}` — partial
  farm/deploy tooling.
- `projects/webroot/` — the chosen webroot capture (just needs wiring + dedup).
- `docs/dev2-wiring-punchlist.md` — the catalogue of name/permission traps a rebuild hits
  (MySQL readers, secret ACLs, `/home/ubuntu` traverse bit, env-detection) — these are the
  runbook items Phase 3/runbook must preserve.
