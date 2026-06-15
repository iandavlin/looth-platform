<?php
/**
 * bb-mirror env config.
 *
 * Pattern lifted from archive-poc/config.php. Auto-detects live vs dev
 * via HTTP_HOST or hostname() fallback. Override via
 * `LG_BB_MIRROR_ENV=dev` in CLI.
 *
 * Backend: postgres. The earlier SQLite rollback path is retired (the
 * `forums` schema in `looth` has been the canonical store since the
 * postgres migration on 2026-05-28). To reintroduce SQLite, see
 * handoffs/2026-05-28-pre-pg-migration.md for the snapshot.
 */

if (defined('LG_BB_MIRROR_ENV_LOADED')) return;
define('LG_BB_MIRROR_ENV_LOADED', true);

// ---------- env detection ----------
$env = getenv('LG_BB_MIRROR_ENV');
if (!$env) {
    $host = $_SERVER['HTTP_HOST'] ?? gethostname();
    if (str_starts_with((string)$host, 'dev.') || str_contains((string)$host, 'ip-172-31-81-87') || str_contains((string)$host, 'claude.loothgroup')) {
        $env = 'dev';
    } else {
        $env = 'live';
    }
}
define('LG_BB_MIRROR_ENV', $env);

// ---------- env-specific values ----------
if ($env === 'live') {
    define('LG_BB_MIRROR_HOST',    'loothgroup.com');
    define('LG_BB_MIRROR_WP_PATH', '/var/www/html');
    define('LG_BB_MIRROR_WP_USER', 'looth-live');
    define('LG_BB_MIRROR_APP_ROOT','/srv/bb-mirror');
    define('LG_BB_MIRROR_PUBLIC_PATH', '/hub');
} else { // dev
    define('LG_BB_MIRROR_HOST',    'dev.loothgroup.com');
    define('LG_BB_MIRROR_WP_PATH', '/var/www/dev');
    define('LG_BB_MIRROR_WP_USER', 'looth-dev');
    define('LG_BB_MIRROR_APP_ROOT','/home/ubuntu/projects/bb-mirror');
    define('LG_BB_MIRROR_PUBLIC_PATH', '/hub');
}

// ---------- derived ----------
define('LG_BB_MIRROR_SCHEMA_PG',  LG_BB_MIRROR_APP_ROOT . '/schema.pg.sql');
define('LG_BB_MIRROR_WP_LOAD',    LG_BB_MIRROR_WP_PATH  . '/wp-load.php');

// Postgres (forums schema in shared looth DB)
define('LG_BB_MIRROR_PG_DB',      'looth');
define('LG_BB_MIRROR_PG_SCHEMA',  'forums');
define('LG_BB_MIRROR_PG_DSN',     'pgsql:host=/var/run/postgresql;dbname=' . LG_BB_MIRROR_PG_DB);

// ---------- DB connection ----------
if (!function_exists('bb_mirror_db')) {
function bb_mirror_db(bool $readonly = true): PDO {
    $pdo = new PDO(LG_BB_MIRROR_PG_DSN, null, null);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec("SET search_path = " . LG_BB_MIRROR_PG_SCHEMA . ", public");
    return $pdo;
}
}

// ---------- time-column helpers ----------
// Postgres TIMESTAMPTZ writes accept ISO 8601 strings; helpers normalize.
if (!function_exists('bb_mirror_ts')) {
function bb_mirror_ts(?int $unix): ?string {
    if ($unix === null || $unix <= 0) return null;
    return gmdate('Y-m-d\TH:i:s\Z', $unix);
}
}

if (!function_exists('bb_mirror_ts_in')) {
function bb_mirror_ts_in($v): ?int {
    if (!$v) return null;
    if (is_numeric($v)) return (int)$v;
    $t = strtotime((string)$v . ' UTC');
    return $t ?: null;
}
}

