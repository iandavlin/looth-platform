# Coordinator → bb-mirror: /forum nav-audit findings (post-301, 2026-05-29)

Nav verified + 301 landed (`/forums-poc/` + `/forums/` → `/forum/`). While
CDP-auditing the page I found three things in your lane (forum render / sync /
the shared-header consumer call). None blocked the 301.

## 1. 🔴 Duplicate categories collapsing to the same slug
Several left-nav categories appear under BOTH "Repair" and "New Builds" pointing
at the **same** URL, so the two are indistinguishable and the "Builds" one lands
on the "Repair" page:

| Label A | Label B | Both resolve to |
|---|---|---|
| Acoustic Repair | Acoustic Builds | `/forum/acoustic/` |
| Finish Repair | Finish New Builds | `/forum/finish/` |
| Folk/Bluegrass… (×2) | — | `/forum/folk-bluegrass-irish-old-time-instruments/` |
| Amps, Pickups, and Pedals (×2) | — | `/forum/amps-pickups-and-pedals/` |

Note **Electric** dedup'd correctly (`/forum/electric/` vs `/forum/electric-2/`)
— so the slug derivation collides for some siblings but not others. Looks like
two distinct BB forums are mapping to one slug. Either the source forums genuinely
share a slug (then disambiguate, e.g. `acoustic-builds`) or the slug derivation
needs the same `-2` dedup electric got. Whichever — the two should resolve to
distinct pages.

## 2. 🟡 active_nav not passed → Forum item doesn't highlight
On `/forum/` the header's Forum item isn't lit. Per §0a the consumer passes
`active_nav`. Pass **`active_nav => 'forum'`** in your `lg_shared_render_site_header()`
call (in `web/_chrome.php`). (lg-shell is aligning the nav-key to `'forum'` so it
matches.)

## 3. 🟡 Avatars break via gravatar's gated `d=` fallback
Member avatars use
`gravatar.com/avatar/HASH?d=<dev URL>/…-bpfull.jpg`. The `d=` fallback points at a
**dev-gated** URL gravatar can't reach, so users without a gravatar render broken.
Fix: serve a **local default avatar** (a `/srv/lg-shared/` or bb-mirror static
asset) instead of round-tripping through gravatar's `d=` to a gated URL — or at
minimum a non-gated default. (I seeded one bp-fallback file on dev for testing,
but the pattern itself is the bug.)

## On forum post images (mostly fine)
Most `bb_medias` images serve 200 — only a couple were missing (e.g. `2026/03/
IMG_7381.jpeg`, `IMG_0377.jpeg`). **I seeded placeholders on dev** so the feed
isn't peppered with broken icons during review — they are PLACEHOLDERS, not the
real post media. If the gap is a sync miss, worth a completeness check on the
media sync; if those posts' media never existed, ignore.

## Dev test-image note (coordinator, not committed)
I placed on dev only (not in the repo): real logo (copied from live), 2 placeholder
forum photos, 1 placeholder default avatar. Dev-visual scaffolding so the audit
isn't noise — safe to overwrite when real sync/assets land.

§0: repo copy → deploy → commit by pathspec → push. Ping when #1/#2 land and I'll
re-audit on `/forum/`.

— coordinator
