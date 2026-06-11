# SESSION-HANDOFF — bespoke-cutover (consolidated coordinator: fable, 2026-06-11 PM)

**Successor: read this, then `docs/bespoke-cutover-charter.md` + `docs/hub-architecture-audit.md`
(architecture brain). Prior coord sessions: `handoffs/2026-06-11-am-coord.md` (flash-fix/C3 +
img.php resizer), `handoffs/2026-06-10-fable.md` (day 1). This file is the live state.**

## Setup (unchanged facts)
- You are `ubuntu` ON the dev box (curl ifconfig.me → 50.19.198.38). Full sudo.
- **The fork IS what dev serves**: branch `bespoke-cutover` at `~/worktrees/bespoke-cutover/`;
  `/srv/bb-mirror` symlinks in (live-on-save). Overlay files (`/var/www/dev/*.js`) deploy by
  `sudo cp` from `hub-overlay-flag/` + a `?v=` bump in pwa.js; ALWAYS re-sync the mirror FROM
  live before overlay edits (Buck hot-edits; mirror drift = clobber).
- archive-poc + profile-app + lg-shared + events serve from the MAIN tree (`/home/ubuntu/projects`)
  via /srv symlinks — front-page/profile/header work goes there, NOT the fork.
- Commit small by pathspec, NEVER push (Ian reviews; he pushed/reviewed nothing today).
  A git-tsar sweeps the main tree; the fork got cross-lane hunks swept into 7be215a — commit fast.
- Comms: `msg` CLI. Buck delivery protocol: he commits branches in /home/buck/{looth-platform,
  bespoke-cutover}, msgs the SHA; coord merges. He still occasionally hot-deploys his own lane.

## Governance set today (Ian rulings — enforce these)
- **LANE WALL:** desktop (≥641) Hub geometry/chrome = COORD ONLY. Buck = mobile ≤640 + shop/
  directory/messenger/events surfaces. "Coord has desktop until after deploy."
- **PARITY GATE** (in every lane briefing + memory): no user-facing control ships on one surface
  without its counterpart in the same change, or a written tabled-note.
