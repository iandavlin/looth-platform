<?php
declare(strict_types=1);

/**
 * Block render for profile-2.0 (/u/). The establishing pattern: header-as-ceiling
 * gate + the profile-header (identity) block rendered from the spine.
 *
 * This turn implements the profile-header block + the gate decision end-to-end
 * (write-only; the coordinator wires u.php → looth_render_profile_blocks after the
 * schema is applied). Remaining blocks (location, craft, …) follow this shape.
 *
 * Render contract (Block::gateDecision):
 *   'private' → render nothing (owner-only).
 *   'gate'    → members-only join/sign-in interstitial, stop.
 *   'render'  → header block, then each composed block where
 *               Block::canSee(role, headerVis, blockVis).
 * Owner ('me') additionally gets per-block vis chips + the View-as toggle (later).
 *
 * Markup mirrors the mock at /var/www/dev/mockups/profile-block.html.
 */

use Looth\ProfileApp\Block;

// Block isn't in config.php's require list yet (config.php is shared w/
// shim-replacement — coordinator should add it there for consistency).
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';

if (!function_exists('looth_h')) {
    function looth_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('looth_initials')) {
    function looth_initials(string $name): string {
        $name = trim($name) ?: '?';
        $p = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        return strtoupper(substr(($p[0] ?? '?'), 0, 1) . (isset($p[1]) ? substr($p[1], 0, 1) : ''));
    }
}

/**
 * Tiny inline-SVG preview of what a block looks like on the profile — shown on each caddy
 * chip so the owner can see what they're adding. Shapes only (the chip CSS frames them).
 */
function looth_caddy_preview(string $key): string
{
    $svg = static fn(string $inner): string =>
        '<svg viewBox="0 0 120 48" width="100%" height="100%" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">' . $inner . '</svg>';
    switch ($key) {
        case 'about':
            return $svg('<rect x="12" y="11" width="96" height="5" rx="2.5" fill="#9fb295"/><rect x="12" y="23" width="96" height="5" rx="2.5" fill="#c8d3c0"/><rect x="12" y="35" width="60" height="5" rx="2.5" fill="#c8d3c0"/>');
        case 'location':
            return $svg('<line x1="0" y1="16" x2="120" y2="32" stroke="#cdd8c5" stroke-width="3"/><line x1="44" y1="0" x2="70" y2="48" stroke="#cdd8c5" stroke-width="3"/><path d="M60 36c-5-7-9-11-9-15a9 9 0 0 1 18 0c0 4-4 8-9 15z" fill="#6f8a63"/><circle cx="60" cy="21" r="3.2" fill="#fff"/>');
        case 'craft':
        case 'skills':
            return $svg('<rect x="10" y="10" width="44" height="12" rx="6" fill="#9fb295"/><rect x="60" y="10" width="34" height="12" rx="6" fill="#c8d3c0"/><rect x="10" y="27" width="30" height="12" rx="6" fill="#c8d3c0"/><rect x="46" y="27" width="48" height="12" rx="6" fill="#9fb295"/>');
        case 'services':
            return $svg('<rect x="12" y="10" width="96" height="9" rx="3" fill="#9fb295"/><rect x="12" y="24" width="70" height="7" rx="3" fill="#c8d3c0"/><circle cx="100" cy="27" r="6" fill="none" stroke="#9fb295" stroke-width="2"/><path d="M97 27l2 2 4-4" stroke="#9fb295" stroke-width="2" fill="none"/><rect x="12" y="36" width="54" height="6" rx="3" fill="#dbe2d4"/>');
        case 'instruments':
            return $svg('<circle cx="34" cy="30" r="12" fill="none" stroke="#6f8a63" stroke-width="3"/><circle cx="34" cy="30" r="4" fill="#9fb295"/><rect x="44" y="8" width="5" height="26" rx="2.5" fill="#9fb295" transform="rotate(-18 46 20)"/><rect x="70" y="12" width="34" height="6" rx="3" fill="#c8d3c0"/><rect x="70" y="24" width="26" height="6" rx="3" fill="#dbe2d4"/>');
        case 'music':
            return $svg('<path d="M44 12v20" stroke="#6f8a63" stroke-width="3"/><path d="M76 8v20" stroke="#6f8a63" stroke-width="3"/><path d="M44 12l32-4" stroke="#6f8a63" stroke-width="3"/><circle cx="40" cy="34" r="6" fill="#9fb295"/><circle cx="72" cy="30" r="6" fill="#9fb295"/>');
        case 'gallery':
            return $svg('<rect x="12" y="8" width="44" height="15" rx="2" fill="#9fb295"/><rect x="62" y="8" width="46" height="15" rx="2" fill="#c8d3c0"/><rect x="12" y="27" width="46" height="13" rx="2" fill="#c8d3c0"/><rect x="64" y="27" width="44" height="13" rx="2" fill="#9fb295"/><circle cx="24" cy="14" r="2.6" fill="#fff"/>');
        case 'connect':
            return $svg('<circle cx="46" cy="24" r="11" fill="#9fb295" stroke="#fff" stroke-width="2"/><circle cx="60" cy="24" r="11" fill="#bcae8f" stroke="#fff" stroke-width="2"/><circle cx="74" cy="24" r="11" fill="#c8d3c0" stroke="#fff" stroke-width="2"/>');
        case 'socials':
            return $svg('<rect x="12" y="11" width="96" height="11" rx="5.5" fill="#fff" stroke="#d7ddcf"/><rect x="16" y="14" width="15" height="5" rx="2.5" fill="#9fb295"/><rect x="35" y="15" width="44" height="3" rx="1.5" fill="#cdd8c5"/><rect x="12" y="27" width="96" height="11" rx="5.5" fill="#fff" stroke="#d7ddcf"/><rect x="16" y="30" width="15" height="5" rx="2.5" fill="#bcae8f"/><rect x="35" y="31" width="36" height="3" rx="1.5" fill="#cdd8c5"/>');
        case 'resume':
            return $svg('<rect x="44" y="6" width="32" height="36" rx="3" fill="#fff" stroke="#9fb295" stroke-width="2"/><rect x="50" y="13" width="20" height="3" rx="1.5" fill="#c8d3c0"/><rect x="50" y="20" width="20" height="2" rx="1" fill="#cdd8c5"/><rect x="50" y="25" width="14" height="2" rx="1" fill="#cdd8c5"/><rect x="50" y="30" width="20" height="2" rx="1" fill="#cdd8c5"/><rect x="50" y="35" width="10" height="2" rx="1" fill="#cdd8c5"/>');
    }
    // Freeform blocks all share one preview shape — small heading + 3 body lines.
    if (str_starts_with($key, 'freeform:')) {
        return $svg('<rect x="12" y="10" width="56" height="6" rx="3" fill="#9fb295"/><rect x="12" y="22" width="96" height="4" rx="2" fill="#c8d3c0"/><rect x="12" y="30" width="96" height="4" rx="2" fill="#c8d3c0"/><rect x="12" y="38" width="64" height="4" rx="2" fill="#dbe2d4"/>');
    }
    return '';
}

