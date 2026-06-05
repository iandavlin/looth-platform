<?php
/**
 * archive-poc/api/v0/_likes.php — the one net-new system: Likes.
 *
 * Shared by the /stream/ SSR (count + liked-state lookup), the like toggle
 * endpoint (like.php), and any future "most-liked" surface. Storage is the
 * Postgres `discovery` schema (alongside article_blobs) — NOT the SQLite index,
 * which is read-only derived state. Likes are authoritative, net-new, writable.
 *
 * Design notes:
 *  - Identity = `user_uuid` from /whoami VERBATIM (the shared identity contract).
 *    No WP user id, no cookie — whoami is the only source. Anon has no uuid and
 *    therefore can never write a like (enforced in like.php).
 *  - One like per (post_type, item_id, user_uuid): the table PK makes like/unlike
 *    idempotent and the per-item COUNT cheap (idx_likes_item prefix). "Most-liked"
 *    is a GROUP BY away — we don't paint into a corner with a bare counter.
 *  - CSRF: a stateless HMAC of the viewer's uuid under the server secret. The
 *    token is rendered into the stream page for authenticated viewers only and
 *    must come back in the X-LG-CSRF header. An attacker page can't compute it
 *    (no secret) and anon has no uuid to bind, so cross-site writes can't forge.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../config.php';   // PDO + whoami + LG_ARCHIVE_POC_CONFIG_SECRET

if (!function_exists('lg_likes_pdo')) {
/**
 * Postgres handle for the likes store. The read API normally talks to SQLite;
 * likes are the one writable surface, so we open Postgres explicitly (peer auth
 * as the archive-poc role) rather than going through lg_archive_poc_pdo()'s
 * SQLite default. search_path is pinned to discovery.
 */
function lg_likes_pdo(): PDO {
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

if (!function_exists('lg_likes_is_uuid')) {
function lg_likes_is_uuid(?string $s): bool {
    return is_string($s) && (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}
}

if (!function_exists('lg_likes_csrf_token')) {
/**
 * Stateless per-viewer CSRF token. HMAC(uuid|like, secret). Empty string if the
 * viewer has no uuid (anon) or the secret is missing — callers treat "" as
 * "no like UI / reject write".
 */
function lg_likes_csrf_token(?string $userUuid): string {
    if (!lg_likes_is_uuid($userUuid)) return '';
    $secret = defined('LG_ARCHIVE_POC_CONFIG_SECRET') ? LG_ARCHIVE_POC_CONFIG_SECRET : '';
    if ($secret === '') return '';
    return hash_hmac('sha256', strtolower((string) $userUuid) . '|like', $secret);
}
}

if (!function_exists('lg_likes_csrf_ok')) {
/** Constant-time verify of the X-LG-CSRF header against the viewer's expected token. */
function lg_likes_csrf_ok(?string $userUuid, ?string $presented): bool {
    $expected = lg_likes_csrf_token($userUuid);
    if ($expected === '' || !is_string($presented) || $presented === '') return false;
    return hash_equals($expected, $presented);
}
}

if (!function_exists('lg_likes_counts')) {
/**
 * Batch like-counts + viewer-liked-state for a page of items.
 * @param array $items list of ['post_type'=>string,'item_id'=>int]
 * @param ?string $viewerUuid liked-state computed only when a valid uuid is given
 * @return array keyed "post_type:item_id" => ['count'=>int,'liked'=>bool]
 */
function lg_likes_counts(PDO $pdo, array $items, ?string $viewerUuid): array {
    $out = [];
    if (!$items) return $out;
    // Distinct (type,id) pairs; build a VALUES list to join against.
    $pairs = [];
    foreach ($items as $it) {
        $pt = (string) ($it['post_type'] ?? '');
        $id = (int) ($it['item_id'] ?? 0);
        if ($pt === '' || $id <= 0) continue;
        $pairs["$pt:$id"] = [$pt, $id];
    }
    if (!$pairs) return $out;

    // Counts: one grouped query over the wanted pairs.
    $ph = [];
    $args = [];
    $i = 0;
    foreach ($pairs as $p) {
        $ph[] = "(?::text, ?::bigint)";
        $args[] = $p[0];
        $args[] = $p[1];
    }
    $sql = 'SELECT post_type, item_id, COUNT(*) AS c FROM likes
            WHERE (post_type, item_id) IN (' . implode(',', $ph) . ')
            GROUP BY post_type, item_id';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['post_type'] . ':' . $r['item_id']] = ['count' => (int) $r['c'], 'liked' => false];
    }
    foreach ($pairs as $k => $p) {
        if (!isset($out[$k])) $out[$k] = ['count' => 0, 'liked' => false];
    }

    // Viewer liked-state: only if authenticated with a real uuid.
    if (lg_likes_is_uuid($viewerUuid)) {
        $st = $pdo->prepare('SELECT post_type, item_id FROM likes
                             WHERE user_uuid = ?::uuid
                               AND (post_type, item_id) IN (' . implode(',', $ph) . ')');
        $st->execute(array_merge([strtolower((string) $viewerUuid)], $args));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['post_type'] . ':' . $r['item_id'];
            if (isset($out[$k])) $out[$k]['liked'] = true;
        }
    }
    return $out;
}
}

if (!function_exists('lg_likes_toggle')) {
/**
 * Idempotent toggle. Returns ['count'=>int,'liked'=>bool] after the write.
 * Insert-or-noop / delete keyed on the PK — no read-modify-write race.
 */
function lg_likes_toggle(PDO $pdo, string $postType, int $itemId, string $userUuid): array {
    $uuid = strtolower($userUuid);
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM likes WHERE post_type=? AND item_id=? AND user_uuid=?::uuid');
        $del->execute([$postType, $itemId, $uuid]);
        if ($del->rowCount() > 0) {
            $liked = false;
        } else {
            $ins = $pdo->prepare('INSERT INTO likes (post_type, item_id, user_uuid) VALUES (?,?,?::uuid)
                                  ON CONFLICT DO NOTHING');
            $ins->execute([$postType, $itemId, $uuid]);
            $liked = true;
        }
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM likes WHERE post_type=? AND item_id=?');
        $cnt->execute([$postType, $itemId]);
        $count = (int) $cnt->fetchColumn();
        $pdo->commit();
        return ['count' => $count, 'liked' => $liked];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
}
