<?php
/**
 * membership-pages — standalone surface config.
 *
 * Pattern lifted from events/archive-poc/bb-mirror: standalone PHP served on
 * its own nginx route + FPM pool, NO WordPress boot. Reads page-content data
 * directly from WP's MySQL (read-only), renders on /srv/lg-shared/ chrome.
 * Viewer state for the header comes from a cached /whoami loopback. Listing
 * DATA never calls into WP.
 *
 * DB credentials: /etc/lg-membership-db (mode 640, never committed —
 * MANIFEST secret convention). Format = KEY=VALUE lines:
 *   DB_NAME=…  DB_USER=…  DB_PASSWORD=…  DB_HOST=localhost
 *
 * For first deploy on dev the events secret at /etc/lg-events-db is a valid
 * fallback (both surfaces read the same wp_options table read-only). See
 * SESSION-HANDOFF for the provisioning checklist.
 */

declare(strict_types=1);

if (defined('LG_MEMBERSHIP_ENV_LOADED')) return;
define('LG_MEMBERSHIP_ENV_LOADED', true);

/* ---------- env detection ---------- */
$env = getenv('LG_MEMBERSHIP_ENV');
if (!$env) {
    $host = $_SERVER['HTTP_HOST'] ?? gethostname();
    $env = ( str_starts_with((string)$host, 'dev.')
          || str_contains((string)$host, 'claude.loothgroup')
          || str_contains((string)$host, 'ip-172-31-81-87') ) ? 'dev' : 'live';
}
define('LG_MEMBERSHIP_ENV', $env);

if ($env === 'live') {
    define('LG_MEMBERSHIP_HOST', 'loothgroup.com');
} else {
    define('LG_MEMBERSHIP_HOST', 'dev.loothgroup.com');
}

define('LG_MEMBERSHIP_PUBLIC_PATH', '/membership-pages');   // assets mount
define('LG_MEMBERSHIP_TABLE_PREFIX', 'wp_');
define('LG_MEMBERSHIP_UPLOADS_BASE', 'https://' . LG_MEMBERSHIP_HOST . '/wp-content/uploads/');
define('LG_MEMBERSHIP_LOGO',
    'https://' . LG_MEMBERSHIP_HOST . '/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png');

/* ---------- DB secret with events-secret fallback (dev only) ---------- */
$db_secret_path = '/etc/lg-membership-db';
if (!is_readable($db_secret_path) && is_readable('/etc/lg-events-db')) {
    // Dev convenience: both surfaces read wp_options read-only. Live MUST
    // have its own /etc/lg-membership-db per the secret-isolation convention.
    $db_secret_path = '/etc/lg-events-db';
}
define('LG_MEMBERSHIP_DB_SECRET', $db_secret_path);

/* ---------- read-only WP-MySQL connection (no WP boot) ---------- */
if (!function_exists('lg_membership_db')) {
function lg_membership_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $raw = @file_get_contents(LG_MEMBERSHIP_DB_SECRET);
    if ($raw === false) {
        throw new RuntimeException('membership-pages: cannot read DB secret at ' . LG_MEMBERSHIP_DB_SECRET);
    }
    $c = ['DB_HOST' => 'localhost', 'DB_NAME' => '', 'DB_USER' => '', 'DB_PASSWORD' => ''];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = strtoupper(trim($k));
        if (array_key_exists($k, $c)) $c[$k] = trim($v);
    }
    $dsn = "mysql:host={$c['DB_HOST']};dbname={$c['DB_NAME']};charset=utf8mb4";
    $pdo = new PDO($dsn, $c['DB_USER'], $c['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
}

/* ---------- shared helpers ---------- */
if (!function_exists('lg_membership_h')) {
function lg_membership_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}
}
