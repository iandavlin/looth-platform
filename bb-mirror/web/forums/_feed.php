<?php
/**
 * /forums-poc/ and /forums-poc/<slug>/ — activity feed (v2).
 *
 * Features:
 *  - Sort bar: new (default) / old / hot
 *  - Featured image thumbnail on cards (LATERAL join on attachment table)
 *  - Card redesign: OP excerpt + last 3 replies threaded beneath OP,
 *    with accordion toggle for > 3 replies.
 *  - Forum header with title, "Activity" label, and optional bg image.
 *
 * Sort is parsed from REQUEST_URI query string the same way forum_slug
 * and offset are parsed — nginx alias drops QUERY_STRING but REQUEST_URI
 * is intact; index.php already rebuilt $_GET from it before we arrive.
 */

declare(strict_types=1);
require __DIR__ . '/../_chrome.php';

$db         = bb_mirror_db();

// Build forum cat-map once for data-cat on feed cards.
$_cat_map_rows = $db->query("
    SELECT id, slug, parent_forum_id FROM forum
     WHERE visibility = 'public' AND status IN ('open','closed')
")->fetchAll();
$_forum_cat_map = bb_mirror_build_cat_map($_cat_map_rows);
$forum_slug = (string)($_GET['forum_slug'] ?? '');
$raw_offset = max(0, (int)($_GET['offset'] ?? 0));
$card_limit = 50;
$fetch_size = 300; // fetch extra to cover collapse loss

// ?fid=<id> disambiguates duplicate-slug forums (e.g. two 'finish' forums).
$fid = 0;
if (preg_match('/[?&]fid=(\d+)/', $_SERVER['REQUEST_URI'] ?? '', $m)) {
    $fid = (int)$m[1];
}

// Sort: new (default) | old | hot
$sort_param = strtolower(trim((string)($_GET['sort'] ?? 'new')));
if (!in_array($sort_param, ['new', 'old', 'hot'], true)) {
    $sort_param = 'new';
}

$scoped_forum = null;

if ($forum_slug !== '' || $fid > 0) {
    if ($fid > 0) {
        $fs = $db->prepare("
            SELECT id, slug, title, description, parent_forum_id, forum_type, header_image_url
              FROM forum
             WHERE id = ? AND visibility = 'public' AND status IN ('open','closed')
             LIMIT 1
        ");
        $fs->execute([$fid]);
    } else {
        $fs = $db->prepare("
            SELECT id, slug, title, description, parent_forum_id, forum_type, header_image_url
              FROM forum
             WHERE slug = ? AND visibility = 'public' AND status IN ('open','closed')
             LIMIT 1
        ");
        $fs->execute([$forum_slug]);
    }
    $scoped_forum = $fs->fetch();
    if (!$scoped_forum) {
        http_response_code(404);
        bb_mirror_chrome_header('Not Found');
        echo '<div class="page"><p class="bb-mirror__empty">Forum not found.</p></div>';
        bb_mirror_chrome_footer();
        return;
    }
    // Normalise forum_slug for any downstream uses (sort bar, pagination, etc.)
    $forum_slug = (string)$scoped_forum['slug'];
}

// Child forums for subforum pills (only when scoped to a forum that has children)
$child_forums = [];
if ($scoped_forum) {
    $cf_stmt = $db->prepare("
        SELECT id, slug, title
          FROM forum
         WHERE parent_forum_id = ? AND visibility = 'public' AND status IN ('open','closed')
         ORDER BY menu_order ASC
    ");
    $cf_stmt->execute([(int)$scoped_forum['id']]);
    $child_forums = $cf_stmt->fetchAll();
}

// A forum is postable only if it's a LEAF: a real 'forum' (not a 'category'
// container) with no child sub-forums. Category/placeholder parents just hold
// subforums — you post to the subforum, not the container.
$is_postable_forum = $scoped_forum
    && empty($child_forums)
    && (($scoped_forum['forum_type'] ?? 'forum') !== 'category');

// Parent forum (for the header breadcrumb) + sibling forums (for leaf nav).
$parent_forum   = null;
$sibling_forums = [];
if ($scoped_forum && !empty($scoped_forum['parent_forum_id'])) {
    $pf = $db->prepare("SELECT id, slug, title, parent_forum_id
                          FROM forum WHERE id = ? AND visibility = 'public' LIMIT 1");
    $pf->execute([(int)$scoped_forum['parent_forum_id']]);
    $parent_forum = $pf->fetch() ?: null;

    // On a leaf, show the parent's children (this forum's siblings) as the pill row.
    if (empty($child_forums)) {
        $sf = $db->prepare("SELECT id, slug, title
                              FROM forum
                             WHERE parent_forum_id = ? AND visibility = 'public'
                               AND status IN ('open','closed')
                             ORDER BY menu_order ASC");
        $sf->execute([(int)$scoped_forum['parent_forum_id']]);
        $sibling_forums = $sf->fetchAll();
    }
}

// Pills: category page → its children; leaf page → its siblings (active = self).
$pill_forums    = !empty($child_forums) ? $child_forums : $sibling_forums;
$pill_active_id = (!empty($child_forums) || !$scoped_forum) ? 0 : (int)$scoped_forum['id'];

// Slug-frequency map for ?fid= disambiguation on pills + parent link (four forum
// slugs collide, incl. folk-bluegrass-irish-old-time-instruments).
$slug_freq = [];
foreach ($db->query("SELECT slug FROM forum WHERE visibility = 'public'")->fetchAll() as $r) {
    $slug_freq[$r['slug']] = ($slug_freq[$r['slug']] ?? 0) + 1;
}

// -- Build ORDER BY clause --
switch ($sort_param) {
    case 'old':
        $order_by = 'ORDER BY t.last_active_at ASC NULLS LAST';
        break;
    case 'hot':
        $order_by = "ORDER BY (t.reply_count::float / POW(EXTRACT(EPOCH FROM (NOW() - t.last_active_at))/3600 + 2, 1.5)) DESC NULLS LAST";
        break;
    default: // new
        $order_by = 'ORDER BY t.last_active_at DESC NULLS FIRST';
        break;
}

// -- Resolve scope into forum_ids array (for header image query) --
$scope_ids = null; // null = site-wide

if ($scoped_forum) {
    $scope_stmt = $db->prepare("
        WITH RECURSIVE scope AS (
          SELECT id FROM forum WHERE id = ?
          UNION ALL
          SELECT f.id FROM forum f
            JOIN scope s ON f.parent_forum_id = s.id
           WHERE f.visibility = 'public'
        )
        SELECT id FROM scope
    ");
    $scope_stmt->execute([(int)$scoped_forum['id']]);
    $scope_ids = array_column($scope_stmt->fetchAll(), 'id');
}

// -- Header image: explicit override (admin pencil) wins, else auto.
//    Scoped forum -> forum.header_image_url; site-wide -> sync_state.site_header_image.
$header_image_url = null;
$header_image_explicit = false;
if ($scoped_forum && !empty($scoped_forum['header_image_url'])) {
    $header_image_url = (string)$scoped_forum['header_image_url'];
    $header_image_explicit = true;
} elseif ($scope_ids !== null && count($scope_ids) > 0) {
    $scope_id_list = '{' . implode(',', array_map('intval', $scope_ids)) . '}';
    $hi_stmt = $db->prepare("
        SELECT a.url FROM forums.attachment a
          JOIN forums.topic t ON t.id = a.parent_id AND a.parent_kind = 'topic'
         WHERE t.forum_id = ANY(:fids::int[])
           AND a.url IS NOT NULL
         ORDER BY a.id DESC LIMIT 1
    ");
    $hi_stmt->bindValue(':fids', $scope_id_list);
    $hi_stmt->execute();
    $hi_row = $hi_stmt->fetch();
    $header_image_url = $hi_row ? $hi_row['url'] : null;
} else {
    // site-wide: explicit admin override (All Forums pencil) wins, else most recent.
    $sw = $db->query("SELECT value FROM sync_state WHERE key = 'site_header_image'")->fetch();
    if ($sw && trim((string)$sw['value']) !== '') {
        $header_image_url = (string)$sw['value'];
        $header_image_explicit = true;
    } else {
        $hi_row = $db->query("SELECT url FROM forums.attachment WHERE url IS NOT NULL ORDER BY id DESC LIMIT 1")->fetch();
        $header_image_url = $hi_row ? $hi_row['url'] : null;
    }
}

// -- Topic query with LATERAL join for first attachment image --
if ($scoped_forum) {
    $topic_sql = "
        WITH RECURSIVE scope AS (
          SELECT id FROM forum WHERE id = :seed_id
          UNION ALL
          SELECT f.id FROM forum f
            JOIN scope s ON f.parent_forum_id = s.id
           WHERE f.visibility = 'public'
        )
        SELECT
            t.id                                                       AS topic_id,
            t.slug                                                     AS topic_slug,
            t.title                                                    AS topic_title,
            t.content_html                                             AS content_html,
            LEFT(t.content_text, 240)                                  AS op_snippet,
            COALESCE(t.featured_image_url, first_img.url)              AS card_image,
            t.tags                                                     AS tags,
            t.reply_count,
            t.last_active_at                                           AS event_time,
            COALESCE(t.author_name, 'Anonymous')                       AS author_name,
            t.author_id,
            p.slug                                                     AS author_slug,
            t.created_at,
            t.forum_id,
            f.slug                                                     AS forum_slug,
            f.title                                                    AS forum_title,
            pf.slug                                                    AS parent_forum_slug,
            pf.title                                                   AS parent_forum_title
          FROM topic t
          JOIN forum f  ON f.id = t.forum_id
          LEFT JOIN forum pf ON pf.id = f.parent_forum_id
          LEFT JOIN person p ON p.id = t.author_id
          LEFT JOIN LATERAL (
            SELECT url FROM forums.attachment
             WHERE parent_kind = 'topic' AND parent_id = t.id
             ORDER BY id ASC LIMIT 1
          ) first_img ON true
         WHERE t.status = 'publish'
           AND t.forum_id IN (SELECT id FROM scope)
           AND t.forum_id NOT IN (3876)
         $order_by
         LIMIT :fetch_size OFFSET :raw_offset
    ";
    $stmt = $db->prepare($topic_sql);
    $stmt->bindValue(':seed_id',    (int)$scoped_forum['id'], PDO::PARAM_INT);
    $stmt->bindValue(':fetch_size', $card_limit,              PDO::PARAM_INT);
    $stmt->bindValue(':raw_offset', $raw_offset,              PDO::PARAM_INT);
    $stmt->execute();
} else {
    $topic_sql = "
        SELECT
            t.id                                                       AS topic_id,
            t.slug                                                     AS topic_slug,
            t.title                                                    AS topic_title,
            t.content_html                                             AS content_html,
            LEFT(t.content_text, 240)                                  AS op_snippet,
            COALESCE(t.featured_image_url, first_img.url)              AS card_image,
            t.tags                                                     AS tags,
            t.reply_count,
            t.last_active_at                                           AS event_time,
            COALESCE(t.author_name, 'Anonymous')                       AS author_name,
            t.author_id,
            p.slug                                                     AS author_slug,
            t.created_at,
            t.forum_id,
            f.slug                                                     AS forum_slug,
            f.title                                                    AS forum_title,
            pf.slug                                                    AS parent_forum_slug,
            pf.title                                                   AS parent_forum_title
          FROM topic t
          JOIN forum f  ON f.id = t.forum_id
          LEFT JOIN forum pf ON pf.id = f.parent_forum_id
          LEFT JOIN person p ON p.id = t.author_id
          LEFT JOIN LATERAL (
            SELECT url FROM forums.attachment
             WHERE parent_kind = 'topic' AND parent_id = t.id
             ORDER BY id ASC LIMIT 1
          ) first_img ON true
         WHERE t.status = 'publish' AND f.visibility = 'public'
           AND t.forum_id NOT IN (3876)
         $order_by
         LIMIT :fetch_size OFFSET :raw_offset
    ";
    $stmt = $db->prepare($topic_sql);
    $stmt->bindValue(':fetch_size', $card_limit, PDO::PARAM_INT);
    $stmt->bindValue(':raw_offset', $raw_offset, PDO::PARAM_INT);
    $stmt->execute();
}

$topics = $stmt->fetchAll();

// -- Reply TEASER query: only the single newest reply per topic. --
// Perf: rendering every reply for up to 50 cards was the load cost. We now ship
// one teaser stub per card and lazy-load the full thread on "View N replies"
// (see ?replies=<id> → _topic-replies.php + forums.js).
$reply_teaser = []; // topic_id → one reply row
if ($topics) {
    $topic_ids = array_column($topics, 'topic_id');
    $id_list = '{' . implode(',', array_map('intval', $topic_ids)) . '}';

    $reply_sql = "
        SELECT DISTINCT ON (r.topic_id)
               r.topic_id, r.id AS reply_id,
               COALESCE(r.author_name, 'Anonymous') AS author_name,
               p.slug AS author_slug,
               LEFT(r.content_text, 200) AS excerpt,
               r.created_at,
               reply_img.url AS reply_image_url
          FROM reply r
          LEFT JOIN person p ON p.id = r.author_id
          LEFT JOIN LATERAL (
            SELECT url FROM forums.attachment
             WHERE parent_kind = 'reply' AND parent_id = r.id
             ORDER BY id ASC LIMIT 1
          ) reply_img ON true
         WHERE r.topic_id = ANY(:ids::bigint[])
           AND r.status = 'publish'
         ORDER BY r.topic_id, r.created_at DESC
    ";
    $rstmt = $db->prepare($reply_sql);
    $rstmt->bindValue(':ids', $id_list);
    $rstmt->execute();
    foreach ($rstmt->fetchAll() as $row) {
        $reply_teaser[(int)$row['topic_id']] = $row;
    }
}

// -- Helpers --
// bb_mirror_avatar(), feed_rel_time(), bb_mirror_render_reply_stub() live in
// the shared partial so the lazy ?replies endpoint emits identical markup.
require_once __DIR__ . '/_reply-render.php';

function feed_first_embed_url(?string $html): ?string
{
    if (!$html) return null;
    // First standalone provider URL (YouTube / youtu.be / Vimeo / Instagram / X).
    // Stops at whitespace, quote, or '<' so legacy glue (e.g. "<div>") is excluded.
    $re = '~https?://(?:www\\.|m\\.)?(?:youtube\\.com/(?:watch\\?[^\\s"<]*v=|shorts/|embed/)|youtu\\.be/|vimeo\\.com/(?:video/)?\\d|instagram\\.com/(?:p|reel|tv)/|(?:twitter\\.com|x\\.com)/\\w+/status/)[^\\s"<]+~i';
    return preg_match($re, $html, $m) ? $m[0] : null;
}

function feed_ctx(array $card): string
{
    $title = htmlspecialchars($card['forum_title'] ?? '', ENT_QUOTES, 'UTF-8');
    $parent = $card['parent_forum_title'] ?? null;
    if ($parent) {
        return '<span class="feed-card__ctx-parent">' . htmlspecialchars($parent, ENT_QUOTES, 'UTF-8') . '</span>'
             . ' <span class="feed-card__ctx-sep">&rsaquo;</span> '
             . '<span class="feed-card__ctx-forum">' . $title . '</span>';
    }
    return '<span class="feed-card__ctx-parent">' . $title . '</span>';
}

function feed_topic_url(array $card): string
{
    return htmlspecialchars(
        LG_BB_MIRROR_PUBLIC_PATH . '/' . $card['forum_slug'] . '/' . $card['topic_slug'] . '/'
    );
}

// Parse a Postgres text[] literal (as PDO returns it) into a PHP array of strings.
// e.g. {"Total Vise",workstation,"neck reset"} → ['Total Vise','workstation','neck reset']
function feed_parse_pg_array(?string $lit): array
{
    if ($lit === null || $lit === '' || $lit === '{}') return [];
    $inner = substr($lit, 1, -1); // strip { }
    $out = [];
    preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $inner, $m, PREG_SET_ORDER);
    foreach ($m as $part) {
        $val = isset($part[2]) && $part[2] !== ''
            ? $part[2]
            : str_replace(['\\"', '\\\\'], ['"', '\\'], $part[1]);
        $val = trim($val);
        if ($val !== '') $out[] = $val;
    }
    return $out;
}

// Render forum tag chips (topic tags) — links to a tag search.
function feed_render_tags(array $tags): void
{
    if (!$tags) return;
    echo '<div class="feed-card__tags">';
    foreach ($tags as $tag) {
        $url = LG_BB_MIRROR_PUBLIC_PATH . '/?q=' . urlencode($tag);
        echo '<a class="tag-chip" href="' . htmlspecialchars($url) . '">'
           . htmlspecialchars($tag) . '</a>';
    }
    echo '</div>';
}

// Forum feed URL, appending ?fid=<id> when the slug is shared by >1 forum.
function feed_forum_url(array $f, array $slug_freq): string
{
    $qs = (($slug_freq[$f['slug']] ?? 1) > 1) ? '?fid=' . (int)$f['id'] : '';
    return htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/' . $f['slug'] . '/' . $qs);
}

function feed_op_excerpt(array $topic): string
{
    $raw = trim((string)($topic['op_snippet'] ?? ''));
    if ($raw === '') return '';
    $plain = strip_tags($raw);
    if (mb_strlen($plain) > 200) {
        $plain = mb_substr($plain, 0, 200) . '…';
    }
    return htmlspecialchars($plain, ENT_QUOTES, 'UTF-8');
}

function feed_sort_url(string $sort_val, string $forum_slug): string
{
    $base = LG_BB_MIRROR_PUBLIC_PATH . '/';
    $qs_parts = [];
    if ($forum_slug !== '') {
        $qs_parts[] = 'forum_slug=' . urlencode($forum_slug);
    }
    $qs_parts[] = 'sort=' . urlencode($sort_val);
    return htmlspecialchars($base . '?' . implode('&', $qs_parts));
}

// -- Render --
$page_title = $scoped_forum ? (string)$scoped_forum['title'] : 'The Hub';
bb_mirror_chrome_header($page_title);

$header_title     = $scoped_forum ? (string)$scoped_forum['title'] : 'The Hub';
$has_header_image = ($header_image_url !== null && $header_image_url !== '');

// Category colour key for the header accent (site-wide = neutral 'general').
$header_cat = $scoped_forum
    ? ($_forum_cat_map[(int)$scoped_forum['id']] ?? 'general')
    : 'general';
?>

<div class="page feed-page">

  <!-- Forum header -->
  <header class="forum-header<?= $has_header_image ? ' forum-header--has-image' : '' ?><?= $header_image_explicit ? ' forum-header--explicit-image' : '' ?>"
          data-cat="<?= htmlspecialchars($header_cat) ?>">
    <?php if ($has_header_image): ?>
      <div class="forum-header__bg"
           style="background-image: url('<?= htmlspecialchars($header_image_url, ENT_QUOTES, 'UTF-8') ?>')"></div>
    <?php endif; ?>
    <div class="forum-header__body">
      <?php if ($parent_forum): ?>
        <a class="forum-header__parent"
           href="<?= feed_forum_url($parent_forum, $slug_freq) ?>">&lsaquo; <?= htmlspecialchars($parent_forum['title']) ?></a>
      <?php endif; ?>
      <div class="forum-header__title-row">
        <h1 class="forum-header__title"><?= htmlspecialchars($header_title, ENT_QUOTES, 'UTF-8') ?></h1>
      </div>
      <span class="forum-header__label">Activity</span>
      <button class="forum-header__edit-img" type="button" hidden
              data-forum-id="<?= $scoped_forum ? (int)$scoped_forum['id'] : 0 ?>"
              title="Set header image" aria-label="Set header image">&#9998;</button>
    </div>
  </header>

  <!-- Sort bar (+ post button, right-aligned) -->
  <nav class="feed-sort-bar" aria-label="Sort activity">
    <a href="<?= feed_sort_url('new', $forum_slug) ?>"
       class="<?= $sort_param === 'new' ? 'active' : '' ?>">New</a>
    <a href="<?= feed_sort_url('old', $forum_slug) ?>"
       class="<?= $sort_param === 'old' ? 'active' : '' ?>">Old</a>
    <a href="<?= feed_sort_url('hot', $forum_slug) ?>"
       class="<?= $sort_param === 'hot' ? 'active' : '' ?>">Hot</a>
    <?php if ($is_postable_forum): ?>
      <button class="feed-post-btn" type="button" data-ntm-open
              data-forum-id="<?= (int)$scoped_forum['id'] ?>"
              data-forum-slug="<?= htmlspecialchars($forum_slug) ?>">+ Post here</button>
    <?php endif; ?>
  </nav>

  <?php if (!$topics): ?>
    <p class="bb-mirror__empty">No recent activity.</p>

  <?php else: ?>
  <div class="feed">

    <?php foreach ($topics as $topic):
      $turl        = feed_topic_url($topic);
      $ctx         = feed_ctx($topic);
      $rtime       = $topic['event_time'] ? feed_rel_time($topic['event_time']) : '—';
      $start_time  = $topic['created_at'] ? feed_rel_time($topic['created_at']) : '—';
      $author      = htmlspecialchars($topic['author_name']);
      $excerpt     = feed_op_excerpt($topic);
      $topic_id    = (int)$topic['topic_id'];
      $reply_count = (int)$topic['reply_count'];
      $card_image  = $topic['card_image'] ?? null;

      // One teaser reply (newest); full thread lazy-loads via ?replies=<id>.
      $teaser    = $reply_teaser[$topic_id] ?? null;
      $has_more  = $reply_count > ($teaser ? 1 : 0);

      // Category color key for this topic's forum
      $cat_key  = $_forum_cat_map[(int)$topic['forum_id']] ?? 'general';

      // Full-body expand: offer it when the text is meaningfully longer than the
      // excerpt OR the post carries image(s) — expansion lazy-loads the full body
      // + attachment gallery (?body=<id> → _topic-body.php). card_image is a
      // cheap "has at least one image" signal (it's the first attachment / featured).
      $full_html     = (string)($topic['content_html'] ?? '');
      $plain_full    = strip_tags($full_html);
      $show_read_more = (mb_strlen($plain_full) > 250) || !empty($card_image);
      $embed_url     = feed_first_embed_url($full_html);
    ?>
    <article class="feed-card feed-card--topic" data-topic-id="<?= $topic_id ?>" data-cat="<?= htmlspecialchars($cat_key) ?>" data-href="<?= $turl ?>" data-reply-count="<?= $reply_count ?>">
      <div class="feed-card__meta-top">
        <span class="feed-card__forum-ctx"><?= $ctx ?></span>
        <time class="feed-card__time" title="<?= htmlspecialchars((string)$topic['event_time']) ?>"><?= $rtime ?></time>
      </div>

      <div class="feed-card__header">
        <div class="feed-card__header-body">
          <h2 class="feed-card__title">
            <a href="<?= $turl ?>"><?= htmlspecialchars($topic['topic_title']) ?></a>
          </h2>
          <?php if ($excerpt !== ''): ?>
            <div class="feed-card__op">
              <p class="feed-card__op-excerpt"><?= $excerpt ?></p>
              <?php if ($show_read_more): ?>
                <div class="feed-card__full-body"></div>
                <button class="feed-card__read-more" type="button"
                        data-topic-id="<?= $topic_id ?>"
                        data-state="collapsed">Read more &#9660;</button>
              <?php endif; ?>
              <div class="feed-card__op-meta" style="display:flex;align-items:center;gap:6px;">
                <?= bb_mirror_avatar($topic['author_name'] ?: 'A', $topic['author_slug'] ?: $topic['topic_slug'], 36) ?>
                <span>Started by <a class="feed-card__op-author" href="<?= $turl ?>"><?= $author ?></a> &middot; <?= $start_time ?></span>
              </div>
            </div>
          <?php else: ?>
            <div class="feed-card__op-meta" style="display:flex;align-items:center;gap:6px;">
              <?= bb_mirror_avatar($topic['author_name'] ?: 'A', $topic['topic_slug'], 36) ?>
              <span>Started by <a class="feed-card__op-author" href="<?= $turl ?>"><?= $author ?></a> &middot; <?= $start_time ?></span>
            </div>
          <?php endif; ?>
          <?php if ($embed_url): ?>
            <div class="feed-card__embed" data-embed-url="<?= htmlspecialchars($embed_url, ENT_QUOTES, 'UTF-8') ?>"></div>
          <?php endif; ?>
          <?php feed_render_tags(feed_parse_pg_array($topic['tags'] ?? null)); ?>
        </div>
        <?php if (!empty($card_image)): ?>
          <a class="feed-card__cover" href="<?= $turl ?>" aria-label="<?= htmlspecialchars($topic['topic_title']) ?>">
            <img class="feed-card__cover-img"
                 src="<?= htmlspecialchars($card_image) ?>"
                 alt="" loading="lazy">
          </a>
        <?php endif; ?>
      </div>

      <?php if ($teaser || $has_more): ?>
        <div class="feed-card__replies">
          <?php if ($teaser) bb_mirror_render_reply_stub($teaser, false, true, true); ?>

          <!-- Full thread lazy-loads here on click (see forums.js + ?replies=<id>) -->
          <div class="feed-card__replies-full" hidden></div>

          <?php if ($has_more): ?>
            <button class="feed-card__expand" type="button" data-topic-id="<?= $topic_id ?>">
              View <?= $reply_count ?> <?= $reply_count === 1 ? 'reply' : 'replies' ?> &#9660;
            </button>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="feed-card__actions">
        <button class="feed-card__reply-cta" type="button" data-frm-open
                data-topic-id="<?= $topic_id ?>"
                data-forum-id="<?= (int)$topic['forum_id'] ?>"
                data-topic-title="<?= htmlspecialchars((string)$topic['topic_title'], ENT_QUOTES) ?>">&#8617; Reply</button>
      </div>

    </article>
    <?php endforeach; ?>

  </div><!-- .feed -->

  <?php
    $has_next = count($topics) >= $card_limit;
    if ($has_next):
      $next_offset = $raw_offset + $card_limit;
      $qs_parts = [];
      if ($forum_slug !== '') $qs_parts[] = 'forum_slug=' . urlencode($forum_slug);
      $qs_parts[] = 'sort=' . urlencode($sort_param);
      $qs_parts[] = 'offset=' . $next_offset;
  ?>
    <div class="feed-more">
      <a class="feed-more__btn"
         href="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/') ?>?<?= implode('&', $qs_parts) ?>">
        Load older activity
      </a>
    </div>
  <?php endif; ?>

  <?php endif; ?>
</div><!-- .page.feed-page -->

<?php bb_mirror_chrome_footer(); ?>
