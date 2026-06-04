<?php
/**
 * archive-poc env config.
 *
 * Auto-detects live vs dev from $_SERVER['HTTP_HOST'] or hostname() fallback.
 * Exposes constants used by web/, bin/, and the mu-plugin.
 *
 * Override at the top of any script by defining LG_ARCHIVE_POC_ENV before
 * including this file (e.g. for CLI: `LG_ARCHIVE_POC_ENV=live php …`).
 */

declare(strict_types=1);

if (defined('LG_ARCHIVE_POC_ENV_LOADED')) return;
define('LG_ARCHIVE_POC_ENV_LOADED', true);

// ---------- env detection ----------
$env = getenv('LG_ARCHIVE_POC_ENV');
if (!$env) {
    $host = $_SERVER['HTTP_HOST'] ?? gethostname();
    // dev hostnames start with dev. or are the dev box's internal name
    if (str_starts_with((string)$host, 'dev.') || str_contains((string)$host, 'ip-172-31-81-87') || str_contains((string)$host, 'claude.loothgroup')) {
        $env = 'dev';
    } else {
        $env = 'live';
    }
}
define('LG_ARCHIVE_POC_ENV', $env);

// ---------- env-specific values ----------
if ($env === 'live') {
    define('LG_ARCHIVE_POC_HOST',          'loothgroup.com');
    define('LG_ARCHIVE_POC_WP_PATH',       '/var/www/html');
    define('LG_ARCHIVE_POC_WP_USER',       'looth-live');
    define('LG_ARCHIVE_POC_GATE_COOKIE',   '');                 // live has no cookie gate
    define('LG_ARCHIVE_POC_APP_ROOT',      '/srv/archive-poc');
    define('LG_ARCHIVE_POC_LOGO_URL',      'https://loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png');
    define('LG_ARCHIVE_POC_CANONICAL_BASE','https://loothgroup.com');
} else { // dev
    define('LG_ARCHIVE_POC_HOST',          'dev.loothgroup.com');
    define('LG_ARCHIVE_POC_WP_PATH',       '/var/www/dev');
    define('LG_ARCHIVE_POC_WP_USER',       'looth-dev');
    define('LG_ARCHIVE_POC_GATE_COOKIE',   'loothdev_auth');
    define('LG_ARCHIVE_POC_APP_ROOT',      '/home/ubuntu/projects/archive-poc');
    define('LG_ARCHIVE_POC_LOGO_URL',      'https://dev.loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png');
    define('LG_ARCHIVE_POC_CANONICAL_BASE','https://dev.loothgroup.com');
}

// ---------- derived ----------
define('LG_ARCHIVE_POC_SQLITE',   LG_ARCHIVE_POC_APP_ROOT . '/index.sqlite');
define('LG_ARCHIVE_POC_ROWS_JSON',LG_ARCHIVE_POC_APP_ROOT . '/rows.json');
define('LG_ARCHIVE_POC_WP_LOAD',  LG_ARCHIVE_POC_WP_PATH . '/wp-load.php');

// Site display timezone (matches WP's America/New_York). The web tier runs
// WP-free (default UTC), so event times are formatted against this explicitly.
if (!defined('LG_ARCHIVE_POC_TZ')) define('LG_ARCHIVE_POC_TZ', 'America/New_York');

// Dash-driven front-page config (sponsors, looths, CTAs). JSON file written
// atomically by the /_config webhook (lg-layout-v2 dash → loopback). Falls
// back to PHP-constant defaults baked into index.php when missing.
define('LG_ARCHIVE_POC_CONFIG_JSON', LG_ARCHIVE_POC_APP_ROOT . '/config.json');

// Shared secret for the /_config webhook. Lives outside source at
// /etc/lg-archive-poc-secret (mode 640, root:www-data + ACL for archive-poc).
// Empty if file missing — webhook refuses all requests in that state.
if (!defined('LG_ARCHIVE_POC_CONFIG_SECRET')) {
    $_lg_secret = @file_get_contents('/etc/lg-archive-poc-secret');
    define('LG_ARCHIVE_POC_CONFIG_SECRET', $_lg_secret !== false ? trim($_lg_secret) : '');
    unset($_lg_secret);
}

// ---------- PDO connection ----------
// LG_ARCHIVE_POC_DSN env var picks the backend. Default = sqlite at the
// legacy path. Postgres in-flight per docs/STRANGLER-COORDINATION.md §3i.
//   Example (dev pg, peer auth):
//     LG_ARCHIVE_POC_DSN='pgsql:host=/var/run/postgresql;dbname=looth' \
//       sudo -u archive-poc php bin/backfill-pg.php
if (!function_exists('lg_archive_poc_pdo')) {
function lg_archive_poc_pdo(): PDO {
    $dsn = getenv('LG_ARCHIVE_POC_DSN');
    if (!$dsn) $dsn = 'sqlite:' . LG_ARCHIVE_POC_SQLITE;
    $pdo = new PDO($dsn, null, null);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
    } elseif ($driver === 'pgsql') {
        // Pin search_path here, not via ALTER ROLE — looth-dev is shared
        // across stranglers and can't have a single per-role default.
        $pdo->exec('SET search_path = discovery, public');
    }
    return $pdo;
}
}

// ---------- /whoami — viewer identity (cached per request) ----------
// Calls profile-app directly at /profile-api/v0/whoami (bypasses WP shim).
// WP shim adds ~6s boot cost per cold FPM worker; profile-app direct is ~100ms.
// The shim's WP-session bridge (get_current_user_id resolution) is a profile-app
// build item — until it ships, both paths return anon for WP-only users anyway.
// When profile-app ships the trusted-header bridge, update this URL back to the
// shim OR call profile-app directly with X-LG-WP-User-Id + X-LG-Internal-Auth.
// Returns null on failure; callers fall back to cookie-only values in that case.
// tier_unavailable:true (poller down) is treated as tier=public (fail open).
if (!function_exists('lg_archive_poc_whoami')) {
function lg_archive_poc_whoami(): ?array {
    static $fetched = false, $result = null;
    if ($fetched) return $result;
    $fetched = true;
    if (PHP_SAPI === 'cli') return null;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://127.0.0.1/profile-api/v0/whoami',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Host: ' . LG_ARCHIVE_POC_HOST,
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

// ---------- hostname → filesystem map (for thumb localization) ----------
if (!function_exists('lg_archive_poc_host_to_path_map')) {
function lg_archive_poc_host_to_path_map(): array {
    return [
        'https://' . LG_ARCHIVE_POC_HOST . '/' => LG_ARCHIVE_POC_WP_PATH . '/',
        'http://'  . LG_ARCHIVE_POC_HOST . '/' => LG_ARCHIVE_POC_WP_PATH . '/',
    ];
}
}