/**
 * Render a /u/ profile's blocks for a viewer.
 * @param int    $userId  spine user id (subject)
 * @param string $role    'me'|'member'|'friend'|'public'
 * @param string|null $tierBadge derived tier label from /whoami (e.g. 'Pro'); null = none
 */
function looth_render_profile_blocks(int $userId, string $role, ?string $tierBadge = null, string $headerActions = '', ?int $viewerUserId = null): void
{
    $headerVis = Block::headerCeiling($userId);                 // DB literal
    switch (Block::gateDecision($role, $headerVis)) {
        case 'private': return;                                 // nothing renders
        case 'gate':    looth_render_members_gate($userId); return;
    }

    $header = Block::loadHeader($userId);
    if ($header === null) { http_response_code(404); echo 'not found'; return; }
    looth_render_header_block($header, $role, $headerVis, $tierBadge, $headerActions, $userId);

    // Body blocks render in the owner's chosen order (Block::profileLayout); the header is
    // pinned above. Each key maps to its existing renderer — order is the only thing the
    // layout drives in Phase 1 (presence still per-renderer). data-block on each <section>
    // is the DOM order-source the owner's drag-reorder collects from.
    $renderers = [
        'about'       => static fn() => looth_render_about_block($userId, $role, $headerVis),
        'location'    => static fn() => looth_render_location_block($userId, $role, $headerVis),
        'skills'      => static fn() => looth_render_catalog_block($userId, $role, $headerVis, 'skills'),
        'services'    => static fn() => looth_render_catalog_block($userId, $role, $headerVis, 'services'),
        'instruments' => static fn() => looth_render_catalog_block($userId, $role, $headerVis, 'instruments'),
        'music'       => static fn() => looth_render_catalog_block($userId, $role, $headerVis, 'music'),
        'gallery'     => static fn() => looth_render_gallery_block($userId, $role, $headerVis),
        'resume'      => static fn() => looth_render_resume_block($userId, $role, $headerVis),
        'connect'     => static fn() => looth_render_connect_block($userId, $role, $headerVis, $viewerUserId),
        'socials'     => static fn() => looth_render_socials_block($userId, $role, $headerVis),
    ];
    foreach (Block::profileLayout($userId) as $key) {
        if (isset($renderers[$key])) {
            ($renderers[$key])();
        } elseif (Block::isFreeformKey($key)) {
            // Freeform blocks are per-instance — dispatch by key prefix, not by static map.
            looth_render_freeform_block($userId, $key, $role, $headerVis);
        }
    }

    // Owner-only: "+ New section" affordance. Creates a fresh freeform block
    // (POST /me/freeform) and reloads so the new empty section appears at the
    // end of the layout, ready for inline title + body editing.
    if ($role === 'me') {
        $count = count(Block::listFreeformKeys($userId));
        $cap   = Block::FREEFORM_MAX_PER_USER;
        echo '<div class="lg-freeform-add"' . ($count >= $cap ? ' data-at-cap' : '') . '>';
        echo '<button type="button" class="lg-freeform-add__btn" id="lg-freeform-add"'
           . ($count >= $cap ? ' disabled aria-disabled="true" title="Section limit reached (' . (int)$cap . ')"' : ' title="Add a custom titled section"')
           . '>＋ New section</button>';
        echo '</div>';
    }

    // TODO(next increments): practices block — same shape.
}

/**
 * The gallery block — image grid in the app-owned media store. Shared (profile +
 * practice). Owner uploads/removes; block-level pmp on profile_sections key='gallery'.
 */
function looth_render_gallery_block(int $userId, string $role, string $headerVis): void
{
    $g       = Block::loadGallery($userId);
    $images  = $g['images'];
    $isOwner = ($role === 'me');
    if (!$images && !$isOwner) return;
    if (!Block::canSee($role, $headerVis, Block::denormalizeVis((string)$g['vis'])) && !$isOwner) return;

    $title  = trim((string)($g['title'] ?? ''));
    $mode   = (string)($g['display_mode'] ?? Block::GALLERY_DISPLAY_DEFAULT);
    $isCar  = ($mode === 'carousel') && count($images) > 0;

    echo '<section class="block lg-block lg-block--gallery" data-block="gallery">';
    echo '<h3 class="lg-bh">';
    if ($isOwner) {
        // Editable block title — reuses the generic lg-edit inline editor (PUT me-gallery {title}).
        $hasT = $title !== '';
        echo '<span class="lg-edit lg-btitle' . ($hasT ? '' : ' lg-edit--empty') . '"'
           . ' data-edit-field="title" data-edit-url="/profile-api/v0/me/gallery" data-edit-method="PUT"'
           . ' data-edit-type="text" data-edit-placeholder="Gallery">' . looth_h($hasT ? $title : '') . '</span>';
        echo ' ' . looth_pmp_control('gallery', (string)$g['vis'], $headerVis);
    } else {
        echo looth_h($title !== '' ? $title : 'Gallery');
    }
    echo '</h3>';

    // Owner: grid/carousel mode toggle. PUT /me/gallery {display_mode}.
    if ($isOwner) {
        echo '<div class="lg-gmode" role="group" aria-label="Gallery display mode">';
        foreach (['grid' => 'Grid', 'carousel' => 'Carousel'] as $m => $label) {
            $pressed = ($mode === $m) ? 'true' : 'false';
            echo '<button type="button" class="lg-gmode__btn" data-mode="' . $m . '" aria-pressed="' . $pressed . '">' . $label . '</button>';
        }
        echo '</div>';
    }

    $wrapClass = 'lg-gallery'
        . ($isOwner ? ' lg-gallery--edit' : '')
        . ($isCar ? ' lg-gallery--carousel' : ' lg-gallery--grid');
    echo '<div class="' . $wrapClass . '" id="lg-gallery">';

    if ($isCar) {
        echo '<div class="lg-carousel" data-carousel>';
        if (count($images) > 1) {
            echo '<button type="button" class="lg-carousel__nav lg-carousel__nav--prev" aria-label="Previous photo">‹</button>';
            echo '<button type="button" class="lg-carousel__nav lg-carousel__nav--next" aria-label="Next photo">›</button>';
        }
        echo '<div class="lg-carousel__viewport"><div class="lg-carousel__track">';
    }

    foreach ($images as $im) {
        $url = (string)($im['url'] ?? '');
        $cap = (string)($im['caption'] ?? '');
        echo '<figure class="lg-gphoto" data-url="' . looth_h($url) . '">'
           . '<img src="' . looth_h($url) . '" alt="' . looth_h($cap) . '" loading="lazy" decoding="async">';
        if ($isOwner) echo '<button type="button" class="lg-gphoto__rm" aria-label="Remove">×</button>';
        if ($cap !== '') echo '<figcaption>' . looth_h($cap) . '</figcaption>';
        echo '</figure>';
    }

    if ($isCar) {
        echo '</div></div>'; // track + viewport
        if (count($images) > 1) {
            echo '<div class="lg-carousel__dots" role="tablist" aria-label="Photo navigation">';
            for ($i = 0, $n = count($images); $i < $n; $i++) {
                $cur = ($i === 0) ? 'true' : 'false';
                echo '<button type="button" class="lg-carousel__dot" role="tab" aria-current="' . $cur . '" aria-label="Photo ' . ($i + 1) . '"></button>';
            }
            echo '</div>';
        }
        echo '</div>'; // .lg-carousel
    }

    if ($isOwner) echo '<button type="button" class="lg-gphoto__add" id="lg-gallery-add">＋ Add photos</button>';
    echo '</div></section>';
}

