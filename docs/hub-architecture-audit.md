# Hub Architecture Audit — desktop / mobile / app render layers

**Author:** fable (Hub coordinator), session `3edb904c` — 2026-06-10
**Scope:** every layer that touches the Hub render, the collision map, the view-model
boundary, and the staged plan for the mobile/desktop engine split. Baseline for the
work on branch `bespoke-cutover`; revert point = tag `flag-pre-hub-refactor` +
commit `hub(flag)` (overlay snapshot in `hub-overlay-flag/`).

**Headline finding:** the two-engine model is not a greenfield proposal — it is the
**already-ratified charter** (`docs/hub-mobile-desktop-split.md`, CONFIRMED 2026-06-06
Ian + Buck) and roughly **half-landed**. The flat fc-* card contract exists in
`_feed.php`, `mobile-hub.css` is a first-paint media-gated `<head>` link, and
forums.css now carries the desktop grid arrangement (≥641) plus — as of 2026-06-10,
commit `88955fb` — the masonry geometry. What remains is the **un-retired residue**:
hub-polish.js still injects desktop theming/layout-variant/saved-view CSS after first
paint, and the "flash" is currently **masked, not fixed**, by an nginx-injected
`lg-feed-booting` hold that blanks the feed (`opacity:0`) for up to **1500 ms**.

---

## 1. Layer inventory — everything that touches the Hub render

### 1a. Server-rendered canonical (first paint, repo: `bb-mirror/`)

| File | What it does | Breakpoint | Owner (pre-consolidation) |
|---|---|---|---|
| `bb-mirror/web/forums/_feed.php` (1,370 ln) | THE feed: PG/discovery queries, gating, counts, saved-state, then emits the flat fc-* card markup. Compute (ln 20–880) cleanly precedes render (ln 1013–1370); no queries in the render loop. | all (one markup) | Hub lane |
| `forums/_filter-rail.php`, `_hub-filters.php` | Filter rail UI + facet/WHERE building. Pure server, no breakpoint logic. | all | Hub lane |
| `forums/_reply-render.php`, `_topic-body.php`, `_topic-replies.php` | Lazy endpoints for thread/body expand (`?replies=`, `?body=`). | all | Hub lane |
| `bb-mirror/web/forums.css` (170 KB) | Tokens + theme classes (ln 16–202); base structural feed (ln 1441–1456 — base `.feed-card` deliberately **chrome-less** for the split); desktop readability ≥641 (ln 2872); **masonry geometry ≥641 (ln 2893–2916, landed 6/10)**; **desktop grid arrangement of the flat contract ≥641 (ln ~3560–3809)**; mobile guard hiding net-new regions (`.fc-activity,.fc-facepile,.fc-composer{display:none}`). Only 7 `!important` in ~5k lines. | base + ≥641 | Ian / Hub lane |
| `/var/www/dev/mobile-hub.css` (8.5 KB) | The MOBILE engine's look: CSS-arranges the flat contract into the FB app-card via `grid-template-areas`. Media-gated `<head>` link (`_chrome.php:515`) AND self-wrapped in `@media (max-width:640px)`. No `!important`, no JS reshape. Paints first frame. | ≤640 | Buck |
| `/srv/lg-shared/site-header.css` + `_chrome.php` | Canonical shared shell. `_chrome.php` carries pre-paint inline boots: hub theme class (ln 502), hub-compact (ln 518). | all | lg-shell |
| `bb-mirror/web/forums.js` (115 KB, `defer`, `_chrome.php:587`) | Canonical behaviors at all widths: theme/text-size cycles, lightbox, video facade, thread/body expand, composer modal, reactions picker (ln 2041–2174), ☆ save toggle (ln 2176–2249). Event-delegated; hydrates server markup, does not rebuild it. | all | Hub lane |
| `bb-mirror/web/hub-filters.js` | Filter-rail behaviors. | all | Hub lane |

### 1b. nginx injection layer (`/etc/nginx/sites-available/dev.loothgroup.com.conf:28`)

One `sub_filter` on `</head>` injects, pre-paint:
- **Settings boot script** — applies `lg-set-boot` (theme tokens, `data-lguser-*`, font,
  scale) from localStorage **before first paint**. This is why token cohesion already
  works: shared tokens are pre-paint even though app-settings.js loads later.