// ---------- upsert SQL builder ----------
// Postgres ON CONFLICT (<col>) DO UPDATE pattern. $conflict_col can be a
// composite list like 'user_id, target_kind, target_id' for forum_subscription.
if (!function_exists('bb_mirror_upsert_sql')) {
function bb_mirror_upsert_sql(string $table, array $cols, string $conflict_col = 'id'): string {
    $placeholders = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $collist      = '(' . implode(',', $cols) . ')';
    $setters = [];
    foreach ($cols as $c) {
        if ($c === $conflict_col) continue;
        $setters[] = "$c = EXCLUDED.$c";
    }
    return "INSERT INTO $table $collist VALUES $placeholders " .
           "ON CONFLICT ($conflict_col) DO UPDATE SET " . implode(', ', $setters);
}
}

if (!function_exists('bb_mirror_bool')) {
function bb_mirror_bool(bool $b): string {
    return $b ? 'true' : 'false';
}
}

// ---------- viewer + tier filter (single source of truth) ----------
//
// Reads are NOT tier-gated today — visibility filter on forum is the only
// read gate. tier_clause() machinery remains for future write-eligibility
// checks (reply form gating once /whoami ships).

if (!function_exists('bb_mirror_viewer_tiers')) {
function bb_mirror_viewer_tiers(): array {
    return ['public'];
}
}

if (!function_exists('bb_mirror_tier_clause')) {
function bb_mirror_tier_clause(string $column): array {
    $tiers = bb_mirror_viewer_tiers();
    return [
        'sql'  => $column . ' IN (' . implode(',', array_fill(0, count($tiers), '?')) . ')',
        'bind' => $tiers,
    ];
}
}


