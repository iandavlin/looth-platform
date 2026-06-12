# Hub-COORD charter (refreshed 2026-06-10) — you drive all of Hub

You're promoted: **hub-coord**. You own and DRIVE the entire desktop Hub surface — design, layout,
cards, reactions surface, reply reactions, cooler card, composer. Burn in-lane Hub work without
round-tripping; only escalate cross-cutting CONTRACT changes, out-of-lane blockers, or product
decisions Ian must make. Successor to the Hub SURFACE lane (folds in old hub-fold-cpts + reactions-
SURFACE — same files).

Sanity-check the box: `curl -s ifconfig.me` → `50.19.198.38` = act LOCALLY, do NOT SSH. Canonical
tree (`/home/ubuntu/projects/`), NOT a worktree. Commit small by pathspec; **NO silent pushes** (Ian
reviews → git-tsar pushes). Report your session ID so Ian can log you in CHATS-MENU.

## YOU OWN, SOLO (anti-collision) — everything under `bb-mirror/web/`
- `forums.css` — desktop card, ≥641 (whole file is desktop post-split)
- `forums.js` — all feed behavior + reaction/comment/composer wiring
- `forums/_feed.php` — server-rendered flat `fc-*` card markup (the contract)
- `forums/_chrome.php`, `_reply-render.php`, `_topic-replies.php`, `_filter-rail.php`
Nobody else edits these.

## NOT yours — clean boundaries
- **ENGINE** (`briefing-comments-reactions-engine.md`): backend provider — `archive-poc/api/v0/*`,
  `sql/*`, `bin/migrate-*`. You CONSUME its endpoints; never build/edit backend. Contract/API change
  → ask engine lane via Ian.
- **Buck:** `mobile-hub.css` (≤640) + `mobile-hub.js` (behaviors). You never edit mobile; he never
  edits desktop. Shared surface = the flat `fc-*` markup in `_feed.php` — if you change it, ANNOUNCE
  to Buck. Also watch `/var/www/dev/app-mobile-fixes.js` (buck-owned live guard) when a breakpoint
  renders wrong.
- **DESKTOP OWNERSHIP (Ian 6/10):** YOU are the single owner of the canonical desktop Hub
  (`forums.css ≥641` + `_feed.php`). Buck's `/var/www/dev/hub-polish.js` client overlay built a lot
  of desktop in the last 24h (masonry feed, quick-view modal, hover-play video, reaction pill, Save
  button v113 + Saved filter v112, Hub-style panel, search tag suggestions). Going forward Buck's
  overlay is **mobile + experiments only** — it stops adding NEW desktop. Your job: audit
  `hub-polish.js` desktop features, **fold the proven ones back into canonical** forums.css/_feed.php,
  then the overlay's desktop branch retires. Coordinate the retirement with Buck (announce via msg).

## THE CONTRACT (read before touching anything)
`docs/hub-mobile-desktop-split.md` — ONE server-rendered flat `fc-*` card, two CSS layers split at
640px. CSS-ARRANGE, never JS-reshape (reshape = the mobile flash, banned). Count contract: ONE store
per target, **server-rendered count**, optimistic UI reconciles — never a second count source.

## ALREADY SHIPPED since the 6/7 charter — DO NOT REBUILD
- Reaction picker shows all 7 incl. custom image reacts (`cf056ea`); reply reactions live (`219d4a2`)
- Hot-sort repointed onto live `card_reactions` (`4cd12f2`)
- Comment edit/delete wired — engine + consumer trash/edit (`a652b1c`)
- Native per-post "Post anonymously" toggle, retires the Form-38 hack (`6ae1c90`)
- Member-only discussion authors masked from logged-out viewers (`36d868a`)
- Cooler-card composer: real viewer avatar + Quill expand + persistent reply composer (`f7666c4`, `c48f5f0`)
- Card density pass; sort bar (Random·Newest·Trending); mobile category-dup fix
- Save STORE + my-saved API shipped on the ENGINE side (`b820532`, `0a31736`)

## OPEN — GREENLIT (Ian 6/10), work these
0. **FIRST: audit Buck's `hub-polish.js` desktop branch and fold the proven wins into canonical.**
   Saved button + Saved filter are ALREADY shipped on Buck's overlay (v112/v113) — fold them into
   `forums.css`/`_feed.php` rather than rebuilding. Same for masonry/quick-view/etc. as Ian approves.
   This replaces the old "wire Saved from scratch" task.
1. **Moderation** Move / Split / Spam + owner-edit gating. Each needs a BB proxy endpoint (escalate
   via Ian); emit author `wp_user_id` on the reply stub to gate owner-edit.
2. **Discussion card parity** — server-render the full MAX element set (member subline + breadcrumb)
   to match the approved mockup. Cosmetic, low-risk.

## NEEDS-ENGINE (comments-and-reactions lane) — moderation endpoints (lined up 6/10)
For the greenlit Move / Split / Spam moderation work, the engine lane needs to provide BB proxy
endpoints (consumer wires the buttons; endpoint enforces authz):
- **Move** — relocate a topic to a different forum/category.
- **Split** — split selected replies into a new topic.
- **Spam** — mark a topic/reply as spam (BB spam status).
- **owner-edit gate** — emit author `wp_user_id` on the reply stub so the consumer can show owner-edit
  to the author only (endpoint still enforces).

## PARKED — do NOT start yet
- **≤640 forums.css extraction** — POST-CUT only, after Buck confirms `mobile-hub.css` coverage.
  Handoff stub: `bb-mirror/handoffs/2026-06-06-forums-css-640-card-rules-FOR-BUCK.css`.
- Routed OUT: lg-shell owns the dark-mode shell-nav fix (their `.lg-chrome` token scoping).

## Verify
admin uid 1 (iandavlin) bypasses reply flood throttle. Headless CDP is ANON to WP → mod controls
hidden + posts hit the auth gate; use the `chrome-dev-login` skill for a real logged-in view. Raw
canonical: `curl /hub/ --resolve dev.loothgroup.com:443:127.0.0.1 -H "Cookie: loothdev_auth=<$loothdev_token>"`.

## Report back (to Ian)
`DONE · FILES · VERIFIED (desktop unchanged + no flash + counts server-rendered) · NEEDS-ENGINE
(contract asks) · BLOCKED`. Include your session ID.
