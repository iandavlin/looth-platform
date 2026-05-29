---
name: archive-poc
description: Editing the archive-poc front-page config (rows, sponsors, CTAs, sidebars). Load when working on /home/ubuntu/projects/archive-poc/config.json, row layouts, or the dash that saves it. Covers row types, column routing, tag matching, audience gating, and deploy.
---

# archive-poc front-page editing

The front page at `/archive-poc/` is driven by `config.json` (overlaid on `web/defaults.php`). Editing the page = editing this file. Saves go through the WP-admin "Archive POC Config" dash, which POSTs to `/archive-api/v0/_config`; an FE inline editor is the next big project (see SESSION-HANDOFF.md).

## 1. Top-level shape

```json
{
  "sponsors":     [ { name, url, logo, bg } ],
  "local_looths": [ { name, url, avatar } ],
  "cta_member":   [ { label, url, style, action, icon?, attr? } ],
  "cta_public":   [ { label, url, style, action } ],
  "rows":         [ { id, title, type, layout, column, audience, query, ... } ],
  "signup_banner": { title, body, cta_label, cta_url }
}
```

Per-key overlay: if a key is present, it **replaces** the defaults entry entirely; missing keys fall through to `web/defaults.php`. So to edit one sponsor you must include the whole `sponsors` array.

Two kinds of content here, don't conflate them:
- **Site-wide blobs** (sponsors / local_looths / cta_member / cta_public / signup_banner) — static data baked into the band layout. Not row types.
- **rows** — the discovery feed. Where layout/order decisions live.

## 2. Row types — what fields actually matter

Dispatch in `web/index.php` → `_render-main-row.php`. Order shown below = stable callers; everything else is ignored.

| `type` | `layout` | Useful fields | Where it can live |
|---|---|---|---|
| `activity-strip` | `activity` | `query.limit` | Center of activity band only. **Auto-injected at position 0 if missing** (`_config.php`). |
| `cta-bar` | `cta-bar` | (none — pulls from `cta_member`/`cta_public`) | `column: left` only |
| `sponsors` | `sponsors` | (none — pulls from `sponsors`) | `column: right` only |
| `local-looths` | `local-looths` | (none — pulls from `local_looths`) | `column: right`. **Render code still exists but the row was dropped from live in 2026-05-25; re-add with this exact shape to restore.** |
| `events-upcoming` | `events` | `query.limit` | `column: left` (sidebar compact card) — also works in `main` as a full rail |
| `static` | `rail` / `discussions` / `grid` | `query.kind`, `query.exclude_kinds`, `query.tag`, `query.sort` (newest\|oldest\|liked\|active), `query.limit`, `query.max_age_days`, `query.min_likes`, `query.tier_in` | `main` (default), or sidebar |
| `tag-random` | `rail` | `query.sort`, `query.limit`, `seed`, `candidate_pool`, `slot` | `main`. Picks 1 tag deterministically per ISO week from a pool (top-20 by usage). Use `{{tag_label}}` in `title`. |
| `video-promo` | `video-promo` | `side` (`video-left`/`video-right`), `video_id`, `html` (free HTML, supports `[member_map]` shortcode), `aspect` (`16x9`/`4x3`/`1x1`) | `main` |
| `hero` | `billboard` | `featured_post_ids[]`, `fallback_when_empty: { query... }` | `main` |

**Empty `column: ""` = main** (default). Anything labeled "sidebar only" above will look broken in main and vice versa — the renderer assumes the column.

## 3. Column routing

```
.activity-band                     (3 panes, fixed-height band above main)
├── band-pane--left    ← column: "left"   (cta-bar, events-upcoming, anything sidebar-ish)
├── band-pane--center  ← layout: activity  (activity-strip — always; nothing else lives here)
└── band-pane--right   ← column: "right"  (sponsors, local-looths)

.rows                              (main content below the band)
└── ← column: "" | "main" | missing  (static, tag-random, video-promo, hero)
```

Logic at `web/index.php:374-385`. Type doesn't gate the column; the renderer trusts whatever `column` you set. Putting a `static` row in `left` will render it but it won't be styled for a narrow column — expect overflow.

## 4. Tag matching: exact slug, case-sensitive

`query.tag` / `query.tags` does **exact match against `tag.slug`** in SQLite (`_rowlib.php:124-132`). Not fuzzy, not LIKE, not stemmed.

