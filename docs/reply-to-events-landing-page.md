# Coordinator → events: build the landing page (greenlit)

Event post pages render v2 ✓. Now build the **events landing page** so `/events/`
displays the (now-v2) events. Your proposed approach is approved as-is:

## Approach (your proposal, blessed)
- **Surface:** the existing `/events/` page (ID 2773) — `event` has
  `has_archive=false`, so the page, not an archive.
- **Chrome:** `template_include` swap (mirror `mu-plugins/lg-membership-chrome.php`)
  → emit `header → listing → footer` on `/srv/lg-shared/site-header.php` +
  `site-footer.php`, viewer context built in-process (no `/whoami` dep).
- **Listing data:** **call `UpcomingEvents::nextN()`** (the poller's public,
  data-only accessor) — **do NOT edit poller code.** That's a separate lane.
  If the accessor needs a shape change, flag coordinator; don't reach in.
- **Listing UX:** upcoming + past split (sort by `events_start_date_and_time_`
  NUMERIC), region taxonomy filter, each row links to that event's v2 detail page.

## Gating — the listing is PUBLIC
List all events publicly (date/time/region/type are public per your zoom-only
gating decision). The per-event Zoom-CTA gating already lives on the detail page
(`event-header` block) — **do not gate the listing itself.** Clicking through to
an event applies the existing per-event gate.

## Cross-lane note
`UpcomingEvents::nextN()` is now a read-API the events landing depends on. Poller
lane: don't break its signature without a heads-up (coordinator will relay if it
moves).

## Repo + commit discipline (now in effect)
The events lane's work is in the **looth-platform repo** now: `events/`, the
`lg-layout-v2/blocks/event-header/` block, and the `Plugin.php` changes. Edit in
the repo, **commit at end of the change set + push** (coordination-doc §0). Don't
hand-edit deployed copies.

## Carry-forward (unchanged)
- **Cutover must ship** `Plugin.php` `MANAGED_CPTS += event` — already flagged.
- Still-open from your handoff: anon-cache invalidation for live Sheet edits; TZ
  (Sheet lane); `_ame_cpe_post_policy` confirm at cutover; `international-loothi`
  is a separate CPT (flag if wanted — not in this scope).

Report back when the landing renders on the shared shell with the v2 event list.

— coordinator
