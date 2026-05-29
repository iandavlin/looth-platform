Slice 1.5 of profile-app — editor refactor + ordering + schema endpoint.
Pure FE/UX rework on top of slice one's data plumbing. No new taxonomy yet
(that's slice two). You're on the dev box (claude.loothgroup.com, 50.19.198.38).
Read ~/.claude/CLAUDE.md and /home/ubuntu/projects/CLAUDE.md first if you haven't.

## What slice one left you

Working editor at /profile/edit with About / Location / Socials, JWT auth via
the `looth_id` cookie, viewer-role aware read endpoint, server-side section
visibility logic in `Profile::renderForViewer`. All data plumbing works. The
problem is the UX: today's editor is **form-shaped** (always-visible inputs).
We want **live-look + modal** instead — the page renders as the published
profile, edit affordances are subtle pencils, clicking one opens a focused
modal that saves and re-renders in place.

Read profile-app/SESSION-HANDOFF.md first. Then study the new mockup:
**`/var/www/dev/mockups/profile-v2.html`** (live at
https://dev.loothgroup.com/mockups/profile-v2.html). That mockup is the
design target for slice 1.5 — the live-look pattern, the inactive section
state, the drag-to-reorder, the segmented viewer-role toggle, the
header restructure. Match its *feel*, not its bytes.

## What slice 1.5 ships

Same data, same endpoints, new UX, plus onboarding plumbing:

1. Editor renders as the published profile would, not as a form.
2. Header is a live composite block: name + location + socials, each with
   its own per-field pencil that opens its own focused modal.
3. About is **inactive by default** for never-claimed profiles. Click → modal
   → save → activates. Header is always active (mandatory).
4. Sections are drag-reorderable; order persists per-user.
5. Viewer-role toggle is a segmented control (Me / Member / Public), always
   visible top-right. Switching re-renders everything with the proper
   visibility/precision filtering and hides edit affordances for non-Me views.
6. New public endpoint: `GET /profile-api/v0/schema` returns the full
   profile schema as machine-readable JSON. Foundation for the future
   "skill-pack download" (LLM-fill flow); not yet bundled in slice 1.5.
7. **Onboarding plumbing** so users can actually find the editor:
   public `/u/<slug>` read-only route, `/u/<slug>/edit` alias, explicit
   `POST /me/claim` endpoint, "My Profile" link in WP's logged-in user menu,
   first-visit auto-prompt that opens the About modal after a fresh claim.

## Architectural decisions already made (do NOT re-litigate)

- **Live-look + modal is the editor's permanent UX pattern.** Every future
  section (credentials, practices, skills, instruments) will use the same
  pattern: rendered live with a corner pencil → modal → save → in-place
  re-render. No form-mode editor will be re-introduced.

- **Header is mandatory, sections are opt-in.** A fresh profile shows only
  the header populated (name + location come from slice-zero backfill).
  All other sections render in an "inactive" visual state (dashed border,
  muted text, "+ Add your X" prompt). Click the inactive section anywhere
  → opens its modal. Saving the modal lazy-creates the `profile_sections`
  row and the section becomes active. Reverting an active section to
  inactive (deactivating) is OUT of slice 1.5 — data preservation +
  toggle UI lands later.

- **Section order is per-user, persisted, drag-reorderable.** Add
  `profiles.section_order text[]` (Postgres native array). On drop, JS
  PATCHes a new order list; server validates and stores. Renderer walks
  `section_order` in order, then renders any sections present in the DB
  but not in the order list at the end (forward-compat for new section
  types we ship between writes).

- **Viewer-role toggle is a segmented control: Me / Member / Public.**
  Friend resolves to Member for now (the friend graph is later). When a
  non-Me role is selected, ALL pencils and grips hide; the page renders
  with the appropriate visibility/precision filter applied via the
  existing `Profile::renderForViewer`. Toggle state does NOT persist —
  reload returns to Me view.

- **Per-field header pencils.** Name, location, and socials each get
  their own modal scoped to that one block, not a single "edit header"
  modal that smashes them all together. Avatar gets a pencil too but
  opens a "coming later" placeholder modal — avatar upload is deferred.

- **Schema endpoint is public, cacheable, version-stamped, and
  designed with import/export in mind.** `GET /profile-api/v0/schema?v=1`
  returns a JSON document describing all current sections, their fields,
  accepted values, visibility options. Add a `version` field at the root.
  Even though import/export endpoints don't ship in slice 1.5, the shape
  of this doc should be such that a future `/me/export` payload can
  cleanly conform to it and a future `/me/import` payload can validate
  against it. Concretely: use stable string slugs for keys (NOT integer
  ids), keep section payload shapes flat and serializable, avoid coupling
  schema to internal table column names.

- **No taxonomy yet.** Slice 1.5 ships only the refactor and the schema
  endpoint. Catalogs (instruments, skills, credentials, scenes) and the
  header highlights picker arrive in slice two.

- **Lazy claim becomes explicit.** Slice one's behavior of silently
  creating a `profiles` row on first GET to `/profile/edit` is replaced
  by an explicit `POST /profile-api/v0/me/claim` action. The editor
  page now does the following on GET:
  - If the current user has no `profiles` row → render a "Start your
    profile" interstitial inside the editor shell, single big button
    that POSTs to `/me/claim`, then reloads into the active editor with
    the About modal auto-opened as the first nudge.
  - If they have a `profiles` row → render the editor as normal.
  This makes claim a tracked event (`claimed_via` column) and gives the
  user a clear "I just started this" moment instead of accidental
  database state changes from idle navigation.

- **Public read URL is `/u/<slug>`.** Renders the same live-look page in
  read-only mode (no pencils, no grips, no role toggle). Slug resolution:
  try vanity slug first, fall back to numeric `users.id` (the default
  set in slice zero). Uses the existing
  `GET /profile-api/v0/user/<uuid>` data — this is just the SSR wrapper
  for an anon viewer.

- **`/u/<slug>/edit` is an alias.** 302 to `/profile/edit` if the slug
  resolves to the current user. 403 otherwise. Useful for "click 'edit'
  from your own public view" and for the eventual link-from-profile-card
  pattern in directories.

- **WP user menu gets a "My Profile" item.** The existing `profile-auth`
  mu-plugin gets one new hook (`admin_bar_menu` or BB equivalent) that
  adds a top-level "My Profile" link pointing at `/profile/edit`. This
  is the single most discoverable entry point; nothing else in slice 1.5
  matters if users can't find this.

## What to build

1. **Schema migration** (sql/0003_section_order_and_claim.sql):
   - `ALTER TABLE profiles ADD COLUMN section_order text[] NOT NULL DEFAULT '{}'`
   - `ALTER TABLE profiles ADD COLUMN claimed_via text` (nullable; values:
     `'menu' | 'banner' | 'public_view' | 'direct' | 'import' | ...`)
   - That's it. Everything else is FE/SSR work.

2. **Refactor `web/edit.php`** to live-look + modal pattern:
   - Header restructure: avatar (with pencil), then a right-column block
     with name + per-field pencil, meta row with location + per-field
     pencil + member-since + live indicator, socials chip row with
     per-block pencil.
   - Sections render as cards with a heading row (grip handle, title,
     visibility chip, pencil) and a body that's either the rendered
     content or a `+ Add your X` placeholder, controlled by a
     `data-active="0|1"` attribute.
   - Inactive section cards are dashed-border, muted, click-anywhere
     opens the modal.
   - Each modal is a `<div class="backdrop">` with a `<div class="modal">`
     containing only the fields for that one section.
   - Top bar: segmented viewer-role control (Me / Member / Public) on
     the right.
   - Drag handle (`⋮⋮` grip) on each section header; native HTML5 DnD
     with a drop indicator. On drop, PATCH the new order.

3. **Refactor `web/edit.js`**:
   - Drop form-mode JS. Each pencil opens its corresponding modal.
   - Modal save handlers POST to the existing endpoints
     (`PATCH /me/about`, `PUT /me/location`, `PUT /me/socials`).
     On 200, close modal and re-render the affected section in place
     from the response payload — no full page reload.
   - Drag handlers PATCH `/profile-api/v0/me/section-order` with the new
     order array.
   - Viewer-role buttons call `GET /me/preview?as=member|public` and
     swap rendered content in place (or, simpler, re-fetch and re-render
     all sections). Me view requires no fetch — uses the editor's
     master copy.

4. **Claim endpoint:** `POST /profile-api/v0/me/claim`
   - Body: `{ via?: "menu"|"banner"|"public_view"|"direct" }`
     (validate against allowed values; default `direct`)
   - If profiles row exists → 200 with `{claimed: false, existing: true}` (idempotent)
   - Else → INSERT profiles row with `claimed_via = via`, return `{claimed: true}`
   - Editor's first-visit interstitial POSTs to this then reloads with
     `?just_claimed=1` so the JS auto-opens the About modal.

5. **Public view route** at `/u/<slug>`:
   - SSR via the same render pipeline as `/profile/edit` but forced to
     `viewer-role: public` (or member if the requester is authed).
     No pencils, no grips, no role toggle.
   - Slug resolution: lookup by vanity slug first, then by `users.id`
     (numeric). 404 if neither matches.

6. **Edit alias route** at `/u/<slug>/edit`:
   - Resolve slug to user. If matches current authed user → 302 to
     `/profile/edit`. Else 403.

7. **WP user menu addition** in the existing `profile-auth` mu-plugin:
   - Hook `admin_bar_menu` (and the BB-side equivalent if present) to
     add a top-level "My Profile" item linking to
     `https://dev.loothgroup.com/profile/edit`. Visible to logged-in
     users only. Don't gate on whether profile is claimed — the editor
     handles the claim interstitial.

8. **New endpoint:** `PATCH /profile-api/v0/me/section-order`
   - Body: `{ order: ["about","credentials","practices",...] }`
   - Validates each entry is a known section key
   - Writes to `profiles.section_order`
   - Returns the saved order

9. **New endpoint:** `GET /profile-api/v0/schema`
   - Public (no auth required)
   - Returns a JSON document of this shape (extend as you build):
     ```json
     {
       "version": 1,
       "sections": {
         "about": {
           "kind": "text",
           "fields": [
             {"name": "text", "type": "markdown", "max_chars": 2000},
             {"name": "visibility", "type": "enum",
              "values": ["public","members","private"], "default": "members"}
           ],
           "default_visibility": "members",
           "mandatory": false
         },
         "location": { ... },
         "socials": {
           "kind": "list",
           "item_schema": {
             "kind": "enum",
             "values": ["instagram","youtube","bandcamp","web","email",
                        "phone","x","tiktok","facebook","patreon"]
           },
           ...
         }
       },
       "viewer_roles": ["me","friend","member","public"],
       "visibility_options": ["public","members","private"],
       "location_precision_options": ["address","city","region","country","hidden"]
     }
     ```
   - Cache-Control: public, max-age=60. Cheap to regenerate on schema bumps.

10. **Re-purpose Profile::renderForViewer** if needed so the editor's
   client-side re-render can reuse the same visibility logic via a
   thin JS helper. Don't duplicate the rules — server is authoritative,
   JS mirrors only for instant UI feedback.

## What to NOT do in slice 1.5

- No taxonomy / catalogs (slice two)
- No header highlights picker (slice two — needs catalogs to exist)
- No practices (slice three)
- No skill-pack zip endpoint (slice two)
- No avatar upload — avatar pencil opens a placeholder modal
- No "deactivate section" affordance — only activation in slice 1.5
- No friend viewer-role (resolves to member; placeholder for later)
- No live deploy. Dev only.

## Validate before declaring done

- Pick a WP user who has NO profiles row. Log in as them, visit
  /profile/edit — confirm the "Start your profile" interstitial appears
  (not the editor). Click the button — confirm the row gets created with
  `claimed_via='direct'` and the editor reloads with About modal auto-open.
- Visit /profile/edit logged in as a real user — confirm it renders as a
  live-looking page, not a form. Pencils only appear on hover and only
  in Me view.
- Click each header pencil (name / location / socials) — each opens its
  own modal scoped to that block. Save closes modal + re-renders that
  block.
- Drag a section to a new position; reload page; order persists.
- Switch viewer-role to "Public" — pencils hide, hidden sections dim with
  the "hidden from this viewer" treatment, location coarsens to its
  public precision.
- Visit `/u/<your-id>` in an incognito window — confirm public read-only
  view renders, no edit affordances. Visit `/u/<your-id>/edit` while
  logged in as that user — confirm 302 to /profile/edit. As a different
  user — confirm 403.
- Confirm the "My Profile" link appears in the WP admin bar when logged
  in on dev.loothgroup.com.
- `curl https://dev.loothgroup.com/profile-api/v0/schema | jq .version`
  returns 1 (or whatever the current schema version is).

## Deliverables

- /profile/edit on dev fully refactored to live-look + modals
- `POST /profile-api/v0/me/claim` + first-visit interstitial flow
- `/u/<slug>` public read-only route working
- `/u/<slug>/edit` alias working
- `PATCH /profile-api/v0/me/section-order` working
- `GET /profile-api/v0/schema` live and returning useful JSON
- "My Profile" link in WP admin bar via updated `profile-auth` mu-plugin
- Updated SESSION-HANDOFF.md with current state, key files, what's next
- The 5-line "what surprised you" summary — especially anything about
  the inactive-section pattern, drag-reorder edge cases (touch devices?
  empty profiles?), the schema-endpoint shape choices, or claim-flow
  edge cases (concurrent claims, claim during interstitial reload)

Don't ask permission to start. Read the slice-one handoff, study the
mockup, then build. Ask only if you hit a real ambiguity that the
mockup + slice-one code don't resolve.
