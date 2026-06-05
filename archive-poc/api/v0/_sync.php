<?php
/**
 * archive-poc/api/v0/_sync.php — single-post reindex endpoint.
 *
 * Loopback-only (nginx restricts $remote_addr to 127.0.0.1). Bootstraps WP
 * via the looth-dev FPM pool, then calls archive_poc_index_post().
 *
 * Request body (JSON or form-encoded):
 *   { "post_id": <int>, "action": "upsert" | "delete" }
 *
 * 204 on success, 4xx/5xx with JSON {error} otherwise.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Defense in depth — nginx should already gate this.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if ($remote !== '127.0.0.1' && $remote !== '::1') {
    http_response_code(403);
    echo json_encode(['error' => 'loopback only']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

// Parse payload: JSON body or form-encoded fallback.
$raw = file_get_contents('php://input') ?: '';
$body = $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($body)) $body = $_POST;

$post_id = (int) ($body['post_id'] ?? 0);
$sync_action  = (string) ($body['action'] ?? 'upsert');
if ($post_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'post_id required']);
    exit;
}

// Boot WP. Picks the right docroot based on what's installed (live uses
// /var/www/html, dev uses /var/www/dev). Host header is set from the same
// choice so WP's siteurl resolves correctly.
if (!function_exists('get_post')) {
    $wp_candidates = [
        '/var/www/html/wp-load.php' => 'loothgroup.com',
        '/var/www/dev/wp-load.php'  => 'dev.loothgroup.com',
    ];
    $wp_load = null; $wp_host = null;
    foreach ($wp_candidates as $path => $host) {
        if (is_file($path)) { $wp_load = $path; $wp_host = $host; break; }
    }
    if ($wp_load === null) {
        http_response_code(500);
        echo json_encode(['error' => 'no wp-load.php found']);
        exit;
    }
    if (!isset($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = $wp_host;
    if (!defined('WP_USE_THEMES'))     define('WP_USE_THEMES', false);
    require $wp_load;
}

require_once __DIR__ . '/../../bin/indexer.php';

// The sync WRITER targets Postgres (the live read-store) — symmetric with the
// PG read cutover. Default to PG; LG_ARCHIVE_POC_DSN overrides (e.g. a sqlite:
// DSN to also refresh the legacy index during the soak, or for tests). Runs on
// the looth-dev FPM pool → peer-auths to the looth-dev pg role, which the
// discovery schema grants INSERT/UPDATE/DELETE on (the WP-side sync writer).
try {
    $dsn = getenv('LG_ARCHIVE_POC_DSN') ?: 'pgsql:host=/var/run/postgresql;dbname=looth';
    $db  = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($drv === 'pgsql')      $db->exec('SET search_path = discovery, public');
    elseif ($drv === 'sqlite') $db->exec('PRAGMA journal_mode = WAL');
} catch (Throwable $e) {
    error_log('archive-poc-sync: db open failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'db open failed']);
    exit;
}

try {
    if ($sync_action === 'delete') {
        archive_poc_delete_post($db, $post_id);
        $result = ['action' => 'delete', 'kind' => null];
    } else {
        $result = archive_poc_index_post($db, $post_id);
    }
} catch (Throwable $e) {
    error_log('archive-poc-sync: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'index failure', 'detail' => $e->getMessage()]);
    exit;
}

http_response_code(200);
echo json_encode(['ok' => true, 'post_id' => $post_id] + $result);
