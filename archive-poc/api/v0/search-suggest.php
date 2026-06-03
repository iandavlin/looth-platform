<?php
// Faceted search-suggest for the modal: returns authors, posts, discussions
// separately from a single query. Used by the chrome search modal only.
// At postgres cutover: swap $db init to lg_archive_poc_pdo() and replace
// content_fts MATCH with tsv @@ websearch_to_tsquery('english', ?).
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['error' => 'method_not_allowed'], 405);
}

$q = param_str('q');
if (strlen(trim($q)) < 2) {
    send_json(['q'=>$q,'authors'=>[],'posts'=>[],'posts_total'=>0,'discussions'=>[],'discussions_total'=>0]);
}

$needle = fts_quote($q);
$LIMIT  = 3;

// ---- Author name search ------------------------------------------------
// Fuzzy match on person.display_name. Weighted by how many posts they have.
$authors = [];
$as = $db->prepare("
    SELECT p.id, p.display_name, p.slug, p.avatar_url,
           COUNT(ci.id) AS post_count
    FROM person p
    LEFT JOIN content_item ci ON ci.author_id = p.id
    WHERE p.display_name LIKE ?
    GROUP BY p.id
    ORDER BY post_count DESC
    LIMIT ?
");
$as->execute(['%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%', $LIMIT]);
foreach ($as->fetchAll() as $r) {
    $authors[] = [
        'id'         => (int)$r['id'],
        'name'       => $r['display_name'],
        'slug'       => $r['slug'],
        'avatar_url' => $r['avatar_url'] ?: null,
        'post_count' => (int)$r['post_count'],
    ];
}

// ---- Posts (everything except discussions) -----------------------------
$posts       = [];
$posts_total = 0;
if ($needle !== '') {
    $ps = $db->prepare("
        SELECT ci.id, ci.kind, ci.title, ci.url,
               ci.thumb_url, ci.thumb_broken, ci.tier, ci.author_name
        FROM content_item ci
        JOIN content_fts f ON f.rowid = ci.id
        WHERE content_fts MATCH ?
          AND ci.kind NOT IN ('discussion','event')
        ORDER BY bm25(content_fts) ASC
        LIMIT ?
    ");
    $ps->execute([$needle, $LIMIT]);
    foreach ($ps->fetchAll() as $r) {
        $posts[] = [
            'id'          => (int)$r['id'],
            'kind'        => $r['kind'],
            'title'       => $r['title'],
            'url'         => $r['url'],
            'thumb_url'   => $r['thumb_url'] ?: null,
            'thumb_broken'=> (int)$r['thumb_broken'] === 1,
            'tier'        => $r['tier'],
            'author_name' => $r['author_name'] ?: null,
        ];
    }
    $pc = $db->prepare("
        SELECT COUNT(*) FROM content_item ci
        JOIN content_fts f ON f.rowid = ci.id
        WHERE content_fts MATCH ? AND ci.kind NOT IN ('discussion','event')
    ");
    $pc->execute([$needle]);
    $posts_total = (int)$pc->fetchColumn();
}

// ---- Discussions --------------------------------------------------------
$discussions       = [];
$discussions_total = 0;
if ($needle !== '') {
    $ds = $db->prepare("
        SELECT ci.id, ci.title, ci.url, ci.reply_count, ci.last_activity
        FROM content_item ci
        JOIN content_fts f ON f.rowid = ci.id
        WHERE content_fts MATCH ?
          AND ci.kind = 'discussion'
        ORDER BY bm25(content_fts) ASC
        LIMIT ?
    ");
    $ds->execute([$needle, $LIMIT]);
    foreach ($ds->fetchAll() as $r) {
        $discussions[] = [
            'id'            => (int)$r['id'],
            'title'         => $r['title'],
            'url'           => $r['url'],
            'reply_count'   => (int)$r['reply_count'],
            'last_activity' => $r['last_activity'] !== null ? (int)$r['last_activity'] : null,
        ];
    }
    $dc = $db->prepare("
        SELECT COUNT(*) FROM content_item ci
        JOIN content_fts f ON f.rowid = ci.id
        WHERE content_fts MATCH ? AND ci.kind = 'discussion'
    ");
    $dc->execute([$needle]);
    $discussions_total = (int)$dc->fetchColumn();
}

send_json([
    'q'                  => $q,
    'authors'            => $authors,
    'posts'              => $posts,
    'posts_total'        => $posts_total,
    'discussions'        => $discussions,
    'discussions_total'  => $discussions_total,
]);