- **`lg-feed-booting` hold** — on `/hub` paths adds a class pairing with
  `html.lg-feed-booting .feed-page .feed{opacity:0}` + a **1500 ms** failsafe timeout.
  hub-polish.js removes it when done (`hub-polish.js:3436–3449`). This is the
  flash-mask: the feed is *invisible* until the overlay finishes, on every viewport.
- `<script src="/pwa.js?v=3" defer>` + manifest + heartbeat beacon.

### 1c. Client overlay layer (`/var/www/dev/*.js`, loaded by pwa.js)

pwa.js (397 ln) runs at `defer` and inserts dynamic scripts. `window.load` is used
**only** for service-worker registration (`pwa.js:15`).

| File | What it does | Gate | Hub-render impact |
|---|---|---|---|
| `app-settings.js` (486 ln) | Theme/font/size engine; sets `--lguser-*` on `<html>`; writes `lg-set-boot` for the pre-paint boot. | site-wide | tokens (shared cohesion layer) |
| **`hub-polish.js` (3,458 ln, v139)** | THE overlay: 36 features, 22 injected `<style>` blocks, 11 DOM-reshape sites. Detail in §3. | `/hub`; per-feature 640-matchMedia | **the residual flash + most collisions** |
| `mobile-hub.js` (145 ln) | Behaviors only (charter-compliant): long-press → shared `.fcr-palette`; strips `hub-compact` on mobile. No CSS. | ≤640 | clean |
| `app-mobile-fixes.js` (286 ln, v26) | Band-aid guard layer: ~12 injected CSS fixes (6 `!important`), archive→hub redirect, hamburger dedupe, sort-bar tuck sync, fullscreen-rotate. Charter says these **fold into mobile-hub.css**; two already went canonical (forums.css ~3550) but the v26 guard copies still ship. | mostly ≤640 | stale-guard risk |
| `bottom-nav.js` (703 ln) | App-shell additive UI: 5-tab bar, profile sheet, notifications, desktop settings gear. | CSS ≤640 (bar); ≥641 (gear) | additive |
| `hub-infinite.js` (209 ln) | Infinite scroll on server offset pagination; appends fetched `.feed-card` nodes. | `/hub` | clean; new cards re-trigger every overlay's MutationObserver pass |
| `shop-bubble.js`, `push.js`, `sw.js`, `loothalong.js`, `events-live.js` | Shop FAB, web-push opt-in (standalone-gated), SW (network-first nav + offline page; no JS/CSS caching), event extras. | various | not feed-render |
| `directory-*.js`, `events-mobile.js`, `profile/practice/sponsor-sheet.js` | Other surfaces. | path+viewport | out of Hub-feed scope |

**Axis-2 (app shell) reality check:** only `push.js` (prompt) and the install banner
are truly standalone-gated. `sw.js` registers everywhere. Bottom-nav applies to all
mobile web. The app is already "a delivery mode, not a third renderer" — axis 2 is in
decent shape today.

---

## 2. Collision map — where two layers fight over the same DOM