// ---------- /whoami — viewer identity (cached per request) ----------
// Option 3: try the fast JWT endpoint first, fall back to the WP shim.
//
//  1. Fast path — /profile-api/v0/whoami keys off the visitor's `looth_id`
//     JWT (~5ms, no WP boot). If it returns authenticated:true, use it.
//  2. Shim fallback — /wp-json/looth/v1/whoami bridges the WP login session
//     (validates wordpress_logged_in_* + adds trusted headers) and catches
//     members who have no JWT yet (the unbridged-member gap). Slow (boots WP,
//     ~687ms), so it fires only when the fast path returned anon AND the
//     visitor actually has a WP login cookie — a cookieless visitor can't be a
//     logged-in member the shim could rescue, so we skip it and stay anon fast.
//
// Self-healing: the login lanes (bridge enabled 2026-06-04) hand every member a
// JWT, so over time almost everyone hits the fast path and the shim rarely fires.
// Both endpoints return the same shape; tier_unavailable:true (poller down) →
// tier='public' (fail open). Returns null on failure; callers fall back to anon.
if (!function_exists('lg_bb_mirror_whoami')) {
function lg_bb_mirror_whoami(): ?array {
    static $fetched = false, $result = null;
    if ($fetched) return $result;
    $fetched = true;
    if (PHP_SAPI === 'cli') return null;

    // --- perf cache (2026-05-29) ---------------------------------------------
    // Caches the *resolved* identity per viewer in tmpfs so even a shim
    // fallback bites only on a miss. TTL-only, NOT wired to PurgeNotifier — a
    // tier/name change becomes visible within WHOAMI_CACHE_TTL. Keyed by BOTH
    // the WP session cookie and the looth_id JWT, so two distinct identities
    // can never collide on a key ("anon" for visitors with neither).
    $WHOAMI_CACHE_TTL = 45;
    $sess = '';
    foreach ($_COOKIE as $k => $v) {
        if (strpos($k, 'wordpress_logged_in_') === 0) { $sess = (string)$v; break; }
    }
    $jwt = (string)($_COOKIE['looth_id'] ?? '');
    $cacheKey  = ($sess !== '' || $jwt !== '') ? hash('sha256', $sess . '|' . $jwt) : 'anon';
    $cacheFile = '/dev/shm/bb-whoami-' . $cacheKey . '.json';
    if (is_readable($cacheFile) && (time() - filemtime($cacheFile)) < $WHOAMI_CACHE_TTL) {
        $hit = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($hit) && array_key_exists('v', $hit)) {
            $result = is_array($hit['v']) ? $hit['v'] : null;
            return $result;
        }
    }
    // -------------------------------------------------------------------------

    // Loopback call forwarding the visitor's own cookies (so their looth_id JWT
    // / WP session ride along). Returns [http_code, decoded_array|null].
    $call = function (string $url): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => [
                'Host: ' . LG_BB_MIRROR_HOST,
                'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? ''),
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = ($code === 200 && $body) ? json_decode($body, true) : null;
        if (is_array($data) && !empty($data['tier_unavailable'])) {
            $data['tier'] = 'public';   // poller down → fail open
        }
        return [$code, is_array($data) ? $data : null];
    };

    // 1. Fast path.
    [$code, $data] = $call('https://127.0.0.1/profile-api/v0/whoami');

    // 2. Shim fallback — only if fast didn't recognize an authenticated viewer
    //    AND there's a WP login cookie the shim could actually bridge.
    if (($data['authenticated'] ?? false) !== true && $sess !== '') {
        [$code, $data] = $call('https://127.0.0.1/wp-json/looth/v1/whoami');
    }

    $result = $data;

    // Cache only definitive results (clean 200). Transient failures (timeout,
    // 5xx) are NOT cached, so the next render retries instead of pinning null.
    if ($code === 200) {
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode(['v' => $result])) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $cacheFile);
        }
    }
    return $result;
}
}
// ---------- anonymous-posting: viewer-moderator check + author mask ----------
//
// The per-post "Post anonymously" feature (anon-rebuild lane). A topic/reply
// carrying is_anon renders as "Anonymous" + generic avatar to everyone EXCEPT
// admins/mods, who see the real author + a "(posted anonymously)" marker.
//
// Reveal authz is server-enforced (contract: admin/mod only). We read the SAME
// capability set the tier-gate bypass uses (lg_bb_mirror_whoami caps), so a
// plain member can't self-elevate. moderate_comments is the canonical mod cap
// (matches the comment-delete authz pattern); the others are admin/editor.
if (!function_exists('lg_bb_mirror_can_moderate')) {
function lg_bb_mirror_can_moderate(): bool {
    static $can = null;
    if ($can !== null) return $can;
    $can  = false;
    $wa   = function_exists('lg_bb_mirror_whoami') ? lg_bb_mirror_whoami() : null;
    $caps = is_array($wa) ? (array)($wa['capabilities'] ?? []) : [];
    foreach (['moderate_comments', 'manage_options', 'administrator',
              'edit_others_posts', 'activate_plugins'] as $c) {
        if (!empty($caps[$c]) || in_array($c, $caps, true)) { $can = true; break; }
    }
    return $can;
}
}

