<?php
/**
 * profile-app — avatar URL backfill.
 *
 * For every users row with avatar_url IS NULL, populate via:
 *   1. BuddyBoss avatar upload    /wp-content/uploads/avatars/<wp_user_id>/*-bpfull.*
 *   2. Gravatar URL with the Looth default as fallback
 *
 * The rendering layer doesn't need to know which source — just an
 * absolute URL. Idempotent (only touches NULL avatar_url rows).
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Db;

const LOOTH_DEFAULT_AVATAR = 'https://dev.loothgroup.com/wp-content/uploads/avatars/0/674d94a75ed58-bpfull.jpg';
const AVATAR_BASE_URL      = 'https://dev.loothgroup.com/wp-content/uploads/avatars';
const AVATAR_BASE_FS       = '/var/www/dev/wp-content/uploads/avatars';

$pg = Db::pg();

$rows = $pg->query("
    SELECT u.id, u.primary_email, b.wp_user_id
    FROM users u
    JOIN wp_user_bridge b ON b.user_id = u.id
    WHERE u.avatar_url IS NULL
")->fetchAll(PDO::FETCH_ASSOC);

printf("backfill-avatars: %d candidates\n", count($rows));

$upd = $pg->prepare("UPDATE users SET avatar_url = :url WHERE id = :id");
$counts = ['bb' => 0, 'gravatar' => 0, 'skipped' => 0];

foreach ($rows as $r) {
    $wpId  = (int)$r['wp_user_id'];
    $email = (string)$r['primary_email'];

    $bb = bb_avatar_url($wpId);
    if ($bb !== null) {
        $upd->execute([':url' => $bb, ':id' => $r['id']]);
        $counts['bb']++;
        continue;
    }
    if ($email !== '') {
        $url = gravatar_url($email);
        $upd->execute([':url' => $url, ':id' => $r['id']]);
        $counts['gravatar']++;
        continue;
    }
    $counts['skipped']++;
}

foreach ($counts as $k => $v) printf("  %-10s %d\n", $k, $v);


function bb_avatar_url(int $wpId): ?string
{
    $dir = AVATAR_BASE_FS . '/' . $wpId;
    if (!is_dir($dir)) return null;
    $matches = glob($dir . '/*-bpfull.*') ?: [];
    if (!$matches) return null;
    // Pick newest (BB rewrites on upload).
    usort($matches, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $basename = basename($matches[0]);
    return AVATAR_BASE_URL . '/' . $wpId . '/' . $basename;
}

function gravatar_url(string $email): string
{
    $hash = md5(strtolower(trim($email)));
    return 'https://www.gravatar.com/avatar/' . $hash
        . '?d=' . urlencode(LOOTH_DEFAULT_AVATAR)
        . '&s=400';
}
