# SESSION-HANDOFF — bespoke-cutover (Hub coordinator: fable)

**Session:** `3edb904c`, 2026-06-10 (full day). **Successor: read this, then
`docs/bespoke-cutover-charter.md` + `docs/hub-architecture-audit.md` — they are
the architecture brain; this file is the live state.**

## Who you are / the setup
- Consolidated coordinator (Ian pulled all Hub ownership into ONE chat running
  agents — no lanes except Buck on mobile, see below). You are `ubuntu`, full
  sudo, ON the dev box (curl ifconfig.me → 50.19.198.38).
- **The fork IS what dev serves.** Branch `bespoke-cutover` at
  `~/worktrees/bespoke-cutover/`; `/srv/bb-mirror` symlinks INTO it, so edits
  to `bb-mirror/**` are live on save (forums.css/js cache-bust on filemtime).
- **Overlay files** (`/var/www/dev/*.js`) are NOT symlinked: edit the mirror
  copies in `hub-overlay-flag/`, bump the `?v=` in `hub-overlay-flag/pwa.js`,
  then `sudo cp <files> pwa.js /var/www/dev/`. cp preserves buck's ownership.
- Revert points: tag `flag-pre-hub-refactor` on main; flip-back =
  `sudo ln -sfn /home/ubuntu/projects/bb-mirror /srv/bb-mirror`.
- **Commit small by pathspec. NEVER push** — Ian reviews, git-tsar pushes.
  Don't work in `/home/ubuntu/projects` (git-tsar auto-sweeps it).
- Verification: curl with the gate cookie (value in nginx conf / git log), and
  the `chrome-dev-login` skill (CDP 127.0.0.1:9222) for real-browser checks.
  Gotchas hit today: shell cwd "reset" notes are sometimes stale — `pwd` before
  relative-path python; sed edits invalidate the Edit tool's read state; CDP
  anon sessions see masked/fewer replies BY DESIGN (visibility contract).

## What landed today (all on the branch, 20 commits, dev-verified)
- **Audit + charter + flag** (`1be8e81`,`701c25b`): collision map C1–C10, launch
  plan, overlay snapshot under version control.
- **Engine split progress:** C2/C5/C6/C10 closed (`542f9f5`,`3ef51bf`). Theme
  bridge is server-side; first frame paints themed.
- **Themes pared to TWO** (`25a9152`→`cdd33b2`,`7cce68c`): Light = zero
  overrides; Dark = one set, gear-owned, both viewports, per-device. Legacy
  hub-theme-* system deleted everywhere. Bubble picker gone (mode-derived).
  Gear = the ONLY page-state zone (sidebar panel + T/C/Cpt toggles deleted,
  Hub-feed Cards/Fullscreen control removed; Hub layout = Mosaic|Stream pick).
- **Desktop layout** (`88b3730`,`2abf59e`,`f8a9743`,`571b923`): full-width
  spread (the REAL cap was `.bb-layout__content .page`), mosaic = 3 cols
  (2 under 1100px), stream = 1100px reading column with banner/sort/chipbar/
  author-bar aligned to card edges, uniform true-16:9 covers (no first-child
  hero), object-fit cover imgs (legacy 3/2+360px clamp released).
- **Search/sort** (`4aef4b6`,`953f180`): author filter case-insensitive (was
  0-result), author bar canonical-cased, popular-tags dropdown removed, sort
  persists via `lg_hub_sort` cookie + active pill leads, Random = true mix
  (log-damped weights; teasers sink 0.25 for non-entitled).
- **CPT cards click through — modals ONLY for discussions** (`953f180`,
  `f7a24c0`,`7f796c3`): mobile content/loothprint sheets interception, desktop
  quick-view, search-preview popup all retired; covers/titles are real anchors.
  CPT bottom row consolidated (overlay bookmark pill retired; `.fc-actions` =
  reactions left, comment + ☆ save right).
- **Desktop discussion modal §4e** (`a98d0e5`,`631b30c`,`f7a24c0`,`9567cc7`):
  in-page DOM (no iframe/breadcrumbs), OP + full drained thread, one-deep
  nesting + per-reply reactions, FB-style action rows under post/comments,
  canonical composer via [data-frm-open] (z: modal 8800 < composer 9000 <
  lightbox 9999), S/M/L sizes, text-size scaling, picker styled via
  `.feed-page` class on the scroll body. Card pared to preview ≥641.
- **Counts + privacy** (`9567cc7`): mirror counts REAL published replies
  (bbPress meta drifts — materializers.php + backfill.php patched, full
  re-crib run). Logged-out contact scrub (`web/_anon-scrub.php`): emails/
  mailto/@handles/mention-anchors neutralized at every discussion render;
  edit-payload (raw body) no longer ships to anon. Anon replies REMOVED
  (composer + reply.php); anon posts stay.
- **Anon replies / visibility:** the visibility contract = names withheld,
  content visible, logged-out only — Ian confirmed this is what he wants;
  do NOT re-add content-hiding.

## Open work, in order
1. **C3 — desktop card arrangement flip** (audit §3/§7): remove the overlay's
   `.feed-card` display:block!important geometry block → forums.css's dormant
   grid arrangement takes over (VISUAL change — do with Ian watching; reconcile
   8px vs 16px radius). Then retire the remaining ensureDesktopCss theming
   rules (now value-identical via the bridge).
2. **Drop the `lg-feed-booting` blank on desktop** (nginx conf sub_filter) once
   C3 lands — THE flash fix. Then mobile (after C9).
3. **Modal → mobile** (Ian: "we'll apply that to mobile if it's sweet" — he
   liked it): port §4e full-screen ≤640, retire the lrs replies sheet
   (~200 overlay lines). Modal soft edge: after posting a reply it doesn't
   live-refresh (close/reopen does) — fix while in there.
4. **Profile dark-mode pass** — `docs/handoff-profile-darkmode.md` (self-
   contained; Ian may spawn a separate chat on it).
5. **lg-layout-v2 dark overhaul** — charter post-launch list (insulation patch
   is the interim; Ian design call pending: dark paper vs always-light).
6. **C9 / Buck mobile** — Buck was onboarded over `msg` (clone brief, lane =
   ≤640 only, bundle/SHA delivery). `msg unread` at session start; his open
   asks (push VAPID/sender, footer canonical CSS) are queued post-launch.
7. **Launch plan** (charter §2): ~2.5 wks; Track 2 live-wiring not started.
   Ian = deploy hands for live. G6 sync hardening got REAL evidence today
   (the 5-vs-2 counts) — reconcile cron + count-recompute pattern matters.
8. Sweep later: dead forums.js blocks (theme/text cycles, compact), dead
   hub-compact CSS, unused content-sheet/loothprint-sheet code in hub-polish.

## Current versions
hub-polish v147 · app-settings v29 · app-mobile-fixes v28 · forums.css/js
served from the fork. Overlay is ~700 lines lighter than v139 this morning.
