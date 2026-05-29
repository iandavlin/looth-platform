<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_render.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Profile;

$viewer = Auth::currentUser();

if (!$viewer) {
    // If the user has a WP session cookie but no looth_id yet (the
    // direct-link / bookmark / email-link case), bounce through the WP
    // mu-plugin's issue endpoint to mint and 302 right back. Invisible hop.
    $hasWpSession = false;
    foreach ($_COOKIE as $name => $_) {
        if (strpos($name, 'wordpress_logged_in_') === 0) { $hasWpSession = true; break; }
    }
    if ($hasWpSession) {
        $return = $_SERVER['REQUEST_URI'] ?? '/profile/edit';
        header('Location: /wp-json/looth/auth/issue?return=' . urlencode($return));
        exit;
    }
    looth_render_login_interstitial('/profile/edit');
    exit;
}

if (!Profile::hasClaimed((int)$viewer['id'])) {
    looth_render_claim_interstitial($viewer);
    exit;
}

$full = Profile::loadFull((int)$viewer['id']);
$role = 'me';
looth_render_editor($full, 'editor', $role);
