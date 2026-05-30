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
function looth_render_profile_blocks(int $userId, string $role, ?string $tierBadge = null): void
{
    $headerVis = Block::headerCeiling($userId);                 // DB literal
    switch (Block::gateDecision($role, $headerVis)) {
        case 'private': return;                                 // nothing renders
        case 'gate':    looth_render_members_gate($userId); return;
    }

    $header = Block::loadHeader($userId);
    if ($header === null) { http_response_code(404); echo 'not found'; return; }
    looth_render_header_block($header, $role, $headerVis, $tierBadge);

    // TODO(next increments): foreach composed block (location, craft, connect, …)
    //   $eff = Block::effectiveVisibility($headerVis, $blockVis);
    //   if (Block::canSee($role, $headerVis, $blockVis)) looth_render_block(...);
    //   owner also sees a capped-by-header hint where Block::isCappedByHeader().
}

/** The profile-header (identity) block — the author-identity card. */
function looth_render_header_block(array $header, string $role, string $headerVis, ?string $tierBadge): void
{
    $f       = $header['fields'];
    $name    = (string)($f['display_name'] ?? 'Member');
    $avatar  = $f['avatar'] ?? null;
    $glance  = (string)($f['at_a_glance'] ?? '');
    $website = $f['website'] ?? null;
    $socials = $f['socials'] ?? [];
    $visUi   = Block::normalizeVis($headerVis);                 // 'public'|'member'|'private'
    $isOwner = ($role === 'me');

    echo '<section class="block lg-block lg-block--header" data-block="profile-header">';

    if ($isOwner) {
        echo '<span class="lg-vchip lg-vchip--' . looth_h($visUi) . '">' . looth_h(ucfirst($visUi)) . '</span>';
    }

    echo '<div class="lg-idrow">';
    echo '<div class="lg-idrow__pic">';
    if ($avatar) {
        echo '<img src="' . looth_h((string)$avatar) . '" alt="' . looth_h($name) . '" width="96" height="96">';
    } else {
        echo looth_h(looth_initials($name));                   // initials fallback (single-source empty state)
    }
    if ($isOwner) echo '<button class="lg-idrow__cam" type="button" aria-label="Change avatar">📷</button>';
    echo '</div>';

    echo '<div class="lg-idrow__body">';
    echo '<h1 class="lg-idrow__name">' . looth_h($name);
    if ($tierBadge) echo ' <span class="lg-tierpill">' . looth_h($tierBadge) . '</span>';   // derived, never stored
    echo '</h1>';
    if ($glance !== '') echo '<p class="lg-idrow__glance">' . looth_h($glance) . '</p>';
    if ($website) {
        $label = preg_replace('#^https?://#i', '', (string)$website);
        echo '<a class="lg-idrow__web" href="' . looth_h((string)$website) . '" rel="me noopener" target="_blank">' . looth_h($label) . ' ↗</a>';
    }
    if ($socials) {
        echo '<div class="lg-socrow">';
        foreach ($socials as $s) {
            $kind = (string)($s['kind'] ?? '');
            $url  = (string)($s['url'] ?? '');
            if ($url === '') continue;
            echo '<a class="lg-socrow__a" href="' . looth_h($url) . '" rel="me noopener" target="_blank" title="' . looth_h($kind) . '">'
               . looth_h(strtoupper(substr($kind, 0, 2))) . '</a>';
        }
        echo '</div>';
    }
    echo '</div></div></section>';
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
