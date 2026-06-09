# MASTER CUT-DAY RUNBOOK (live-deploy lane)

End-to-end ordered playbook for the cut. Each step tagged **[DEV✓]** (verified on
dev) / **[LIVE]** (Ian runs on the new/old box) / **[OPEN]** (decision needed).
Model = **ADOPT live's DBs** onto a new self-contained box, then flip traffic.

> ⚠️ OPEN QUESTION — POSTGRES SOURCE (needs Ian/coord): "adopt the Postgres DBs"
> needs clarification. **Live has NO Postgres** — the strangler PG (profile_app +
> looth: forums/discovery) was built on DEV from dev's WP data. So at cut, Postgres
> is either:
>   (a) **REBUILT from the adopted live WP DB** via each lane's migration/sync
>       (bb-mirror forum sync, archive-poc indexer/backfill, profile-app xprofile
>       + social backfill) — authoritative, matches live exactly; OR
>   (b) **carried from dev** if dev's PG dataset is accepted as the cut source.
> The WP-MariaDB adopt is unambiguous; PG needs this call. Runbook assumes (a).

---

## PHASE 0 — Pre-cut readiness (done ahead, on dev/repo)
- **[DEV✓]** Everything-in-git: all 5 apps + confs single-source git-native;
  mu-plugins + FPM captured; symlink farm built + drift-guarded.
- **[LANE]** Env-parameterize the flagged lane code before cut (else breaks on live):
  `lg-article-materializer` LG_DASH_THEME_SNAPSHOT → /srv path; `bb-mirror-sync`
  BB_MIRROR_SYNC_HOST → env/site-derived. (Routed to archive-poc + bb-mirror lanes.)
- **[LANE]** Snippets keep/drop = DROP ALL (Ian); theme = DROP. Lane code env-clean.
- **[LIVE]** Run `cutover/live-recon-snippets-plugins.sh` read-only on live →
  results feed final plugin/theme keep-drop. (Coord-reviewed PASS; output SENSITIVE.)

## PHASE 1 — Provision the new box  **[LIVE]**
- EC2 **m7a.large** (2 vCPU / 8 GB, x86) — pending Ian confirm.
- Packages: `nginx php8.3-fpm php8.3-pgsql php8.3-mysql mariadb-server postgresql
  redis git wp-cli rclone` (⚠️ php8.3-pgsql is easy to forget — breaks every strangler).
- Create FPM pool OS users: archive-poc, bb-mirror, profile-app, events, membership,
  looth-dev. Create PG roles (same names, peer auth) + looth-dev write role.
- `git clone` the monorepo to /home/ubuntu/projects (or chosen root).

