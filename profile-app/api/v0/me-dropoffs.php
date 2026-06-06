<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';   // not in config.php's require list (yet)

/**
 * Drop-off-locations block — a list of business drop-off points, each
 * {name, address, hours, notes}. Stored as one profile_sections row
 * (key='dropoffs', data JSONB {items:[...]}); the block-level visibility
 * lives on the same row (pmp, ceiling-capped at render). No dedicated table.
 *
 *   GET → the assembled drop-offs block (Block::loadDropoffs).
 *   PUT → { items?: [{name,address,hours,notes}], visibility?: 'public'|'member'|'private' }
 *         items replaces the whole list; visibility sets the block pmp. At least
 *         one of the two is required. Omitting items preserves the stored list.
 *
 * NOTE TO COORDINATOR (nginx — strangler-profile-app.conf at merge):
 *   1. rewrite "^/profile-api/v0/me/dropoffs/?$" /profile-api/v0/me-dropoffs.php last;
 *   2. add `me-dropoffs` to the auth-gated /me/*\.php allowlist regex.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;
use Looth\ProfileApp\Profile;

$user   = Auth::requireUser();
$uid    = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $block = Block::loadDropoffs($uid);
    if ($block === null) profile_app_json(404, ['error' => 'not_found']);
    profile_app_json(200, $block);
}

if ($method !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) profile_app_json(400, ['error' => 'invalid_json']);

$hasItems = isset($in['items']) && is_array($in['items']);
$hasVis   = array_key_exists('visibility', $in);
if (!$hasItems && !$hasVis) profile_app_json(400, ['error' => 'items_or_visibility_required']);

// Validate the block visibility early so we never half-write.
if ($hasVis && Block::visFromInput($in['visibility']) === null) {
    profile_app_json(400, ['error' => 'invalid_visibility', 'allowed' => ['public', 'member', 'private']]);
}

// Omitting items keeps the stored list (visibility-only update).
if ($hasItems) {
    if (count($in['items']) > Profile::DROPOFFS_MAX) {
        profile_app_json(400, ['error' => 'too_many', 'max' => Profile::DROPOFFS_MAX]);
    }
    $items = [];
    foreach ($in['items'] as $i => $item) {
        if (!is_array($item)) profile_app_json(400, ['error' => "item_{$i}_not_object"]);
        $name    = (string)($item['name'] ?? '');
        $address = (string)($item['address'] ?? '');
        $hours   = (string)($item['hours'] ?? '');
        $notes   = (string)($item['notes'] ?? '');
        foreach (['name' => $name, 'address' => $address, 'hours' => $hours, 'notes' => $notes] as $f => $v) {
            if (strlen($v) > Profile::DROPOFF_FIELD_MAX) {
                profile_app_json(400, ['error' => "field_too_long_at_$i", 'field' => $f, 'max' => Profile::DROPOFF_FIELD_MAX]);
            }
        }
        $items[] = [
            'name'    => $name,
            'address' => $address,
            'hours'   => $hours,
            'notes'   => $notes,
            'lat'     => $item['lat'] ?? null,   // optional: seed exact pin coords; else server geocodes
            'lng'     => $item['lng'] ?? null,
        ];
    }
} else {
    $cur   = Block::loadDropoffs($uid);
    $items = $cur['items'] ?? [];
}

$shape = Block::saveDropoffs($uid, $items, $hasVis ? (string)$in['visibility'] : null);
if ($shape === null) profile_app_json(404, ['error' => 'not_found']);

profile_app_json(200, ['ok' => true, 'dropoffs' => $shape]);
