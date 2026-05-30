<?php
require __DIR__.'/../config.php';
/**
 * archive-poc/web/_chrome.php — site header partial.
 *
 * Thin adapter: resolves viewer state from /whoami and passes it to the
 * shared header partial at /srv/lg-shared/site-header.php.
 *
 * Required vars from caller:
 *   $is_member (bool)   — whether the viewer is a logged-in member
 *
 * Globals consumed (set by index.php from /whoami):
 *   $GLOBALS['LG_VIEWER_TIER']  — 'public'|'lite'|'pro'
 */

require_once '/srv/lg-shared/site-header.php';

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

// Viewer state from /whoami (cached per request by lg_archive_poc_whoami())
$_whoami      = lg_archive_poc_whoami();
$_viewer_tier = $GLOBALS['LG_VIEWER_TIER'] ?? 'public';
$_caps        = $_whoami['capabilities'] ?? [];

$_display_name = '';
if ($is_member) {
    // Prefer /whoami display_name; fall back to WP cookie parse.
    $_display_name = $_whoami['display_name'] ?? '';
    if ($_display_name === '') {
        foreach ($_COOKIE as $name => $val) {
            if (str_starts_with($name, 'wordpress_logged_in_')) {
                $parts = explode('|', urldecode($val), 4);
                if (!empty($parts[0])) { $_display_name = $parts[0]; break; }
            }
        }
    }
    if ($_display_name === '') $_display_name = 'Account';
}

lg_shared_render_site_header([
    'authenticated'      => $is_member,
    'tier'               => $_viewer_tier,
    'display_name'       => $_display_name,
    'avatar_url'         => $_whoami['avatar_url'] ?? null,
    'capabilities'       => $_caps,
    'msg_unread'         => null,          // lazy-loaded by archive.js
    'notif_unread'       => null,          // lazy-loaded by archive.js
    'logo_url'           => LG_ARCHIVE_POC_LOGO_URL,
    'search_id'          => 'chrome-q',    // archive.js listens for #chrome-q
    'search_placeholder' => 'Search the archive…',
    'profile_url'        => '/profile/edit',
    // Which nav item to suppress (you don't link to the page you're on). The
    // /archive/ search page sets 'archive'; the front page sets '' so the
    // Archive button shows. Defaults to 'archive' if a caller doesn't set it.
    'active_nav'         => $lg_active_nav ?? 'archive',
]);