| # | Collision | Evidence | Severity |
|---|---|---|---|
| C1 | **The feed-booting blank.** nginx boot adds `lg-feed-booting` (feed `opacity:0`) on /hub at all viewports; removed at `hub-polish.js:3449`, failsafe 1500 ms. First paint is a blank feed until a 227 KB script executes. On mobile this masks hub-polish's own reshapes even though mobile-hub.css painted correctly. | nginx conf:28; hub-polish.js:3436–3449 | **HIGH — this "is" the flash** |
| C2 | **Masonry geometry duplicated** in forums.css (ln 2893–2916, no `!important`) AND `ensureDesktopCss` (hub-polish.js:2899–2911, all `!important`). Values match today by hand-sync; any drift = silent reflow war. JS copy is the retire-debt of 88955fb. | both cited | HIGH (fragile) |
| C3 | **Desktop card chrome forked:** forums.css desktop block sets bg/border/**radius 8px**/category rail (ln ~3578); ensureDesktopCss re-sets bg/border/**radius 16px**/shadow with `!important` (hub-polish.js:2906–2911). The overlay always wins; canonical desktop look is dead-on-arrival until JS retires. | both cited | HIGH |
| C4 | **Two engagement rows per card:** canonical `.fc-actions → .fcr` + save (server, `_feed.php:1179–1193`) vs overlay `.lg-card-actions` heart/reply/share (hub-polish.js:431–530). mobile-hub.js must resolve both. Post-86d1dee the row is server-emitted and hub-polish "wires only" — converging, but two parallel vocabularies remain. | cited | MED–HIGH |
| C5 | **Saved view duplicated:** canonical Saved rail filter landed 6/10 (`9bcf24e`, server `?saved=1`) while hub-polish keeps its own saved pill + client-rendered saved feed (ln 547–741). Two Saved UIs on one surface. | cited | MED |
| C6 | **hub-compact stripped twice** on mobile: mobile-hub.js:30–40 AND app-mobile-fixes.js:187–199. | cited | LOW (idempotent) |
| C7 | **Text-size race:** forums.js pill writes `--lg-read-scale`; app-settings re-applies 3× to win; app-mobile-fixes pins sizes with `!important` (ln 124–126). Works, but timing-fragile. | cited | MED |
| C8 | **app-mobile-fixes v26 guard copies** of rules already canonical in forums.css (~3550 says "Buck drops the v7 guard once this lands"; guard now at v26, still shipping). | forums.css ~3548; a-m-f.js:81–85 | MED |
| C9 | **Mobile JS reshapes vs the charter:** charter bans JS DOM-reshape, yet hub-polish still does `relayCard` meta-top rebuilds (ln 771–833), `fbStyleReply` bubble recomposition (ln 859–908), composer transform (ln 1132–1277) on mobile. Exactly what C1's hold exists to hide. | cited | HIGH (mobile-engine debt) |
| C10 | **Theme re-point cascade war:** ensureDesktopCss re-points forums.css's own tokens (`--bg`, `--bg-card`, `--fg`…) under picked light themes with `!important` (hub-polish.js:2925+) — forums' native `hub-theme-*` and the app's `--lguser-*` are two parallel theme systems. Cohesion achieved by force. | cited | MED–HIGH |

Desktop flash mechanics (C1+C2+C3): server paints masonry geometry + (suppressed)
chrome → feed held at opacity 0 → hub-polish executes → injects 22 style blocks
(~40 `!important` desktop) → removes hold → feed pops in, restyled. Zero *visible*
reshape only because the user stares at blank space instead.

---

## 3. hub-polish.js fold backlog (classification)

### 3a. Desktop features

| Feature (lines) | Class | Target |
|---|---|---|
| Masonry geometry (2899–2911) | already folded (88955fb) | **retire JS copy now** |
| Desktop card chrome/shadow/radius (2906–2911) | CSS-FOLDABLE | forums.css ≥641 (reconcile 8 vs 16 px radius — Ian call) |
| Theme-coherence rules reading `--lguser-*` (2889–2960) | CSS-FOLDABLE — tokens are pre-paint via nginx boot, static CSS works first-frame | forums.css ≥641; ideally forums.css adopts `--lguser-*` natively, dissolving C10 |
| Layout variants masonry/cards/stream/compact (3036–3061; attr `data-lg-hublayout` from localStorage) | CSS-FOLDABLE + tiny boot | rules → forums.css ≥641; attr → nginx pre-paint boot |
| Hub Style appearance panel (3084–3120) | NEEDS-JS (UI) | stays client, skinny |
| Desktop quick-view modal (1998–2099) | NEEDS-JS | move into forums.js as canonical |
| Desktop search tag suggestions (127–185) | NEEDS-JS | forums.js |
| Saved pill + client saved feed (547–741) | SUPERSEDED by `?saved=1` (9bcf24e) | delete |
| Save/action row builder (431–530) | SUPERSEDED by server row (86d1dee) | shrink to pure wiring in forums.js |
| Desktop video hover (2448+) | NEEDS-JS | forums.js |
| Desktop filter-nav default-collapse (105–121) | MARKUP+CSS | server emits collapsed state |

### 3b. Mobile features still JS-reshaping (engine debt, C9)
- `relayCard` meta-top rebuild (771–833) → likely obsolete under the flat contract
  (server already emits fc-avatar/fc-author/fc-time/fc-category as siblings;
  mobile-hub.css arranges) — verify + delete.
- `fbStyleReply` (859–908) + FB composer transform (1132–1277) → server partials off
  the same view model + mobile-hub.css arrangement.
