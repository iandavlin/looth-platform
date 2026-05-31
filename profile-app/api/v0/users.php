<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Db;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}

// Batch user lookup by uuid list. Used by strangler surfaces that need to
// resolve author identity for many users at once (e.g. BB-mirror rendering
// a forum thread). Single round-trip, cap at 100.
$raw = $_GET['uuids'] ?? '';
if (!is_string($raw) || $raw === '') profile_app_json(400, ['error' => 'uuids_required']);

$uuids = [];
foreach (explode(',', $raw) as $u) {
    $u = strtolower(trim($u));
    if ($u !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $u)) {
        $uuids[$u] = true;
    }
}
$uuids = array_keys($uuids);

if (!$uuids)                 profile_app_json(400, ['error' => 'no_valid_uuids']);
if (count($uuids) > 100)     profile_app_json(400, ['error' => 'too_many', 'cap' => 100]);

$ph = implode(',', array_fill(0, count($uuids), '?'));
$st = Db::pg()->prepare("
    SELECT uuid, slug, display_name, avatar_url, at_a_glance
    FROM users
    WHERE uuid IN ($ph) AND archived_at IS NULL
");
$st->execute($uuids);

$items = [];
while ($r = $st->fetch()) {
    $items[] = [
        'uuid'         => $r['uuid'],
        'slug'         => $r['slug'] ?: null,
        'display_name' => $r['display_name'] ?? null,
        'avatar_url'   => $r['avatar_url'] ?? null,
        'bio'          => $r['at_a_glance'] ?? null,   // single-source author bio → bylines/author box
    ];
}

profile_app_json(200, ['items' => $items, 'count' => count($items)]);
