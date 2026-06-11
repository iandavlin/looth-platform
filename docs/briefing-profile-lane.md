# Lane briefing — PROFILE (profile-app + /u/ pages), 2026-06-11

**You are a lane chat on the dev box** (`curl ifconfig.me` → 50.19.198.38, you are `ubuntu`, full sudo — act locally, never SSH out). Read `~/.claude/CLAUDE.md` first.

## Scope / ownership
- **Yours:** `/home/ubuntu/projects/profile-app/**` (served live via `/srv/profile-app` symlink — edits are live on save) and the `/u/<slug>` + `/p/<slug>` render surfaces (`web/u.php`, `web/p.php`, `web/_render*.php`, `web/edit.*`).
- **NOT yours:** the Hub (`bb-mirror/**`, bespoke-cutover fork), Buck's client overlay (`/var/www/dev/*.js` — buck-lane), the poller (`~/worktrees/login-poller`), nginx (coordinator/root).
- Identity contract (don't re-litigate): profile/identity data comes from profile-app keyed on `user_uuid` (`/profile-api/v0/whoami` self, `/users` others). **Tier still = WP roles; auth still = WP login cookie.** Never trust the `lg_tier` cookie server-side.

## Hard product rules (Ian — do NOT reverse)
- **Profiles are PUBLIC for FREE members** (Ian 6/7). Looth Pro gates business-page features, NOT profile visibility. Don't re-apply the old "Public = Pro" model.
- **Practices are NOT launching.** A whole other location block is likely coming — do **not** build features on the `location:pN` section blocks. (Anon already gets coarsened geo from them — leave that safety net in.)
- Contact links require login + email/phone never in the bulk directory (shipped `cbc65d7`/`698051e`) — don't loosen.

## Work queue (in order)
1. **Dark mode on `/u/` + `/p/`** — Buck's site audit: both pages are unreadable in dark mode. Canonical fix = make the profile surfaces token-driven (follow `--lguser-*` / OS `prefers-color-scheme` like the Hub does), NOT a client-JS patch (Buck offered an app-settings.js band-aid; the right home is profile-app CSS). Verify with the `chrome-dev-login` skill at 1280 + 390, light + dark.
2. **Avatar provision default** — every new provisioned user gets a gravatar-placeholder avatar; 496 were backfilled from BuddyPress (`tools/backfill-bb-avatars.sh`) but **new users re-rot** until the provision default is fixed (`src/Provision.php`). Fix the default path.
3. **Patreon-onboard gaps (profile-app side)** — OAuth onboard creates the WP user but: identity splits from the poller sweep, rows orphan on delete, whoami bridge gap. Poller lane owns the cookie fix; you own the profile-app row lifecycle. See memory/docs from 6/4 investigation before touching.
4. **Verify the renderLocation cutover patch** — 2-decimal + text-fallback in `src/Profile.php::renderLocation` must be in place (NULL components must not break public location rendering). Confirm present + covered; it ships at cutover.

## Cross-lane tripwires
- Buck's mobile **privacy pull-up** (`profile-sheet.js`, buck-lane) duplicates your `u.php` pmp endpoint table verbatim. **If you change any pmp endpoint shape, msg buck-COORD before shipping.**
- The profile editor embed is iframed by Buck's mobile layer — markup changes to `edit.php` / `.lg-caddy` are shared-markup changes: announce via `msg`.

## Protocol
- Commit your own increments **promptly** in clean, logical, TESTED steps (a git-tsar auto-sweeps this tree — uncommitted work gets bundled under someone else's message). **Commit ≠ push. NEVER push** — Ian reviews first.
- Some files in the shared tree are lane-user-owned: edit via `sudo` + `chown` back to the original owner; never `sudo git`.
- Dev = small test fixtures only; bulk backfills run at cutover.
- Verify over HTTP with the gate cookie (mint via `/claim?t=<token>` from the nginx conf) and the `chrome-dev-login` skill for real-browser checks.

## Report-back (end of session, verbatim format)
```
PROFILE LANE report — <date>
SHIPPED: <file(s) + commit SHA(s), one line each, what+why>
VERIFIED: <how — curl/CDP, viewport, light/dark, logged-in/anon>
OPEN: <what's left on the queue + anything newly found>
ASKS: <decisions needed from Ian / cross-lane pings sent>
```