- Sheets (content 1563–1912, replies 2733–2784, loothprint 2187–2295), lightbox,
  autoplay, top-search, header auto-hide → legitimately NEEDS-JS; consolidate into
  mobile-hub.js (behaviors bundle), not a "polish" monolith.

---

## 4. The view-model boundary in `_feed.php`

Split point is **line ~880/1013**: all queries batch, up-front; render is pure
template. Per-card view model (all computed once, keyed lookups in the loop):

- identity/author: `author_name/slug`, `author_profiles[wp_id]` → avatar/bio (611–615)
- card core: title, excerpt (240-char teaser-safe), thumb, kind, duration, forum/cat
  key, tags (631–643), timestamps
- gating: viewer rank once (213–214); per-card `is_gated` compare (1131); SQL
  pre-gates to teaser-safe columns — leak-safe
- engagement: `card_reaction_counts` (796–828), `content_comment_counts` (749–795),
  reply teaser (669–720), facepile (722–747), reply reactions (830–848)
- saved-state: server ☆ + `?saved=1` (86d1dee/9bcf24e)
- video facade: `yt_id` stored-or-regex (850–880)

Carving `hub_feed_view_model(): array` is cheap and formalizes what is structurally
true; gives infinite-scroll/any future renderer the same data function.

---

## 5. Shared vs surface-specific

**Truly shared (correct):** discovery/PG data + gating + counts (one store per
target, server-rendered — contract verified); the flat fc-* markup; `--lguser-*` /
`--lg-*` tokens (pre-paint); canonical site-header; `.fcr-palette` picker.

**Shared but shouldn't be:** the "polish" monolith (desktop + mobile + utilities in
one 227 KB file, one version number — v139); the `lg-feed-booting` hold (one global
mask serving two engines' different debts).

**Forked but should be shared:** the two theme systems (C10); the two engagement-row
vocabularies (C4); the two Saved UIs (C5).

**Pre-existing divergence (log only):** hot-sort still ranks on stale
`content_item.like_count` while displayed counts come from `card_reactions`.

---

## 6. Corrections to the kickoff briefing

- **"pwa.js loads hub-polish on window.load (after images)"** — no; pwa.js is
  `defer` and hub-polish is an early `async=false` dynamic script (`pwa.js:102–109`).
  The flash mechanism is real but it's "blank-hold until overlay executes" (C1), not
  "paint mobile then reshape".
- **"A large un-media-queried base block forces mobile shape at all widths"** — no
  longer true: base `.feed-card` was stripped of chrome for the split
  (forums.css:1451–1456); desktop arrangement+geometry are head-linked ≥641. The
  residue is C2/C3 (overlay re-styling what the server now paints).

---

## 7. Execution plan (launch-driven, ~2 weeks)

Revert point: tag `flag-pre-hub-refactor` + `hub-overlay-flag/` snapshot.

- **Days 1–2 — kill duplicates (C2/C5/C6):** retire JS masonry twin, overlay saved
  view, duplicate compact-strip.
- **Days 3–5 — finish desktop engine (C3/C10 + fold backlog §3a):** fold theming /
  card chrome / layout variants into forums.css ≥641; `data-lg-hublayout` into the
  nginx pre-paint boot; reconcile radius fork; **drop `lg-feed-booting` on desktop**;
  verify zero reflow via chrome-dev-login.
- **Days 6–10 — formalize mobile engine (C9):** delete relayCard (verify obsolete);
  fbStyleReply + composer become server markup off the view model + mobile-hub.css;
  fold app-mobile-fixes guards into mobile-hub.css and retire the file; consolidate
  NEEDS-JS behaviors into mobile-hub.js; **drop the hold on mobile**.
- **Days 11–12 — teardown + guardrails:** hub-polish.js empties → deleted from
  pwa.js loader; add "no `!important` against fc-* outside the two engine files"
  grep check; update the split charter doc.
- **Viewport-split mechanism: KEEP the media-query split; no `lg_vw` cookie.** One
  flat markup + two media-gated CSS engines already gives exactly one engine per
  width on the first frame with zero server viewport knowledge. Revisit a server
  split only if a mobile partial genuinely can't be CSS-arranged — then split that
  partial, not the engine.

Stage 1 (view-model carve) is not launch-blocking; do it opportunistically when
_feed.php is open for the C9 work.