/**
 * The about block — free text. Shared (profile + practice). Owner edits inline
 * (multiline); block-level pmp on profile_sections key='about'.
 */
function looth_render_about_block(int $userId, string $role, string $headerVis): void
{
    $ab      = Block::loadAbout($userId);
    $text    = (string)$ab['text'];
    $isOwner = ($role === 'me');
    if ($text === '' && !$isOwner) return;
    if (!Block::canSee($role, $headerVis, Block::denormalizeVis((string)$ab['vis'])) && !$isOwner) return;

    echo '<section class="block lg-block lg-block--about" data-block="about">';
    echo '<h3 class="lg-bh">About';
    if ($isOwner) echo ' ' . looth_pmp_control('about', (string)$ab['vis'], $headerVis);
    echo '</h3>';
    if ($isOwner) {
        $has = $text !== '';
        echo '<div class="lg-about lg-edit' . ($has ? '' : ' lg-edit--empty') . '"'
           . ' data-edit-field="text" data-edit-url="/profile-api/v0/me/about" data-edit-method="PATCH"'
           . ' data-edit-type="textarea" data-edit-multiline="1" data-edit-placeholder="Write a bit about your work…">'
           . ($has ? looth_h($text) : 'Write a bit about your work…') . '</div>';
    } else {
        echo '<div class="lg-about">' . nl2br(looth_h($text)) . '</div>';
    }
    echo '</section>';
}

/**
 * Freeform titled block — user-set title + free-text body. Same pre-wrap render
 * model as About; differs by being multi-instance (multiple blocks per profile,
 * keyed by `freeform:<8hex>`) and by carrying its own title.
 */
function looth_render_freeform_block(int $userId, string $key, string $role, string $headerVis): void
{
    $ff = Block::loadFreeform($userId, $key);
    if ($ff === null) return;
    $title   = (string)$ff['title'];
    $body    = (string)$ff['body'];
    $isOwner = ($role === 'me');

    if ($title === '' && $body === '' && !$isOwner) return;
    if (!Block::canSee($role, $headerVis, Block::denormalizeVis((string)$ff['vis'])) && !$isOwner) return;

    $kAttr   = looth_h($key);
    $sectionUrl = '/profile-api/v0/me/freeform?key=' . rawurlencode($key);

    echo '<section class="block lg-block lg-block--freeform" data-block="' . $kAttr . '" data-freeform-key="' . $kAttr . '">';
    echo '<h3 class="lg-bh">';
    if ($isOwner) {
        $hasT = $title !== '';
        echo '<span class="lg-edit lg-btitle' . ($hasT ? '' : ' lg-edit--empty') . '"'
           . ' data-edit-field="title" data-edit-url="' . looth_h($sectionUrl) . '" data-edit-method="PUT"'
           . ' data-edit-type="text" data-edit-placeholder="Section title (e.g. Experience, Education)">'
           . looth_h($hasT ? $title : '') . '</span>';
        echo ' ' . looth_pmp_control($key, (string)$ff['vis'], $headerVis);
        // Note: the standard lg-block__rm (✕) injected by u.php removes from the
        // LAYOUT (data preserved → block goes back to the caddy). Permanent
        // delete happens from the caddy chip's trash button.
    } else {
        echo looth_h($title !== '' ? $title : 'Section');
    }
    echo '</h3>';

    if ($isOwner) {
        $hasB = $body !== '';
        echo '<div class="lg-freeform lg-edit' . ($hasB ? '' : ' lg-edit--empty') . '"'
           . ' data-edit-field="body" data-edit-url="' . looth_h($sectionUrl) . '" data-edit-method="PUT"'
           . ' data-edit-type="textarea" data-edit-multiline="1" data-edit-placeholder="Write something…">'
           . ($hasB ? looth_h($body) : 'Write something…') . '</div>';
    } else {
        echo '<div class="lg-freeform">' . nl2br(looth_h($body)) . '</div>';
    }
    echo '</section>';
}
/**
 * The resume block — single PDF (versioned). Per-resume visibility lives on
 * users.resume_visibility (NOT a profile_sections row — resume is a singleton
 * credential). Owner sees upload/replace + delete + pmp; visitor sees a
 * "Download resume (PDF)" button when the resume is visible to them.
 */
