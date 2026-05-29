<?php
/**
 * bb-mirror front controller.
 *
 * Routes /forums-poc/* URLs to the right template under web/forums/. The
 * templates under web/forums/ are includes, not directly addressable
 * (nginx denies any .php under web/ other than this one — see
 * dev.loothgroup.com.conf).
 *
 * URL shapes (pre-cutover):
 *   /forums-poc/                          → forums/_feed.php     (site-wide activity feed)
 *   /forums-poc/<forum-slug>/             → forums/_feed.php     (scoped to forum + descendants)
 *   /forums-poc/<forum-slug>/<topic>/     → forums/_single-topic.php
 *   /forums-poc/?q=<query>               → forums/_search.php   (any URL shape)
 *
 * Post-cutover the mount becomes /forums/ — config.php exposes that as
 * LG_BB_MIRROR_PUBLIC_PATH.
 *
 * NOTE: web/forums/_topic-list.php and web/forums/index.php are no longer
 * routed to but remain on disk (kept for reference / emergency fallback).
 * Rename them to *.bak or delete once the feed UI is confirmed stable.
 */

declare(strict_types=1);
require __DIR__ . '/../config.php';

// Parse REQUEST_URI manually instead of trusting $_GET. nginx alias +
// try_files + fastcgi-php.conf drops QUERY_STRING even though $request_uri
// preserves the original URL. Parse it here and rebuild $_GET uniformly.
$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = parse_url($request_uri, PHP_URL_PATH) ?: '/';
$qs  = parse_url($request_uri, PHP_URL_QUERY) ?: '';
if ($qs !== '' && empty($_GET)) {
    parse_str($qs, $parsed);
    $_GET = array_merge($parsed, $_GET);
}

// Strip the public mount prefix.
$prefix = LG_BB_MIRROR_PUBLIC_PATH;
if (str_starts_with($uri, $prefix)) {
    $uri = substr($uri, strlen($prefix));
}
$uri = '/' . ltrim($uri, '/');

$segments = array_values(array_filter(explode('/', $uri), fn($s) => $s !== ''));

// Search wins over any path shape.
if (trim((string)($_GET['q'] ?? '')) !== '') {
    require __DIR__ . '/forums/_search.php';
    return;
}

// Topic body fragment: no path segments + ?body=<id>
$seg_count = count($segments);
if ($seg_count === 0 && isset($_GET['body'])) {
    require __DIR__ . '/forums/_topic-body.php';
    exit;
}

// Threaded replies fragment: no path segments + ?replies=<id> (lazy "View N replies")
if ($seg_count === 0 && isset($_GET['replies'])) {
    require __DIR__ . '/forums/_topic-replies.php';
    exit;
}

switch ($seg_count) {
    case 0:
        // Site-wide activity feed (no forum filter)
        require __DIR__ . '/forums/_feed.php';
        break;

    case 1:
        // Scoped feed: forum + all descendant subforums via recursive CTE
        $_GET['forum_slug'] = $segments[0];
        require __DIR__ . '/forums/_feed.php';
        break;

    case 2:
        // Single topic (unchanged)
        $_GET['forum_slug'] = $segments[0];
        $_GET['topic_slug'] = $segments[1];
        require __DIR__ . '/forums/_single-topic.php';
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}
