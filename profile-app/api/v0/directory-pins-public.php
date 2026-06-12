<?php
declare(strict_types=1);
/**
 * /profile-api/v0/directory/pins-public — the ANONYMOUS member-map layer.
 *
 * The Strava-heatmap pattern (Ian 6/12): logged-out visitors see the REAL
 * spread of the community as aggregated, de-identified density — cluster
 * bubbles with counts — and identity/zoom detail is what logging in unlocks.
 *
 * Privacy contract (strictly coarser than anything already public):
 *   - NO names, slugs, UUIDs, or anything clickable-through. Payload is
 *     grid cells only: [lat, lng, count].
 *   - Coordinates rounded to 1 decimal (~11 km cells) — coarser than the
 *     'city' precision the product already defines as the public default.
 *   - Honors every per-user control the members map honors: location section
 *     on the profile (or never-customized default), and
 *     location_public_precision <> 'private'.
 *   - Aggregate of public-by-choice coarse positions; cells are NOT filtered
 *     by count because an ~11km anonymous dot carries less information than
 *     the city name those members already chose to show publicly.
 *
 * Cacheable: the aggregate changes slowly; 15 min public cache.
 */
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Db;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);

$pg = Db::pg();
$rows = $pg->query("
    SELECT round(lat::numeric, 1) AS cell_lat,
           round(lng::numeric, 1) AS cell_lng,
           count(*)               AS n
      FROM users u
     WHERE u.archived_at IS NULL
       AND u.lat IS NOT NULL AND u.lng IS NOT NULL
       AND EXISTS (SELECT 1 FROM profiles p WHERE p.user_id = u.id)
       AND (u.profile_layout IS NULL OR u.profile_layout @> '[\"location\"]'::jsonb)
       AND COALESCE(u.location_public_precision, 'private') <> 'private'   -- NULL = never consented = members-only (Ian 6/12)
     GROUP BY cell_lat, cell_lng
")->fetchAll();

$cells = [];
$total = 0;
foreach ($rows as $r) {
    $n = (int)$r['n'];
    $total += $n;
    $cells[] = [(float)$r['cell_lat'], (float)$r['cell_lng'], $n];
}

header('Cache-Control: public, max-age=900');
profile_app_json(200, ['count' => $total, 'cells' => $cells]);
