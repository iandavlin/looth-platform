# Plan — profile/practice block system (profile 2.0)

Converged design from the coordinator↔Ian design pass (2026-05-29). This is the
brief profile-app builds against. Owner: profile-app (in-lane); the JSON
authoring layer + LLM skill are cross-cutting (coordinator-tracked).

## The model: relational spine + composable storefront blocks

A profile (`/u/`) or practice (`/p/`) = **a fixed canonical spine + a
user-composed block region.** Same shape as a v2 post (structured meta +
freeform block body), but with its OWN block library.

### Spine — relational, canonical, queryable (drives the directory)
NOT palette blocks; the canonical data, rendered fixed, living in columns:
- **Header** ("Me at a glance" for a person; tagline for a practice) — identity,
  avatar, summary line, location, website, socials
- location (geo), practice-**type**, craft (search-fuel), socials, tier (derived)

These power the directory: search + geo + tier + practice-type facet. They
can't be removed/composed away.

### Storefront/showcase — composable, palette-driven, JSON (drives page richness)
The **user-deployable blocks**, added from a palette, drag-drop ordered:
- store hours · portfolio gallery · services menu · commission process ·
  FAQ · testimonials · embeds · workshop showcase …
- Content stored as JSON (a `blocks` region on the profile/practice). Fine to
  be non-relational — nobody filters the directory by "has a gallery."

**The line that makes it safe:** spine = relational (queried); storefront =
JSON blocks (presentation, not queried). Don't let users compose the spine away.

## Decisions locked (2026-05-29)

1. **Independent profile block library** — NOT shared with lg-layout-v2.
   Different domain; profile-app is standalone (not WP). Shared **design tokens**
   (`/srv/lg-shared`) keep both on-brand without sharing block code.
2. **Designed blocks, content-in/styling-fixed** — not free HTML. This is the
   brand-coherence mechanism: users arrange, can't make it ugly.
3. **Block limits** — per-tier caps (free = spine + ~2 storefront blocks; Pro =
   full palette + more slots) and per-type rules (one header/one hours;
   multiple galleries OK). Limits drive the Pro upsell.
