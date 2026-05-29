<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$user = Auth::requireUser();
$in   = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !isset($in['items']) || !is_array($in['items'])) {
    profile_app_json(400, ['error' => 'items_required']);
}

$clean = [];
foreach ($in['items'] as $i => $item) {
    if (!is_array($item)) profile_app_json(400, ['error' => "item_$i_not_object"]);
    $kind  = $item['kind']  ?? null;
    $value = $item['value'] ?? null;
    $sort  = $item['sort_order'] ?? $i;
    if (!in_array($kind, Profile::SOCIAL_KINDS, true)) profile_app_json(400, ['error' => "invalid_kind_at_$i"]);
    if (!is_string($value) || trim($value) === '') profile_app_json(400, ['error' => "empty_value_at_$i"]);
    $value = trim($value);

    switch ($kind) {
        case 'email':
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) profile_app_json(400, ['error' => "bad_email_at_$i"]);
            break;
        case 'phone':
            if (!preg_match('/^[\d\s\-\+\(\)\.x]{4,}$/', $value)) profile_app_json(400, ['error' => "bad_phone_at_$i"]);
            break;
        case 'web':
            if (!preg_match('#^https?://#i', $value)) $value = 'https://' . ltrim($value, '/');
            if (!filter_var($value, FILTER_VALIDATE_URL)) profile_app_json(400, ['error' => "bad_url_at_$i"]);
            break;
        default:
            // handle/username for the social platforms — normalize: strip leading @, strip URL prefix if user pasted full URL
            $value = preg_replace('#^https?://[^/]+/#i', '', $value);
            $value = ltrim($value, '@/');
            if ($value === '' || strlen($value) > 200) profile_app_json(400, ['error' => "bad_handle_at_$i"]);
            break;
    }

    $clean[] = ['kind' => $kind, 'value' => $value, 'sort_order' => (int)$sort];
}

$pg = Db::pg();
$pg->beginTransaction();
try {
    $pg->prepare('DELETE FROM profile_socials WHERE user_id = :u')->execute([':u' => (int)$user['id']]);
    $ins = $pg->prepare('INSERT INTO profile_socials (user_id, kind, value, sort_order) VALUES (:u, :k, :v, :s)');
    foreach ($clean as $item) {
        $ins->execute([':u' => (int)$user['id'], ':k' => $item['kind'], ':v' => $item['value'], ':s' => $item['sort_order']]);
    }
    $pg->commit();
} catch (Throwable $e) {
    $pg->rollBack();
    profile_app_json(500, ['error' => 'db_error', 'detail' => $e->getMessage()]);
}

profile_app_json(200, ['ok' => true, 'items' => $clean]);
