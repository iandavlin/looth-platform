<?php
/**
 * archive-poc/api/v0/_comments.php — content-comments store (comments lane).
 *
 * Pulls content comments OUT of WordPress. The modal read path (comments.php) runs
 * on the archive-poc FPM pool and reads this table directly — no WP boot, ~50ms —
 * replacing the WP-booting deploy/lg-comments-frame.php (~1–3s). The write path
 * (comment-post.php) runs on the looth-dev WP pool so it can validate the WP login
 * cookie (the posting gate — per the gate-on-WP-cookie-not-/whoami rule: an
 * unbridged member has a valid WP cookie but /whoami reads them anon, so gating on
 * /whoami would lock real members out of posting).
 *
 * Storage = Postgres `discovery` schema (alongside likes, article_blobs,
 * content_item). Keyed (post_type, item_id) exactly like discovery.likes, where
 * item_id mirrors wp_posts.ID. Author identity = the shared user_uuid contract,
 * resolved to a LIVE profile card at read time via /profile-api/v0/users (so renames
 * / new avatars follow); legacy anonymous commenters carry a frozen author_name with
 * a NULL user_uuid.
 *
 * shared by: comments.php (read), comment-post.php (write), bin/backfill-comments.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../config.php';   // PDO DSN env + LG_ARCHIVE_POC_HOST

/**
 * Content post-types whose comments live in this store (Ian, 2026-06-04). shop_order
 * (WooCommerce order notes) is deliberately EXCLUDED — internal order data, not
 * community comments. Forum replies stay in bb-mirror. Single source of truth for
 * the read/write allowlist AND the backfill filter.
 *
 * NOTE (flagged to coordinator): the stream surfaces four more content types that
 * also carry comments — loothcuts, useful_links, member-benefit, sponsor-post
 * (~21 rows on dev). They are NOT in Ian's explicit list; left out pending a call.
 * Adding them later = extend this array (+ re-run backfill); nothing else changes.
 */
if (!defined('LG_COMMENTS_TYPES')) {
    define('LG_COMMENTS_TYPES', [
        'loothprint', 'post-type-videos', 'post-imgcap', 'post',
        'shorty', 'coe-questions', 'ajde_events',
    ]);
}

if (!function_exists('lg_comments_pdo')) {
/**
 * Postgres handle for the comments store. Identical shape to lg_likes_pdo(): the
 * pg role comes from the FPM pool's OS user via peer auth — archive-poc (read /
 * owner) on the modal pool, looth-dev (write) on the WP pool. search_path pinned to
 * discovery.
 */
function lg_comments_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = getenv('LG_ARCHIVE_POC_DSN') ?: 'pgsql:host=/var/run/postgresql;dbname=looth';
    $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $pdo->exec('SET search_path = discovery, public');
    }
    return $pdo;
}
}