- **Filters are filter-only:** facet MUTE retired everywhere (8559ed2); stored mute cookies ignored.
- **Privacy UI converges on Buck's SLIDER panel**, canonical both surfaces (profile lane queue #5).
- **Own replies:** members edit + hard-delete their own (9739fbb; author-or-mod, nonce, pg-synced).
- **Loothprint desktop popup: YES** (reverses 6/10 "modals only for discussions" — prints only).
- **Members may CREATE loothprints/loothcuts: YES** — status=pending + moderation queue.
- **Logged-in front page = Bento: YES** (Buck builds branch). Member-hero merged (c59bc5a main).
- **Feed width + sponsor placement: DELEGATED TO BUCK as one pair** (his caps v180/v182/v183 vs
  Ian's no-cap; sponsor deck /sponsors-deck/ — coord rec: caps + side rail #8). ⏳ AWAITING his
  pick → then ABSORB the winning geometry into forums.css and DELETE the overlay copy.
- Secrets rotate AT CUT. Practices NOT launching (location:pN sections get a future block).

## Live overlay state (post-today)
hub-polish **v181** (= Buck v180 caps + his lp-gate-note folded) · shop-bubble **v21**
(no FAB anywhere; desktop Loothtool header tab → instant modal — note this un-tabled a
post-launch item, Ian shrugged) · app-settings v31 · app-mobile-fixes v29 · profile-sheet v7
(NOW tracked in mirror) · push.js v1. Overlay carries ~zero feed geometry EXCEPT Buck's
cap/column rules pinned against canonical — dies at the absorb.

## Today's mainline (fork: `git log --since=2026-06-11`; main tree likewise)
Fork highlights: own-reply edit/delete; Saved pill + sticky control bar under sticky header
(61px) + close-X on the rail (+gutter fix 50cc511); mute retired; modal embeds + inline reply
images; Newest=created_at; modal live-refresh + forum_id fix; Optimum rename (both palette
copies — fork serves the Hub's); Buck bug batch (49dd02b); overlay absorbs/reconciles ×3.
Other-coord/perf commits in the same fork: img.php resizer (f26ac06), 18-card first page,
C3 geometry absorb (7e79f8c), full-bleed covers (94b1848), font inlining, eager LCP covers.
Main highlights: front-page Classic Landing (2632f93) + member-hero (c59bc5a); contact-PII
scrape-proofing (cbc65d7, 698051e, 03657e3); CPT sticky dock (2c0bc82); lane briefings + parity
(8db7e7b); POST-LAUNCH-LIST.md; connections backfill script (3e4178c); shell body{margin:0}
(802fb8d) + scrollbar-gutter (082b5e5).
Infra (not in git — CUT-PLAYBOOK): gzip types enabled; security headers; xmlrpc deny;
backup-file deny + wp-config quarantine; /.well-known/ gate-exempt on 443; perf-lane cache TTLs.

## Data backfills RUN ON DEV (re-run at cut; scripts kept)
- **Avatars**: Buck Block 1 (forums.person: mixed-content 496→0, dir-0 201→18) + coord Block 2
  (profile_app: gravatar/null 1318→278). Generator /home/buck/avatar-backfill-gen.php.
  ⏳ Code-side chain (Provision::ensure, materializers, backfill-avatars) = profile lane, so new
  rows don't regress.
- **Connections**: BB friendships → connections, 10,377 rows (7,135 accepted/3,253 pending).
  `tools/backfill-bb-connections.sh` (idempotent; ~1k unbridged rows sweep up at cut).

## Open queue (priority order)
1. ⏳ **Buck's geometry+sponsor pick** → absorb to forums.css, delete overlay copy. (His v183
   also auto-opens the filter rail ≥2520px w/ key `lg-nav-open-wide` — collides with sponsor
   side-rail margin; he knows.)
2. **Coord builds greenlit:** loothprint desktop popup (modal shell hosting the ?embed=1 renderer,
   0c35ad8 path; Buck keeps mobile sheet) · member create endpoints for loothprint/loothcuts
   (pending+moderation; file-upload-types active for stl/zip/3mf; loothcut = self-host video path)
   · re-point the front-page member map + Bento You-pin from dead /wp-json/looth/v1/members-geo
   to the privacy-honoring /profile-api/v0/directory/members feed (NO new route).
3. **Merge watch:** Buck's avatar code-chain branch; his Bento branch; profile-section-move
   canonical (a30096e → profile lane, mobile overlay self-retires).
4. **Lanes running** (briefings docs/briefing-*.md): performance (next: minify pending Buck OK;
   re-measure push.js/hub-polish bootup on a QUIET box — the "regression" was measured during
   contention + an anon session, and v177 was coord's, not Buck's), profile (slider convergence,
   avatar chain, QA owner JWT mint — /u/claude-admin-qa exists, role stays public til the
   looth_id JWT is minted in headless), content-cleanup (tier-gate bug repro:
   /post-type-videos/anders-nicklasson-of-true-temperament/ — gate fires on a PUBLIC item,
   per-item flag not tier data), front-page (Bento support, dark pass shipped).
5. **The launch plan** (charter §2 / Track 2) — still the thing everything else displaces.
   Ian's scope-freeze answer was never given; re-ask when the feature flood slows.
6. Filed bugs: header nav ~83px font-race shift (evidence in docs/SESSION-HANDOFF.md main);
   one console 401 on Hub (overlay fetch).

## Gotchas (today's scar tissue)
- `/tmp` cleared 3× mid-session — canonical CDP driver lives at `tools/cdp-drive.py`, copy out.
- Buck wiped the shared headless Chrome cookies once; re-mint via `chrome-dev-login`
  (claude_admin = WP id 1912, recreated post-reload).
- **Never put no-cache on HTML** — tried it, dev felt "dog shit slow", reverted (6461827).
  Stale-page reviews are solved by hard-refresh, not headers.
- CDP tab ids go stale between commands — re-list inside the same script.
- `$pc` vs `$postContext`: standalone render body markup lives INSIDE lg_standalone_page($pc,…).
- forums.person.id == WP user id. The Hub's reaction palette = the FORK's archive-poc copy.
- Temp Buck key auto-removes Sat 18:44 UTC (`systemctl list-timers buck-key-rotate`).
- Buck's clone can resurrect coord-removed overlay code — he's warned twice; check version
  numbers in his msgs against live before assuming.

---

## Addendum — 6/11 late evening (same coordinator, post-handoff burst)

**Ian rulings tonight (all relayed to Buck):**
- **Backfill directive CHANGED**: "cutting close to complete with a backfill
  top off" — full backfills on dev are now fine; cut day re-runs idempotent
  scripts as a top-off. Buck's legacy-DM run is ratified. Keep-list:
  `docs/CUT-DAY-DATA-TOPOFF.md` (avatar chain + DM script fixes live there).
- **Buck's wide-screen column-cap override APPROVED** ("override fine") —
  the v180–v183 caps/rail behavior stand; absorb into forums.css when his
  geometry settles. NB his rail auto-open targets an aside the hub no longer
  renders (see filters modal below) — he should retire it.
- **members-geo**: restore; **each member's pin follows their own profile
  privacy decision** (slider panel) — no blanket coarse/pull. Gates the
  front-page Bento map tile + You-pin.
- **Events are OUT of the hub feed** (events page owns them) — union, Type
  facet, and category counts all exclude kind='event' (592f7ac).
- **Filters = centered MODAL, multi-select** (8a31dda + 8307540): Categories
  AND Types both open, no segmented toggle; picks apply in place via fetch;
  closes ONLY on × / click-off (no Esc). Hub renders no nav aside / hamburger
  / drawer anymore; forum subpages keep the classic left nav.

**Also landed tonight:**
- Launch-blocker closed: 253 backup files swept out of /var/www/dev →
  `/var/www/dev-bak-archive/` (paths+ownership kept, loothdevs-writable);
  deny rules verified live both vhosts (loothtool got the .bak rule);
  assetlinks.json serves anon (Buck's TWA unblocked).
- Hub scroll repeat/reshuffle fixed two-sided: pinned mosaic columns
  (forums.js §9 — multicol rebalance was moving read cards between columns)
  + deterministic paging (sort tiebreakers, frozen hot clock `hnow=`).
- Desktop header GEAR restored: live pwa.js loads bottom-nav.js on ALL
  viewports again (the gated loader had it mobile-only). hub-polish v185 =
  wireFilterDrawer no-ops when #hub-fmodal exists. Both live-only until
  Buck's next mirror commit.
- Gating bug: post 71179 (public-badged video) carried a stray per-block
  `gated_tier: looth-lite` — stripped in WP meta + blob re-materialized;
  anon now gets the embed. Only post with the mismatch (71441 has NO tier
  term at all — content-cleanup should term it).
- Merges: buck/social-modal-dark → fork (c88e443); buck/front-page-bento →
  main (509a17d — was already cp-deployed, tree now clean);
  buck/profile-section-move → main (2953172 — owner-view eyeball still on
  Buck). Fork is 5 commits ahead, unpushed (review-before-push rule).

**Still queued (coord lane):** Buck's _feed.php asks — cover img
width/height attrs (proper scroll-jump fix; his hub-nojump shim then
retires), "Comment" label on content cards, viaReplies `.lg-act-replies`
one-liner, topic-tap exclusion list, ntm auth retry, heart→thumbs-up SVG.
Then the three greenlit builds (loothprint popup, member create-flow,
members-geo re-point — now per-user-privacy shaped).
