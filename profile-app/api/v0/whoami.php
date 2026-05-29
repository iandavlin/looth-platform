<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Whoami;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}

// WP-session bridge: if the shim passed a trusted X-LG-WP-User-Id header
// (guarded by X-LG-Internal-Auth verified via hash_equals), use
// buildForWpUserId() directly — no JWT cookie required. Spoofing without
// the secret falls through to JWT/anon resolve().
$wpUserIdHeader = isset($_SERVER['HTTP_X_LG_WP_USER_ID'])
    ? (int)$_SERVER['HTTP_X_LG_WP_USER_ID']
    : 0;

if ($wpUserIdHeader > 0 && Whoami::verifyInternalAuth()) {
    $payload = Whoami::buildForWpUserId($wpUserIdHeader);
} else {
    $payload = Whoami::resolve();
}

// Honor ETag if caller sent If-None-Match.
$etag = $payload['cache']['etag'] ?? null;
if ($etag && ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=' . (\Looth\ProfileApp\Cache::WHOAMI_TTL));
    exit;
}

if ($etag) header('ETag: ' . $etag);
header('Cache-Control: private, max-age=' . (\Looth\ProfileApp\Cache::WHOAMI_TTL));
profile_app_json(200, $payload);
