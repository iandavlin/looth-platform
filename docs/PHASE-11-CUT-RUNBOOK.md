# Phase 11 — THE CUT runbook (dev2 → loothgroup.com)

**Ordered, executable sequence for flipping `loothgroup.com` → the dev2 prod candidate.**
Companions: `docs/dev2-build-checklist.md` (gotcha detail + phase history), `docs/dev2-to-live-handoff.md`
(pending-deploys ledger), `docs/DEPLOY-PLAN.md` (strategy). Drafted 6/14; refine during the dress rehearse.

**Golden rules**
- **Flip DNS + WP URL in the SAME window — never DNS alone** (WP redirects to siteurl → loop / wp-admin lockout).
- **Identity-bridge reconcile happens AFTER the final data top-off**, not at build time (post-snapshot signups).
- **content_html / index URLs are STORED columns** — code deploy alone won't fix existing rows; re-backfill/reindex.
- Hold old-live as rollback for a defined soak window before raising DNS TTL back.

---

## A. Pre-cut (hours-to-days ahead — no downtime)
1. **Lower DNS TTL** on `loothgroup.com` A record (Cloudflare) → 60–120s, so the flip propagates fast.
2. **Merge `bespoke-cutover` → `main`** in the looth-platform repo; repoint dev2's clone
   (`/home/ubuntu/git/looth-platform`) to `main` (prod must not track a lane branch). This carries the
   bb-mirror Hub paragraph fix (`9510cbf`) + forum-description fix + any other lane work.
3. **Confirm all pending dev2 deploys applied** (handoff §PENDING): host-derive `8c677aa`, avatar fallbacks,
   archive-front, Phase 8 timers (done), eefbe97 (done).
4. **Dress-rehearse B–D below** on a throwaway clone if at all possible; at minimum walk the commands.