function looth_render_resume_block(int $userId, string $role, string $headerVis): void
{
    $r       = Block::loadResume($userId);
    if ($r === null) return;
    $url     = $r['url'] ?? null;
    $isOwner = ($role === 'me');

    if (!$url && !$isOwner) return;
    if (!Block::canSee($role, $headerVis, Block::denormalizeVis((string)$r['vis'])) && !$isOwner) return;

    echo '<section class="block lg-block lg-block--resume" data-block="resume">';
    echo '<h3 class="lg-bh">Resume';
    if ($isOwner) echo ' ' . looth_pmp_control('resume', (string)$r['vis'], $headerVis);
    echo '</h3>';

    if ($url) {
        // Both owner + visitor see the download. SVG = download glyph.
        echo '<div class="lg-resume">';
        echo '<a class="lg-resume__a" href="' . looth_h((string)$url) . '" target="_blank" rel="noopener" download>'
           . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none"'
           . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
           . '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'
           . '<span>Download resume (PDF)</span></a>';
        if ($isOwner) {
            echo '<button type="button" class="lg-resume__set" id="lg-resume-set" title="Replace resume">Replace</button>';
            echo '<button type="button" class="lg-resume__rm" id="lg-resume-rm" title="Remove resume" aria-label="Remove resume">×</button>';
        }
        echo '</div>';
    } elseif ($isOwner) {
        echo '<div class="lg-resume lg-resume--empty">';
        echo '<button type="button" class="lg-resume__set lg-resume__set--add" id="lg-resume-set"'
           . ' title="Upload a resume PDF (≤ 10 MB)">＋ Upload resume (PDF)</button>';
        echo '<p class="lg-resume__hint">Adds a download link. PDF only, up to 10 MB.</p>';
        echo '</div>';
    }
    echo '</section>';
}

/**
 * The craft block — instruments / skills / highlights as search-fuel chips, one
 * block-level vis, ceiling-capped via Block::canSee.
 */
/**
 * Generic catalog-chip block (Skills / Services / Instruments / Music). Owner gets removable
 * chips + an "+ Add" that opens the catalog search-multiselect (looth-cat-picker JS in u.php);
 * visitors see read-only chips. Block vis on profile_sections key=$key. data-block/data-kind
 * tell the picker which block + catalog it drives.
 */
function looth_render_catalog_block(int $userId, string $role, string $headerVis, string $key): void
{
    $block = Block::loadCatalogBlock($userId, $key);
    if ($block === null) return;
    $items   = $block['items'];
    $isOwner = ($role === 'me');
    $label   = Block::LAYOUT_BLOCKS[$key]['label'] ?? ucfirst($key);
    $kind    = Block::CATALOG_BLOCKS[$key]['kind'] ?? $key;

    if (!$items && !$isOwner) return;                                  // empty + visitor → no block
    if (!Block::canSee($role, $headerVis, Block::denormalizeVis((string)$block['vis'])) && !$isOwner) return;

    echo '<section class="block lg-block lg-block--' . looth_h($key) . '" data-block="' . looth_h($key) . '">';
    echo '<h3 class="lg-bh">' . looth_h($label);
    if ($isOwner) echo ' ' . looth_pmp_control($key, (string)$block['vis'], $headerVis);
    echo '</h3>';

    if ($isOwner) {
        echo '<div class="lg-chips lg-cat-edit" data-block="' . looth_h($key) . '" data-kind="' . looth_h($kind) . '">';
        foreach ($items as $it) {
            echo '<span class="lg-chip lg-chip--edit" data-id="' . (int)$it['id'] . '">'
               . looth_h((string)$it['name']) . '<button type="button" class="lg-chip__rm" aria-label="Remove">×</button></span>';
        }
        echo '<button type="button" class="lg-link__add lg-cat-add">+ Add ' . looth_h(strtolower($label)) . '</button>';
        echo '</div>';
    } else {
        echo '<div class="lg-chips">';
        foreach ($items as $it) echo '<span class="lg-chip">' . looth_h((string)$it['name']) . '</span>';
        echo '</div>';
    }
    echo '</section>';
}

function looth_render_craft_block(int $userId, string $role, string $headerVis): void
{
    $craft = Block::loadCraft($userId);
    if ($craft === null) return;
    $f       = $craft['fields'];
    $skills  = $f['skills']      ?? [];
    $insts   = $f['instruments'] ?? [];
    $isOwner = ($role === 'me');

    if (!$skills && !$insts && !$isOwner) return;          // empty craft → no block for visitors
    if (!Block::canSee($role, $headerVis, Block::denormalizeVis((string)$craft['vis'])) && !$isOwner) return;

    echo '<section class="block lg-block lg-block--craft" data-block="craft">';
    echo '<h3 class="lg-bh">Craft';
    if ($isOwner) echo ' ' . looth_pmp_control('craft', (string)$craft['vis'], $headerVis);
    echo '</h3>';

    if ($isOwner) {
        echo '<div class="lg-chips lg-craft-edit" id="lg-craft-edit">';
        foreach ($skills as $s) {
            echo '<span class="lg-chip lg-chip--edit" data-type="skill" data-id="' . (int)($s['id'] ?? 0) . '">'
               . looth_h((string)($s['name'] ?? '')) . '<button type="button" class="lg-chip__rm" aria-label="Remove">×</button></span>';
        }
        foreach ($insts as $i) {
            echo '<span class="lg-chip lg-chip--edit" data-type="instrument" data-id="' . (int)($i['id'] ?? 0) . '">'
               . looth_h((string)($i['name'] ?? '')) . '<button type="button" class="lg-chip__rm" aria-label="Remove">×</button></span>';
        }
        echo '<button type="button" class="lg-link__add" id="lg-craft-add">+ Add skill / instrument</button>';
        echo '</div>';
    } else {
        echo '<div class="lg-chips">';
        foreach ($skills as $s) echo '<span class="lg-chip">' . looth_h((string)($s['name'] ?? '')) . '</span>';
        foreach ($insts  as $i) echo '<span class="lg-chip">' . looth_h((string)($i['name'] ?? '')) . '</span>';
        echo '</div>';
    }
    echo '</section>';
}

/**
 * The socials / links block — website + platform links, one block-level vis,
 * ceiling-capped. Sole location for social links (header inline row dropped).
 */
/**
 * Build an ABSOLUTE outbound URL for a stored social handle. Handles are stored cleaned
 * (no scheme, no leading @), so emitting the bare value as an href makes the browser
 * resolve it SAME-SITE (e.g. instagram "ianhatesguitars" → /u/ianhatesguitars). Always
 * expand to the real platform URL here. Returns '' for an empty value.
 */
