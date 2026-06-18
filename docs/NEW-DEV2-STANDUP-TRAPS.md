# New dev2 standup — trap log

**Started:** 2026-06-18 · **Owner:** ubuntu · **Box:** new dev2 @ `54.146.118.131`
(AMI-clone of live, ~2h behind). Companion to `docs/dev2-wiring-punchlist.md` (the prior
rebuild's trap catalogue) and `docs/NEW-DEV2-GIT-NATIVE-BUILD-SPEC.md` (the plan).

This is the **living gotcha log** for standing up the new dev2 git-native. Every name /
permission / network trap we hit goes here, with the fix, so it becomes the runbook.
Pattern (per the last rebuild): every break is a **name** (host/siteurl/cookie/env) or a
**permission** (ownership/ACL/traverse/SG/grant).

---

## OPEN

### T3 — env-detection hardcodes the OLD host → CLI/cron misfire (CONFIRMED on this box)
- New box internal host = **`ip-172-31-71-217`**. 5 app configs (archive-poc, bb-mirror,
  events, membership-pages, profile-app `config.php`) still carry a prior host string
  (dev1 `ip-172-31-81-87` or old-dev2 `ip-172-31-18-136`) → CLI/cron with no HTTP_HOST
  falls back to gethostname() → resolves wrong env → wrong DB / `/var/www/html` path.
- **Web is fine** (Host header matches); only CLI/cron link-gen + provisioning misfire.
- **Fix:** search-replace the stale host → `ip-172-31-71-217` in each dev2 config (the
  punch-list #8/#13/#15 recurrence). Do during conversion.
- **Status:** open, low-urgency (web unaffected).

## RESOLVED

### T5 — R2 uploads mount dead → blank thumbnails  ✅ 2026-06-18
- TWO compounding causes: (1) **diagnostic red herring** — I tested with `rclone lsd`, but
  per the `r2-wiring` skill **scoped R2 tokens 403 on LIST by design**; the real test is a
  direct object put→get→delete probe to the EXACT bucket name. (2) the token's **Client-IP
  filter didn't include dev2's `54.146.118.131`** (Ian added it).
- Also: bucket-name churn. Ian split tokens into read-only "live" buckets (`loothgroup2-0`,
  `loothgroup2-0-profile-bucket`) and read-write "dev" buckets (`loothgroup-uploads-dev`,
  `loothgroup-2-0-profile-dev`). The mount pointed at `loothgroup2-0` (not covered by the
  dev-write token).
- **FIX:** repointed `r2up` creds → the **dev-write token** (`6389a93d…`) and the mount unit
  bucket `loothgroup2-0` → **`loothgroup-uploads-dev`** (RW, dev bucket — now shared with
  dev1). `systemctl restart r2-uploads-dev.service`. Verified: read (2023/24/25) + write OK,
  thumbnails render. Backups: `rclone.conf.bak-*`, `r2-uploads-dev.service.bak-*`.
- NOTE: dev2 now shares dev1's `loothgroup-uploads-dev` uploads bucket. Correct when dev2
  replaces dev1; flag if independent buckets are wanted.
- LESSON for the trap log: **never diagnose R2 with `lsd` — use the object probe** (r2-wiring skill).

## RESOLVED

### T4 — clone host-pins say "live" → logged-out / wp-cli broken (env layer)  ✅ 2026-06-18
- Root: cloned `/etc/looth/env` = `LG_ENV=live` + `LG_PUBLIC_HOST=loothgroup.com`. The
  `env='live'` config branch points at `looth_live`+`/var/www/html` (absent here), and
  shared host=`loothgroup.com` → cookie-domain/iss `.loothgroup.com` on a dev2 box →
  session never sticks. Box's real DBs = `looth_import`/`lg_membership`/`looth`/`profile_app`.
- **FIX:** flipped the TWO values in `/etc/looth/env` → `LG_ENV=dev2`,
  `LG_PUBLIC_HOST=dev2.loothgroup.com` (the designed single switch; DB keys already correct).
  Plus WP `siteurl`/`home` → `https://dev2.loothgroup.com` (direct MySQL). FPM reloaded.
- Verified: apps resolve dev2, `LG_PROFILE_APP_HOST=dev2.loothgroup.com`,
  `/profile-api/v0/whoami` returns clean JSON, front page 200.
- LEFTOVER: **bb-mirror CLI/cron** still resolves `live` (its config reads `getenv` + host,
  NOT `/etc/looth/env`; web is fine via the pool `env[LG_BB_MIRROR_ENV]=dev`). Handle in the
  git-native pass — ideally make bb-mirror honor lg_env like the others.
- Backup: `/etc/looth/env.bak-pre-dev2flip-*`.

### T1 — SSH unreachable → was instance size + EIP, NOT CF/SG
- SG allowed 22 all along. Root cause = **t3a.small (2 GiB) too small for the live clone**
  (booted into thrash) + a fresh AMI launch. **Resized to t3a.medium (3.8 GiB)** and the
  EIP `54.146.118.131` attached → reachable. Box healthy (734 MiB used / 3 GiB free).
  Lesson: working dev2 needs ≥4 GiB; attach the EIP so the IP is stable across relaunches.

### T2 — `ubuntu` NOPASSWD sudo over SSH
- Confirmed **YES** on first connect (`sudo -n true` passes). No hang. Working key =
  `/home/ubuntu/projects/lg-stripe-billing/claude-keypair.pem`.

### Note — opcache `validate_timestamps = On`
- Good news for the deploy model: **PHP edits go live on `git pull` with NO FPM reload**
  on this box. (May flip Off in true production — re-verify at cut; matrix rows 1/3/4/5.)

---

### T1-ARCHIVED — SSH port 22 times out from dev1 (50.19.198.38)
- **Symptom:** `ssh ubuntu@54.146.118.131` → `connect to host … port 22: Connection
  timed out` (a DROP, not a refuse) on all candidate keys.
- **Not Cloudflare:** CF only proxies 80/443; SSH bypasses CF and hits the EC2 IP directly.
- **SG RULED OUT:** the SG *does* allow `SSH TCP 22 ← 50.19.198.38/32` (confirmed from
  console 6/18). 443 is Cloudflare-only; 80/ICMP not opened — so probes on those from dev1
  time out *by design* and prove nothing. Port 22 is the only valid probe, and it DROPS.
- **Cause (revised):** a drop on an *allowed* port = box not answering at network layer →
  (a) still booting (first-boot MySQL/InnoDB recovery), or (b) **overloaded**: box is a
  **t3a.small (2 vCPU / 2 GiB)** running a full live clone (MySQL ~1.6 GB data + PHP-FPM +
  Postgres + Redis + memcached + rclone) → likely heavy swap / OOM / burst-credit
  exhaustion → too busy to complete a TCP handshake.
- **Diagnose (EC2 console — can't see from outside):** instance status checks 2/2?
  CPU util + CPU credit balance (CloudWatch); minutes since launch.
- **Fix:** if up >10 min & still unreachable → **stop → resize to t3a.medium (4 GB) →
  start**. 2 GB is marginal for a live clone; the working dev2 should be ≥4 GB.
- **Status:** waiting on Ian (console check / resize).

### T2 — `ubuntu` needs passwordless sudo over SSH (carry-over from last rebuild)
- **Symptom (prior dev2):** `sudo` inside a non-interactive SSH command **hangs** (no TTY
  for the password prompt) → my throughput dies.
- **Fix:** ensure new box grants `ubuntu` NOPASSWD sudo (live/dev1 has it). One-liner at
  first boot. **Verify on first connect** (the watcher checks `sudo -n true`).
- **Status:** unverified until SSH is up.

---

## TOOLING TRAPS (mine, not the box)

### M1 — `/dev/tcp` port-probe blocked in background Bash → watcher dies (exit 144)
- Background poll loops using `bash -c "echo > /dev/tcp/$IP/22"` were killed instantly
  (exit 144) by the sandbox. **Don't** port-probe via `/dev/tcp` in backgrounded Bash.
  Use plain one-shot `ssh -o ConnectTimeout` attempts, or `ScheduleWakeup` to retry.

---

## RESOLVED

_(none yet)_

---

## WATCH-LIST (expected traps from the punch-list — confirm on this box)

Carried from `docs/dev2-wiring-punchlist.md` — the AMI clone should inherit most, but
verify after the data top-off + any host rename:
- `/home/ubuntu` traverse bit (`chmod o+x`) — else every strangler surface 403s.
- Per-app MySQL readers (`profile-app`@localhost unix_socket grant) — else provisioning
  dead system-wide, front end reads logged-out.
- Secrets + ACLs: `/etc/lg-profile-app-secret`, `/etc/lg-internal-secret`,
  `/etc/lg-membership-db`, jwt pem group ACL, `/etc/lg-vapid`, `/etc/lg-events-db`.
- Env-detection: archive-poc/bb-mirror/events/membership/profile-app config resolving the
  right env (host-string + `ip-172-31-*` CLI fallback) for the new box name.
- Cookie domain / JWT iss / siteurl pinned to the box host (search-replace on rename).
