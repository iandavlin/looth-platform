<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * Block model for profile-2.0 — the block sets + the header-ceiling visibility
 * rule. ⚠️ SKELETON (Phase 1 scaffold) — method bodies are stubs / TODO. Do not
 * wire into render until docs/plan-profile-2.0-phase1-build.md is approved.
 *
 * The ONE load-bearing rule: effective block visibility = the MORE RESTRICTIVE
 * of (header, block). The header is the profile's front door / ceiling; a block
 * can lock down further but never open past the header. (Ian, FINAL 2026-05-29 —
 * plan-profile-block-system.md "Visibility model — FINAL".)
 *
 * Vocabulary: DB literal is 'members' (plural); the platform/posts vocabulary is
 * 'member' (singular). normalizeVis() maps to the canonical singular for JSON/UI;
 * persistence keeps the existing 'members' literal (build plan §0).
 */
final class Block
{
    /** Tri-state, ordered open→closed. Index = restrictiveness rank. */
    public const VIS_ORDER = ['public', 'members', 'private'];

    /**
     * Block registry. Which block keys belong to which entity palette — mirrors
     * the composer (shared + entity-specific). Storefront = practice-only.
     * 'header' is the required, non-removable ceiling block per entity.
     */
    public const SETS = [
        'shared'   => ['location', 'about', 'gallery'],
        'profile'  => ['profile-header', 'craft', 'connect', 'socials', 'practices'],
        'practice' => ['practice-header', 'hours', 'services', 'turnaround', 'staff'],
    ];

    /** The required header block key for each entity. */
    public const HEADER = ['profile' => 'profile-header', 'practice' => 'practice-header'];

    /** Palette for an entity = shared + that entity's own. */
    public static function paletteFor(string $entity): array
    {
        // TODO: return SETS['shared'] ∪ SETS[$entity], header first.
        return [];
    }

    /** Canonical singular vocabulary for JSON/UI ('members' → 'member'). */
    public static function normalizeVis(string $vis): string
    {
        return $vis === 'members' ? 'member' : $vis;
    }

    /** DB literal from canonical ('member' → 'members'). */
    public static function denormalizeVis(string $vis): string
    {
        return $vis === 'member' ? 'members' : $vis;
    }

    /**
     * THE header-ceiling rule. effective = more restrictive of (header, block).
     * Returns the closed-most of the two on VIS_ORDER. One function, every path.
     */
    public static function effectiveVisibility(string $headerVis, string $blockVis): string
    {
        $h = array_search($headerVis, self::VIS_ORDER, true);
        $b = array_search($blockVis, self::VIS_ORDER, true);
        if ($h === false) $h = 1; // default members
        if ($b === false) $b = 1;
        return self::VIS_ORDER[max($h, $b)];   // max rank = more restrictive
    }

    /**
     * Read the entity's header (ceiling) visibility. Per DECISION C the header
     * vis lives in a profile_sections / practice_sections row key='header'.
     * @param string $entity 'profile'|'practice'
     */
    public static function headerCeiling(int $subjectId, string $entity): string
    {
        // TODO: SELECT visibility FROM {entity}_sections WHERE owner=$subjectId AND key='header'
        //       fallback 'members' (the locked baseline default — Ian rules the
        //       member-vs-public default on the next mockup; non-blocking).
        return 'members';
    }

    /**
     * Whole-profile gate decision from header vis + viewer role.
     * Returns one of: 'render' | 'gate' (join/sign-in) | 'private' (404/owner-only).
     *   header private → 'private' (nothing renders but to owner)
     *   header member  → logged-out 'gate'; member/owner 'render'
     *   header public  → 'render' (blocks then peek-through per their own vis)
     * @param string $role 'me'|'member'|'public' (friend folds to member)
     */
    public static function gateDecision(string $role, string $headerVis): string
    {
        // TODO: implement the 3-branch ruling above.
        return 'render';
    }

    /**
     * Can a viewer of $role see a block whose RAW vis is $blockVis, under a header
     * of $headerVis? Applies the ceiling then the role check.
     */
    public static function canSee(string $role, string $headerVis, string $blockVis): bool
    {
        $eff = self::effectiveVisibility($headerVis, $blockVis);
        return Profile::canSee($role, $eff);
    }

    /**
     * UX helper: is the header overriding (capping) this block's chosen vis?
     * Drives the editor tooltip "Header is members-only, so this block is limited
     * to members." True when block is set MORE OPEN than the header allows.
     */
    public static function isCappedByHeader(string $headerVis, string $blockVis): bool
    {
        return self::effectiveVisibility($headerVis, $blockVis) !== $blockVis;
    }
}
