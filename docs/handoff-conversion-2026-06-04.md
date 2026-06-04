# Conversion lane — handoff 2026-06-04

Supersedes the loothprint/video state in `handoff-coordinator-2026-06-03.md`. This session
took the managed-CPT → v2-layout conversion from "videos only" to: **videos + loothprints +
loothcuts + documents done, and a working article parser**. All deterministic parsers; no AI.

## State (publish posts; `_lg_layout_v2` = converted)

| CPT | done / total | remaining | parser | notes |
|---|---|---|---|---|
| post-type-videos | 340 / 341 | **1** | `tools/video-parse.php` | only **20210** left (oembed-only, no plain URL) |
| loothprint | 165 / 166 | **1** | `tools/loothprint-parse.php` | only **3666** "Jack Brace part Deux" (no `loothprint_3d_file`) |
| loothcuts | 7 / 7 | 0 | loothprint-parse (CPT-aware) | done |
| document | 4 / 6 | **2** | loothprint-parse (`document` branch) | **46552, 46009** flagged (no PDF/file — inline-content docs) |
| post-imgcap | 12 / 63 | **51** | `tools/article-parse.php` | parser built + validated on 7; **batch not yet run** |
| useful_links | 0 / 39 | 39 | — | not started |
| sponsor-post | 0 / 18 | 18 | — | not started |
| member-benefit | 0 / 6 | 6 | — | not started |

## Per-post loop (unchanged)
`parse (dry-run → /tmp json) → review → wp lg-layout-v2 import --post-id=<id> → _materialize curl → verify`.
Materialize: `curl -s -X POST https://dev.loothgroup.com/archive-api/v0/_materialize --resolve dev.loothgroup.com:443:127.0.0.1 -k -H 'Content-Type: application/json' -d '{"post_id":<id>,"action":"upsert"}'`.
Gate cookie for curl verify: `loothdev_auth=<$loothdev_token>` (non-`/billing` paths need it). Routes: `/video/`, `/article/` (post-imgcap), `/loothprint/`, `/loothcuts/`, `/document/<id>/` (id-based), `/sponsor/`.

## Parsers (all committed)
- **video-parse** (committed earlier, 94de8ac): post_content → header/embed/wysiwyg/chapters/links/footer. Chapters auto-gate via tier (embed in AUTO_GATE_TYPES).
- **loothprint-parse** (e67bf96, ba882e1, 50ba49f, +document branch 5011d43): ACF→blocks. `download` block = file only, **auto-gates from the post tier** (members get file; anon gets `lg-gate-cta--download`, file URL absent). CPT-aware: loothcuts (`loothcut_` prefix, cnc_file, prose in ACF), document (PDF via file_upload/pdf_url). Instructional video embed kept **public** (`gated_tier:"public"`). Recipe: `~/.claude/skills/write-article-v2/recipes/loothprint.md`.
- **article-parse** (5011d43): inline-HTML body → panel prose / section-headings / aspect-placed images. **Image-caption model (Ian's, conclusive):** a *short* (≤400c) prose run immediately preceding an image becomes that image's `image_text` → renders as the **figcaption** (description under the image); the img `alt` (short label) is the **lightbox** caption (engine change: image render `data-lg-caption` ← alt). Longer prose stays a panel. Sequential image numbers (1,2,3… top-to-bottom, incl. pairs). Datelines/bylines stripped from tagline. Links host-shortened. Non-image attachments (stray .mp4) dropped.

## Key fixes this session (committed)
- materializer: collect gallery `image_ids[]` into the blob media map; **defensive srcset filter** (drop size variants whose files don't exist) — fixes the dev-clone broken-`<img>` issue at bake time, so **no per-image `wp media regenerate` needed**.
- parsers: CRLF paragraph normalization; make_clickable + host-shortened link text (no panel overflow).

## ⚠️ Cross-lane relays pending (handed to Ian; not yet actioned)
1. **lg-layout-v2 engine:** (a) mirror the srcset existence-filter canonically in `WpMedia::resolve` (my fix is the dev archive-poc materializer + image render copies only — LIVE/WP-render need the canonical); (b) `overflow-wrap:break-word` on `.lg-wysiwyg`; (c) image render `data-lg-caption ← alt` (committed in the dev standalone copy, needs canonical mirror).
2. **archive (feed/index) lane:** a `document` (47597 "Marketing Club 3-14-25", a PDF deck) is mis-bucketed under "Loothprints" in the member feed — feed type-grouping fix. (Its real video is the separate post-type-videos 48704.)

## Open items / stragglers
- **20210** (video, oembed-only) + **3666** (loothprint, no file) + **46552/46009** (documents, no PDF) — hand-finish.
- **base64-embedded article images:** some articles (e.g. **67638** Erlewine Archive) embed images as base64 data-URIs in post_content → `image_id` null, bloated blob, no srcset. Decide: extract to real attachments vs leave. The caption model still works on them.
- **post-imgcap member-tier gating UNRESOLVED:** articles have no money-shot block, so a `looth-lite` article (e.g. 67638) renders **fully to anon** (no auto-gate). Decision needed before batch: leave articles public, or insert a `paywall` section block (teaser-then-gate) for tiered posts.
- **shorty (shorts, 29)** + banger/freebie-video/etc. — NOT in the managed-CPT route. `shorty` is video-shaped → run `video-parse` + add `shorty` to the nginx CPT alternation in `strangler-archive-poc.conf`. Sysadmin (me/ubuntu), not a relay.

## Next steps (recommended order)
1. Decide article member-gating (above) → then **aggregate dry-run over the 51 post-imgcap** (inline-HTML vs ACF `img_cap` repeater split + flags) → batch.
2. **useful_links (39)** — new simple links-callout parser.
3. sponsor-post (18), member-benefit (6) — small recipes.
4. shorty onboarding (route + video-parse).
5. The ACF `img_cap` repeater article model (second pass) for posts with empty post_content.

## Ops / gotchas
- **Dev box reboots unexpectedly** (twice now; last 6/4 10:38). After a reboot: re-arm mail cap (`iptables OUTPUT DROP 25/465/587` — done), `/tmp` clears (resume batches by re-parsing unconverted; DB persists), rclone R2 mount auto-remounts. See `feedback_dev_reboot_recovery` memory.
- Uploads served from R2 via rclone FUSE (`/var/www/dev/wp-content/uploads -> /mnt/loothgroup-uploads-dev`), NOT local disk.
- Nothing pushed — all local on `main`. Latest: 5011d43.