/**
 * Inline SVG glyph for a given socials kind. Used by the header links rail and
 * (later) the visitor socrow. Lucide-style stroke icons (MIT) for big brands;
 * neutral fallbacks for niche kinds (patreon/linktree/bandcamp). Returns the
 * full <svg> element ready to drop into a button. 18×18 viewBox; size via CSS.
 */
function looth_social_icon(string $kind): string
{
    // Path data per kind. All paths designed for viewBox="0 0 24 24" + stroke fill.
    static $paths = [
        'web'       => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18z"/>',
        'email'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'phone'     => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r=".9" fill="currentColor"/>',
        'x'         => '<path d="M4 4l16 16M20 4 4 20"/>',
        'youtube'   => '<path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.42a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58 2.78 2.78 0 0 0 1.94 2C5.12 20 12 20 12 20s6.88 0 8.6-.42a2.78 2.78 0 0 0 1.94-2A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58z"/><path d="M10 9v6l5-3z" fill="currentColor"/>',
        'facebook'  => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
        'tiktok'    => '<path d="M9 12a4 4 0 1 0 4 4V4a5 5 0 0 0 5 5"/>',
        'patreon'   => '<circle cx="9" cy="11" r="6"/><line x1="18" y1="3" x2="18" y2="21"/>',
        'linktree'  => '<path d="M12 3v18"/><path d="m5 8 7-5 7 5"/><path d="m5 14 7 5 7-5"/>',
        'bandcamp'  => '<path d="M4 18l4-12h12l-4 12z"/>',
    ];
    $p = $paths[$kind] ?? '<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>';   // fallback = generic link
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18"'
        . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true">' . $p . '</svg>';
}

function looth_social_url(string $kind, string $value): string
{
    $v = trim($value);
    if ($v === '') return '';
    if ($kind === 'email') return 'mailto:' . $v;
    if ($kind === 'phone') return 'tel:' . preg_replace('/[^\d+]/', '', $v);
    if (preg_match('#^https?://#i', $v)) return $v;                 // already absolute (web, or pasted URL)
    $h = ltrim($v, '@/');
    switch ($kind) {
        case 'web':       return 'https://' . $h;
        case 'instagram': return 'https://instagram.com/' . $h;
        case 'x':         return 'https://x.com/' . $h;
        case 'youtube':   return 'https://youtube.com/@' . $h;
        case 'facebook':  return 'https://facebook.com/' . $h;
        case 'tiktok':    return 'https://tiktok.com/@' . $h;
        case 'patreon':   return 'https://patreon.com/' . $h;
        case 'linktree':  return 'https://linktr.ee/' . $h;
        case 'bandcamp':  return strpos($h, '.') !== false ? 'https://' . $h : 'https://' . $h . '.bandcamp.com';
        default:          return 'https://' . $h;
    }
}

/** One editable link row (owner). data-value = raw stored value → round-trips to me-socials. */
function looth_link_row(string $kind, string $value): string
{
    return '<div class="lg-link" draggable="true" data-kind="' . looth_h($kind) . '" data-value="' . looth_h($value) . '">'
         . '<span class="lg-link__grip" aria-hidden="true">⠿</span>'
         . '<span class="lg-link__kind">' . looth_h($kind) . '</span>'
         . '<span class="lg-link__val">' . looth_h(preg_replace('#^https?://#i', '', $value)) . '</span>'
         . '<button type="button" class="lg-link__rm" aria-label="Remove">×</button></div>';
}

function looth_render_socials_block(int $userId, string $role, string $headerVis): void
{
    $soc = Block::loadSocials($userId);
    if ($soc === null) return;
    $ordered = $soc['fields']['ordered'] ?? [];   // every link in stored order (incl. web) → reorderable
    $isOwner = ($role === 'me');

    if (!$ordered && !$isOwner) return;                    // empty → no block for visitors
    if (!Block::canSee($role, $headerVis, Block::denormalizeVis((string)$soc['vis'])) && !$isOwner) return;

    echo '<section class="block lg-block lg-block--socials" data-block="socials">';
    echo '<h3 class="lg-bh">Links';
    if ($isOwner) echo ' ' . looth_pmp_control('socials', (string)$soc['vis'], $headerVis);
    echo '</h3>';

    if ($isOwner) {
        // Editable list in stored order — drag to reorder, × to remove, + to add.
        echo '<div class="lg-links lg-links--edit" id="lg-links-edit">';
        foreach ($ordered as $l) {
            $url = (string)($l['url'] ?? '');
            if ($url !== '') echo looth_link_row((string)($l['kind'] ?? ''), $url);
        }
        echo '<button type="button" class="lg-link__add" id="lg-link-add">+ Add link</button>';
        echo '</div>';
    } else {
        echo '<div class="lg-socrow">';
        foreach ($ordered as $l) {
            $kind = (string)($l['kind'] ?? '');
            $url  = (string)($l['url'] ?? '');
            if ($url === '') continue;
            $href = looth_social_url($kind, $url);
            if ($href === '') continue;
            if ($kind === 'web') {
                $label = preg_replace('#^https?://#i', '', $url);
                echo '<a class="lg-socrow__a" href="' . looth_h($href) . '" rel="me noopener" target="_blank" title="website">'
                   . looth_h($label) . ' ↗</a>';
            } else {
                echo '<a class="lg-socrow__a" href="' . looth_h($href) . '" rel="me noopener" target="_blank" title="' . looth_h($kind) . '">'
                   . looth_h(strtoupper(substr($kind, 0, 2))) . '</a>';
            }
        }
        echo '</div>';
    }
    echo '</section>';
}

/**
 * The connect block — the person's connections surface (count + preview avatars +
 * mutuals for a visitor + the owner's pending-inbox hint). Built on the social-layer
 * Connections backend via Block::loadConnect. Block-level pmp, ceiling-capped. The
 * Connect/Message *actions* live in the header slot — this is the list/count surface.
 */
