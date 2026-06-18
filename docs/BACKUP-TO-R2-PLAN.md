# Backup-to-R2 plan

**Date:** 2026-06-18 · **Owner:** ubuntu · **Pairs with**
`docs/NEW-DEV2-GIT-NATIVE-BUILD-SPEC.md` — this is the **data leg** of the same
disaster-recovery story.

**Sequence (Ian 6/18): FAST-FOLLOW to the git sorting.** Land the git-native build
(re-baseline + symlink farm + new dev2) first; stand this backup leg up immediately
after — it's the restore test for that standup, but it does not block it.

## The three-legged recovery model

Rebuild any box = **git** (code) + **runbook** (secrets/infra) + **R2 backup** (data).
This doc owns the third leg. After this, the build spec's "top off data" step *is*
"restore latest from the backup bucket."

| Leg | Recovers | Source of truth | Status |
|-----|----------|-----------------|--------|
| git | app code, configs, mu-plugins, webroot | `looth-platform` @ main (GitHub) | being hardened (audit) |
| runbook | secrets, DB users, ACLs, env knobs | `docs/dev2-wiring-punchlist.md` + ROTATE-SECRETS | exists |
| **R2 backup** | **the databases + box-only data** | **this plan** | **GAP — building now** |

## Current state (recon 2026-06-18)

- **Ad-hoc only:** `/home/ubuntu/backups/` holds manual "AUTOPILOT" mysqldumps (6/16) +
  some 6/13 dumps (778M). **No schedule, no offsite copy** — they die with the box.
- **rclone remotes already wired:** `r2`, `r2backups`, `r2live`, `r2up`, `cfbk`, `r2test`
  (S3 type; the `no_check_bucket = true` PUT gotcha is already set on the write remotes).
- **Already durable (object store, don't re-copy):** WP uploads = R2, fuse-mounted
  (`r2:loothgroup-uploads-dev` at `/mnt/...`). On live, profile-media also moved to R2.
- **NOT backed up anywhere:** Postgres (`looth` 70M discovery, `profile_app` 19M);
  `/srv/profile-app-media` (local root:root on this box); `/etc` secrets+configs offsite.

## What to back up (and what not to)

**DO back up → R2 (not reproducible from git/runbook):**
- **MySQL:** `looth_dev` (WP, 816M), `looth_import` (835M), `lg_membership` (1.7M).
  (`loothtool_dev` = the separate loothtool site — back up only if we still care.)
- **Postgres:** `looth` (discovery), `profile_app`.
- **Secrets:** `/etc/lg-*`, `/etc/looth/*.pem`, `/etc/lg-vapid/*` — **encrypted** before
  upload. Low churn → on-change + weekly.
- **/etc configs:** nginx `sites-available`+`snippets`, php-fpm pools, systemd units —
  small tar (belt-and-braces; most ride git after the symlink farm).
- **`/srv/profile-app-media`** until it's migrated to its own R2 bucket like live.

**DON'T back up (already safe or reproducible):**
- The git repo (GitHub is its backup; optionally push a periodic `git bundle` for paranoia).
- WP uploads (already R2-resident — instead enable **bucket versioning** on it).
- DB-state captured inside the MySQL dump already (`wp_snippets`, active plugins/theme).
- Migration cruft: `looth_restore_tmp`, PG `*_fresh` dbs — **delete these**, don't back up
  (and drop them before imaging the box so the AMI isn't bloated).

## Mechanism

- **Bucket:** a dedicated `loothgroup-backups` bucket via the existing `r2backups` remote.
  Enable **versioning + lifecycle** on it; ideally give the box **put-only** creds (no
  delete) so a compromised box can't wipe history.
- **Script:** `tools/backup/backup-to-r2.sh` —
  1. `mysqldump --single-transaction --routines` each MySQL db → `gz`.
  2. `pg_dump -Fc` each PG db.
  3. tar `/etc` configs; tar+**encrypt** (age or gpg) the secrets.
  4. `rclone copy` everything to `r2backups:loothgroup-backups/<host>/<YYYY-MM-DD>/`
     (uses `no_check_bucket=true`). Write a `MANIFEST` + sha256 per file.
- **Schedule:** systemd timer, **nightly** for DBs+configs; secrets weekly + on-change.
- **Retention:** dated keys + R2 lifecycle = **7 daily / 4 weekly / 3 monthly**.
- **Monitoring:** timer writes a heartbeat; alert on a missed/failed run (mailpit on dev →
  real SMTP at cut; or the sudo-queue ping). A silent backup failure is the classic trap.
- **Restore drill (documented + actually run once):**
  `rclone copy r2backups:loothgroup-backups/<host>/<date>/ /tmp/restore` →
  `zcat … | mysql`, `pg_restore`, untar configs, decrypt secrets. **A backup you've never
  restored is not a backup.** The new-dev2 standup is the first live restore test.

## Tie-in to the new-dev2 standup

The build spec's **step B (top off data)** becomes: *restore the latest R2 backup set*.
Standing up new dev2 = first real proof the restore path works. After that, the recovery
story is complete and provable: lose the box → new AMI → clone repo → run symlink-farm →
apply runbook secrets → restore latest from R2 → live.

## Setup checklist

- [ ] Confirm/point `r2backups` at a `loothgroup-backups` bucket; enable versioning +
      lifecycle; provision put-only creds for the box.
- [ ] Write `tools/backup/backup-to-r2.sh` (+ secrets encryption key handling).
- [ ] Install `backup-to-r2.timer/.service`; verify a manual run lands dated objects.
- [ ] Add the missed-run alert.
- [ ] Run one full **restore drill** (folds into new-dev2 step B).
- [ ] Drop cruft DBs (`looth_restore_tmp`, PG `*_fresh`) before they get imaged/backed up.
- [ ] Decide profile-media: keep tarring it, or migrate to its own R2 bucket (live-parity).
