<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;
use Looth\ProfileApp\Block;

/** Great-circle miles — distance is computed from the DISPLAYED (precision-coarsened) point so it never leaks precision. */
function dir_haversine_mi(float $la1, float $lo1, float $la2, float $lo2): float
{
    $r = 3958.8;
    $dLa = deg2rad($la2 - $la1);
    $dLo = deg2rad($lo2 - $lo1);
    $a = sin($dLa / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLo / 2) ** 2;
    return $r * 2 * asin(min(1.0, sqrt($a)));
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);

$viewer       = Auth::currentUser();
$viewerUserId = $viewer ? (int)$viewer['id'] : 0;
$role         = $viewer ? 'member' : 'public';

$lat    = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lng    = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
$radius = isset($_GET['radius']) ? max(1, min(500, (int)$_GET['radius'])) : 50;
$insts  = isset($_GET['inst'])  ? (array)$_GET['inst']  : [];
$skills = isset($_GET['skill']) ? (array)$_GET['skill'] : [];
$scenes = isset($_GET['scene']) ? (array)$_GET['scene'] : [];
$creds  = isset($_GET['cred'])  ? (array)$_GET['cred']  : [];
$page   = max(1, (int)($_GET['page'] ?? 1));
$pageSize = isset($_GET['page_size']) ? max(1, min(200, (int)$_GET['page_size'])) : 20;
$offset   = ($page - 1) * $pageSize;

$pg = Db::pg();

$wheres = [
    'EXISTS (SELECT 1 FROM profiles p WHERE p.user_id = u.id)',
    'u.archived_at IS NULL',
];
$params = [];

if ($insts) {
    $ph = [];
    foreach ($insts as $i => $s) { $k = ":i$i"; $ph[] = $k; $params[$k] = (string)$s; }
    $wheres[] = "EXISTS (SELECT 1 FROM profile_instruments pi
                         JOIN instrument_catalog ic ON ic.id = pi.instrument_id
                         WHERE pi.user_id = u.id AND ic.slug IN (" . implode(',', $ph) . "))";
}
if ($skills) {
    $ph = [];
    foreach ($skills as $i => $s) { $k = ":sk$i"; $ph[] = $k; $params[$k] = (string)$s; }
    $wheres[] = "EXISTS (SELECT 1 FROM profile_skills ps
                         JOIN skill_catalog sc ON sc.id = ps.skill_id
                         WHERE ps.user_id = u.id AND sc.slug IN (" . implode(',', $ph) . "))";
}
if ($scenes) {
    $ph = [];
    foreach ($scenes as $i => $s) { $k = ":sc$i"; $ph[] = $k; $params[$k] = (string)$s; }
    $wheres[] = "EXISTS (SELECT 1 FROM profile_scenes ps WHERE ps.user_id = u.id AND ps.scene_slug IN (" . implode(',', $ph) . "))";
}
if ($creds) {
    $ph = [];
    foreach ($creds as $i => $s) { $k = ":cr$i"; $ph[] = $k; $params[$k] = (string)$s; }
    $wheres[] = "EXISTS (SELECT 1 FROM profile_credentials pc
                         JOIN credential_catalog cc ON cc.id = pc.catalog_id
                         WHERE pc.owner_type='profile' AND pc.owner_id = u.id AND cc.slug IN (" . implode(',', $ph) . "))";
}

