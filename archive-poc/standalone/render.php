<?php
/**
 * archive-poc/standalone/render.php
 *
 * Standalone CPT article renderer (layout-standalone lane).
 *
 * Reads a materialized blob from discovery.article_blobs (Postgres), gates
 * per-viewer, runs the portable lg-layout-v2 engine, wraps in the shared
 * site shell. Zero WordPress boot.
 *
 * Routing: nginx passes two fastcgi_param values (available as $_SERVER):
 *   LG_POST_TYPE  — the WP post_type slug (e.g. "post-imgcap")
 *   LG_SLUG       — the post slug extracted from the URL (e.g. "jazz-bass")
 *
 * Admin preview: ?as=public|lite|pro overrides viewer state (same as archive-poc).
 *
 * CLI (gating self-check, one blob by post_id):
 *   LG_POST_TYPE=post-imgcap LG_SLUG=jazz-bass php render.php --proof
 */

declare(strict_types=1);

use LG\LayoutV2\Manifest;
use LG\LayoutV2\Pipeline;
use LG\LayoutV2\Theme;
use LG\LayoutV2\TierResolver;

$DIR = __DIR__;
require $DIR . '/engine/src/Autoload.php';
require $DIR . '/wp-shim.php';

// config.php: lg_archive_poc_pdo() + lg_archive_poc_whoami() + constants.
// Force Postgres DSN before the factory reads getenv() — same trick as materializer.
if (!getenv('LG_ARCHIVE_POC_DSN')) {
    putenv('LG_ARCHIVE_POC_DSN=pgsql:host=/var/run/postgresql;dbname=looth');
}
require_once dirname($DIR) . '/config.php';

Manifest::configure($DIR . '/engine/blocks');

$IS_CLI = (PHP_SAPI === 'cli');

/* ── Routing ─────────────────────────────────────────────────────────── */
$postType = (string) ($_SERVER['LG_POST_TYPE'] ?? getenv('LG_POST_TYPE') ?? '');
$slug     = (string) ($_SERVER['LG_SLUG']      ?? getenv('LG_SLUG')      ?? '');
$postId   = (int)    ($_SERVER['LG_POST_ID']   ?? getenv('LG_POST_ID')   ?? 0);
$slug     = preg_replace('/[^a-z0-9\-]/i', '', $slug);

if ($postType === '' || ($slug === '' && $postId <= 0)) {
    lg_standalone_fail($IS_CLI, 404, 'missing post_type + (slug or id)');
}

