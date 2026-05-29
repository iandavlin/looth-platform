<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Identity;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}

$secret = $_SERVER['HTTP_X_HOOK_SECRET'] ?? '';
if (LG_PROFILE_APP_HOOK_SECRET === '' || !hash_equals(LG_PROFILE_APP_HOOK_SECRET, $secret)) {
    profile_app_json(401, ['error' => 'bad_secret']);
}

$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) profile_app_json(400, ['error' => 'invalid_json']);

$wpId   = isset($in['wp_user_id']) ? (int)$in['wp_user_id'] : 0;
$email  = isset($in['email']) ? (string)$in['email'] : '';
$name   = isset($in['display_name']) ? (string)$in['display_name'] : null;
if ($wpId <= 0 || $email === '') profile_app_json(400, ['error' => 'missing_fields']);

$normalized = Identity::normalizeEmail($email);
$uuid       = Identity::computeUuid($email);

$pg = Db::pg();
$pg->beginTransaction();
try {
    $stmt = $pg->prepare('
        INSERT INTO users (uuid, primary_email, billing_email, contact_email, display_name)
        VALUES (:uuid, :email, :email, :email, :name)
        ON CONFLICT (uuid) DO UPDATE SET display_name = COALESCE(users.display_name, EXCLUDED.display_name)
        RETURNING id, (xmax = 0) AS inserted
    ');
    $stmt->execute([':uuid' => $uuid, ':email' => $normalized, ':name' => $name]);
    $row = $stmt->fetch();
    $userId   = (int)$row['id'];
    $inserted = (bool)$row['inserted'];

    $pg->prepare('
        INSERT INTO wp_user_bridge (user_id, wp_user_id)
        VALUES (:uid, :wp)
        ON CONFLICT (user_id) DO UPDATE SET wp_user_id = EXCLUDED.wp_user_id, synced_at = now()
    ')->execute([':uid' => $userId, ':wp' => $wpId]);

    $pg->prepare('
        INSERT INTO email_aliases (email_normalized, user_id, source)
        VALUES (:e, :u, :s)
        ON CONFLICT (email_normalized) DO NOTHING
    ')->execute([':e' => $normalized, ':u' => $userId, ':s' => 'wp']);

    $pg->commit();
} catch (Throwable $e) {
    $pg->rollBack();
    profile_app_json(500, ['error' => 'db_error', 'detail' => $e->getMessage()]);
}

profile_app_json(200, ['ok' => true, 'uuid' => $uuid, 'user_id' => $userId, 'created' => $inserted]);