if (!function_exists('lg_comments_is_uuid')) {
function lg_comments_is_uuid(?string $s): bool {
    return is_string($s) && (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}
}

if (!function_exists('lg_comments_thread')) {
/**
 * All visible comments for one content item, oldest-first. Flat rows; the renderer
 * assembles the parent/child tree. Only `approved` status is returned to readers.
 *
 * @return array<int,array> rows with keys: id, parent_id, user_uuid, author_name,
 *         body, created_at (unix int)
 */
function lg_comments_thread(PDO $pdo, string $postType, int $itemId): array {
    $st = $pdo->prepare(
        "SELECT id, parent_id, user_uuid, author_name, body,
                EXTRACT(EPOCH FROM created_at)::bigint AS created_at
         FROM comments
         WHERE post_type = ? AND item_id = ? AND status = 'approved'
         ORDER BY created_at ASC, id ASC");
    $st->execute([$postType, $itemId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
}

if (!function_exists('lg_comments_count')) {
/** Fast approved-comment count for one content item (modal header / card badge). */
function lg_comments_count(PDO $pdo, string $postType, int $itemId): int {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM comments WHERE post_type=? AND item_id=? AND status='approved'");
    $st->execute([$postType, $itemId]);
    return (int) $st->fetchColumn();
}
}

if (!function_exists('lg_comments_insert')) {
/**
 * Insert one net-new comment. Identity is resolved server-side by the caller (the
 * write endpoint, from the validated WP cookie) — a client never supplies the
 * author, so IDOR is structurally impossible, mirroring like.php.
 *
 * @param array $f { post_type, item_id, parent_id?:?int, user_uuid?:?string,
 *                   author_wp_id?:?int, author_name?:?string, body }
 * @return int new comment id
 */
function lg_comments_insert(PDO $pdo, array $f): int {
    $parent = isset($f['parent_id']) && $f['parent_id'] > 0 ? (int) $f['parent_id'] : null;
    // A reply must thread to a comment ON THE SAME content item — otherwise drop the
    // parent (top-level) rather than splice a foreign thread.
    if ($parent !== null) {
        $chk = $pdo->prepare('SELECT 1 FROM comments WHERE id=? AND post_type=? AND item_id=?');
        $chk->execute([$parent, (string) $f['post_type'], (int) $f['item_id']]);
        if (!$chk->fetchColumn()) $parent = null;
    }
    $uuid = lg_comments_is_uuid($f['user_uuid'] ?? null) ? strtolower((string) $f['user_uuid']) : null;
    $st = $pdo->prepare(
        'INSERT INTO comments (post_type, item_id, parent_id, user_uuid, author_wp_id, author_name, body)
         VALUES (?,?,?,?::uuid,?,?,?) RETURNING id');
    $st->execute([
        (string) $f['post_type'],
        (int) $f['item_id'],
        $parent,
        $uuid,
        isset($f['author_wp_id']) && $f['author_wp_id'] > 0 ? (int) $f['author_wp_id'] : null,
        isset($f['author_name']) && $f['author_name'] !== '' ? (string) $f['author_name'] : null,
        (string) $f['body'],
    ]);
    return (int) $st->fetchColumn();
}
}

if (!function_exists('lg_comments_profile_lookup')) {
/**
 * Loopback batch call to /profile-api/v0/users. $key is 'uuids' or 'wp_ids';
 * $vals is the CSV-able list (cap 100 per call, chunked here). Returns the raw
 * `items` array (each: uuid, slug, display_name, avatar_url, bio[, wp_user_id]).
 * The endpoint itself is an unauthenticated batch card lookup, but on dev the
 * cookie gate sits in front of it — so we forward the current request's cookie
 * (exactly like lg_archive_poc_whoami()), which browser-driven read/write paths
 * always carry. CLI callers (the backfill) have none and simply get [] for the
 * bridge → callers fall back to the stored author_name. On live there is no gate.
 * Returns [] on any failure.
 */
function lg_comments_profile_lookup(string $key, array $vals): array {
    $vals = array_values(array_unique(array_filter($vals, static fn($v) => $v !== '' && $v !== null)));
    if (!$vals) return [];
    $hdrs = ['Host: ' . LG_ARCHIVE_POC_HOST];
    if (!empty($_SERVER['HTTP_COOKIE'])) $hdrs[] = 'Cookie: ' . $_SERVER['HTTP_COOKIE'];
    $out = [];
    foreach (array_chunk($vals, 100) as $chunk) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://127.0.0.1/profile-api/v0/users?' . $key . '=' . rawurlencode(implode(',', $chunk)),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => $hdrs,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 200 && $body) {
            $data = json_decode($body, true);
            if (is_array($data) && is_array($data['items'] ?? null)) {
                foreach ($data['items'] as $it) $out[] = $it;
            }
        }
    }
    return $out;
}
}

if (!function_exists('lg_comments_author_cards')) {
/**
 * Map author uuid → live profile card for a set of uuids.
 * @return array<string,array> lc-uuid => ['display_name','slug','avatar_url']
 */
function lg_comments_author_cards(array $uuids): array {
    $uuids = array_filter(array_map('strtolower', $uuids), 'lg_comments_is_uuid');
    $cards = [];
    foreach (lg_comments_profile_lookup('uuids', $uuids) as $it) {
        $u = strtolower((string) ($it['uuid'] ?? ''));
        if ($u === '') continue;
        $cards[$u] = [
            'display_name' => (string) ($it['display_name'] ?? ''),
            'slug'         => (string) ($it['slug'] ?? ''),
            'avatar_url'   => (string) ($it['avatar_url'] ?? ''),
        ];
    }
    return $cards;
}
}

if (!function_exists('lg_comments_uuids_for_wp_ids')) {
/**
 * Map WP user ids → user_uuid via the profile bridge. Used by the write endpoint
 * (single id) and the backfill (batch) to stamp the author uuid on a comment.
 * @return array<int,string> wp_user_id => lc-uuid (only the ones that bridge)
 */
function lg_comments_uuids_for_wp_ids(array $wpIds): array {
    $wpIds = array_values(array_filter(array_map('intval', $wpIds), static fn($i) => $i > 0));
    $map = [];
    foreach (lg_comments_profile_lookup('wp_ids', $wpIds) as $it) {
        $wid = (int) ($it['wp_user_id'] ?? 0);
        $u   = strtolower((string) ($it['uuid'] ?? ''));
        if ($wid > 0 && lg_comments_is_uuid($u)) $map[$wid] = $u;
    }
    return $map;
}
}