function looth_render_connect_block(int $userId, string $role, string $headerVis, ?int $viewerUserId = null): void
{
    $c = Block::loadConnect($userId, $viewerUserId);
    if ($c === null) return;
    $f         = $c['fields'];
    $count     = (int)($f['count'] ?? 0);
    $isOwner   = ($role === 'me');
    $pendingIn = (int)($f['pending_in'] ?? 0);

    if ($count === 0 && !$isOwner) return;                                  // empty + visitor → no block
    if (!Block::canSee($role, $headerVis, Block::denormalizeVis((string)$c['vis'])) && !$isOwner) return;

    echo '<section class="block lg-block lg-block--connect" data-block="connect">';
    echo '<h3 class="lg-bh">Connections';
    if ($count > 0) echo ' <span class="lg-connect__count">' . $count . '</span>';
    if ($isOwner)   echo ' ' . looth_pmp_control('connect', (string)$c['vis'], $headerVis);
    echo '</h3>';

    if ($isOwner && $pendingIn > 0) {
        echo '<a class="lg-connect__pending" href="/profile/edit#connections">'
           . $pendingIn . ' pending request' . ($pendingIn === 1 ? '' : 's') . ' →</a>';
    }

    $mutuals = $f['mutuals'] ?? [];
    if ($mutuals) {
        $n = count($mutuals);
        echo '<p class="lg-connect__mutual">🤝 ' . $n . ' mutual connection' . ($n === 1 ? '' : 's') . '</p>';
    }

    $people = $f['connections'] ?? [];
    if ($people) {
        echo '<div class="lg-connect__grid">';
        foreach ($people as $p) {
            $slug = (string)($p['slug'] ?? '');
            $name = (string)($p['name'] ?? 'Member');
            $av   = $p['avatar'] ?? null;
            echo '<a class="lg-connect__person" href="/u/' . looth_h(rawurlencode($slug)) . '" title="' . looth_h($name) . '">'
               . '<span class="lg-connect__av">';
            if ($av) echo '<img src="' . looth_h((string)$av) . '" alt="' . looth_h($name) . '" width="44" height="44">';
            else     echo looth_h(looth_initials($name));
            echo '</span></a>';
        }
        echo '</div>';
    } elseif ($isOwner) {
        echo '<p class="lg-connect__empty">No connections yet — Connect with members from their profiles.</p>';
    }

    echo '</section>';
}

/** Owner-only per-block/tier visibility chip (vis already normalized to 'member'). */
function looth_vchip(string $visUi): string
{
    return '<span class="lg-vchip lg-vchip--' . looth_h($visUi) . '">' . looth_h(ucfirst($visUi)) . '</span>';
}

/** DB-literal → human label for a pmp value. */
function looth_pmp_label(string $visDb): string
{
    return ['public' => 'Public', 'members' => 'Member', 'private' => 'Private', 'on_request' => 'On request'][$visDb]
        ?? ucfirst($visDb);
}

/**
 * Owner-only INTERACTIVE pmp control (Me view). Renders the visibility chip as a
 * <button> carrying the block id, its stored vis, and the header ceiling — the
 * JS in u.php opens a menu and persists via the existing /me endpoints. Server
 * stays the source of truth (validation + the gate); this is just the affordance.
 *
 * @param string $block      'header'|'craft'|'socials'|'location-approx'|'location-exact'
 * @param string $visNorm    the block's stored vis, NORMALIZED ('member')
 * @param string $ceilingDb  header ceiling as DB literal; '' for the header itself (no cap)
 */
function looth_pmp_control(string $block, string $visNorm, string $ceilingDb): string
{
    $visDb = Block::denormalizeVis($visNorm);                       // back to DB literal
    $css   = Block::normalizeVis($visDb);                           // 'member' for the CSS class
    $capped = $ceilingDb !== '' && Block::effectiveVisibility($ceilingDb, $visDb) !== $visDb;

    $title = 'Change who can see this';
    if ($capped) {
        $eff = Block::effectiveVisibility($ceilingDb, $visDb);
        $title = 'Your header is ' . looth_pmp_label($ceilingDb) . '-only — viewers see this as ' . looth_pmp_label($eff);
    }

    return '<button type="button" class="lg-vchip lg-pmp lg-vchip--' . looth_h($css) . ($capped ? ' lg-pmp--capped' : '') . '"'
         . ' data-pmp-block="' . looth_h($block) . '"'
         . ' data-pmp-vis="' . looth_h($visDb) . '"'
         . ' data-pmp-ceiling="' . looth_h($ceilingDb) . '"'
         . ' aria-haspopup="true" title="' . looth_h($title) . '">'
         . looth_h(looth_pmp_label($visDb))
         . ' <span class="lg-pmp__caret" aria-hidden="true">' . ($capped ? '⚠▾' : '▾') . '</span></button>';
}

/** One precision-picker button (Members see / Public sees). */
function looth_prec_control(string $audience, string $value): string
{
    $label = ['private' => 'Private', 'state' => 'State', 'city' => 'City', 'street' => 'Street address'][$value] ?? ucfirst($value);
    return '<button type="button" class="lg-prec" data-prec-aud="' . looth_h($audience) . '" data-prec="' . looth_h($value) . '"'
         . ' title="What ' . looth_h($audience) . ' see of your location">'
         . looth_h($label) . ' <span class="lg-pmp__caret" aria-hidden="true">▾</span></button>';
}

/**
 * The location block — Ian's model: ONE address; the display precision follows the
 * AUDIENCE. members_precision / public_precision (private|state|city|street) decide
 * what a member vs the public sees; the owner always sees street + sets both knobs.
 * One map, plotted at the viewer's precision. Header ceiling still gates upstream.
 */
