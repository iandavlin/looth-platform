---
name: lg-layout-v2
description: Patterns, gotchas, and hard-won rules for the lg-layout-v2 WP plugin. Load this when working on anything under blocks/, src/, or the dash. Captures cascade ordering, columns model, bundle/cache lifecycle, and content-shape choices that bit us during initial development.
---

# lg-layout-v2 patterns & gotchas

A managed-CPT layout engine. Posts are described by a JSON layout in
`_lg_layout_v2` post meta; blocks are dispatched to `blocks/<name>/render.php`
and styled via a generated CSS bundle. Cascade ordering matters — most styling
surprises trace back to one of the rules below.

## 1. CSS cascade order is layer-dictated

Bundle declares layers in this order; values in later layers win over earlier
ones **regardless of selector specificity**:

```
@layer legacy, reset, theme, block-shell, block-defaults, context, dash;
```

- `block-shell` — structural CSS for a block (var consumption, layout)
- `block-defaults` — per-block manifest defaults (and per-variant defaults)
- `context` — context normalization (e.g. `.lg-columns__col > .lg-image` strips chrome)
- `dash` — Global Defaults + per-block overrides from the admin dashboard

**Common bite:** Global Defaults' `:where(.lg-heading, .lg-wysiwyg, …) { --lg-font-size: 16px }` lives in `dash`, and beats every block's `--lg-font-size: 32px` default in `block-defaults` *even though `:where()` has zero specificity* — because dash is a later layer.

If a block needs to keep its own typography (sizes, weights) independent of sitewide body styling, set `"inherits_global": false` in its manifest. That removes the block's selector from the global `:where(...)` list in CssBuilder::dashLayer.

## 2. Per-level / per-state typography → variants, not props

A block declaring a `level` prop (h2/h3/h4) needs **variants** with distinct
CSS, not just an HTML-tag swap. Pattern from `blocks/section-heading/`:

- Manifest: `"variants": { "h2": { "extends": "defaults", "text": { "font-size": "32px" } }, "h3": …, "h4": … }`
- Render: emit modifier class `<h2 class="lg-section-heading lg-section-heading--h2">` so the variant CSS hits.
- Dash automatically shows per-variant sub-panels — authors get per-level controls "for free."

Without the modifier class, all levels share `.lg-heading { --lg-font-size: … }` defaults and look identical.

## 3. Columns: explicit per-column buckets, not round-robin

Data shape (set in stone after the round-robin → bucket refactor):

```json
{ "type": "columns",
  "columns": [
    { "blocks": [child, child] },
    { "blocks": [child] }
  ]
}
```

- Column count derives from `columns.length` (2 or 3 only; render clamps).
- `cols` prop is **gone**. Don't reintroduce it.
- Validator + Pipeline both special-case columns to walk `$b['columns'][i]['blocks']` instead of `$b['blocks']`.
- Nested columns rejected at validation (`depth > 0`).

If MetaBox or any consumer of the layout JSON needs to mutate columns
children, work through the bucket: `$block['columns'][$colIdx]['blocks']`.

## 4. Image vs text in a columns row → object-fit cover

For visual balance when content heights differ (almost always), let the image
fill the column height via `object-fit: cover`:

```css
.lg-columns__col > .lg-image { height: 100%; grid-template-rows: 1fr auto; }
.lg-columns__col > .lg-image .lg-image__image { height: 100%; overflow: hidden; }
.lg-columns__col > .lg-image .lg-image__img { width: 100%; height: 100%; object-fit: cover; }
```

Trade-off: crops the image. Acceptable for editorial photography; not
acceptable for diagrams where edges matter — for those, pull the image out of
columns into a full-width slot.

**Don't try text-fit-to-fill.** CSS has no native "expand font-size to fit
container height." JS solutions exist but are fragile (fight reflow, font
loading, accessibility zoom) — skip.

## 5. After CSS or manifest changes: regenerate + bump epoch

Two state caches that need invalidating, in order:

1. **The CSS bundle** at `assets/lg-layout-v2-bundle.css`:
   ```bash
   sudo -u www-data wp --path=/var/www/dev eval 'LG\LayoutV2\WpAssets::regenerate_bundle();'
   ```
   Without this, browsers serve stale CSS.

2. **The per-post rendered-HTML cache** (only anonymous viewers; logged-in
   render fresh every time). Bump the global epoch:
   ```bash
   sudo -u www-data wp --path=/var/www/dev option update lg_layout_v2_cache_epoch $(date +%s)
   ```
   Without this, anonymous visitors keep seeing yesterday's HTML.

The bundle file's mtime becomes `?ver=` on the stylesheet, so a fresh
regeneration also cache-busts browsers automatically.

**Symptom:** "I made a change, hard-refreshed, see no difference." Either the
bundle wasn't regenerated, or the post's render cache wasn't bumped, or both.

## 6. wpautop is off for v2 renders — emit HTML directly

`WpRenderer::filter_content` strips wpautop, wptexturize, convert_smilies,
convert_chars, and capital_P_dangit from `the_content` when v2 owns the
render. So multi-line tags in render.php, structured markup like Instagram's
blockquote, etc., survive intact.

**Don't write defensive single-line HTML to dodge wpautop.** It's gone.

(Wysiwyg block HTML still flows through wp_kses_post in EditorPickers'
sanitizer — that's the right boundary because it's author-typed text.)

## 7. The metabox uses path arrays, not int slots

MetaBox renders block slots using path arrays like `[2]` (root slot 2) or
`[2, 'columns', 0, 'blocks', 1]` (child 1 of column 0 of root slot 2). Two
helpers compose the rest:

- `path_to_name_prefix($path)` → `"lg_v2_blocks[2][columns][0][blocks][1]"`
- `path_to_action_suffix($path)` → `"2_c0_1"` (drops literal `blocks`, prefixes column indices with `c`)