/* ── Blob lookup ─────────────────────────────────────────────────────── */
/* By post_id when the permalink is id-based (e.g. /document/<id>/); else by slug. */
try {
    $db = lg_archive_poc_pdo();
    if ($postId > 0) {
        $stmt = $db->prepare('SELECT blob FROM article_blobs WHERE post_type = :pt AND post_id = :id LIMIT 1');
        $stmt->execute([':pt' => $postType, ':id' => $postId]);
    } else {
        $stmt = $db->prepare('SELECT blob FROM article_blobs WHERE post_type = :pt AND slug = :sl LIMIT 1');
        $stmt->execute([':pt' => $postType, ':sl' => $slug]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('lg-render: db error: ' . $e->getMessage());
    lg_standalone_fail($IS_CLI, 500, 'db error');
}

if (!$row) {
    lg_standalone_fail($IS_CLI, 404, "not found: $postType/" . ($postId > 0 ? "#$postId" : $slug));
}

$blob = json_decode((string) $row['blob'], true);
if (!is_array($blob) || !is_array($blob['layout'] ?? null) || !is_array($blob['post_context'] ?? null)) {
    lg_standalone_fail($IS_CLI, 500, 'blob malformed');
}
$layout      = $blob['layout'];
$postContext = $blob['post_context'];

/* ── Proof mode (CLI only) ───────────────────────────────────────────── */
if ($IS_CLI && in_array('--proof', $argv ?? [], true)) {
    exit(lg_standalone_proof($layout, $postContext, $blob));
}

/* ── Viewer ──────────────────────────────────────────────────────────── */
$previewAs = '';
if (!$IS_CLI) {
    $pa = $_GET['as'] ?? '';
    if (in_array($pa, ['public', 'lite', 'pro'], true)) $previewAs = $pa;
}

if ($previewAs !== '') {
    [$viewer, $authed, $shellTier, $viewerName] = lg_standalone_viewer_from_preview($previewAs);
} else {
    [$viewer, $authed, $shellTier, $viewerName] = lg_standalone_viewer_from_whoami();
}

/* ── Render ──────────────────────────────────────────────────────────── */
$articleHtml = lg_standalone_render_article($layout, $postContext, $viewer, $authed);
$css         = $GLOBALS['LG_STANDALONE_LAST_CSS'] ?? '';

if (!$IS_CLI) header('Content-Type: text/html; charset=utf-8');
echo lg_standalone_page($postContext, $articleHtml, $css, $authed, $shellTier, $viewerName, $previewAs);


/* ════════════════════════════════════════════════════════════════════════
   Helpers
   ════════════════════════════════════════════════════════════════════════ */

function lg_standalone_viewer_from_whoami(): array {
    $whoami = lg_archive_poc_whoami();
    $authed = !empty($whoami['authenticated']);
    $tier   = $authed && in_array($whoami['tier'] ?? '', ['public', 'lite', 'pro'], true)
              ? $whoami['tier'] : 'public';
    $name   = $authed ? (string) ($whoami['display_name'] ?? '') : '';
    [$viewer] = lg_standalone_build_viewer($authed, $tier);
    return [$viewer, $authed, $tier, $name];
}

function lg_standalone_viewer_from_preview(string $as): array {
    $authed = ($as !== 'public');
    [$viewer] = lg_standalone_build_viewer($authed, $as);
    return [$viewer, $authed, $as, $authed ? 'Preview' : ''];
}

function lg_standalone_build_viewer(bool $authed, string $tier): array {
    $TAX = ['lite' => 'looth-lite', 'pro' => 'looth-pro'];
    if (!$authed) return [TierResolver::anonymous()];
    $tiers = isset($TAX[$tier]) ? [$TAX[$tier]] : [];
    return [['is_admin' => false, 'is_delinquent' => false, 'tiers' => $tiers, 'preview_role' => null]];
}

function lg_standalone_render_article(array $layout, array $pc, array $viewer, bool $authed): string {
    $GLOBALS['LG_PC']          = $pc + ['layout' => $layout];
    $GLOBALS['LG_VIEWER_AUTH'] = $authed;
    $ctx = [
        'viewer'         => $viewer,
        'editor_mode'    => false,
        'can_edit'       => false,
        'media_resolver' => 'lg_standalone_media_resolver',
        'post_id'        => (int) ($pc['post_id'] ?? 0),
        'post_tier'      => (string) ($pc['post_tier'] ?? ''),
    ];
    $result = Pipeline::run($layout, Theme::defaultValues(), [], $ctx);
    $GLOBALS['LG_STANDALONE_LAST_CSS'] = (string) ($result['css'] ?? '');
    return (string) ($result['html'] ?? '');
}

function lg_standalone_page(array $pc, string $articleHtml, string $css, bool $authed, string $tier, string $viewerName, string $previewAs): string {
    $title = htmlspecialchars((string) ($pc['title'] ?? 'Looth Group'), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

    require_once '/srv/lg-shared/site-header.php';
    require_once '/srv/lg-shared/site-footer.php';

    ob_start();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $title ?> — Looth Group</title>
<meta name="robots" content="<?= $tier === 'public' ? 'index, follow' : 'noindex, follow' ?>">
<link rel="stylesheet" href="/lg-shared/site-header.css">
<style>
<?= $css ?>
</style>
<style>
body { margin: 0; background: #f0eee8; color: #323532;
       font-family: 'Jost', system-ui, -apple-system, sans-serif; }
.lg-standalone-main { max-width: 760px; margin: 0 auto; padding: 24px 16px 64px; }
</style>
</head>
<body>
<?php
    lg_shared_render_site_header([
        'authenticated' => $authed,
        'tier'          => $tier,
        'display_name'  => $viewerName,
        'avatar_url'    => null,
        'capabilities'  => [],
        'msg_unread'    => null,
        'notif_unread'  => null,
        'active_nav'    => '',
        'logout_url'    => '/wp-login.php?action=logout',
        'profile_url'   => '/profile/edit',
    ]);
?>
<main class="lg-standalone-main" id="lg-main">
<?= $articleHtml ?>
</main>
<?php lg_shared_render_site_footer(); ?>
</body>
</html>
<?php
    return (string) ob_get_clean();
}

function lg_standalone_proof(array $layout, array $pc, array $blob): int {
    $gatedUrl = '';
    foreach ($layout['blocks'] ?? [] as $b) {
        if (is_array($b) && ($b['type'] ?? '') === 'embed' && !empty($b['gated_tier'])) {
            $gatedUrl = (string) ($b['url'] ?? '');
            break;
        }
    }
    $ytId = '';
    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{6,})~', $gatedUrl, $m)) {
        $ytId = $m[1];
    }

    [$pubViewer, $pubAuth] = lg_standalone_viewer_from_preview('public');
    $pub = lg_standalone_render_article($layout, $pc, $pubViewer, $pubAuth);
    [$proViewer, $proAuth] = lg_standalone_viewer_from_preview('pro');
    $pro = lg_standalone_render_article($layout, $pc, $proViewer, $proAuth);

    $checks = [];
    if ($ytId !== '') {
        $checks[] = ['gated payload ABSENT in public HTML',  strpos($pub, $ytId) === false];
        $checks[] = ['gated payload PRESENT in pro HTML',    strpos($pro, $ytId) !== false];
    }
    $checks[] = ['no editor markers leak (public)',   strpos($pub, '<lg-edit') === false];
    $checks[] = ['raw blob not on the wire',          strpos($pub, 'post_context') === false && strpos($pro, 'post_context') === false];

    $allPass = true;
    $out = ['layout-standalone proof — ' . ($pc['title'] ?? '?'), str_repeat('─', 60)];
    foreach ($checks as [$label, $ok]) {
        $allPass = $allPass && $ok;
        $out[] = ($ok ? '  PASS  ' : '  FAIL  ') . $label;
    }
    $out[] = str_repeat('─', 60);
    $out[] = $allPass ? 'RESULT: ALL PASS' : 'RESULT: FAILURES ABOVE';
    $out[] = sprintf('  public: %d bytes · pro: %d bytes', strlen($pub), strlen($pro));
    fwrite(STDOUT, implode("\n", $out) . "\n");
    return $allPass ? 0 : 1;
}

function lg_standalone_fail(bool $isCli, int $code, string $msg): void {
    if ($isCli) { fwrite(STDERR, "render-standalone: $msg\n"); exit($code >= 500 ? 2 : 1); }
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo "render-standalone: $msg\n";
    exit;
}
