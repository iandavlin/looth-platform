<?php
declare(strict_types=1);

/**
 * Block render loop for profile-2.0 (/u/ and /p/). ⚠️ SKELETON — Phase 1 scaffold.
 * Not yet included by u.php / p.php. Reuses the markup pieces in _render.php /
 * _render_practice.php; adds the header-ceiling gate + the "View as" toggle.
 *
 * Render contract:
 *   1. Resolve header ceiling (Block::headerCeiling).
 *   2. Block::gateDecision(role, headerVis):
 *        'private' → render nothing (owner-only / 404-ish).
 *        'gate'    → render the members-only join/sign-in interstitial, stop.
 *        'render'  → render header, then each block where
 *                    Block::canSee(role, headerVis, blockVis).
 *   3. Owner ('me') also gets the View-as Public/Member/Me control + per-block
 *      vis chips, and a tooltip where Block::isCappedByHeader() is true.
 *
 * Target output already mocked: /var/www/dev/mockups/profile-block.html (gate +
 * peek-through) and practice-repair.html. Match those.
 */

use Looth\ProfileApp\Block;
use Looth\ProfileApp\Profile;

/**
 * @param array  $entity  shaped profile|practice (Profile::loadFull / Practice::shape)
 * @param string $kind    'profile'|'practice'
 * @param string $role    'me'|'member'|'public'
 */
function looth_render_blocks(array $entity, string $kind, string $role): void
{
    // TODO(scaffold):
    //   $headerVis = Block::headerCeiling($entity['user_id'] ?? $entity['id'], $kind);
    //   switch (Block::gateDecision($role, $headerVis)) {
    //     case 'private': return;                       // nothing renders
    //     case 'gate':    looth_render_members_gate($entity); return;
    //   }
    //   looth_render_header_block($entity, $kind, $role, $headerVis);
    //   foreach (looth_block_order($entity, $kind) as $key) {
    //       $blockVis = looth_block_vis($entity, $key);
    //       if (!Block::canSee($role, $headerVis, $blockVis)) continue;
    //       looth_render_block($key, $entity, $role, $headerVis, $blockVis);
    //   }
    echo "<!-- _render_blocks: scaffold; not implemented -->";
}

/** Members-only interstitial shown when a member-header profile is hit logged-out. */
function looth_render_members_gate(array $entity): void
{
    // TODO: join / sign-in card. Mock: profile-block.html .gate.
}

/** The View-as Public/Member/Me owner control (render layer; shipped, not just mock). */
function looth_render_view_as(string $current): void
{
    // TODO: emit the toggle; owner-only. Re-renders blocks at the chosen role.
}