4. **pmp = per-BLOCK** public/member/private (decided 2026-05-29, supersedes
   per-field). One visibility per block — unifies the rule across spine +
   storefront, matches the composable model (block = unit of composition AND
   visibility), simplifies the JSON contract. Mixed-visibility items split into
   their own blocks: the header decomposes into **identity** (public) +
   **location** (own block — see below) + **contact** (storefront-side,
   private). Spine + storefront share ONE visibility model even though storage
   differs.

   **Location is the one documented exception** — it needs visibility ×
   **specificity**, not just show/hide (safety-sensitive + it's the geo facet).
   Two precision tiers, each with its own visibility:
   - **Approximate** (city/region) → drives the "near me" facet; gate public|member.
   - **Exact** (full address) → booking/visiting; gate member|private|on-request.

   Proximity search runs on **coarsened (city-centroid) coords** so "near me"
   ranks a member without ever exposing the exact pin — exact lat/lng resolves
   only for permitted viewers. Reverses slice-2.75's precision-drop
   (intentional; visibility × specificity beats either alone). Columns largely
   exist (`location_city/region` = approximate; `postcode/lat/lng` = exact) +
   ~one added visibility field. Every OTHER block stays simple block-level
   show/hide — location is the lone special case, model stays uniform elsewhere.
5. **Profile (`/u/`) and practice (`/p/`) are separate loads**, related via
   links (profile→practices card, practice→staff). No conditional in/out
   headers (that was the over-elaborate ACF design — dropped).
6. **Typed practices** — `practices.type` (repair / build / touring_tech /
   retail / …) drives which storefront blocks/fields are relevant. Additive
   expansion surface. Practice-type is the ONE structured directory facet worth
   keeping (bounded + mandatory-at-creation → always populated).

## pmp defaults — LOCKED (2026-05-29, ratified w/ profile-app)

> **SUPERSEDED on the header by the Phase-0 member-baseline** (see "Block sets +
> pmp baseline" below): the required header now comes in **member**, not public;
> public is opt-up. The relative ordering below still holds (location-exact more
> private than approximate; contact most restricted) — just shifted down one notch
> from a member baseline rather than a public one.

- `identity`/header → **member** (was public — opt up to public)
- location **approximate** → **member** · location **exact** → **private**
- **`contact` = storefront/practice-side only**, not a personal profile field
  (personal email/phone on the header is a privacy footgun).
- Socials store **kind + url only** — block-level pmp supersedes per-row vis.

## Slice-2.75 reversal — INTENTIONAL, settled (don't re-litigate)

Slice 2.75 dropped `location_precision` ("user is the privacy gate via what they
type"). The block-system **deliberately reintroduces** precision as
visibility×specificity (approximate + exact tiers). Rationale: a brick-and-mortar
address is materially more safety-sensitive than a city, and the geo-facet split
(coarse coords for "near me", exact only for permitted viewers) solves the
search-vs-privacy tension 2.75 couldn't. This is settled — future-self, we
already had this argument and chose the richer model on purpose.

## Slice-4 carryover (rides cutover, NOT deferred)

`users.location_address` + its backfill from BB **xprofile field 96**
(address-precision text) land at **slice-4**, alongside the existing
location_city/region snapshot — even though the block UI ships post-cutover.
Why: the data exists now; populating at slice-4 (~30 lines in
`snapshot-location-from-bb.php`) means users land in the new model on cutover
day with no back-pass. Field-96 confirmation on live queued in BATCH-06 (#62-63).

## Block sets + pmp baseline — profile vs practice (2026-05-29, Ian, Phase-0)

**Two entity views, overlapping block sets (some shared, some specific):**
- **Profile** (`/u/`): **profile-header** ("me at a glance") + craft, connect,
  socials, location. The person.
- **Practice** (`/p/`): **practice-header** (name / type / location) + storefront
  (hours / services / gallery), staff roster. The business.
- **Shared blocks** work on both (e.g. gallery, about/text); **specific blocks**
  are entity-only (storefront/hours = practice-only). The composer filters the
  palette by entity = shared + that entity's own.

**Separate headers** — supersedes the single identity block with
`subject: person|practice` toggle (`spec-block-identity-location.md`): split into
**profile-header** + **practice-header** blocks.

**Only the header is REQUIRED.** Everything else is optional/composed — a minimal
profile or practice is just a header; the user adds the rest.

**pmp baseline = MEMBER (supersedes `identity → public` and "practice =
public-leaning").** A new profile/practice comes in **member-only**; the user
opts **up to public** (storefronts will, for findability) or **down to private**,
per block. Product stance: **profiles are members-community by default** — the
public web doesn't see a profile unless the owner opens it. The directory works
because members see members; public visibility is opt-in. (Per-block pmp still
applies — member is just the out-of-box default for the required header.)

**One composer tool, two entity views, member-default pmp, opt-up to public.**

## "View as" toggle — owner previews public vs member (2026-05-29, Ian)

The profile owner gets a live **View as: Public / Member / Me** toggle on their
own profile/practice — a SHIPPED control, not just the mockup's review device.
Flip it to see exactly what each audience sees: Public → the members-gate +
only opted-public blocks; Member → the member-visible blocks; Me → the full
owner view with visibility chips + edit affordances. This is what makes
block-level pmp + the member baseline tangible — set a block's visibility, flip
the toggle, watch the effect. (iter-2's viewer-switch becomes a real product
feature.) Applies to both `/u/` and `/p/`.

## Avatar = single source of profile-image truth (2026-05-29, Ian)

The profile spine owns the avatar for the WHOLE platform — every surface (shared
header, forum threads, archive author banner, post author-header/footer bylines,
directory, profile/practice) renders the SAME image, editable in ONE place.
Full cross-cutting contract: `STRANGLER-COORDINATION.md` → "Avatar /
author-identity — SINGLE SOURCE." profile-2.0's share:
- **Owns** `users.avatar_url` + the image store; profile-app **stores and serves**
  a canonical, stable, versioned per-`user_uuid` URL (not `wp-content`, not
  Gravatar). Surfaces read it via `/whoami` (viewer) + the batch users lookup
  (authors).
- **Editor:** the profile-2.0 avatar upload is the single edit point → writes
  spine + bytes, bumps `avatar_version`, fires identity-purge so mirrors re-pull.
- **Backfill (slice-4):** copy each user's current avatar IMAGE into the store
  (BB-uploaded files literal; Gravatar-only → one-time fetch or initials).
- Universal empty-state = the initials circle.

## FE editing model — sidebar palette (NOT layoutv2's inline-add) (2026-05-29, Ian)

**The profile block system is a SEPARATE system from layoutv2 (Ian, 2026-05-29).**
- **layoutv2 = content** (posts, articles, events/gated CPTs) — inline-add editor,
  **untouched.** Don't go near it.
- **Profile system = profiles + practices** — its own block library, own editor,
  own render. Not layoutv2 reskinned, not coupled.
- **Only shared thing: design tokens** (`/srv/lg-shared`) so both look on-brand.
- May *reference* layoutv2 for general patterns (block render, autosave, JSON
  round-trip) but **no shared code, no coupling.** Standalone in profile-app.

**Add-UX = a sidebar block palette + drag-to-canvas + drag-reorder** — the
page-builder model (Elementor/Webflow), NOT layoutv2's inline-`+` add. Rationale:
profile/practice is "assemble a page from parts," and a persistent palette
surfaces the full block menu (discoverability → adoption → Pro upsell), shows
Pro-locked blocks as standing upsell, and shows per-type limits in place.
- **Block settings** (once placed): lean = the sidebar swaps to a settings panel
  on block-select (dual-mode sidebar, Gutenberg/Elementor style) vs. inline
  gear/pencil. TBD — resolve via mockup.
- **Mobile:** persistent sidebar collapses to a drawer/FAB on phone widths
  (design for it — mobile imminent).
- Same "share pattern/mechanics + tokens, not code/add-UX" philosophy as the
  independent library.

## Storefront blocks carry user-defined content (2026-05-29)

Composable storefront blocks have a per-type **content schema** the user fills
via the editor: gallery = `{title, images[], captions}`; carousel =
`{title, items[]}`; etc. User-defined titles/captions/labels per block. (All
post-cutover, pilot #2+.)

## JSON authoring layer + LLM skill

- A **versioned, validated JSON profile schema** = the authoring/draft contract
  (NOT storage for the spine — that stays relational; the JSON maps INTO the
  tables for spine, and IS the storage for the storefront block region).
- **LLM skill** drafts a profile/practice from existing material (a luthier's
  site, a pasted bio, legacy xprofile/ACF text) → validated JSON → user reviews
  → commits. Attacks onboarding friction; doubles as a migration accelerator.
- Guardrails: validate against the schema; **human approves before publish**
  (real person's data — hallucination risk); conservative pmp defaults (LLM
  never opens private fields); tier_badge derived, never drafted.
- Scope note: the import path largely exists (profile-app's `/me/*` endpoints);
  new work = schema + validator + fan-out + skill. Mirrors `write-article-v2`.

## Tier-gating (falls out of the above)

- **Free** = spine + ~2 basic storefront blocks (about, links). Findable,
  respectable. Everyone's in the directory — gating is on *marketing yourself*,
  not *being found*.
- **Pro** = full storefront palette unlocked + higher block limits + the rich
  `/p/` practice page.

## Sequencing reframe (2026-05-29, Ian: "forward")

**Full profile 2.0 is built dev-complete, then flipped. No dev on live.**
"Post-cutover" anywhere in this doc = wrong word; read it as "later dev-built
increment, still proven before any flip." Profile 2.0 is the **long pole of the
profile side of the cut**. The **SPINE is a pre-cut migration target** (migrate
once into the final model — never into a shape we'll change); the **composable
storefront + FE editor + LLM skill** are dev-built before the flip but don't
gate the data migration (they're user-authored content, not migrated data).
Full marching orders: `marching-orders-profile-2.0.md`.

## Build sequence — two pilots establish the whole pattern

**Pilot 1 — Header block (spine).** ← start here
Establishes: JSON shape ↔ relational mapping ↔ per-field pmp ↔ render ↔
LLM-draftable ↔ person/practice duality. Shared `.idhead` across `/u/` + `/p/`.

```json
{
  "block": "header",
  "subject": "person",                       // "person" | "practice"
  "fields": {
    "display_name":  { "value": "Max Monte",                  "vis": "public" },
    "avatar":        { "value": "…",                          "vis": "public" },
    "at_a_glance":   { "value": "Acoustic builder & repair…", "vis": "public" }, // person summary; practice uses "tagline"
    "location":      { "value": {"city":"Guelph","region":"ON"}, "vis": "member" },
    "website":       { "value": "https://…",                  "vis": "public" },
    "socials":       [ { "kind": "instagram", "url": "…", "vis": "public" } ]
  },
  "tier_badge":    "auto",        // person-only, DERIVED from /whoami — never stored/drafted
  "practice_type": null           // practice-only — repair|build|touring_tech|retail
}
```
Maps to: person → `users.display_name/avatar_url/at_a_glance/location_*/location_visibility`, socials → `profile_socials`; practice → `practices.* + practices.type`.

**Open calls to lock before building the header:**
- pmp defaults: name/avatar/at_a_glance/website/socials = public, location =
  member, contact = private? (lean: yes)
- Is `contact` (email/phone) a header field, or storefront-only? (lean:
  storefront/practice, not personal header)

**Pilot 2 — "Store hours" block (storefront).**
Establishes the deployable-palette pattern: palette add, drag-drop order, JSON
storage, per-type limit, tier-gate, designed render. First user-composed block.

After both pilots, the composable system has its template; remaining blocks are
additive.
