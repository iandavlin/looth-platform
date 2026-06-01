<?php
/**
 * profile-app env config.
 *
 * Slice-zero: identity backbone only. Postgres-backed. Mirrors archive-poc's
 * env-detection pattern.
 */

declare(strict_types=1);

if (defined('LG_PROFILE_APP_ENV_LOADED')) return;
define('LG_PROFILE_APP_ENV_LOADED', true);

/**
 * Mint-bounce for WEB surfaces. A viewer with a valid WP session cookie but no
 * looth_id is bounced ONCE through the WP /issue endpoint to mint a looth_id,
 * then 302'd back. Genuine anonymous viewers (no WP cookie) and stale/expired
 * sessions fall through via a one-shot guard cookie and render anonymously.
 *
 * Generalizes web/edit.php's inline hop so every strangled surface mints
 * identity for logged-in WP users even when no WP-rendered page (which fires the
 * init-mint hook) was visited. Call at the TOP of a web entrypoint, before any
 * output. NEVER call from an API endpoint — those must return JSON 401.
 */
function looth_issue_bounce_if_needed(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    if (!empty($_COOKIE['looth_id'])) return;                  // already have identity
    $hasWp = false;
    foreach ($_COOKIE as $n => $_v) { if (strncmp($n, 'wordpress_logged_in_', 20) === 0) { $hasWp = true; break; } }
    if (!$hasWp) return;                                       // genuine anonymous viewer — no bounce
    if (!empty($_COOKIE['looth_issue_tried'])) return;         // one-shot guard: stale session / mint failure can't loop
    setcookie('looth_issue_tried', '1', ['expires' => time() + 120, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    $ret = $_SERVER['REQUEST_URI'] ?? '/';
    if ($ret === '' || $ret[0] !== '/') $ret = '/';
    header('Location: /wp-json/looth/auth/issue?return=' . rawurlencode($ret));
    exit;
}

$env = getenv('LG_PROFILE_APP_ENV');
if (!$env) {
    $host = $_SERVER['HTTP_HOST'] ?? gethostname();
    if (str_starts_with((string)$host, 'dev.') || str_contains((string)$host, 'ip-172-31-81-87') || str_contains((string)$host, 'claude.loothgroup')) {
        $env = 'dev';
    } else {
        $env = 'live';
    }
}
define('LG_PROFILE_APP_ENV', $env);

if ($env === 'live') {
    define('LG_PROFILE_APP_HOST',        'loothgroup.com');
    define('LG_PROFILE_APP_WP_PATH',     '/var/www/html');
    define('LG_PROFILE_APP_APP_ROOT',    '/srv/profile-app');
    define('LG_PROFILE_APP_PG_DSN',      'pgsql:host=/var/run/postgresql;dbname=profile_app');
    define('LG_PROFILE_APP_MYSQL_DB',    'looth_live');
    define('LG_PROFILE_APP_MYSQL_BILLING_DB', 'lg_membership');
} else {
    define('LG_PROFILE_APP_HOST',        'dev.loothgroup.com');
    define('LG_PROFILE_APP_WP_PATH',     '/var/www/dev');
    define('LG_PROFILE_APP_APP_ROOT',    '/home/ubuntu/projects/profile-app');
    define('LG_PROFILE_APP_PG_DSN',      'pgsql:host=/var/run/postgresql;dbname=profile_app');
    define('LG_PROFILE_APP_MYSQL_DB',    'looth_dev');
    define('LG_PROFILE_APP_MYSQL_BILLING_DB', 'lg_membership');
}

// Hardcoded identity namespace — DO NOT CHANGE. The whole point of v5 is that
// the same email computes the same UUID across services. lg-stripe-billing
// will use this same constant. Rotating it would orphan every previously-
// computed identity.
define('LOOTH_IDENTITY_NAMESPACE', 'eaef23f7-9bc9-4a95-ac49-ffff632e6646');

// Shared webhook secret. Lives outside source at /etc/lg-profile-app-secret
// (mode 640). Mu-plugin reads from wp_options['profile_hook_secret'] which
// must match.
if (!defined('LG_PROFILE_APP_HOOK_SECRET')) {
    $_s = @file_get_contents('/etc/lg-profile-app-secret');
    define('LG_PROFILE_APP_HOOK_SECRET', $_s !== false ? trim($_s) : '');
    unset($_s);
}

require_once LG_PROFILE_APP_APP_ROOT . '/vendor/autoload.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Identity.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Db.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Auth.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Profile.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Practice.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/GeoIP.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Cache.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Whoami.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';   // profile-2.0 spine (centralized per coordinator ask)
require_once LG_PROFILE_APP_APP_ROOT . '/src/Mint.php';    // looth_id signing (shim-replacement)
require_once LG_PROFILE_APP_APP_ROOT . '/src/Connections.php';   // social layer
require_once LG_PROFILE_APP_APP_ROOT . '/src/Messaging.php';     // social layer
require_once LG_PROFILE_APP_APP_ROOT . '/src/Notifications.php'; // social layer
require_once LG_PROFILE_APP_APP_ROOT . '/src/Social.php';        // social layer widget + counts
