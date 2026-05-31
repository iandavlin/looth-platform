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

        return [
            'block'   => 'profile-header',
            'subject' => 'person',
            'vis'     => self::normalizeVis(self::headerCeiling($userId)),
            'fields'  => [
                'display_name' => $row['display_name'],
                'avatar'       => $row['avatar_url'],    // null → initials fallback at render
                'at_a_glance'  => $row['at_a_glance'],   // single-source author bio
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
    /** Per-AUDIENCE precision levels (Ian's model): one address, two audience knobs. */
    public const LOCATION_PRECISION = ['private', 'state', 'city', 'street'];

    /**
     * Load the stored address + the two audience precision knobs. One address;
     * `members_precision` / `public_precision` each ∈ private|state|city|street
     * decide how precise the DISPLAY is for that audience. Owner always sees street.
     */
    public static function loadLocation(int $userId): ?array
    {
        $s = Db::pg()->prepare('SELECT location_text, lat, lng, location_country, location_region,
                                       location_city, location_postcode, location_address,
                                       location_members_precision, location_public_precision
                                FROM users WHERE id = :i');
        $s->execute([':i' => $userId]);
        $r = $s->fetch();
        if (!$r) return null;

        $lat = $r['lat'] !== null ? (float)$r['lat'] : null;
        $lng = $r['lng'] !== null ? (float)$r['lng'] : null;
        $place = [
            'address'  => $r['location_address'],
            'postcode' => $r['location_postcode'],
            'city'     => $r['location_city'],
            'region'   => $r['location_region'],
            'country'  => $r['location_country'],
            'lat'      => $lat,
            'lng'      => $lng,
            'text'     => $r['location_text'],
        ];
        $clean = fn($p, $d) => (is_string($p) && in_array($p, self::LOCATION_PRECISION, true)) ? $p : $d;

        return [
            'block'   => 'location',
            'subject' => 'person',
            'has'     => (bool)($place['address'] || $place['city'] || $place['region'] || $place['text'] || $lat !== null),
            'members_precision' => $clean($r['location_members_precision'] ?? null, 'city'),
            'public_precision'  => $clean($r['location_public_precision']  ?? null, 'private'),
            'place'   => $place,
        ];
    }

    /**
     * What to DISPLAY at a given precision level. Null for 'private' / no data.
     * Else [text, lat, lng, zoom, kind] — kind 'exact' (marker) | 'coarse' (circle).
     * street = full address + exact pin · city = City, Region + town dot ·
     * state = Region, Country + region-level dot.
     */
    public static function locationDisplay(array $place, string $precision): ?array
    {
        if ($precision === 'private') return null;
        $lat = $place['lat']; $lng = $place['lng'];
        $city = (string)($place['city'] ?? ''); $region = (string)($place['region'] ?? '');
        $country = (string)($place['country'] ?? '');

        if ($precision === 'street') {
            $text = (string)($place['address'] ?: $place['text'] ?: trim(implode(', ', array_filter([$city, $region]))));
            if (!empty($place['postcode'])) $text = ($text !== '' ? $text . ' · ' : '') . $place['postcode'];
            if ($text === '' && $lat === null) return null;
            return ['text' => $text, 'lat' => $lat, 'lng' => $lng, 'zoom' => 15, 'kind' => 'exact'];
        }
        if ($precision === 'city') {
            $text = trim(implode(', ', array_filter([$city, $region]))) ?: (string)($place['text'] ?? '');
            return ['text' => $text, 'lat' => self::coarsen($lat, 1), 'lng' => self::coarsen($lng, 1), 'zoom' => 11, 'kind' => 'coarse'];
        }
        // state
        $text = trim(implode(', ', array_filter([$region, $country])));
        return ['text' => $text, 'lat' => self::coarsen($lat, 0), 'lng' => self::coarsen($lng, 0), 'zoom' => 6, 'kind' => 'coarse'];
    }

    /** Validate an incoming precision level; returns it or null. */
    public static function precisionFromInput($p): ?string
    {
        return (is_string($p) && in_array($p, self::LOCATION_PRECISION, true)) ? $p : null;
    }

    /** Validate an incoming exact-tier vis ('member'|'private'|'on_request'); DB literal or null. */
    public static function exactVisFromInput($vis): ?string
    {
        if (!is_string($vis)) return null;
        $db = self::denormalizeVis($vis);   // 'member' → 'members'
        return in_array($db, self::EXACT_VIS_VALUES, true) ? $db : null;
    }

    // ---------- composable-block visibility (generic) ----------

    public const CRAFT_KEY    = 'craft';
    public const SOCIALS_KEY  = 'socials';
    public const CONNECT_KEY  = 'connect';

    /** Any composable block's visibility (DB literal) from its profile_sections row. */
    public static function blockVisibility(int $userId, string $key, string $default = 'members'): string
    {
        $s = Db::pg()->prepare('SELECT visibility FROM profile_sections WHERE user_id = :u AND key = :k');
        $s->execute([':u' => $userId, ':k' => $key]);
        $v = $s->fetchColumn();
        return ($v && in_array($v, self::VIS_VALUES, true)) ? (string)$v : $default;
    }

    /**
     * Set a composable block's visibility. $visInput is the UI vocabulary
     * ('public'|'member'|'private'); returns the persisted DB literal, or null if
     * invalid. Upserts the profile_sections row (data left untouched / '{}').
     */
    public static function saveBlockVisibility(int $userId, string $key, $visInput, int $sortOrder = 0): ?string
    {
        $vis = self::visFromInput($visInput);   // → DB literal
        if ($vis === null) return null;
        Db::pg()->prepare("
            INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
            VALUES (:u, :k, :v, '{}'::jsonb, :so)
            ON CONFLICT (user_id, key) DO UPDATE
               SET visibility = EXCLUDED.visibility, updated_at = now()
        ")->execute([':u' => $userId, ':k' => $key, ':v' => $vis, ':so' => $sortOrder]);
        return $vis;
    }

    // ---------- block: craft (what you make/do — search fuel) ----------

    /**
     * Assemble the craft block — instruments + skills + highlights (the
     * directory search-fuel), with one block-level vis (profile_sections key='craft').
     * Reuses Profile::loadFull (the canonical assembler) to avoid duplicating the
     * catalog joins. Returns null if the user doesn't exist.
     */
    public static function loadCraft(int $userId): ?array
    {
        $full = Profile::loadFull($userId);
        if (!$full) return null;
        $vis = $full['sections'][self::CRAFT_KEY]['visibility'] ?? 'members';
        $pick = fn(array $rows, array $keys) => array_map(
            fn($r) => array_intersect_key($r, array_flip($keys)), $rows
        );
        return [
            'block'   => 'craft',
            'subject' => 'person',
            'vis'     => self::normalizeVis($vis),
            'fields'  => [
                'instruments' => $pick($full['instruments'] ?? [], ['id', 'slug', 'name']),
                'skills'      => $pick($full['skills']      ?? [], ['id', 'slug', 'name', 'note']),
                'highlights'  => $pick($full['highlights']  ?? [], ['kind', 'slug', 'name']),
            ],
        ];
    }

    // ---------- block: about (free-text; shared, profile + practice) ----------

    public const ABOUT_KEY = 'about';

    /** Assemble the about block — free text + block vis (profile_sections key='about'). */
    public static function loadAbout(int $userId): array
    {
        $s = Db::pg()->prepare("SELECT visibility, data FROM profile_sections WHERE user_id = :u AND key = 'about'");
        $s->execute([':u' => $userId]);
        $r = $s->fetch();
        $text = '';
        if ($r) { $d = json_decode((string)$r['data'], true) ?: []; $text = (string)($d['text'] ?? ''); }
        return [
            'block'   => 'about',
            'subject' => 'person',
            'vis'     => self::normalizeVis(($r && in_array($r['visibility'], self::VIS_VALUES, true)) ? $r['visibility'] : 'members'),
            'text'    => $text,
        ];
    }

    // ---------- block: gallery (image grid; shared, profile + practice) ----------

    public const GALLERY_KEY      = 'gallery';
    public const GALLERY_URL_BASE = '/profile-media/gallery';
    public const GALLERY_MAX      = 24;

    private static function userUuid(int $userId): ?string
    {
        $s = Db::pg()->prepare('SELECT uuid FROM users WHERE id = :i');
        $s->execute([':i' => $userId]);
        $u = $s->fetchColumn();
        return $u === false ? null : strtolower((string)$u);
    }

    /** Assemble the gallery block — image list + block vis (profile_sections key='gallery'). */
    public static function loadGallery(int $userId): array
    {
        $s = Db::pg()->prepare("SELECT visibility, data FROM profile_sections WHERE user_id = :u AND key = 'gallery'");
        $s->execute([':u' => $userId]);
        $r = $s->fetch();
        $images = [];
        $title  = '';
        if ($r) {
            $d = json_decode((string)$r['data'], true) ?: [];
            $title = (string)($d['title'] ?? '');
            foreach (($d['images'] ?? []) as $im) {
                if (is_array($im) && !empty($im['url'])) {
                    $images[] = ['url' => (string)$im['url'], 'caption' => (string)($im['caption'] ?? '')];
                }
            }
        }
        return [
            'block'   => 'gallery',
            'subject' => 'person',
            'vis'     => self::normalizeVis(($r && in_array($r['visibility'], self::VIS_VALUES, true)) ? $r['visibility'] : 'members'),
            'title'   => $title,
            'images'  => $images,
        ];
    }

    /**
     * Persist the gallery image list (used for add/remove/reorder/caption). Sanitizes
     * to URLs under THIS user's gallery dir (no foreign URLs). visInput optional.
     */
    public static function saveGalleryImages(int $userId, array $images, ?string $visInput = null, ?string $title = null): array
    {
        $uuid   = self::userUuid($userId);
        $prefix = self::GALLERY_URL_BASE . '/' . $uuid . '/';
        $clean  = [];
        foreach ($images as $im) {
            if (!is_array($im)) continue;
            $url = (string)($im['url'] ?? '');
            if ($uuid === null || strpos($url, $prefix) !== 0) continue;     // only this user's images
            $clean[] = ['url' => $url, 'caption' => mb_substr((string)($im['caption'] ?? ''), 0, 200)];
            if (count($clean) >= self::GALLERY_MAX) break;
        }
        // Title: null = keep whatever's stored (so photo add/remove + vis changes don't wipe it).
        $finalTitle = ($title === null)
            ? self::loadGallery($userId)['title']
            : mb_substr(trim($title), 0, 80);
        $payload = ['images' => $clean];
        if ($finalTitle !== '') $payload['title'] = $finalTitle;
        $data = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($visInput !== null && self::visFromInput($visInput) !== null) {
            Db::pg()->prepare("
                INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
                VALUES (:u, 'gallery', :v, :d::jsonb, 40)
                ON CONFLICT (user_id, key) DO UPDATE SET visibility = EXCLUDED.visibility, data = EXCLUDED.data, updated_at = now()
            ")->execute([':u' => $userId, ':v' => self::visFromInput($visInput), ':d' => $data]);
        } else {
            Db::pg()->prepare("
                INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
                VALUES (:u, 'gallery', 'members', :d::jsonb, 40)
                ON CONFLICT (user_id, key) DO UPDATE SET data = EXCLUDED.data, updated_at = now()
            ")->execute([':u' => $userId, ':d' => $data]);
        }
        return self::loadGallery($userId);
    }

    // ---------- block: socials / links (website + platforms) ----------

    /**
     * Assemble the socials/links block — website (kind='web') + the other social
     * links, one block-level vis (profile_sections key='socials'). kind + url only.
     * NOTE: the inc-1 header also renders an inline social row; this is the
     * canonical managed list. Overlap flagged for coordinator ruling.
     */
    public static function loadSocials(int $userId): ?array
    {
        $pg = Db::pg();
        $exists = $pg->prepare('SELECT 1 FROM users WHERE id = :i');
        $exists->execute([':i' => $userId]);
        if (!$exists->fetchColumn()) return null;

        $website = null;
        $links   = [];
        $q = $pg->prepare('SELECT kind, value FROM profile_socials WHERE user_id = :u ORDER BY sort_order, id');
        $q->execute([':u' => $userId]);
        while ($r = $q->fetch()) {
            if ($r['kind'] === 'web' && $website === null) {
                $website = $r['value'];
            } else {
                $links[] = ['kind' => $r['kind'], 'url' => $r['value']];
            }
        }

        return [
            'block'   => 'socials',
            'subject' => 'person',
            'vis'     => self::normalizeVis(self::blockVisibility($userId, self::SOCIALS_KEY, 'members')),
            'fields'  => ['website' => $website, 'links' => $links],
        ];
    }

    // ---------- block: connect (the person's connections surface) ----------

    /** Max connection avatars previewed inline in the block. */
    private const CONNECT_PREVIEW = 12;

    /**
     * Assemble the connect block — the person's connections surface — built ON the
     * social-layer `Connections` backend (NOT re-implemented). Block-level pmp on
     * profile_sections key='connect', ceiling-capped by the header at render.
     *
     *   count        — accepted connections (the headline)
     *   connections  — up to CONNECT_PREVIEW hydrated {uuid,name,slug,avatar}
     *   mutuals      — shared accepted connections with the viewer (visitor view only)
     *   pending_in / pending_out — owner's request inbox/outbox counts (owner view only)
     *
     * The Connect / Message *actions* stay in the header slot (Social::renderProfileActions);
     * this block is the connections LIST/COUNT surface (division flagged for coordinator).
     *
     * @param int      $userId         subject (whose profile this is)
     * @param int|null $viewerUserId   the viewer's user id (for mutuals + owner inbox); null = anon
     */
    public static function loadConnect(int $userId, ?int $viewerUserId = null): ?array
    {
        $pg = Db::pg();
        $u = $pg->prepare('SELECT uuid FROM users WHERE id = :i');
        $u->execute([':i' => $userId]);
        $subjectUuid = $u->fetchColumn();
        if ($subjectUuid === false) return null;
        $subjectUuid = (string)$subjectUuid;

        $lists    = Connections::listFor($subjectUuid);
        $accepted = $lists['accepted'] ?? [];
        $isOwner  = ($viewerUserId !== null && $viewerUserId === $userId);

        // Reciprocal privacy (Ian, 2026-05-31): showing connection B in A's list
        // exposes B too — so a non-owner only sees connections whose OWN connect
        // block is visible to that audience. A connection with no connect block (→
        // member default) or a private one stays hidden on the public front even if
        // A's connect block is public. Owner always sees all of their own connections.
        if (!$isOwner && $accepted) {
            $audience = ($viewerUserId !== null) ? 'member' : 'public';
            $visMap   = self::connectVisFor(array_column($accepted, 'uuid'));
            $accepted = array_values(array_filter($accepted, static function ($b) use ($audience, $visMap) {
                $v = $visMap[$b['uuid']] ?? ['connect' => 'members', 'header' => 'members'];
                return self::canSee($audience, $v['header'], $v['connect']);
            }));
        }

        $shape = static fn(array $r): array => [
            'uuid'   => $r['uuid'],
            'name'   => $r['display_name'],
            'slug'   => $r['slug'] ?: (string)$r['uuid'],
            'avatar' => $r['avatar_url'],
        ];

        // Mutuals: shared accepted connections between viewer and subject (visitor view).
        $mutuals = [];
        if (!$isOwner && $viewerUserId !== null) {
            $vu = $pg->prepare('SELECT uuid FROM users WHERE id = :i');
            $vu->execute([':i' => $viewerUserId]);
            $viewerUuid = $vu->fetchColumn();
            if ($viewerUuid !== false) {
                $viewerSet = [];
                foreach (Connections::listFor((string)$viewerUuid)['accepted'] ?? [] as $r) {
                    $viewerSet[$r['uuid']] = true;
                }
                foreach ($accepted as $r) {
                    if (isset($viewerSet[$r['uuid']])) $mutuals[] = $shape($r);
                }
            }
        }

        $fields = [
            'count'       => count($accepted),
            'connections' => array_map($shape, array_slice($accepted, 0, self::CONNECT_PREVIEW)),
            'mutuals'     => $mutuals,
        ];
        if ($isOwner) {
            $fields['pending_in']  = count($lists['pending_in']  ?? []);
            $fields['pending_out'] = count($lists['pending_out'] ?? []);
        }

        return [
            'block'   => 'connect',
            'subject' => 'person',
            'vis'     => self::normalizeVis(self::blockVisibility($userId, self::CONNECT_KEY, 'members')),
            'fields'  => $fields,
        ];
    }

    /**
     * Batch: each uuid's OWN connect-block vis + header ceiling (DB literals), for the
     * reciprocal-privacy filter in loadConnect. Missing rows default to 'members'.
     * @return array<string,array{connect:string,header:string}>
     */
    private static function connectVisFor(array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter($uuids)));
        if (!$uuids) return [];
        $ph = implode(',', array_fill(0, count($uuids), '?'));
        $st = Db::pg()->prepare(
            "SELECT u.uuid,
                    COALESCE(pc.visibility, 'members') AS connect_vis,
                    COALESCE(ph.visibility, 'members') AS header_vis
               FROM users u
               LEFT JOIN profile_sections pc ON pc.user_id = u.id AND pc.key = 'connect'
               LEFT JOIN profile_sections ph ON ph.user_id = u.id AND ph.key = 'header'
              WHERE u.uuid IN ($ph)"
        );
        $st->execute($uuids);
        $out = [];
        while ($r = $st->fetch()) {
            $out[(string)$r['uuid']] = ['connect' => (string)$r['connect_vis'], 'header' => (string)$r['header_vis']];
        }
        return $out;
    }

    // ---------- block: practice-header (the required /p/ header) ----------

    public const PRACTICE_HEADER_DEFAULT = 'members';

    /**
     * Where a practice's header (ceiling) vis lives WITHOUT new schema: a
     * namespaced row in the OWNER's profile_sections, key='practice-header:<id>'.
     * (A dedicated practice_sections table is the clean future home — flagged.)
     */
    private static function practiceHeaderKey(int $practiceId): string
    {
        return 'practice-header:' . $practiceId;
    }

    /** The practice owner's profile-app user id (practices.created_by). */
    public static function practiceOwnerId(int $practiceId): ?int
    {
        $s = Db::pg()->prepare('SELECT created_by FROM practices WHERE id = :p AND archived_at IS NULL');
        $s->execute([':p' => $practiceId]);
        $v = $s->fetchColumn();
        return ($v === false || $v === null) ? null : (int) $v;
    }

    /** Owner = practices.created_by, or an explicit practice_members role='owner'. */
    public static function isPracticeOwner(int $practiceId, int $userId): bool
    {
        if ($userId <= 0) return false;
        if (self::practiceOwnerId($practiceId) === $userId) return true;
        $s = Db::pg()->prepare("SELECT 1 FROM practice_members WHERE practice_id = :p AND user_id = :u AND role = 'owner'");
        $s->execute([':p' => $practiceId, ':u' => $userId]);
        return (bool) $s->fetchColumn();
    }

    /** The practice header ceiling vis (DB literal). Stored on the owner's profile_sections. */
    public static function practiceHeaderCeiling(int $practiceId): string
    {
        $owner = self::practiceOwnerId($practiceId);
        if ($owner === null) return self::PRACTICE_HEADER_DEFAULT;
        return self::blockVisibility($owner, self::practiceHeaderKey($practiceId), self::PRACTICE_HEADER_DEFAULT);
    }

    /**
     * Assemble the practice-header block from `practices` + the owner's spine
     * (single-source avatar + city/region). vis normalized to 'member'. Null if
     * the practice doesn't exist / is archived.
     */
    public static function loadPracticeHeader(int $practiceId): ?array
    {
        $s = Db::pg()->prepare(
            'SELECT p.id, p.name, p.type, p.tagline, p.website, p.avatar_url AS practice_avatar,
                    o.avatar_url AS owner_avatar, o.location_city AS city, o.location_region AS region
             FROM practices p LEFT JOIN users o ON o.id = p.created_by
             WHERE p.id = :p AND p.archived_at IS NULL'
        );
        $s->execute([':p' => $practiceId]);
        $r = $s->fetch();
        if (!$r) return null;

        return [
            'block'       => 'practice-header',
            'subject'     => 'practice',
            'practice_id' => (int) $r['id'],
            'vis'         => self::normalizeVis(self::practiceHeaderCeiling($practiceId)),
            'fields'      => [
                'name'    => $r['name'],
                'type'    => $r['type'],
                'tagline' => $r['tagline'],
                'website' => $r['website'],
                'avatar'  => $r['owner_avatar'] ?: $r['practice_avatar'],   // owner single-source; practice as fallback
                'city'    => $r['city'],
                'region'  => $r['region'],
            ],
        ];
    }

    /**
     * Persist the practice header's ceiling visibility (the only editable bit here).
     * Caller must confirm ownership for the right status code; this re-checks as
     * defense-in-depth. Returns the re-assembled block, or null on permission /
     * invalid-vis failure.
     */
    public static function savePracticeHeader(int $practiceId, int $editorUserId, array $fields): ?array
    {
        if (!self::isPracticeOwner($practiceId, $editorUserId)) return null;
        $owner = self::practiceOwnerId($practiceId) ?? $editorUserId;
        if (array_key_exists('visibility', $fields)) {
            if (self::saveBlockVisibility($owner, self::practiceHeaderKey($practiceId), $fields['visibility'], 0) === null) {
                return null;   // invalid visibility
            }
        }
        return self::loadPracticeHeader($practiceId);
    }
}
