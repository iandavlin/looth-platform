# Handoff — profile surfaces dark-mode pass

**From:** fable (Hub coordinator), 2026-06-10 · branch `bespoke-cutover`
**Ask (Ian):** "profile is now suffering from dark mode issues" — the /u/<slug>,
/p/<slug>, /profile/edit and /directory/members surfaces render wrong in Dark.

## Context you need (10 minutes of reading)
- `docs/hub-architecture-audit.md` + `docs/bespoke-cutover-charter.md` (this branch).
- Theme model (final, Ian 6/10): exactly TWO modes. **Light = zero overrides**
  (brand default; can't mismatch). **Dark = one override set**: the gear
  (app-settings.js, in `hub-overlay-flag/`) sets `--lguser-*` AND `--lg-*` tokens
  inline on `<html>` pre-paint (nginx boot script replays them), plus ONE dark
  patch-sheet `ensureDarkStyle()` in app-settings.js for surfaces that hardcode
  colors. Modes apply at ALL widths, per device.

## The failure pattern (same as events/footer/articles had)
profile-app pages consume `--lg-*` tokens from `/srv/lg-shared/site-header.css`
+ their own CSS with **hardcoded light values**. In Dark: inline tokens flip
(text goes light) but hardcoded card/page backgrounds stay light → unreadable
mixes, or light islands on dark pages.

## How the fixes are done elsewhere (pick the right tool per surface)
1. **Token-following** (best): replace hardcoded colors in the surface's own CSS
   with `var(--lg-…)`/`var(--lguser-…)` so both modes Just Work. Done for: Hub
   (forums.css dark bridge, `html[data-lguser-theme="dark"]` chains `--bg/--fg/…`).
2. **Patch-sheet rules** (fast): add `D + ' .selector{…!important}'` lines to
   `ensureDarkStyle()` (app-settings.js — it already covers `.dir-card`,
   `#site-header`, `.lg-chrome-foot`, inputs, sheets). Pattern + examples inline.
3. **Insulation** (when the surface is DESIGNED light): pin light tokens on the
   container so dark can't reach it — see `main.lg-standalone-main` (articles)
   in ensureDarkStyle, and the `.lg-chrome` shell-insulation block in forums.css.

## Concrete starting points
- Audit each profile surface in Dark at 1280px + 390px via the `chrome-dev-login`
  skill (CDP screenshots; cookie-mint instructions in the skill).
- profile-app page CSS lives in /srv/profile-app/web (symlink — check ownership
  before editing; coordinate with the profile-app lane if the canonical tree is
  Buck's). Shared header/footer already handled.
- Known specifics: `.dir-card` is patched; the profile VIEW page (`/u/`) hero,
  section cards, and the EDIT form fields are not. The map panel (Leaflet) needs
  only chrome around it darkened — don't restyle map tiles.
- After edits: bump `app-settings.js?v=` in `hub-overlay-flag/pwa.js`, `sudo cp`
  both to /var/www/dev, commit on `bespoke-cutover` (small, by pathspec, no push).

## Report back
DONE / FILES / VERIFIED (both widths, both modes) / NEEDS-ENGINE / BLOCKED — to
fable (coordinator) and Ian.
