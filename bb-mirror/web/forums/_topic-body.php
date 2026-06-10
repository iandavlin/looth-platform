<?php
/**
 * Topic body fragment endpoint.
 *
 * Route: /forums-poc/?body=<topic_id>
 * Returns the topic's content_html PLUS its attachment gallery as a bare HTML
 * fragment (no chrome). Called by forums.js on first "Read more" click.
 *
 * Images render BELOW the text (BuddyBoss-style) and are extra-lazy: the whole
 * fragment is fetched only on expansion, and each <img loading="lazy"> further
 * defers the bytes until it nears the viewport. Markup mirrors the single-topic
 * .post__attachments gallery so the shared CSS applies.
 */
require __DIR__ . '/../../config.php';
require_once __DIR__ . '/_reply-render.php';
$db = bb_mirror_db();

$tid = (int)($_GET['body'] ?? 0);
if (!$tid) {
    http_response_code(400);
    echo 'bad request';
    exit;
}

$stmt = $db->prepare(
    "SELECT content_html FROM forums.topic WHERE id = :id AND status = 'publish' LIMIT 1"
);
$stmt->bindValue(':id', $tid, PDO::PARAM_INT);
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo 'not found';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../_anon-scrub.php';
$lg_body_html = bb_mirror_resolve_mentions((string)$row['content_html'], $db);
if (!lg_bb_mirror_can_post()) $lg_body_html = lg_scrub_anon_contacts($lg_body_html);
echo $lg_body_html;

// -- Attachments below the text -----------------------------------------------
$astmt = $db->prepare(
    "SELECT url, alt, mime, width, height
       FROM forums.attachment
      WHERE parent_kind = 'topic' AND parent_id = :id
      ORDER BY position"
);
$astmt->bindValue(':id', $tid, PDO::PARAM_INT);
$astmt->execute();
$atts = $astmt->fetchAll(PDO::FETCH_ASSOC);

if ($atts) {
    echo '<div class="post__attachments">';
    foreach ($atts as $a) {
        $url      = htmlspecialchars((string)$a['url']);
        $alt      = htmlspecialchars((string)($a['alt'] ?? ''));
        $is_image = $a['mime'] && str_starts_with((string)$a['mime'], 'image/');
        if ($is_image) {
            echo '<a class="attachment attachment--image" href="' . $url . '" target="_blank" rel="noopener">'
               . '<img src="' . $url . '" alt="' . $alt . '" loading="lazy"'
               . ($a['width']  ? ' width="'  . (int)$a['width']  . '"' : '')
               . ($a['height'] ? ' height="' . (int)$a['height'] . '"' : '')
               . '></a>';
        } else {
            echo '<a class="attachment attachment--file" href="' . $url . '" target="_blank" rel="noopener">&#128206; '
               . htmlspecialchars($a['alt'] ?: basename((string)$a['url'])) . '</a>';
        }
    }
    echo '</div>';
}
