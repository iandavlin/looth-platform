<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$user = Auth::requireUser();
$in   = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) profile_app_json(400, ['error' => 'invalid_json']);

// Three accepted shapes — at most one per call:
//
//   1. Picker pick (Nominatim row):
//      { nominatim: { display_name, lat, lon, address: {...} } }
//
//   2. Text-only escape hatch (zero-results "save anyway"):
//      { text_only: "<freeform text>" }
//
//   3. Visibility toggle (single-field autosave):
//      { location_visibility: 'public' | 'members' | 'private' }
//
// Any combination is allowed in one call (the editor sends them separately
// in practice, but we don't enforce that here).

$nominatim   = $in['nominatim']           ?? null;
$textOnly    = $in['text_only']           ?? null;
$visibility  = $in['location_visibility'] ?? null;

$set    = [];
$params = [':id' => (int)$user['id']];

if (is_array($nominatim)) {
    $parsed = parse_nominatim($nominatim);
    if ($parsed === null) profile_app_json(400, ['error' => 'invalid_nominatim_row']);
    $set[] = 'location_text     = :text';
    $set[] = 'lat               = :lat';
    $set[] = 'lng               = :lng';
    $set[] = 'location_country  = :country';
    $set[] = 'location_region   = :region';
    $set[] = 'location_city     = :city';
    $set[] = 'location_postcode = :postcode';
    $set[] = 'place_id          = NULL';
    $set[] = 'place_result      = :raw::jsonb';
    $params += [
        ':text'     => $parsed['text'],
        ':lat'      => $parsed['lat'],
        ':lng'      => $parsed['lng'],
        ':country'  => $parsed['country'],
        ':region'   => $parsed['region'],
        ':city'     => $parsed['city'],
        ':postcode' => $parsed['postcode'],
        ':raw'      => json_encode($nominatim, JSON_UNESCAPED_SLASHES),
    ];
}

if (is_string($textOnly) && trim($textOnly) !== '') {
    if (!empty($set)) profile_app_json(400, ['error' => 'conflicting_fields']);
    $set[] = 'location_text     = :text';
    $set[] = 'lat               = NULL';
    $set[] = 'lng               = NULL';
    $set[] = 'location_country  = NULL';
    $set[] = 'location_region   = NULL';
    $set[] = 'location_city     = NULL';
    $set[] = 'location_postcode = NULL';
    $set[] = 'place_id          = NULL';
    $set[] = 'place_result      = NULL';
    $params[':text'] = trim($textOnly);
}

if (is_string($visibility)) {
    if (!in_array($visibility, Profile::LOCATION_VIS_VALUES, true)) {
        profile_app_json(400, ['error' => 'invalid_visibility']);
    }
    $set[] = 'location_visibility = :vis';
    $params[':vis'] = $visibility;
}

if (empty($set)) profile_app_json(400, ['error' => 'no_fields']);

$sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE id = :id';
Db::pg()->prepare($sql)->execute($params);

profile_app_json(200, ['ok' => true]);


/**
 * Map a Nominatim search-result row to our typed location columns.
 *
 * Nominatim shape (with addressdetails=1):
 *   { lat, lon, display_name, address: {
 *       city|town|village|hamlet, state, country, postcode, country_code, …
 *     } }
 */
function parse_nominatim(array $row): ?array
{
    if (!isset($row['display_name'])) return null;
    $lat = isset($row['lat']) ? (float)$row['lat'] : null;
    $lng = isset($row['lon']) ? (float)$row['lon'] : null;
    $a = $row['address'] ?? [];
    $city = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['hamlet'] ?? $a['suburb'] ?? null;
    return [
        'text'     => (string)$row['display_name'],
        'lat'      => $lat,
        'lng'      => $lng,
        'country'  => $a['country']  ?? null,
        'region'   => $a['state']    ?? $a['region'] ?? null,
        'city'     => $city,
        'postcode' => $a['postcode'] ?? null,
    ];
}
