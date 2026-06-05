<?php
declare(strict_types=1);
/**
 * Hub type-ahead JSON endpoint (routed via index.php on ?suggest=).
 *
 *   /hub/?suggest=hub&q=<text>     -> live search: matching posts + content
 *   /hub/?suggest=author&q=<text>  -> author autocomplete: matching names
 *
 * Unified across forums.topic + discovery.content_item; content is tier-gated to
 * the viewer (same absence model as the feed). Cheap ILIKE substring match —
 * this is a suggest box, not full search (?q= still runs _search.php).
 */

require_once __DIR__ . '/_hub-filters.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

$mode = ($_GET['suggest'] ?? '') === 'author' ? 'author' : 'hub';
$q    = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo json_encode(['q' => $q, 'mode' => $mode, 'results' => []]); return; }

$db   = bb_mirror_db();
$like = '%' . $q . '%';

// Viewer tier -> allowed content tiers (mirrors _feed.php).
$viewer_tier = 'public';
$wa = lg_bb_mirror_whoami();
if (is_array($wa) && in_array($wa['tier'] ?? '', ['public', 'lite', 'pro'], true)) $viewer_tier = (string)$wa['tier'];
$rank  = ['public' => 0, 'lite' => 1, 'pro' => 2];
$tiers = array_keys(array_filter($rank, fn($r) => $r <= $rank[$viewer_tier]));
$tph = [];
foreach ($tiers as $i => $t) $tph[] = ':t' . $i;
$tin = $tph ? implode(',', $tph) : "''";

$results = [];

if ($mode === 'author') {
    $sql = "
        SELECT name, SUM(n) AS n FROM (
            SELECT author_name AS name, count(*) AS n
              FROM topic
             WHERE status = 'publish' AND author_name ILIKE :like1
             GROUP BY author_name
            UNION ALL
            SELECT author_name, count(*)
              FROM discovery.content_item
             WHERE tier IN ($tin) AND author_name ILIKE :like2
             GROUP BY author_name
        ) z
         WHERE name IS NOT NULL AND name <> ''
         GROUP BY name
         ORDER BY n DESC, name ASC
         LIMIT 8";
    $st = $db->prepare($sql);
    $st->bindValue(':like1', $like);
    $st->bindValue(':like2', $like);
    foreach ($tiers as $i => $t) $st->bindValue(':t' . $i, $t);
    $st->execute();
    foreach ($st->fetchAll() as $r) {
        $results[] = ['name' => (string)$r['name'], 'n' => (int)$r['n']];
    }
} else {
    // Live search: topics (build /hub/<forum>/<topic>/) + content (url column).
    $base = LG_BB_MIRROR_PUBLIC_PATH;
    $sql = "
        SELECT kind, title, forum_slug, topic_slug, content_url, ts FROM (
            SELECT 'discussion' AS kind, t.title, f.slug AS forum_slug, t.slug AS topic_slug,
                   NULL::text AS content_url, t.last_active_at AS ts
              FROM topic t JOIN forum f ON f.id = t.forum_id
             WHERE t.status = 'publish' AND f.visibility = 'public'
               AND t.forum_id NOT IN (3876) AND t.title ILIKE :like1
            UNION ALL
            SELECT kind, title, NULL, NULL, url, COALESCE(last_activity, published_at)
              FROM discovery.content_item
             WHERE tier IN ($tin) AND title ILIKE :like2
        ) z
         ORDER BY ts DESC NULLS LAST
         LIMIT 8";
    $st = $db->prepare($sql);
    $st->bindValue(':like1', $like);
    $st->bindValue(':like2', $like);
    foreach ($tiers as $i => $t) $st->bindValue(':t' . $i, $t);
    $st->execute();
    foreach ($st->fetchAll() as $r) {
        $url = $r['kind'] === 'discussion'
            ? $base . '/' . $r['forum_slug'] . '/' . $r['topic_slug'] . '/'
            : (string)$r['content_url'];
        $results[] = ['kind' => (string)$r['kind'], 'title' => (string)$r['title'], 'url' => $url];
    }
}

echo json_encode(['q' => $q, 'mode' => $mode, 'results' => $results]);
