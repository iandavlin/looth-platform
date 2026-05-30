<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * Block model for profile-2.0 — block sets + the header-as-CEILING visibility
 * rule, and the establishing pilot block (profile-header / identity).
 *
 * THE load-bearing rule (Ian, FINAL — plan-profile-block-system.md "Visibility
 * model — FINAL" + "Schema — RESOLVED dev-final"): the header's visibility IS the
 * profile/practice's own visibility = the section CAP. Effective block visibility
 * = the MORE RESTRICTIVE of (header, block). Stored on the header
 * `profile_sections` row (key='header'.visibility) — no dedicated column.
 *
 * Visibility vocabulary: the DB literal is 'members' (plural). normalizeVis()
 * maps to the platform/JSON/UI 'member' (singular). This class is the ONE
 * normalize point — persistence keeps the existing literal, callers speak 'member'.
 */
final class Block
{
    /** DB-literal tri-state, ordered open→closed; index = restrictiveness rank. */
    public const VIS_ORDER  = ['public', 'members', 'private'];
    public const VIS_VALUES = ['public', 'members', 'private'];

    /** Header section key (where the ceiling vis lives) and its default. */
    public const HEADER_KEY     = 'header';
    public const HEADER_DEFAULT = 'members';   // Ian's deferred default; member-baseline

    /**
     * Block registry — which keys belong to which entity palette (mirrors the
     * composer: shared + entity-specific). storefront = practice-only.
     */
    public const SETS = [
        'shared'   => ['location', 'about', 'gallery'],
        'profile'  => ['profile-header', 'craft', 'connect', 'socials', 'practices'],
        'practice' => ['practice-header', 'hours', 'services', 'turnaround', 'staff'],
    ];

    /** The required header block key per entity. */
    public const HEADER = ['profile' => 'profile-header', 'practice' => 'practice-header'];

    /** Palette for an entity = header first, then shared, then entity-own. */
    public static function paletteFor(string $entity): array
    {
        if (!isset(self::SETS[$entity])) return [];
        return array_merge([self::HEADER[$entity]], self::SETS['shared'], self::SETS[$entity]);
    }

    // ---------- the single normalize point: DB 'members' <-> UI 'member' ----------

    /** DB literal → UI/JSON canonical ('members' → 'member'). */
    public static function normalizeVis(string $vis): string
    {
        return $vis === 'members' ? 'member' : $vis;
    }

    /** UI/JSON canonical → DB literal ('member' → 'members'). */
    public static function denormalizeVis(string $vis): string
    {
        return $vis === 'member' ? 'members' : $vis;
    }

    /** Validate an incoming UI vis ('public'|'member'|'private'); returns DB literal or null. */
    public static function visFromInput($vis): ?string
    {
        if (!is_string($vis)) return null;
        $db = self::denormalizeVis($vis);
        return in_array($db, self::VIS_VALUES, true) ? $db : null;
    }

    // ---------- the header-ceiling rule ----------

    /**
     * Effective block visibility = MORE RESTRICTIVE of (header, block).
     * Inputs + output are DB literals. One function, every render path.
     */
    public static function effectiveVisibility(string $headerVis, string $blockVis): string
    {
        return self::VIS_ORDER[max(self::visRank($headerVis), self::visRank($blockVis))];
    }

    /**
     * Restrictiveness rank on VIS_ORDER. Unknown values (e.g. the exact-tier
     * 'on_request') FAIL CLOSED to the most restrictive rank — never under-gate.
     */
    public static function visRank(string $vis): int
    {
        $i = array_search($vis, self::VIS_ORDER, true);
        return $i === false ? count(self::VIS_ORDER) - 1 : $i;   // unknown → private rank
    }

    /**
     * The entity's header/ceiling visibility (DB literal). Lives on the
     * profile_sections row key='header'. Falls back to HEADER_DEFAULT.
     */
    public static function headerCeiling(int $userId): string
    {
        $s = Db::pg()->prepare(
            "SELECT visibility FROM profile_sections WHERE user_id = :u AND key = :k"
        );
        $s->execute([':u' => $userId, ':k' => self::HEADER_KEY]);
        $vis = $s->fetchColumn();
        return ($vis && in_array($vis, self::VIS_VALUES, true)) ? (string)$vis : self::HEADER_DEFAULT;
    }

    /**
     * Whole-profile gate decision from header vis + viewer role.
     *   'private' → owner-only; nothing renders to others.
     *   'gate'    → members-only; a logged-out visitor gets the join/sign-in gate.
     *   'render'  → render; blocks then refine DOWN per their own effective vis.
     * @param string $role 'me'|'member'|'friend'|'public'
     */
    public static function gateDecision(string $role, string $headerVis): string
    {
        if ($role === 'me') return 'render';
        switch ($headerVis) {
            case 'private': return 'private';                       // owner only
            case 'members': return $role === 'public' ? 'gate' : 'render';
            case 'public':  return 'render';                        // public peeks through
            default:        return $role === 'public' ? 'gate' : 'render';
        }
    }

    /**
     * Can a viewer of $role see a block of raw vis $blockVis, beneath a header of
     * $headerVis? Applies the ceiling, then the existing role check.
     */
    public static function canSee(string $role, string $headerVis, string $blockVis): bool
    {
        return Profile::canSee($role, self::effectiveVisibility($headerVis, $blockVis));
    }

    /**
     * UX: is the header capping this block's chosen vis? (block set more open than
     * the header allows) → drives the editor tooltip "Header is members-only, so
     * this block is limited to members."
     */
    public static function isCappedByHeader(string $headerVis, string $blockVis): bool
    {
        return self::effectiveVisibility($headerVis, $blockVis) !== $blockVis;
    }

    // ---------- pilot block: profile-header (identity) ----------

    /**
     * Assemble the profile-header block from the relational spine. The
     * establishing pattern: JSON shape ↔ relational mapping ↔ block-level (here
     * ceiling) pmp. Returns null if the user doesn't exist.
     *
     *   users.display_name / avatar_url / at_a_glance  → fields
     *   profile_socials (kind='web')                    → website
     *   profile_socials (other kinds)                   → socials[]
     *   profile_sections key='header' .visibility       → block vis (the ceiling)
     *   tier_badge: 'auto' — DERIVED at render from /whoami, never stored/drafted.
     *
     * `vis` is returned NORMALIZED ('member'); persistence stays 'members'.
     */
    public static function loadHeader(int $userId): ?array
    {
        $pg = Db::pg();
        $u = $pg->prepare('SELECT display_name, avatar_url, at_a_glance FROM users WHERE id = :i');
        $u->execute([':i' => $userId]);
        $row = $u->fetch();
        if (!$row) return null;

        $website = null;
        $socials = [];
        $sq = $pg->prepare('SELECT kind, value FROM profile_socials WHERE user_id = :u ORDER BY sort_order, id');
        $sq->execute([':u' => $userId]);
        while ($s = $sq->fetch()) {
            if ($s['kind'] === 'web' && $website === null) {
                $website = $s['value'];
            } else {
                $socials[] = ['kind' => $s['kind'], 'url' => $s['value']];
            }
        }

        return [
            'block'   => 'profile-header',
            'subject' => 'person',
            'vis'     => self::normalizeVis(self::headerCeiling($userId)),
            'fields'  => [
                'display_name' => $row['display_name'],
                'avatar'       => $row['avatar_url'],          // null → initials fallback at render
                'at_a_glance'  => $row['at_a_glance'],         // single-source author bio
                'website'      => $website,
                'socials'      => $socials,                    // kind + url only (block-level pmp)
            ],
            'tier_badge' => 'auto',   // derived from /whoami at render; never stored
        ];
    }

    /**
     * Write the header's editable fields + the ceiling visibility. Returns the
     * persisted shape. $fields keys (all optional): at_a_glance (string|null),
     * visibility ('public'|'member'|'private' — the ceiling).
     * display_name stays in me-name; avatar in the avatar endpoint; socials in
     * me-socials — this writes the header-specific bits.
     */
    public static function saveHeader(int $userId, array $fields): array
    {
        $pg = Db::pg();

        if (array_key_exists('at_a_glance', $fields)) {
            $bio = $fields['at_a_glance'];
            $bio = ($bio === null || $bio === '') ? null : (string)$bio;
            $pg->prepare('UPDATE users SET at_a_glance = :b WHERE id = :u')
               ->execute([':b' => $bio, ':u' => $userId]);
        }

        if (array_key_exists('visibility', $fields)) {
            $vis = self::visFromInput($fields['visibility']);   // → DB literal
            if ($vis !== null) {
                $pg->prepare("
                    INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
                    VALUES (:u, 'header', :v, '{}'::jsonb, 0)
                    ON CONFLICT (user_id, key) DO UPDATE
                       SET visibility = EXCLUDED.visibility, updated_at = now()
                ")->execute([':u' => $userId, ':v' => $vis]);
            }
        }

        return self::loadHeader($userId) ?? [];
    }

    // ---------- pilot block: location (two-tier, user-managed pin) ----------

    /** Exact-tier visibility values (never 'public' — a precise pin can't be open web). */
    public const EXACT_VIS_VALUES = ['members', 'private', 'on_request'];

    /** User-managed display precision for the pin (canon: exact → neighborhood → city). */
    public const PRECISION_VALUES  = ['exact', 'neighborhood', 'city'];
    public const PRECISION_DEFAULT = 'exact';

    /** Coarsening decimal places per precision (~111km/dp). Approximate tier is town-level. */
    private const DP_NEIGHBORHOOD = 2;   // ~1.1 km
    private const DP_CITY         = 1;   // ~11 km
    private const DP_APPROX       = 1;   // the always-coarse "near me"/map tier (town-level)

    /** Round a coordinate to $dp decimals (the no-stored-column coarsening). Null-safe. */
    public static function coarsen($coord, int $dp)
    {
        return $coord === null ? null : round((float)$coord, $dp);
    }

    /**
     * Assemble the location block from the spine — both tiers. The render layer
     * gates each tier; this returns the full editor shape (vis normalized to 'member').
     *
     *   approximate ← users.location_city/region/country + COARSE coord
     *                 (city-centroid derived by rounding the stored point; NO approx
     *                 column) + users.location_visibility.
     *   exact       ← users.lat/lng (the user-placed pin), at the chosen display
     *                 PRECISION, + users.location_address/postcode +
     *                 users.location_exact_visibility. Folds to null when the user
     *                 set precision='city' (no precise pin exposed).
     */
    public static function loadLocation(int $userId): ?array
    {
        $pg = Db::pg();
        $s = $pg->prepare('SELECT location_text, lat, lng, location_country, location_region,
                                  location_city, location_postcode, location_address,
                                  location_visibility, location_exact_visibility, location_pin_precision
                           FROM users WHERE id = :i');
        $s->execute([':i' => $userId]);
        $r = $s->fetch();
        if (!$r) return null;

        $approxVis = $r['location_visibility']       ?: 'members';
        $exactVis  = $r['location_exact_visibility'] ?: 'private';
        $precision = $r['location_pin_precision']    ?: self::PRECISION_DEFAULT;

        $lat = $r['lat'] !== null ? (float)$r['lat'] : null;
        $lng = $r['lng'] !== null ? (float)$r['lng'] : null;

        // Exact-tier coord at the user's chosen display precision. 'city' = no
        // precise pin (fold to approximate only).
        $exactLat = $exactLng = null;
        if ($lat !== null && $lng !== null) {
            if ($precision === 'exact') {
                $exactLat = $lat;            $exactLng = $lng;
            } elseif ($precision === 'neighborhood') {
                $exactLat = self::coarsen($lat, self::DP_NEIGHBORHOOD);
                $exactLng = self::coarsen($lng, self::DP_NEIGHBORHOOD);
            } // 'city' → leave null
        }
        $hasExact = $exactLat !== null;

        return [
            'block'   => 'location',
            'subject' => 'person',
            'text'    => $r['location_text'],          // legacy/escape-hatch display string
            'precision' => $precision,
            'approximate' => [
                'vis'     => self::normalizeVis($approxVis),
                'city'    => $r['location_city'],
                'region'  => $r['location_region'],
                'country' => $r['location_country'],
                'lat'     => self::coarsen($lat, self::DP_APPROX),   // town-level, never the pin
                'lng'     => self::coarsen($lng, self::DP_APPROX),
            ],
            'exact' => [
                'vis'      => self::normalizeVis($exactVis),
                'present'  => $hasExact,
                'address'  => $r['location_address'],
                'postcode' => $r['location_postcode'],
                'lat'      => $exactLat,   // already at display precision; null if folded to city
                'lng'      => $exactLng,
            ],
        ];
    }

    /** Validate an incoming exact-tier vis ('member'|'private'|'on_request'); DB literal or null. */
    public static function exactVisFromInput($vis): ?string
    {
        if (!is_string($vis)) return null;
        $db = self::denormalizeVis($vis);   // 'member' → 'members'
        return in_array($db, self::EXACT_VIS_VALUES, true) ? $db : null;
    }
}