## PHASE 2 — Files via symlink farm  **[DEV✓ pattern]**
- Run `cutover/symlink-farm.sh --apply` on the new box. Order inside is correct:
  plugins → mu-plugins → **apps (/srv/<app>) → nginx** (so /srv paths resolve before
  nginx loads). Drift-guarded (won't deploy stale/mismatched).
- WP plugins symlink into wp-content/plugins; mu-plugins flat into wp-content/mu-plugins
  (excludes lg-user-audit/retired/3rd-party); webroot assets → docroot.
- `nginx -t && systemctl reload nginx`; `systemctl reload php8.3-fpm`.

## PHASE 3 — ⭐ ROLLBACK PREP (before touching anything live-facing)  **[LIVE]**
- Take the **deliberate, immediately-pre-cut snapshot of BOTH DBs**:
  `mysqldump` the live WP DB; `pg_dump` the PG DBs (once they exist on the new box).
- **Test-restore the snapshot ONCE** to a scratch DB to prove it's good. This snapshot
  **IS the rollback** (replaces the old frozen-box model). Restore = downtime +
  loss of writes-since-snapshot — acceptable for a planned cut.

## PHASE 4 — Adopt the WP database + wp-config  **[LIVE]**
- Import the live WP **MariaDB** dump into the new box's **local MariaDB**.
- Write the new box's **wp-config.php** = **live's wp-config with the AUTH salt block
  kept VERBATIM**, only the **DB-connection lines swapped** for the new local DB.
  (Salt ceremony collapsed per Ian: keep the block, don't regen, don't hand-extract 8 lines.)
  ⭐ This makes logged-in consistency AUTOMATIC: real salts + real session_tokens.
- **⚠️ DO NOT run a domain search-replace.** siteurl stays loothgroup.com untouched
  (COOKIEHASH = md5(siteurl); changing it = mass logout). **[DEV✓** mechanics].

## PHASE 5 — Build Postgres from adopted WP data  **[LIVE]** (per OPEN question, assuming (a))
- Apply schema + DDL/extensions/grants **[DEV✓ — exact defs verified]**:
  - `CREATE EXTENSION pg_trgm` + 4 GIN trgm indexes (forums.topic.title/author_name,
    discovery.content_item.title/author_name).
  - profile_app.users.discussion_visibility + forums.person.discussion_visibility
    (text NOT NULL DEFAULT 'member' CHECK public|member — singular 'member').
  - forums.topic/reply.is_anon BOOLEAN; discovery.comments.edited_at TIMESTAMPTZ.
  - GRANTs: bb-mirror SELECT on discovery.comments + content_item; looth-dev writes;
    archive-poc schema owner; profile-app SELECTs. (peer auth, unix socket.)
- Run each lane's migration/sync from the adopted WP data: bb-mirror forum sync +
  person-resync; archive-poc indexer/backfill; profile-app xprofile + social backfill.

## PHASE 6 — Secrets (provision on box; NEVER git)  **[LIVE]**
- wp-config AUTH salt block — carried in Phase 4 (kept from live's wp-config).
- /etc/lg-internal-secret, /etc/lg-archive-poc-secret, /etc/lg-profile-app-secret,
  /etc/lg-events-db, /etc/lg-membership-db, /etc/looth/jwt-*.pem, /etc/lg-vapid/*.
- `setfacl -m u:profile-app:r /etc/lg-internal-secret` (read gotcha).
- Stripe/Patreon creds — ship DORMANT (no creds) per coord §3h.
- rclone → LIVE bucket (dev token is dev-scoped/IP-locked).

## PHASE 7 — DB-state that doesn't ride the clone  **[LIVE]**
- Theme: `wp theme activate twentytwentyfive`; do not carry BB child/parent.
- Snippets: drop all wp_snippets; code-snippets plugin droppable. lg-snippets stays.
- Plugin active-state: set per keep-list on the cut DB (the import carries live's
  active set — drop Elementor/Woo/code-snippets/etc; keep the strangler + supporting).

## PHASE 8 — Re-arm /whoami (login ≠ tier)  **[LIVE]**
A DB import breaks tier 4 ways — re-arm so logged-in members resolve to correct tier:
- Reactivate the **poller** (import deactivates it).
- Restore `lgms_db_*` creds (wiped by import).
- BB REST gate re-arms → re-open; bridge gaps → re-bridge.
- Cache flush. (NOTE: in adopt model siteurl already correct — no siteurl rewrite.)
- Full **bb-mirror person-resync** (person keys on recyclable WP IDs → stale names).

## PHASE 9 — VERIFY GATES (hard gates before flip)  **[LIVE]**
- ⭐ **Logged-in consistency**: real existing live cookie → new box → `authenticated=true`
  AND correct tier. (false = salts/siteurl; authed-but-public = whoami re-arm incomplete.)
- Tiers: looth1-4 resolve correctly via /whoami.
- Sponsor routes: /sponsors/ 200, /sponsors/<slug>/ serves blob, /sponsor-page no-301. **[DEV✓]**
- /hub/ + /archive/ + /events/ + /u/<slug> 200. **[DEV✓ on dev]**
- nginx -t clean; all FPM pools up; redis up.

## PHASE 10 — Flip traffic  **[LIVE]**
- Point DNS / load balancer from old box → new box.
- Old box stays running (serves loothtool; was NOT the rollback — see Phase 3).
- Watch logs; if bad → restore the Phase 3 snapshot (accept downtime + lost writes).

---

## DEV2 REHEARSAL PLAN (the de-risker — run the WHOLE thing on a throwaway box first)
Provision a throwaway EC2 (same m7a.large), then execute Phases 1-9 against a CLONE
of live, verify, and **prove rollback**. Ready to run the moment Ian provisions the box.
1. **Clone source**: take a fresh live WP MariaDB dump (+ wp-config) for the rehearsal.
2. **Phases 1-2**: provision + symlink farm. Confirm every app serves.
3. **Phase 3 rehearsal**: take the pre-cut snapshot, test-restore it → prove the
   rollback artifact is valid BEFORE relying on it at cut.
4. **Phases 4-8**: adopt WP DB, wp-config (salts kept), build PG, secrets, DB-state,
   whoami re-arm. Time each step (cut-day budget).
5. **Phase 9 gates**: run EVERY verify gate. Especially: carry a real live login
   cookie to the rehearsal box → must stay authenticated + correct tier.
6. **Prove rollback**: deliberately "break" then restore the Phase 3 snapshot; confirm
   recovery + measure restore time (= the downtime budget if cut rolls back).
7. Tear down the throwaway box. Record timings + any gaps → final cut-day checklist.
**A clean rehearsal pass = cut is executable. This is the payoff of all the prep.**

---
### Status summary
- DEV✓: app convergence, symlink farm, DDL/grants defs, login-consistency mechanics,
  sponsor/hub/archive route serving, central git capture.
- NEEDS-IAN-ON-LIVE: box provision, recon run, DB adopt, secrets, whoami re-arm, gates, flip.
- OPEN: Postgres source (rebuild-from-WP vs carry-dev); m7a.large confirm; lane env-fixes.