function looth_render_location_block(int $userId, string $role, string $headerVis): void
{
    $loc = Block::loadLocation($userId);
    if ($loc === null) return;
    $isOwner = ($role === 'me');
    $has     = !empty($loc['has']);
    if (!$has && !$isOwner) return;                                           // empty + visitor → no block

    // Precision for THIS viewer.
    if ($isOwner)               $prec = 'street';
    elseif ($role === 'public') $prec = (string)$loc['public_precision'];
    else                        $prec = (string)$loc['members_precision'];   // member / friend

    $disp = $has ? Block::locationDisplay($loc['place'], $prec) : null;
    if ($disp === null && !$isOwner) return;                                  // private for this audience

    echo '<section class="block lg-block lg-block--location" data-block="location">';
    echo '<h3 class="lg-bh">Location</h3>';

    if ($disp !== null && $disp['text'] !== '') {
        echo '<div class="lg-loc__line">📍 ' . looth_h((string)$disp['text']) . '</div>';
    }
    if ($disp !== null && $disp['lat'] !== null) {
        echo '<div class="lg-loc__map" data-kind="' . looth_h((string)$disp['kind']) . '"'
           . ' data-zoom="' . (int)$disp['zoom'] . '"'
           . ' data-lat="' . looth_h((string)$disp['lat']) . '" data-lng="' . looth_h((string)$disp['lng']) . '"></div>';
    }

    // Owner controls: change the actual location (search) + the two audience knobs.
    if ($isOwner) {
        if (!$has) {
            echo '<p class="lg-loc__empty">No location set — add yours so members can find you on the map.</p>';
        }
        echo '<div class="lg-loc__edit" id="lg-loc-edit">'
           . '<button type="button" class="lg-link__add lg-loc__change">'
           . ($has ? '✎ Change location' : '＋ Set your location') . '</button></div>';
        if ($has) {
            echo '<div class="lg-loc__aud">'
               . '<span class="lg-loc__audrow"><span class="lg-loc__audlabel">👥 Members see</span> '
               . looth_prec_control('members', (string)$loc['members_precision']) . '</span>'
               . '<span class="lg-loc__audrow"><span class="lg-loc__audlabel">🌐 Public sees</span> '
               . looth_prec_control('public', (string)$loc['public_precision']) . '</span>'
               . '</div>';
        }
    }

    echo '</section>';
}

/** The profile-header (identity) block — the author-identity card. */
function looth_render_header_block(array $header, string $role, string $headerVis, ?string $tierBadge, string $headerActions = '', int $userId = 0): void
{
    $f       = $header['fields'];
    $name    = (string)($f['display_name'] ?? 'Member');
    $avatar  = $f['avatar'] ?? null;
    $glance  = (string)($f['at_a_glance'] ?? '');
    $banner  = $f['banner'] ?? null;
    $visUi   = Block::normalizeVis($headerVis);
    $isOwner = ($role === 'me');

    $sectionClass = 'block lg-block lg-block--header'
        . (($banner || $isOwner) ? ' lg-block--header--has-banner' : '');
    echo '<section class="' . $sectionClass . '" data-block="profile-header">';

    if ($isOwner) {
        // The header IS the ceiling → no cap on itself ('' ceiling).
        echo looth_pmp_control('header', $visUi, '');
    }

    // Banner strip (optional). Owner sees an upload/remove control either way;
    // visitor sees nothing when there's no banner. Sits ABOVE the identity row,
    // INSIDE the header card so the header-as-ceiling rule covers it.
    if ($banner || $isOwner) {
        echo '<div class="lg-banner' . ($banner ? '' : ' lg-banner--empty') . '" data-banner>';
        if ($banner) {
            echo '<img class="lg-banner__img" src="' . looth_h((string)$banner) . '" alt="" loading="lazy" decoding="async">';
        }
        if ($isOwner) {
            // POST /me/banner (multipart); DELETE /me/banner.
            $label = $banner ? 'Change banner' : '+ Add banner';
            echo '<button type="button" class="lg-banner__set" id="lg-banner-set" aria-label="' . looth_h($label) . '"'
               . ' title="' . looth_h($label) . '">'
               . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16"'
               . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
               . '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/>'
               . '<path d="m21 15-5-5L5 21"/></svg>'
               . '<span>' . looth_h($label) . '</span></button>';
            if ($banner) {
                echo '<button type="button" class="lg-banner__rm" id="lg-banner-rm" aria-label="Remove banner" title="Remove banner">×</button>';
            }
        }
        echo '</div>';
    }

    echo '<div class="lg-idrow">';
    echo '<div class="lg-idrow__pic">';
    if ($avatar) {
        echo '<img src="' . looth_h((string)$avatar) . '" alt="' . looth_h($name) . '" width="96" height="96">';
    } else {
        echo looth_h(looth_initials($name));
    }
    if ($isOwner) echo '<button class="lg-idrow__cam" type="button" aria-label="Change avatar">📷</button>';
    echo '</div>';

    echo '<div class="lg-idrow__body">';
    echo '<h1 class="lg-idrow__name">';
    if ($isOwner) {
        // click-to-edit → PATCH /me/name {display_name}
        echo '<span class="lg-edit" data-edit-field="display_name" data-edit-url="/profile-api/v0/me/name"'
           . ' data-edit-method="PATCH" data-edit-type="text">' . looth_h($name) . '</span>';
    } else {
        echo looth_h($name);
    }
    if ($tierBadge) echo ' <span class="lg-tierpill">' . looth_h($tierBadge) . '</span>';
    echo '</h1>';
    if ($isOwner) {
        // click-to-edit (even when empty → placeholder) → PATCH /me/header {at_a_glance}
        $hasG = $glance !== '';
        echo '<p class="lg-idrow__glance lg-edit' . ($hasG ? '' : ' lg-edit--empty') . '"'
           . ' data-edit-field="at_a_glance" data-edit-url="/profile-api/v0/me/header" data-edit-method="PATCH"'
           . ' data-edit-type="text" data-edit-placeholder="Add a one-line bio…">'
           . ($hasG ? looth_h($glance) : 'Add a one-line bio…') . '</p>';
    } elseif ($glance !== '') {
        echo '<p class="lg-idrow__glance">' . looth_h($glance) . '</p>';
    }
    echo '</div></div>';                                   // close __body + idrow

    // Header status lights (availability widgets). Owner can toggle/remove each + add more.
    $lights = $userId ? Block::loadHeaderLights($userId) : [];
    $avail  = ($isOwner && $userId) ? Block::availableLights($userId) : [];
    if ($lights || $avail) {
        echo '<div class="lg-lights"' . ($isOwner ? ' data-lights-edit' : '') . '>';
        foreach ($lights as $l) {
            echo '<span class="lg-light lg-light--' . looth_h($l['tone']) . '" data-key="' . looth_h($l['key']) . '" data-state="' . looth_h($l['state']) . '"'
               . ($isOwner ? ' role="button" tabindex="0" title="Click to toggle"' : '') . '>'
               . '<span class="lg-light__dot"></span><span class="lg-light__label">' . looth_h($l['label']) . '</span>';
            if ($isOwner) echo '<button type="button" class="lg-light__rm" aria-label="Remove status">×</button>';
            echo '</span>';
        }
        if ($avail) echo '<button type="button" class="lg-light-add" id="lg-light-add">+ Status</button>';
        echo '</div>';
    }

    // Header links rail — iconified social/external links, surfaced UP from the
    // dedicated socials block per Ian's brief (links live in the header). The
    // socials block stays as the canonical inline editor; owner edits via the
    // pencil here which jumps to that block.
    if ($userId) {
        $soc       = Block::loadSocials($userId);
        $linkOrder = ($soc && isset($soc['fields']['ordered'])) ? (array)$soc['fields']['ordered'] : [];
        $visible   = [];
        foreach ($linkOrder as $l) {
            $kind = (string)($l['kind'] ?? '');
            $url  = (string)($l['url'] ?? '');
            if ($kind === '' || $url === '') continue;
            $href = looth_social_url($kind, $url);
            if ($href === '') continue;
            $visible[] = ['kind' => $kind, 'href' => $href, 'raw' => $url];
        }
        if ($visible || $isOwner) {
            echo '<div class="lg-hlinks"' . ($isOwner ? ' data-hlinks-owner' : '') . '>';
            foreach ($visible as $v) {
                $title = $v['kind'] === 'web'
                    ? preg_replace('#^https?://#i', '', $v['raw'])
                    : ($v['kind'] === 'email' || $v['kind'] === 'phone' ? $v['raw'] : ucfirst($v['kind']));
                echo '<a class="lg-hlinks__a" href="' . looth_h($v['href']) . '"'
                   . ' rel="me noopener" target="_blank" title="' . looth_h((string)$title) . '"'
                   . ' aria-label="' . looth_h(ucfirst($v['kind'])) . '">'
                   . looth_social_icon($v['kind']) . '</a>';
            }
            if ($isOwner) {
                // Pencil → jumps to the socials block (the inline editor lives there).
                echo '<button type="button" class="lg-hlinks__edit" data-hlinks-edit'
                   . ' aria-label="Edit links" title="Edit links">'
                   . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="15" height="15"'
                   . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                   . '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>'
                   . '</button>';
            }
            echo '</div>';
        }
    }

    // Social actions slot (Connect / Message) — server-rendered widget; empty for
    // owner/self. Sits below the identity row, inside the header card.
    if ($headerActions !== '') echo $headerActions;
    echo '</section>';
}