$selectDistance = '';
$orderBy = 'u.id DESC';
if ($lat !== null && $lng !== null) {
    // earthdistance: point(lng, lat) <@> point(lng, lat) returns miles.
    // Geo-filter implicitly hides users we can't see location for (their
    // lat/lng never enter the query); that's correct — they're invisible
    // on the map but still surface in the un-filtered list.
    $selectDistance = ', (point(u.lng, u.lat) <@> point(:lng, :lat)) AS distance_mi';
    // Privacy: a user only appears on the map when their precision for THIS audience isn't 'private'
    // (members → members_precision default city; public → public_precision default private).
    $wheres[] = '(u.lat IS NOT NULL AND u.lng IS NOT NULL AND (point(u.lng, u.lat) <@> point(:lng, :lat)) <= :radius
                  AND (CASE WHEN :authed = 1 THEN COALESCE(u.location_members_precision, \'city\')
                                             ELSE COALESCE(u.location_public_precision, \'private\') END) <> \'private\')';
    $orderBy  = 'distance_mi ASC';
    $params[':lat'] = $lat; $params[':lng'] = $lng; $params[':radius'] = $radius;
    $params[':authed'] = $viewerUserId !== 0 ? 1 : 0;
}

$sql = "SELECT u.id, u.uuid, u.display_name, u.avatar_url,
               u.location_text, u.location_address, u.location_city, u.location_region, u.location_country, u.location_postcode,
               u.lat, u.lng, u.location_members_precision, u.location_public_precision, u.slug
               $selectDistance
        FROM users u
        WHERE " . implode(' AND ', $wheres) . "
        ORDER BY $orderBy
        LIMIT $pageSize OFFSET $offset";
$stmt = $pg->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Total count (no distance — separate query). Reuses $wheres / $params so
// it matches the filtered result set.
$countSql = "SELECT COUNT(*) FROM users u WHERE " . implode(' AND ', $wheres);
$cStmt = $pg->prepare($countSql);
$cStmt->execute($params);
$total = (int)$cStmt->fetchColumn();

// Pull highlights for each result.
$results = [];
if ($rows) {
    $userIds = array_map(fn($r) => (int)$r['id'], $rows);
    $hPh = implode(',', array_fill(0, count($userIds), '?'));
    $hStmt = $pg->prepare("
        SELECT h.user_id, h.kind, h.ref_id, h.sort_order,
               CASE WHEN h.kind='instrument' THEN ic.slug ELSE sc.slug END AS slug,
               CASE WHEN h.kind='instrument' THEN ic.name ELSE sc.name END AS name
        FROM profile_highlights h
        LEFT JOIN instrument_catalog ic ON h.kind='instrument' AND ic.id = h.ref_id
        LEFT JOIN skill_catalog      sc ON h.kind='skill'      AND sc.id = h.ref_id
        WHERE h.user_id IN ($hPh) ORDER BY h.user_id, h.sort_order");
    $hStmt->execute($userIds);
    $highlightsByUser = [];
    while ($h = $hStmt->fetch()) {
        $highlightsByUser[(int)$h['user_id']][] = ['kind' => $h['kind'], 'slug' => $h['slug'], 'name' => $h['name']];
    }

    $audience = $viewerUserId !== 0 ? 'members' : 'public';
    foreach ($rows as $r) {
        $subjectId = (int)$r['id'];
        // Per-audience precision (owner viewing self → full street); coarsens the pin or hides it.
        if ($subjectId === $viewerUserId) {
            $precision = 'street';
        } else {
            $raw = $audience === 'members' ? $r['location_members_precision'] : $r['location_public_precision'];
            $precision = Block::precisionFromInput($raw) ?? ($audience === 'members' ? 'city' : 'private');
        }
        $place = [
            'address'  => $r['location_address'],
            'postcode' => $r['location_postcode'],
            'city'     => $r['location_city'],
            'region'   => $r['location_region'],
            'country'  => $r['location_country'],
            'lat'      => $r['lat'] !== null ? (float)$r['lat'] : null,
            'lng'      => $r['lng'] !== null ? (float)$r['lng'] : null,
            'text'     => $r['location_text'],
        ];
        $disp = Block::locationDisplay($place, $precision);          // null when private for this audience
        $loc  = $disp
            ? ['text' => $disp['text'], 'lat' => $disp['lat'], 'lng' => $disp['lng'], 'zoom' => $disp['zoom'], 'kind' => $disp['kind']]
            : ['hidden' => true];

        // Distance from the DISPLAYED (coarsened) point, so it matches the pin's precision.
        $dist = null;
        if ($disp && $lat !== null && $lng !== null && $disp['lat'] !== null && $disp['lng'] !== null) {
            $dist = round(dir_haversine_mi((float)$lat, (float)$lng, (float)$disp['lat'], (float)$disp['lng']), 1);
        }

        $results[] = [
            'uuid'         => $r['uuid'],
            'slug'         => $r['slug'] ?: (string)$subjectId,
            'display_name' => $r['display_name'],
            'avatar_url'   => $r['avatar_url'],
            'location'     => $loc,
            'highlights'   => $highlightsByUser[$subjectId] ?? [],
            'distance_mi'  => $dist,
        ];
    }
}

profile_app_json(200, [
    'total'     => $total,
    'page'      => $page,
    'page_size' => $pageSize,
    'has_more'  => ($offset + count($results)) < $total,
    'items'     => $results,
]);
