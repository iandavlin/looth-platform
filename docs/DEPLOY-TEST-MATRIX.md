# Deploy test matrix — "can I edit every kind of thing via git pull?"

**Date:** 2026-06-18 · **Owner:** ubuntu · **Pairs with**
`docs/NEW-DEV2-GIT-NATIVE-BUILD-SPEC.md` (step I = prove the model). This matrix is
both the **proof** and the seed of `tools/gates/deploy-ready.sh`.

## The model being tested

> Edit in the repo on the source box → commit → push → on the serving box
> **`git pull` + reload-the-affected-zone** → it's live, no copy step.

"Deploy" = `git pull` **plus a conditional reload** depending on what changed. The smart
`deploy.sh` should diff the pulled paths and reload only the zones touched. Every change
*class* below rides git differently and needs a different reload — so we test one canary
edit per class, end to end.

## Test procedure (same shape every row)

1. On dev1 (repo source of truth): make a tiny visible canary edit in the repo path.
2. `git commit` + `git push` (real path: lane → main).
3. On dev2: `git pull` (+ the row's reload).
4. Verify the canary is live (curl / view-source / systemctl). Then revert the canary.

A change that needs a reload but *appears* live without one is a trap (stale opcache,
browser cache) — note which.

## The matrix

| # | Change class | Live path → rides via | Reload needed | Verify |
|---|---|---|---|---|
| 1 | **App PHP** (archive-poc, profile-app, events, bb-mirror, lg-shared) | `/srv/* → repo` (symlink) | FPM reload **iff** opcache `validate_timestamps=Off`; else none | curl the rendered endpoint shows the canary string |
| 2 | **Webroot JS/CSS** (hub-polish.js, bottom-nav.js, mobile-hub.css, sheets…) | `/var/www/dev/*.js → repo/webroot` (symlink — TBD, currently real copies) | none server-side, but **bump `?v=`** to beat browser cache | load the page, see the change |
| 3 | **Front-end loader** (pwa.js injector, after R4 → mu-plugin) | `wp-content/mu-plugins/lg-frontend-loader.php → repo` | FPM reload | view-source: the `<script>` tag / bundle choice changed |
| 4 | **mu-plugin** (profile-auth, *-sync, whoami-shim, etc.) | `wp-content/mu-plugins/* → repo/platform/mu-plugins` | FPM reload | observe the hook's effect (e.g. /whoami, a header) |
| 5 | **Bundled plugin** (lg-layout-v2, lg-snippets, lg-legacy-import) | `wp-content/plugins/* → repo` (already symlinked) | FPM reload | a post render reflects the edit |
| 6 | **nginx routing snippet** (strangler-*.conf, lg-shared.conf) | `/etc/nginx/snippets/* → repo/platform/nginx` (symlink) | `nginx -t && systemctl reload nginx` | curl a test `location` you added |
| 7 | **nginx vhost — routing part** | `sites-available/vhost → repo` (symlink, host-pinned lines in a box-local `include`) | `nginx -t && reload` | curl; confirm identity block (server_name/cert) untouched |
| 8 | **FPM pool config** | `/etc/php/8.3/fpm/pool.d/* → repo/platform/fpm` (symlink) | `systemctl reload php8.3-fpm` | a pool env / setting takes effect |
| 9 | **systemd unit/timer** | `/etc/systemd/system/* → repo/platform/systemd` (symlink) | `systemctl daemon-reload` + restart/enable | `systemctl status`/`list-timers` shows the change |

## The negative tests (things that must NOT change on pull — confirm, don't fix)

These are the "doesn't ride git" runbook layer. The test is to **confirm a `git pull`
leaves them alone**, so nothing is a surprise at cut:

- **nginx server-identity** (server_name, SSL cert, cookie-gate token) — box-local include.
- **DB-state:** active plugins, active theme, `wp_snippets`, the pwa.js DB-snippet (until
  R4 moves it to a file).
- **Secrets:** `/etc/lg-*`, jwt pem, vapid.
- **Data:** uploads (R2), profile-media, MySQL/PG contents.

## Pre-flight to verify on first SSH (sets the reload rules above)

- [ ] **opcache `validate_timestamps`** (`php -i | grep validate_timestamps`, + the FPM
      pool). On = PHP edits go live on pull with no reload; Off = every PHP edit needs an
      FPM reload (or opcache reset). Decides rows 1/3/4/5. (Add result to trap log.)
- [ ] Confirm webroot asset cache headers / how `?v=` busting works (row 2).
- [ ] Confirm systemd reads symlinked units cleanly (row 9 — needs `daemon-reload`).

## Outcome → the gate

Once all 9 rows pass once, `tools/gates/deploy-ready.sh` encodes the steady-state checks:
serving checkout on `main` & clean; every path that should be a symlink IS one (no
real-file drift); the loader mu-plugin present. Run pre-pull, forever.
