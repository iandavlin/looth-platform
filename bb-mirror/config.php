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
    define('LG_BB_MIRROR_PUBLIC_PATH', '/forums');
} else { // dev
    define('LG_BB_MIRROR_HOST',    'dev.loothgroup.com');
    define('LG_BB_MIRROR_WP_PATH', '/var/www/dev');
    define('LG_BB_MIRROR_WP_USER', 'looth-dev');
    define('LG_BB_MIRROR_APP_ROOT','/home/ubuntu/projects/bb-mirror');
    define('LG_BB_MIRROR_PUBLIC_PATH', '/forums-poc');
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
// Same loopback pattern as archive-poc. Calls the WP shim with the caller's
// cookies. Returns null on failure; callers fall back to anon state.
// tier_unavailable:true (poller down) → tier='public' (fail open).
if (!function_exists('lg_bb_mirror_whoami')) {
function lg_bb_mirror_whoami(): ?array {
    static $fetched = false, $result = null;
    if ($fetched) return $result;
    $fetched = true;
    if (PHP_SAPI === 'cli') return null;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://127.0.0.1/wp-json/looth/v1/whoami',
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
        $data['tier'] = 'public';
    }
    $result = is_array($data) ? $data : null;
    return $result;
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