## B. Fetch from LIVE (file access — NOT in the DB dump, or sessions/JWTs die at flip)
5. **LIVE's 8 wp-config salt lines** (AUTH/SECURE_AUTH/LOGGED_IN/NONCE × KEY/SALT) → replace dev2's fresh salts.
6. **LIVE's JWT keypair** `/etc/looth/jwt-private.pem` + `jwt-public.pem` → carry the SAME onto the cut box.
   (Wrong-key rejection verified 6/14 → mismatched keys correctly fail; carrying live's pair avoids a re-mint storm.)

## C. Cut window — freeze → top-off → data
7. **Freeze live writes** (old-live maintenance/read-only) so the delta top-off is consistent.
8. **Final live mysqldump** (incl. users + sessions) → import into dev2 `looth_import`.
9. **Final PG top-off** from live: `profile_app`, `looth` (discovery + forums). (Custom data is small ~6MB.)
10. **Re-apply ALL cut-critical gotchas** (checklist §CUT-CRITICAL GOTCHAS) on the box:
    - `chmod o+x /home/ubuntu` (app traversal)
    - env overrides on the pools — `LG_ARCHIVE_POC_ENV|LG_BB_MIRROR_ENV|LG_EVENTS_ENV|LG_MEMBERSHIP_ENV=dev`
      (incl. the **looth-dev WP pool**), and flip **`LG_*_PUBLIC_HOST` → `loothgroup.com`** on the
      bb-mirror/looth-dev/archive-poc/events pools + the reconcile/vis timer env.
    - secret-reader ACLs: `setfacl -m u:profile-app:r /etc/lg-internal-secret` AND `… /etc/looth/jwt-private.pem`;
      `u:membership:r /etc/lg-membership-db`; billing-svc ACL when billing deploys.
    - `usermod -aG looth-dev www-data` (nginx traverses wp-content); `uploads` is a **symlink** to the R2 mount
      (rm any real dir first); FUSE flags `--allow-other --dir-perms 0755 --file-perms 0644`; `acl` pkg present.
11. **Data steps (order matters):**
    a. **Identity-bridge reconcile AFTER the top-off:** `sudo -u profile-app php /srv/profile-app/bin/reconcile-bridge.php`
       then `sudo WP_PATH=/var/www/dev /srv/profile-app/bin/backfill-looth-uuid.sh` (idempotent; exits non-zero unless
       GATE GREEN).
       - **Dup-WP-account note (investigated 6/14 — NOT a cut blocker):** `wp_user_bridge.user_id` is the PK (one profile
         = one WP account), so a member with two WP accounts on the same email bridges only ONE; the other is orphaned →
         anon at whoami. Scope on the live-derived data = **exactly ONE member**: mikelle.davlin (wp **1848** orphaned /
         **1905** bridged) — both her Patreon onboard double-accounts (same `lgpo_patreon_user_id`, both active). The
         bridge-backfill GATE will flag the orphan. **Cut action:** whitelist that one `wp_user_id` so step 11a doesn't
         red; the real member-merge (pick canonical, neutralize the other via NON-delete — `wp user delete` fires the
         lifecycle nuke) is owned by the **poller/Patreon-onboard lane** (root cause: onboard mints a 2nd WP user for an
         existing Patreon email). Re-scan for new dup-email members at the cut: `SELECT user_email,COUNT(*) c FROM wp_users
         WHERE user_email<>'' GROUP BY user_email HAVING c>1;`.
    b. **archive-poc discovery index URLs** → `loothgroup.com`: **REINDEX, don't search-replace.** `bin/indexer.php`/
       `backfill.php` build `url` from `get_permalink()` (WP `home_url`), so `bin/reindex-all.php` + `bin/materialize-all.php`
       rebuild all url/thumb/`article_blobs` at the correct host natively. **⚠️ ORDERING: this MUST run AFTER the WP-URL
       flip (step 12)** — else it bakes in `dev2`. (Scope if you ever must search-replace instead: ~702 url + 691 thumb_url
       rows + body_text/blobs — fragile, fallback only.) Verify front page = 0 `dev.`/`dev2.` links after.
    c. **bb-mirror re-backfill** (content_html paragraph fix `9510cbf`) + **person-resync** (stale author names after reload).
    d. **PG grant** (front-page discussion row): `GRANT USAGE ON SCHEMA forums TO "archive-poc";`
       `GRANT SELECT ON forums.topic, forums.forum TO "archive-poc";` — else front page 500s for members.
    e. **NOTE — steps that read WP `home_url` (11b reindex/materialize) run in step 13a, AFTER the URL flip.** The rest of
       11 (bridge, grant, bb-mirror) is host-independent and can run here.

## D. Cut window — host/secrets/SSL flip
12. **WP URL:** flip `WP_HOME`/`WP_SITEURL` constants (wp-config) `dev2.loothgroup.com` → `loothgroup.com`.
    Content already carries `loothgroup.com` (from the live dump) → no content search-replace to live needed
    (the dev2 search-replace was test-only). Remove the dev2 shim.
12a. **Reindex now that `home_url`=loothgroup.com** (deferred from 11b — these read WP permalinks):
    `sudo -u looth-dev wp eval-file /srv/archive-poc/bin/reindex-all.php` then `… bin/materialize-all.php`
    (rebuilds discovery `url`/`thumb_url`/`article_blobs` at the live host). Spot-check the front page for 0 off-host links.
13. **gate OFF** (nginx cookie-gate maps) — live has no gate. **Delete `/etc/lg-loothdev-gate.env`** + drop the
    `LG_LOOTHDEV_GATE_TOKEN` from the reconcile/vis units.
14. **R2:** real uploads bucket/token with **write**; remount rw.
15. **Real secrets:** SMTP, Stripe, Patreon, VAPID → live values; **re-point Stripe + Patreon webhooks → new box**.
16. **DSNs** peer→password where the FPM user changes; **5-way /whoami re-arm** (poller, lgms creds, BB REST gate, bridge).
17. **SSL** for `loothgroup.com` (certbot DNS-01 via Cloudflare); **nginx `server_name` → loothgroup.com**.
18. **FLIP DNS** (Cloudflare A `loothgroup.com` → dev2 IP) **in the same window as step 12**.

## E. Verify (immediately post-flip)
19. `tools/gates/run-all.sh` repointed at `loothgroup.com` — all 4 gates green (matrix, craft, infra-sec, hub-paragraph).
20. Real WP **login + `/whoami` tier ladder** (anon/lite/pro); front / hub / profile / events render; images resolve.
21. **Payments** test-mode → live-mode smoke; webhook round-trip.
22. Re-run the **wrong-key JWT** check against the live keypair (sanity).
23. Spot-check: composer "post" works for a logged-in member (the `auth.php`/looth-dev-pool path); discussion visibility.

## F. Post-cut
24. **Hold old-live as rollback** for a defined window (DNS still reversible while TTL low).
25. After soak confirms healthy → **raise DNS TTL** back to normal; decommission old-live per plan.
26. Convert remaining apps (archive-poc, profile-app, events) to git checkouts for uniform `git pull` deploys (post-cut cleanup).

---
### Rollback (if E fails)
- DNS is the master switch: revert the `loothgroup.com` A record to old-live (TTL is low). WP_HOME constant on
  old-live unchanged → it serves immediately. Investigate on dev2 out of the hot path.