Action grammar:
- `add_block` / `add_block_{N}_c{C}` / `remove_block_{N}` / `remove_block_{N}_c{C}_{M}`
- `add_column_{N}` / `remove_column_{N}`
- `move_block_{N}_c{F}_{M}_to_c{T}`

Adding a new structural sub-block? Extend `path_to_action_suffix` to handle
its literal segment, add a parser entry in `save()`, and update
`parse_slots_recursive` for the new shape.

## 8. Float containment in wysiwyg

`.lg-wysiwyg` is `display: flow-root` specifically so author-inserted images
with WP alignment classes (`.alignleft` / `.alignright`) float **inside** the
wysiwyg and don't leak into the next block. Don't remove flow-root unless you
have a different plan for float containment.

## 9. Structured repeater props — `array_of_objects`

Props that hold a list of homogeneous rows (callout's `items`, future
FAQ blocks, hidden-links lists, etc.) use the `array_of_objects` prop type
with an `items.props` sub-schema. The engine ships full plumbing — three
surfaces all read from the same manifest:

- **Validator** — type-checks each row's props against the sub-schema, honors per-prop `enum`s. See `Validator::walk` + `typeMatches`.
- **MetaBox** — `render_repeater` emits a row UI (Add / ↑ / ↓ / ×); `icon`-named props get an `Icons::keys()` dropdown with a live SVG preview. `build_block_from_raw` parses rows back, sorts by `__pos`, drops rows where every value equals the manifest default (so a "+ Add row" + Save with nothing typed doesn't persist junk).
- **FE editor** — `openItemsModal` in `lg-fe-editor.js` builds the same UI in a modal, reading current state from a `<script type="application/json" data-lg-<block>-state>` node the renderer emits in editor mode.

Adding a new repeater-driven block:
1. Declare `"<prop>": { "type": "array_of_objects", "items": { "props": { ... } } }` in the manifest.
2. Manifest is sufficient for the metabox — repeater renders automatically.
3. For FE inline editing, `render.php` must emit the state JSON in editor mode AND `doEdit` in `lg-fe-editor.js` needs a branch for your block type (callout pattern is the template).

## 10. Icons palette — single source of truth

Inline SVG glyphs live in `LG\LayoutV2\Icons::PRESETS` and are filterable via
`apply_filters('lg_layout_v2_icon_presets', $set)`. All glyphs share
`viewBox="0 0 24 24"` and inherit color via `currentColor`, so CSS owns size +
color. `Icons::svg($key)` falls back to the `link` glyph for unknown keys.

This palette is the *intended* destination for any new inlined SVG. Post-header /
post-footer still inline their own social icons — that's a planned port (see
2026-05-23 handoff §6.3), not the canonical pattern for new code.

## 11. FE editor state JSON — let the renderer ship the current state

When a block needs a structured editor (modal, repeater, anything beyond
contenteditable), don't fetch current props over REST just to seed the UI.
Have `render.php` emit them as a hidden script node *only* in editor mode:

```php
<?php if ($editorMode): ?>
<script type="application/json" data-lg-<block>-state><?php
    echo json_encode([...], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?></script>
<?php endif; ?>
```

`JSON_HEX_TAG | JSON_HEX_AMP` are mandatory — without them, an item value
containing `</script>` would terminate the parent script tag early. The FE
editor JS reads + parses this with a try/catch and an empty-default fallback.

## 12. HTML-format string props

A `string` prop that carries HTML (e.g. callout's `body`) must declare
`"format": "html"` in its schema entry. Without it:

- MetaBox `build_block_from_raw` strips the tags via `sanitize_textarea_field`.
- FE editor's `wireInlineEditable` round-trips through `innerText`, losing paragraph structure.

With `"format": "html"`, both surfaces switch to `wp_kses_post` /
`innerHTML` respectively. The FE-side detection reads from the localized
`MANIFESTS[type].schema.props[prop].format`, so manifests must be in sync
between save (PHP) and render (JS) — they always are because both come from
`Manifest::get()`.

## 13. Crawler-friendly accordions — use `<details>`, not JS

For collapsible long-form content (transcripts, FAQs, expandable sources),
use the native `<details>` / `<summary>` primitive — never a JS-driven
`display:none` toggle. The content lives in the DOM source either way;
the only thing `<details>` collapses is the visual rendering. Crawlers
index every word regardless of the `open` attribute. JS toggles risk
de-weighting (historically) or outright invisibility on bots that don't
execute scripts.

Pattern (see `blocks/transcript/`):
- Manifest: `body` is `format: "html"`, plus a `label` string and an `open` boolean.
- render.php: emit `<details><summary>…</summary><div>…</div></details>`.
- shell.css: strip the default disclosure marker (`::-webkit-details-marker { display: none }` + `::marker { content: '' }`), draw your own chevron via `::after` and rotate on `[open]`.
- No JS. No fade animations. The browser does the toggle for free.

If you need fancy animation, layer it on top with `<details>` still as the source of truth — but the default no-JS implementation is the right starting point.

## 14. Test fixtures + render-test snapshots

CSS bundle changes propagate to every fixture's `bundle.css` snapshot. After
any change to a block's shell.css or manifest:

```bash
bin/render-test.php --all --update-snapshots
```

This is also a sanity check — if a structural change breaks rendered HTML for
a fixture that shouldn't have changed, the diff is your warning.

## When in doubt

- Read `docs/ARCHITECTURE.md` for the big picture.
- Read `docs/MANIFEST.md` before adding new manifest fields.
- The block manifest is the contract — if behavior doesn't match what the
  manifest says, the bug is usually in `Pipeline.php` or `CssBuilder.php`
  not honoring the manifest, not the other way around.
