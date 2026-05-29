<?php
/**
 * Topic body fragment endpoint.
 *
 * Route: /forums-poc/?body=<topic_id>
 * Returns the topic's content_html as a bare HTML fragment (no chrome).
 * Called by forums.js on first "Read more" click for lazy-fetch.
 */
require __DIR__ . '/../../config.php';
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
echo $row['content_html'];