- `mandolin` will NOT pick up `mandolins`, `mandolin-build`, `mandolin-repair`.
- WP slugs are usually singular, lowercase, hyphenated — check the actual slug in the tag taxonomy before using it. `wp term list post_tag --search=mandolin` from a WP shell is the fastest way.
- `query.tags: ["a", "b"]` requires content with BOTH tags (AND, not OR).

To approximate fuzziness: use multiple specific tags via `tag-random` with a curated `candidate_pool`, or fall back to FTS5 search (which IS porter-stemmed, but isn't available to rows — only the `/search` API).

## 5. Audience gating

`audience: "both" | "members" | "public"` filters the row out entirely based on viewer state (`index.php:103-107`).

- `both` — everyone
- `members` — anyone with a `wordpress_logged_in_*` cookie
- `public` — anonymous only (use for upsell rows)

This is independent of `tier`. Tier (public/lite/pro) is a per-card overlay (lock badge + JSON-LD paywall signal). Currently all indexed items are `tier: public` because source data isn't populated — gating mechanism works but doesn't bite.

QA preview: `/archive-poc/?as=public|lite|pro` overrides member + tier for the request.

## 6. Saving & deploying config changes

**Editing config.json directly on dev:**
1. Edit `/home/ubuntu/projects/archive-poc/config.json`
2. Reload `/archive-poc/` — changes take effect on next page load (no cache, fresh per request)

**Through the dash:**
1. WP-admin → "Archive POC Config" → edit → Save
2. Dash POSTs to `/archive-api/v0/_config` with X-LG-Config-Secret header
3. `_config.php` validates, re-injects `activity-strip` at position 0 if missing, atomically writes config.json

**Deploying to live (`/srv/archive-poc/`):**
1. Stage on dev: `sudo cp /home/ubuntu/projects/archive-poc/config.json /var/www/dev/.well-known/config.json.txt && sudo chmod 644 /var/www/dev/.well-known/config.json.txt`
2. On live: `sudo curl -fSL --cookie "loothdev_auth=$TOKEN" https://dev.loothgroup.com/.well-known/config.json.txt -o /srv/archive-poc/config.json && sudo chown archive-poc:archive-poc /srv/archive-poc/config.json`
3. Canonical commands: `deploy/LIVE-DEPLOY.md`. **rows.json / config.json deploys are commonly forgotten** — the file changes on dev, looks fine, but live still shows yesterday's layout.

For mu-plugin or `_sync.php` changes: also `sudo redis-cli -n 0 FLUSHDB` (db0 only — db2 is loothtool.com). For CSS/JS: no flush needed, `?v=<mtime>` busts caches automatically.

## 7. Footguns specific to editing this config

1. **`activity-strip` is mandatory.** If you omit it, `_config.php` injects it at position 0. Don't fight this — just include it explicitly in the right spot.

2. **`local-looths` works as a row type but isn't styled for `main`.** Live config dropped it; re-add with `type: "local-looths"`, `layout: "local-looths"`, `column: "right"` to put it back in the right sidebar under sponsors.

3. **Tag-random titles need `{{tag_label}}`.** Without it, both tag-random rows render the literal title. With it, the picked tag's label substitutes in (`_rowlib.php:93`).

4. **`exclude_kinds` is an array, not a string.** `["discussion"]`, not `"discussion"`. Validator flattens scalars but arrays of strings survive.

5. **Per-key overlay = full replacement.** Adding one sponsor means re-listing all of them in config.json. Removing all entries by sending `[]` empties the section (uses no defaults fallback).

6. **3-file helper duplication.** Kind map, tier resolution, thumb resolution, event-date extraction are inlined in `bin/indexer.php`, `bin/backfill.php`, and `deploy/archive-poc-sync.mu-plugin.php`. Doesn't affect config editing, but if a row stops surfacing the right posts after a CPT or taxonomy change, the bug is probably drift between these three files. Update all three.

## 8. Where things live

- **Config:** `config.json` (root) — overlay on `web/defaults.php`
- **Default rows:** `web/defaults.php` — fallback if config.json is missing keys
- **Renderer:** `web/index.php` (page shell + column routing) → `web/_render-main-row.php` (per-layout HTML)
- **Query layer:** `api/v0/_rowlib.php` (`archive_poc_run_row`, tag picking, events query)
- **Save endpoint:** `api/v0/_config.php` (loopback-only + secret-gated)
- **Dash UI (WP side):** `lg-layout-v2/src/ArchivePocDash.php`
- **Handoff for FE inline editor work:** `SESSION-HANDOFF.md`
