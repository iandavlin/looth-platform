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
    looth_render_header_block($header, $role, $headerVis, $tierBadge, $headerActions);

    // about (free-text intro) — shared block.
    looth_render_about_block($userId, $role, $headerVis);

    // increment 2: the location block (two-tier, ceiling-capped per tier).
    looth_render_location_block($userId, $role, $headerVis);

    // increment 3: craft (search-fuel) + socials/links blocks.
    looth_render_craft_block($userId, $role, $headerVis);
    // connect block (built on the social-layer Connections backend).
    looth_render_connect_block($userId, $role, $headerVis, $viewerUserId);
    looth_render_socials_block($userId, $role, $headerVis);

    // TODO(next increments): practices block — same shape.
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
 * The craft block — instruments / skills / highlights as search-fuel chips, one
 * block-level vis, ceiling-capped via Block::canSee.
 */
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
/** One editable link row (owner). data-value = raw stored value → round-trips to me-socials. */
function looth_link_row(string $kind, string $value): string
{
    return '<div class="lg-link" data-kind="' . looth_h($kind) . '" data-value="' . looth_h($value) . '">'
         . '<span class="lg-link__kind">' . looth_h($kind) . '</span>'
         . '<span class="lg-link__val">' . looth_h(preg_replace('#^https?://#i', '', $value)) . '</span>'
         . '<button type="button" class="lg-link__rm" aria-label="Remove">×</button></div>';
}

function looth_render_socials_block(int $userId, string $role, string $headerVis): void
{
    $soc = Block::loadSocials($userId);
    if ($soc === null) return;
    $website = $soc['fields']['website'] ?? null;
    $links   = $soc['fields']['links']   ?? [];
    $isOwner = ($role === 'me');

    if (!$website && !$links && !$isOwner) return;         // empty → no block for visitors
    if (!Block::canSee($role, $headerVis, Block::denormalizeVis((string)$soc['vis'])) && !$isOwner) return;

    echo '<section class="block lg-block lg-block--socials" data-block="socials">';
    echo '<h3 class="lg-bh">Links';
    if ($isOwner) echo ' ' . looth_pmp_control('socials', (string)$soc['vis'], $headerVis);
    echo '</h3>';

    if ($isOwner) {
        // Editable list: website (kind=web) first, then the other links, + Add.
        echo '<div class="lg-links" id="lg-links-edit">';
        if ($website) echo looth_link_row('web', (string)$website);
        foreach ($links as $l) {
            $url = (string)($l['url'] ?? '');
            if ($url !== '') echo looth_link_row((string)($l['kind'] ?? ''), $url);
        }
        echo '<button type="button" class="lg-link__add" id="lg-link-add">+ Add link</button>';
        echo '</div>';
    } else {
        echo '<div class="lg-socrow">';
        if ($website) {
            $label = preg_replace('#^https?://#i', '', (string)$website);
            echo '<a class="lg-socrow__a" href="' . looth_h((string)$website) . '" rel="me noopener" target="_blank" title="website">'
               . looth_h($label) . ' ↗</a>';
        }
        foreach ($links as $l) {
            $kind = (string)($l['kind'] ?? '');
            $url  = (string)($l['url'] ?? '');
            if ($url === '') continue;
            echo '<a class="lg-socrow__a" href="' . looth_h($url) . '" rel="me noopener" target="_blank" title="' . looth_h($kind) . '">'
               . looth_h(strtoupper(substr($kind, 0, 2))) . '</a>';
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
    if ($loc === null || empty($loc['has'])) return;
    $isOwner = ($role === 'me');

    // Precision for THIS viewer.
    if ($isOwner)               $prec = 'street';
    elseif ($role === 'public') $prec = (string)$loc['public_precision'];
    else                        $prec = (string)$loc['members_precision'];   // member / friend

    $disp = Block::locationDisplay($loc['place'], $prec);
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

    // Owner controls: two audience knobs.
    if ($isOwner) {
        echo '<div class="lg-loc__aud">'
           . '<span class="lg-loc__audrow"><span class="lg-loc__audlabel">👥 Members see</span> '
           . looth_prec_control('members', (string)$loc['members_precision']) . '</span>'
           . '<span class="lg-loc__audrow"><span class="lg-loc__audlabel">🌐 Public sees</span> '
           . looth_prec_control('public', (string)$loc['public_precision']) . '</span>'
           . '</div>';
    }

    echo '</section>';
}

/** The profile-header (identity) block — the author-identity card. */
function looth_render_header_block(array $header, string $role, string $headerVis, ?string $tierBadge, string $headerActions = ''): void
{
    $f       = $header['fields'];
    $name    = (string)($f['display_name'] ?? 'Member');
    $avatar  = $f['avatar'] ?? null;
    $glance  = (string)($f['at_a_glance'] ?? '');
    $visUi   = Block::normalizeVis($headerVis);
    $isOwner = ($role === 'me');

    echo '<section class="block lg-block lg-block--header" data-block="profile-header">';

    if ($isOwner) {
        // The header IS the ceiling → no cap on itself ('' ceiling).
        echo looth_pmp_control('header', $visUi, '');
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
