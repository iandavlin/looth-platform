# New dev2 — git-native build spec

**Date:** 2026-06-18 · **Owner:** ubuntu (coordinator) · **Supersedes the retrofit
framing in** `docs/STRANGLER-GIT-DEPLOY-AUDIT.md` (audit stands; this is the *how*).

## Why this doc

dev2 became live. We're standing up a **new dev2** (needed anyway) and using that
standup to make the platform robust under the real deploy model:

> Edit in repo → push → `git pull main` on the box → it's live. ONE checkout. The
> working tree (via a symlink farm) IS what serves. nginx/FPM take one `reload`.
> Source of truth = `looth-platform` repo @ `main`.

Instead of retrofitting a messy live box, we **clone live → convert to git-native on
a fresh box over SSH.** The standup itself proves the model works.

## Provisioning decision (LOCKED)

- **AMI-clone the current live box** → launch as new dev2 (new IP) → top off data →
  **convert to git-native in place.** Keeps all infra/secrets/DB correct (skips the ~18
  dev2-wiring traps); we only do the git plumbing. Alt (blank box → provision from git +
  runbook) is the purer cold-boot proof but re-fights every infra trap — deferred to a
  later validation, not this standup.
- **Ownership:** the deploy tree is **ubuntu-owned** (www-data in-group for read). The
  www-data-collapse idea is dropped.
- **Webroot source of truth:** `projects/webroot/`. Delete `live-webroot-capture/`.

## Phase 0 — replaced by RE-BASELINE (no 55-commit merge)

The audit's Phase 0 (reconcile lane branches) is **cancelled.** We declare deployed
reality canonical and reset git to it:

1. Tag the current main tip: `git tag archive/pre-rebaseline-2026-06 <main-sha>` and push
   the tag (history recoverable, nothing lost).
2. Snapshot the topped-off live tree into a clean commit → that is the new `main`.
3. Force-push `main`; delete stale branches (`lane/*`, `lane-profile-app`,
   `bespoke-cutover`, `consolidate-*`, dead `buck/*`). Keep only `main` + any genuinely
   active lane.
4. From here: lanes branch off `main`, merge back to `main`, box pulls `main`. One
   integration branch, forever.

> This is the answer to "can we just call the current state canonical?" — yes, for the
> branch problem. It does NOT fix the plumbing (below); that's why we build, not just clone.

## Standup checklist (on the fresh box, over SSH)

**A. Boot the box**
- [ ] AMI/EBS-snapshot current live → launch new dev2 instance (new IP).
- [ ] First-boot fixups so I can work: `ubuntu` NOPASSWD sudo (live box has it; dev2 SSH
      gotcha = sudo-in-heredoc hangs without it), `chmod o+x /home/ubuntu` (traverse bit).
- [ ] Confirm it serves: `/ /hub/ /sponsors/ /archive-poc/ /u/<slug> /whoami` → 200.

**B. Top off data** (the clone is a point-in-time; refresh the delta)
- [ ] MySQL: dump-delta or full re-dump from live → import (users + `session_tokens` incl.).
- [ ] Postgres (discovery / profile_app): refresh from live.
- [ ] profile-app-media + R2/uploads: rsync/clone delta. (Data, never git.)
- [ ] Re-run env/host fixups for the new box name (search-replace `dev.`→new host;
      the `ip-172-31-*` CLI env-detection per the punch-list).

**C. Re-baseline git** (section above) — tag, snapshot, force-push, prune.

**D. Single checkout on main**
- [ ] `git clone` (or reset existing) so `/home/ubuntu/projects` is on **main**, clean,
      ubuntu-owned end to end (`chown -R ubuntu` the whole tree; fixes the
      archive-poc/bb-mirror non-ubuntu hazard).
- [ ] Retire all extra worktrees — exactly one serving checkout.

**E. Finish the symlink farm** (extend `cutover/symlink-farm.sh`, dry-run, idempotent)
- [ ] Webroot: reconcile live `/var/www/dev/*.js` edits INTO `projects/webroot/`, then
      replace each live file with a symlink → repo. Delete `live-webroot-capture/`.
- [ ] /srv real dirs → symlinks into repo: `lg-push`, `thumb-app`. (Defer parked
      `lg-stripe-billing`.) `/srv/bb-mirror` → the main checkout (not a worktree).
- [ ] nginx real-file snippets → symlinks: `strangler-archive-poc.conf`, `lg-shared.conf`.
- [ ] Fold box-only into `platform/`: mu-plugins `buddyboss-performance-api.php`,
      `burst_rest_api_optimizer.php`, `looth-vendor/`, `lg-membership-chrome/`; nginx
      `strangler-membership.conf`, `preview-buck-profile-app.conf`. Reconcile the
      `bb-mirror-sync.php` (−18) / `archive-poc-sync.php` (+5) line drift.

**F. Hidden wiring → git**
- [ ] Move the **pwa.js loader injection** out of the DB snippet into a tracked mu-plugin
      (`platform/mu-plugins/lg-frontend-loader.php`). Catalogue any remaining live-feature
      `wp_snippets` into the runbook.

**G. Hygiene**
- [ ] Sweep `.bak` farms (36 webroot, 26 nginx). Add `*.bak*` to `.gitignore`.

**H. The guardrail (deploy gate)**
- [ ] `tools/gates/deploy-ready.sh`: asserts (1) serving checkout on `main` & clean,
      (2) every path that should be a symlink IS one (no real-file drift), (3) the pwa.js
      loader mu-plugin is present & active. Run pre-pull. Encodes the defect class as a
      gate (CRAFT-STANDARD). Add app surfaces to `tools/gates/craft-gate.py` PAGES.

**I. Prove the model**
- [ ] Edit a trivial thing in the repo → push → `git pull` on the box → confirm live with
      no copy step. Run `tools/gates/run-all.sh` green. That green pull IS the deliverable.

## The "doesn't ride git" runbook layer (carried by AMI clone, listed for cut)

Not git, not a failure — preserved by the clone, must be in the cut runbook:
- **Secrets:** `/etc/lg-*`, `/etc/looth/jwt-private.pem`, `/etc/lg-vapid/*`.
- **DB state:** active plugins, active theme, `wp_snippets`, the per-app MySQL reader
  users (`profile-app`@localhost unix_socket grant — the rebuild trap #14).
- **Data:** profile-app-media, R2 uploads, SQLite/PG runtime.
- Full trap catalogue: `docs/dev2-wiring-punchlist.md`.

## What's gained

A box where **`git pull` deploys**, built by a process that *proves* it. After this,
"new dev box" or "rebuild" = clone repo + symlink-farm script + runbook + data load — a
checklist, not an archaeology dig. The deploy gate stops the real-file drift from ever
creeping back.

## Fast-follow: R2 backup (the data leg)

Once the git sorting lands, stand up `docs/BACKUP-TO-R2-PLAN.md` (Ian 6/18: fast-follow,
not a blocker). It completes the recovery story — git=code, runbook=secrets/infra,
R2=data — and its first restore drill IS this standup's **step B (top off data)**.
