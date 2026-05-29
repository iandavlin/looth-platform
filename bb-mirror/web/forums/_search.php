<?php
/**
 * /forums-poc/?q=<query> — full-text search over topics + replies.
 *
 * tsvector indexes are maintained by triggers on topic + reply (see
 * schema.pg.sql). Query uses websearch_to_tsquery for natural-language
 * input: quoted phrases, `-exclude`, `OR`, etc. all work out of the box.
 *
 * Results: union of topic-title matches + reply-body matches. Each result
 * links to the topic page and previews the snippet with ts_headline().
 * Topics outrank replies (weight A vs B in the tsvector).
 */

declare(strict_types=1);

require __DIR__ . '/../_chrome.php';

$db = bb_mirror_db();
$q = trim((string)($_GET['q'] ?? ''));

bb_mirror_chrome_header('Search: ' . ($q ?: 'forums'));

if ($q === '' || mb_strlen($q) < 2) {
    ?>
    <div class="page">
      <h1 class="bb-mirror__page-title">Search</h1>
      <p class="bb-mirror__empty">Enter at least 2 characters to search.</p>
    </div>
    <?php
    bb_mirror_chrome_footer();
    return;
}

// websearch_to_tsquery is forgiving — handles bad input without erroring.
// 50-row cap; pagination later if needed.
$stmt = $db->prepare("
    WITH q AS (SELECT websearch_to_tsquery('english', ?) AS tsq),
    topic_hits AS (
      SELECT 'topic' AS kind, t.id, t.id AS topic_id, t.slug AS topic_slug,
             t.title, t.author_name, t.created_at,
             ts_rank(t.search_doc, q.tsq) * 2.0 AS rank,
             ts_headline('english', COALESCE(t.content_text,''), q.tsq,
                         'MaxWords=24, MinWords=10, ShortWord=2') AS snippet,
             f.slug AS forum_slug, f.title AS forum_title
        FROM topic t
        CROSS JOIN q
        JOIN forum f ON f.id = t.forum_id
       WHERE t.status IN ('publish','closed')
         AND f.visibility = 'public'
         AND t.search_doc @@ q.tsq
    ),
    reply_hits AS (
      SELECT 'reply' AS kind, r.id, r.topic_id, t.slug AS topic_slug,
             t.title, r.author_name, r.created_at,
             ts_rank(r.search_doc, q.tsq) AS rank,
             ts_headline('english', COALESCE(r.content_text,''), q.tsq,
                         'MaxWords=24, MinWords=10, ShortWord=2') AS snippet,
             f.slug AS forum_slug, f.title AS forum_title
        FROM reply r
        CROSS JOIN q
        JOIN topic t ON t.id = r.topic_id
        JOIN forum f ON f.id = r.forum_id
       WHERE r.status = 'publish'
         AND t.status IN ('publish','closed')
         AND f.visibility = 'public'
         AND r.search_doc @@ q.tsq
    )
    SELECT * FROM topic_hits
    UNION ALL
    SELECT * FROM reply_hits
    ORDER BY rank DESC
    LIMIT 50
");
$stmt->execute([$q]);
$rows = $stmt->fetchAll();

function fmt_ts_search($ts): string {
    if (!$ts) return '';
    $unix = is_numeric($ts) ? (int)$ts : strtotime((string)$ts . ' UTC');
    return $unix ? date('Y-m-d', $unix) : '';
}
?>

<div class="page">
  <h1 class="bb-mirror__page-title">Search</h1>
  <p class="search-meta">
    <?= count($rows) ?> result<?= count($rows) === 1 ? '' : 's' ?> for
    <strong><?= htmlspecialchars($q) ?></strong>
  </p>

  <?php if (!$rows): ?>
    <p class="bb-mirror__empty">No matches. Try fewer words or a different phrasing.</p>
  <?php else: ?>
    <ul class="search-results" role="list">
      <?php foreach ($rows as $r):
        $href = LG_BB_MIRROR_PUBLIC_PATH . '/' . $r['forum_slug'] . '/' . $r['topic_slug'] . '/';
        if ($r['kind'] === 'reply') $href .= '#reply-' . (int)$r['id'];
      ?>
        <li class="search-result search-result--<?= htmlspecialchars($r['kind']) ?>">
          <a class="search-result__link" href="<?= htmlspecialchars($href) ?>">
            <h3 class="search-result__title">
              <?= htmlspecialchars($r['title']) ?>
              <?php if ($r['kind'] === 'reply'): ?>
                <span class="search-result__badge">reply</span>
              <?php endif; ?>
            </h3>
            <p class="search-result__snippet"><?= $r['snippet'] /* ts_headline returns marked-up HTML */ ?></p>
            <p class="search-result__meta">
              in <span class="search-result__forum"><?= htmlspecialchars($r['forum_title']) ?></span>
              · by <?= htmlspecialchars($r['author_name'] ?: '—') ?>
              · <?= htmlspecialchars(fmt_ts_search($r['created_at'])) ?>
            </p>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<?php bb_mirror_chrome_footer(); ?>