/** Members-only interstitial — shown when a member-ceiling profile is hit logged-out. */
function looth_render_members_gate(int $userId): void
{
    echo '<div class="lg-gate">'
       . '<div class="lg-gate__lock">🔒</div>'
       . '<h2>This profile is members-only</h2>'
       . '<p>Profiles on Looth are a members community by default. Sign in to see more — or join to get your own.</p>'
       . '<div class="lg-gate__cta"><a class="lg-gate__join" href="/lgjoin/">Join Looth</a>'
       . '<a class="lg-gate__signin" href="/wp-login.php">Sign in</a></div>'
       . '</div>';
}

/* ==================== PRACTICE (/p/) blocks ==================== */

/**
 * Render a /p/ practice's blocks for a viewer — parallel to
 * looth_render_profile_blocks(). practice-header is the required, ceiling block;
 * storefront blocks (hours/services/staff) come in later increments.
 * @param string $role 'me'|'member'|'friend'|'public'
 */
function looth_render_practice_blocks(int $practiceId, string $role, ?string $tierBadge = null): void
{
    $headerVis = Block::practiceHeaderCeiling($practiceId);     // DB literal
    switch (Block::gateDecision($role, $headerVis)) {
        case 'private': return;                                 // owner-only
        case 'gate':    looth_render_practice_gate(); return;
    }
    $h = Block::loadPracticeHeader($practiceId);
    if ($h === null) { http_response_code(404); echo 'not found'; return; }
    looth_render_practice_header_block($h, $role, $headerVis, $tierBadge);
    // TODO(next): practice storefront blocks — same ceiling-capped shape.
}

/** The practice-header (identity) block — name / type / tagline / location / website / owner avatar. */
function looth_render_practice_header_block(array $header, string $role, string $headerVis, ?string $tierBadge): void
{
    $f       = $header['fields'];
    $name    = (string)($f['name'] ?? 'Practice');
    $type    = (string)($f['type'] ?? '');
    $tagline = (string)($f['tagline'] ?? '');
    $website = $f['website'] ?? null;
    $avatar  = $f['avatar'] ?? null;
    $loc     = trim(implode(', ', array_filter([(string)($f['city'] ?? ''), (string)($f['region'] ?? '')])));
    $isOwner = ($role === 'me');

    echo '<section class="block lg-block lg-block--practice-header" data-block="practice-header">';
    if ($isOwner) echo looth_pmp_control('practice-header', Block::normalizeVis($headerVis), '');

    echo '<div class="lg-idrow">';
    echo '<div class="lg-idrow__pic">';
    if ($avatar) echo '<img src="' . looth_h((string)$avatar) . '" alt="' . looth_h($name) . '" width="96" height="96">';
    else echo looth_h(looth_initials($name));
    echo '</div>';

    echo '<div class="lg-idrow__body">';
    echo '<h1 class="lg-idrow__name">' . looth_h($name);
    if ($type !== '') echo ' <span class="lg-ptype">' . looth_h(ucwords(str_replace('_', ' ', $type))) . '</span>';
    if ($tierBadge) echo ' <span class="lg-tierpill">' . looth_h($tierBadge) . '</span>';
    echo '</h1>';
    if ($tagline !== '') echo '<p class="lg-idrow__glance">' . looth_h($tagline) . '</p>';
    if ($loc !== '') echo '<div class="lg-loc__line" style="margin-top:8px">📍 ' . looth_h($loc) . '</div>';
    if ($website) {
        $label = preg_replace('#^https?://#i', '', (string)$website);
        echo '<a class="lg-idrow__web" href="' . looth_h((string)$website) . '" rel="me noopener" target="_blank">' . looth_h($label) . ' ↗</a>';
    }
    echo '</div></div></section>';
}

/** Members-only interstitial for a member-ceiling practice hit logged-out. */
function looth_render_practice_gate(): void
{
    echo '<div class="lg-gate">'
       . '<div class="lg-gate__lock">🔒</div>'
       . '<h2>This practice is members-only</h2>'
       . '<p>Sign in to see this practice — or join Looth to list your own.</p>'
       . '<div class="lg-gate__cta"><a class="lg-gate__join" href="/lgjoin/">Join Looth</a>'
       . '<a class="lg-gate__signin" href="/wp-login.php">Sign in</a></div>'
       . '</div>';
}
