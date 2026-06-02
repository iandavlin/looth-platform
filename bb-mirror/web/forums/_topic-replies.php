<?php
/**
 * Threaded replies fragment endpoint.
 *
 * Route: /forums-poc/?replies=<topic_id>
 * Returns the full threaded reply list for one topic as a bare HTML fragment
 * (top-level threads newest-first, each with indented direct children).
 * Called by forums.js on the first "View N replies" click — keeps the initial
 * feed light (one teaser reply per card; the rest load on demand).
 */

declare(strict_types=1);

require __DIR__ . '/../../config.php';
require __DIR__ . '/_reply-render.php';

$tid  = (int)($_GET['replies'] ?? 0);
$sort = (($_GET['sort'] ?? '') === 'oldest') ? 'oldest' : 'newest';   // default newest
if (!$tid) { http_response_code(400); echo 'bad request'; exit; }

$db = bb_mirror_db();

$rs = $db->prepare("
    SELECT r.id AS reply_id, r.parent_reply_id,
           COALESCE(r.author_name, 'Anonymous') AS author_name,
           p.slug AS author_slug,
           LEFT(r.content_text, 200) AS excerpt,
           r.created_at,
           reply_img.url AS reply_image_url
      FROM forums.reply r
      LEFT JOIN forums.person p ON p.id = r.author_id
      LEFT JOIN LATERAL (
        SELECT url FROM forums.attachment
         WHERE parent_kind = 'reply' AND parent_id = r.id
         ORDER BY id ASC LIMIT 1
      ) reply_img ON true
     WHERE r.topic_id = :tid AND r.status = 'publish'
     ORDER BY r.created_at ASC
");
$rs->bindValue(':tid', $tid, PDO::PARAM_INT);
$rs->execute();
$flat = $rs->fetchAll();

if (!$flat) { header('Content-Type: text/html; charset=utf-8'); echo ''; exit; }

// Build the tree: index by id, attach child ids, collect top-level roots.
$by_id = [];
foreach ($flat as $r) {
    $by_id[(int)$r['reply_id']] = $r + ['_children' => []];
}
$top = [];
foreach ($by_id as $rid => $node) {
    $pid = $node['parent_reply_id'] !== null ? (int)$node['parent_reply_id'] : null;
    if ($pid !== null && isset($by_id[$pid])) {
        $by_id[$pid]['_children'][] = $rid;
    } else {
        $top[] = $rid;
    }
}
// Order top-level threads by the chosen sort (children stay chronological within
// each thread). Newest-first is the default + matches the feed teaser.
usort($top, function ($a, $b) use ($by_id, $sort) {
    $d = strtotime((string)$by_id[$a]['created_at']) - strtotime((string)$by_id[$b]['created_at']);
    return $sort === 'oldest' ? $d : -$d;
});

// Paginate top-level threads: 5 per page, "Load more" fetches the next page
// (offset > 0) and appends. Each thread carries its full descendant subtree.
$PER       = 5;
$offset    = max(0, (int)($_GET['offset'] ?? 0));
$total_top = count($top);
$page      = array_slice($top, $offset, $PER);

header('Content-Type: text/html; charset=utf-8');

// Newest/Oldest toggle — first page only, and only with >1 top-level thread.
if ($offset === 0 && $total_top > 1) {
    echo '<div class="replies-sort" data-topic-id="' . $tid . '">';
    foreach (['newest' => 'Newest', 'oldest' => 'Oldest'] as $key => $label) {
        echo '<button type="button" class="replies-sort__btn' . ($sort === $key ? ' is-active' : '')
           . '" data-sort="' . $key . '">' . $label . '</button>';
    }
    echo '</div>';
}

// Flatten each thread to 2 visual tiers (DFS): top-level + a single child indent.
// Replies deeper than a direct child render at the child indent with a "↪ @parent"
// prefix so the conversation stays legible without runaway nesting.
$walk = function (int $rid, int $depth, ?string $parent_author) use (&$walk, &$by_id) {
    $node = $by_id[$rid];
    bb_mirror_render_reply_stub(
        $node,
        $depth >= 1,                              // is_child (one indent tier)
        true,                                     // defer image behind "Show image"
        true,                                     // per-reply Reply button
        $depth >= 2 ? $parent_author : null       // "↪ @author" for deep replies
    );
    foreach ($node['_children'] as $cid) {
        $walk($cid, $depth + 1, (string)($node['author_name'] ?? 'Anonymous'));
    }
};
foreach ($page as $rid) {
    $walk($rid, 0, null);
}

// Load-more control when more top-level threads remain.
if ($offset + $PER < $total_top) {
    $next = $offset + $PER;
    $more = min($PER, $total_top - $next);
    echo '<button class="replies-loadmore" type="button"'
       . ' data-topic-id="' . $tid . '"'
       . ' data-sort="' . htmlspecialchars($sort) . '"'
       . ' data-offset="' . $next . '">Load ' . $more . ' more ' . ($more === 1 ? 'reply' : 'replies') . ' &#9662;</button>';
}