// Leak-safe anon mask. Mutates an author-bearing row IN PLACE before render:
//   • non-moderator + is_anon  → identity ABSENT: name→"Anonymous", slug/avatar/
//     author_id nulled so no /u/ link, no real avatar, no profile resolution.
//     (Same discipline as gated teasers — suppressed server-side, not CSS-hidden.)
//   • moderator + is_anon      → identity KEPT, $row['_anon_revealed']=true so the
//     renderer can append the "(posted anonymously)" marker.
// Recognizes the standard author columns; absent keys are skipped. is_anon is
// selected as ::int ('1'/'0') so the truthy check is reliable across PDO casts.
// Returns true when the row was anonymous (either masked or revealed).
if (!function_exists('lg_bb_mirror_mask_anon')) {
function lg_bb_mirror_mask_anon(array &$row, bool $can_mod): bool {
    $v = $row['is_anon'] ?? null;
    $is_anon = ($v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true');
    if (!$is_anon) return false;
    if ($can_mod) {
        $row['_anon_revealed'] = true;   // keep real identity; renderer shows marker
        return true;
    }
    $row['author_name'] = 'Anonymous';
    if (array_key_exists('author_slug', $row)) $row['author_slug'] = null;
    if (array_key_exists('avatar_url',  $row)) $row['avatar_url']  = null;
    if (array_key_exists('author_id',   $row)) $row['author_id']   = null;
    $row['_anon_masked'] = true;
    return true;
}
}


// Leak-safe DISCUSSION-AUTHOR visibility mask (discussion_visibility briefing 6/7).
// Mutates a discussion (forum) author row IN PLACE before identity resolution.
// The author's profile preference discussion_visibility ('public'|'member', DB
// default 'member') x the viewer's login state:
//   - viewer LOGGED-IN          -> no-op, returns false FIRST. Members always see
//     the real author; the logged-in path never reads the column (zero added cost,
//     per the perf rule -- masking is logged-out-only).
//   - logged-out + 'member'      -> identity ABSENT: name->"Private member", slug/
//     avatar/author_id/user_uuid nulled, so no /u/ link, no avatar URL, no profile
//     resolution. Same discipline as gated teasers -- server-side, never CSS-hidden.
//   - logged-out + 'public'      -> real author (returns false).
// Value is the singular 'member' (load-bearing). Callers SELECT
// COALESCE(p.discussion_visibility,'member') so a NULL (no person row yet) defaults
// to masked. Discussion authors ONLY -- callers guard on card_type so CPT authors
// are unaffected. Returns true when the row was masked.
if (!function_exists('lg_bb_mirror_mask_visibility')) {
function lg_bb_mirror_mask_visibility(array &$row, bool $viewer_logged_in): bool {
    if ($viewer_logged_in) return false;                          // logged-in: never read it
    if (($row['discussion_visibility'] ?? null) !== 'member') return false;
    $row['author_name'] = 'Private member';
    if (array_key_exists('author_slug', $row)) $row['author_slug'] = null;
    if (array_key_exists('avatar_url',  $row)) $row['avatar_url']  = null;
    if (array_key_exists('author_id',   $row)) $row['author_id']   = null;
    if (array_key_exists('user_uuid',   $row)) $row['user_uuid']   = null;
    $row['_visibility_masked'] = true;
    return true;
}
}

// ---------- pagination ----------
if (!defined('LG_BB_MIRROR_PER_PAGE')) define('LG_BB_MIRROR_PER_PAGE', 15);

if (!function_exists('bb_mirror_page')) {
function bb_mirror_page(): int {
    $p = (int)($_GET['page'] ?? 1);
    return $p < 1 ? 1 : $p;
}
}

// ---------- avatar fallback (non-gated default) ----------
// get_avatar_url()/whoami return gravatar URLs whose d= fallback points at a
// dev-gated BuddyBoss bp-full image gravatar can't fetch -> broken avatar for
// users without a gravatar. Force a non-gated default. Swap the const to a
// gate-exempt local asset later for branding (one line).
if (!defined('LG_BB_MIRROR_DEFAULT_AVATAR')) define('LG_BB_MIRROR_DEFAULT_AVATAR', 'mp');
if (!function_exists('lg_bb_mirror_safe_avatar')) {
function lg_bb_mirror_safe_avatar(?string $url): ?string {
    if (!$url) return $url;
    if (!preg_match('~^https?://[^/]*gravatar\\.com/~i', $url)) return $url;
    $p = parse_url($url);
    parse_str($p['query'] ?? '', $q);
    $q['d'] = LG_BB_MIRROR_DEFAULT_AVATAR;   // overrides the gated bp-full d=
    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? 'gravatar.com')
         . ($p['path'] ?? '') . '?' . http_build_query($q);
}
}
