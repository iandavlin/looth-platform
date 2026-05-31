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
function looth_render_profile_blocks(int $userId, string $role, ?string $tierBadge = null, string $headerActions = ''): void
{
    $headerVis = Block::headerCeiling($userId);                 // DB literal
    switch (Block::gateDecision($role, $headerVis)) {
        case 'private': return;                                 // nothing renders
        case 'gate':    looth_render_members_gate($userId); return;
    }

    $header = Block::loadHeader($userId);
    if ($header === null) { http_response_code(404); echo 'not found'; return; }
    looth_render_header_block($header, $role, $headerVis, $tierBadge, $headerActions);

    // increment 2: the location block (two-tier, ceiling-capped per tier).
    looth_render_location_block($userId, $role, $headerVis);

    // increment 3: craft (search-fuel) + socials/links blocks.
    looth_render_craft_block($userId, $role, $headerVis);
    looth_render_socials_block($userId, $role, $headerVis);

    // TODO(next increments): connect, practices — same shape:
    //   if (Block::canSee($role, $headerVis, $blockVis)) looth_render_block(...);
    //   owner also sees a capped-by-header hint where Block::isCappedByHeader().
}

/**
 * The craft block — instruments / skills / highlights as search-fuel chips, one
 * block-level vis, ceiling-capped via Block::canSee.
 */
function looth_render_craft_block(int $userId, string $role, string $headerVis): void
{
    $craft = Block::loadCraft($userId);
    if ($craft === null) return;
    $f = $craft['fields'];
    $chips = array_merge(
        array_map(fn($i) => (string)($i['name'] ?? ''), $f['skills']      ?? []),
        array_map(fn($i) => (string)($i['name'] ?? ''), $f['instruments'] ?? [])
    );
    $chips = array_values(array_filter($chips, fn($c) => $c !== ''));
    if (!$chips) return;                                   // empty craft → no block

    $isOwner = ($role === 'me');
    if (!Block::canSee($role, $headerVis, Block::denormalizeVis((string)$craft['vis'])) && !$isOwner) return;

    echo '<section class="block lg-block lg-block--craft" data-block="craft">';
    echo '<h3 class="lg-bh">Craft';
    if ($isOwner) echo ' ' . looth_pmp_control('craft', (string)$craft['vis'], $headerVis);
    echo '</h3><div class="lg-chips">';
    foreach ($chips as $c) echo '<span class="lg-chip">' . looth_h($c) . '</span>';
    echo '</div></section>';
}

/**
 * The socials / links block — website + platform links, one block-level vis,
 * ceiling-capped. Sole location for social links (header inline row dropped).
 */
function looth_render_socials_block(int $userId, string $role, string $headerVis): void
{
    $soc = Block::loadSocials($userId);
    if ($soc === null) return;
    $website = $soc['fields']['website'] ?? null;
    $links   = $soc['fields']['links']   ?? [];
    if (!$website && !$links) return;                      // empty → no block

    $isOwner = ($role === 'me');
    if (!Block::canSee($role, $headerVis, Block::denormalizeVis((string)$soc['vis'])) && !$isOwner) return;

    echo '<section class="block lg-block lg-block--socials" data-block="socials">';
    echo '<h3 class="lg-bh">Links';
    if ($isOwner) echo ' ' . looth_pmp_control('socials', (string)$soc['vis'], $headerVis);
    echo '</h3><div class="lg-socrow">';
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
    echo '</div></section>';
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

/**
 * The location block — two tiers, each ceiling-capped. The approximate tier
 * (city/region + a town-level coarse dot) is governed by location_visibility; the
 * exact tier (the user-placed pin at the chosen precision + address) by
 * location_exact_visibility. Effective vis = more-restrictive(header, tier) via
 * Block::canSee. The map only ever plots the MANAGED pin (exact at precision, or
 * the coarse approximate dot) — never a precise pin the viewer isn't permitted.
 */
function looth_render_location_block(int $userId, string $role, string $headerVis): void
{
    $loc = Block::loadLocation($userId);
    if ($loc === null) return;
    $a = $loc['approximate'];
    $e = $loc['exact'];

    $hasApprox = ($a['city'] || $a['region'] || $loc['text']);
    $hasExact  = !empty($e['present']);
    if (!$hasApprox && !$hasExact) return;                 // empty location → no block

    $isOwner   = ($role === 'me');
    $canApprox = Block::canSee($role, $headerVis, Block::denormalizeVis((string)$a['vis']));
    $canExact  = $hasExact && Block::canSee($role, $headerVis, Block::denormalizeVis((string)$e['vis']));
    if (!$canApprox && !$canExact && !$isOwner) return;    // gated entirely

    echo '<section class="block lg-block lg-block--location" data-block="location">';
    echo '<h3 class="lg-bh">Location</h3>';

    if (($canApprox || $isOwner) && $hasApprox) {
        $line = trim(implode(', ', array_filter([$a['city'], $a['region'], $a['country']])));
        if ($line === '') $line = (string)($loc['text'] ?? '');
        echo '<div class="lg-loc__line">📍 ' . looth_h($line);
        if ($isOwner) echo ' ' . looth_pmp_control('location-approx', (string)$a['vis'], $headerVis);
        echo '</div>';
        if ($a['lat'] !== null) {
            // town-level coarse dot for "near me" / map — never the exact pin.
            echo '<div class="lg-loc__map" data-precision="approx"'
               . ' data-lat="' . looth_h((string)$a['lat']) . '" data-lng="' . looth_h((string)$a['lng']) . '"></div>';
        }
    }

    if ($canExact) {
        echo '<div class="lg-loc__exact">🏠 ' . looth_h((string)($e['address'] ?: 'Exact location'));
        if (!empty($e['postcode'])) echo ' · ' . looth_h((string)$e['postcode']);
        if ($isOwner) echo ' ' . looth_pmp_control('location-exact', (string)$e['vis'], $headerVis);
        echo '</div>';
        echo '<div class="lg-loc__pin" data-precision="' . looth_h((string)$loc['precision']) . '"'
           . ' data-lat="' . looth_h((string)$e['lat']) . '" data-lng="' . looth_h((string)$e['lng']) . '"></div>';
    } elseif ($isOwner && $hasExact) {
        echo '<div class="lg-loc__exact-note">🏠 Exact address ' . looth_pmp_control('location-exact', (string)$e['vis'], $headerVis) . ' — hidden from viewers</div>';
    } elseif ($canApprox && $hasExact) {
        $who = ((string)$e['vis'] === 'on_request') ? 'on request' : 'to ' . looth_h((string)$e['vis']) . 's';
        echo '<div class="lg-loc__exact-note">Exact address available ' . $who . '</div>';
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
    echo '<h1 class="lg-idrow__name">' . looth_h($name);
    if ($tierBadge) echo ' <span class="lg-tierpill">' . looth_h($tierBadge) . '</span>';
    echo '</h1>';
    if ($glance !== '') echo '<p class="lg-idrow__glance">' . looth_h($glance) . '</p>';
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
