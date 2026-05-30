<?php
/**
 * archive-poc/standalone/render.php
 *
 * STANDALONE CPT render harness (layout-standalone lane — PoC).
 *
 * Reads a HAND-MATERIALIZED blob (layout + PostContext) for one real article,
 * applies gating against a viewer array, runs the portable lg-layout-v2 render
 * engine, and wraps the result in the shared site shell. There is NO WordPress
 * boot anywhere in this path — the only "WP" present is wp-shim.php, a flat-
 * array read of the materialized blob.
 *
 * HOST = archive-poc (design §6, ruled): this lives under the archive-poc tree
 * and is meant to ride the archive-poc FPM pool + nginx location. It is NOT
 * wired into nginx this turn (pure dark-launch PoC) — run it directly:
 *
 *   CLI (full page):
 *     php render.php --blob=article-jazz-bass --as=public  > /tmp/pub.html
 *     php render.php --blob=article-jazz-bass --as=pro      > /tmp/pro.html
 *
 *   CLI (gating self-check — renders both, asserts the §5 invariants, sets
 *   exit code; this is the one command the coordinator should run to verify):
 *     php render.php --blob=article-jazz-bass --proof
 *
 *   Web (once a route exists): /…/standalone/render.php?blob=article-jazz-bass&as=pro
 *
 * Viewer vocabulary mirrors archive-poc's /whoami → TierResolver mapping
 * (design §5): ?as=public|lite|pro|admin.
 */

declare(strict_types=1);

use LG\LayoutV2\Manifest;
use LG\LayoutV2\Pipeline;
use LG\LayoutV2\Theme;
use LG\LayoutV2\TierResolver;

$DIR = __DIR__;
require $DIR . '/engine/src/Autoload.php';   // vendored engine (copy, not move)
require $DIR . '/wp-shim.php';               // materialize-on-save boundary

Manifest::configure($DIR . '/engine/blocks');

$IS_CLI = (PHP_SAPI === 'cli');

/* ── Args ────────────────────────────────────────────────────────────── */
$proof = false;
$blobName = 'article-jazz-bass';
$as = 'public';
if ($IS_CLI) {
    $opt = getopt('', ['blob:', 'as:', 'proof']);
    $proof    = array_key_exists('proof', $opt);
    $blobName = isset($opt['blob']) ? (string) $opt['blob'] : $blobName;
    $as       = isset($opt['as'])   ? (string) $opt['as']   : $as;
} else {
    $blobName = isset($_GET['blob']) ? (string) $_GET['blob'] : $blobName;
    $as       = isset($_GET['as'])   ? (string) $_GET['as']   : $as;
}
/* Sanitize the blob name to a bare slug — never let it escape standalone/blobs. */
$blobName = preg_replace('/[^a-z0-9-]/i', '', $blobName) ?: 'article-jazz-bass';
$blobPath = $DIR . '/blobs/' . $blobName . '.json';

if (!is_file($blobPath)) {
    lg_standalone_fail($IS_CLI, "blob not found: blobs/$blobName.json");
}
$blob = json_decode((string) file_get_contents($blobPath), true);
if (!is_array($blob) || !is_array($blob['layout'] ?? null) || !is_array($blob['post_context'] ?? null)) {
    lg_standalone_fail($IS_CLI, "blob malformed: expected { layout:{}, post_context:{} }");
}
$layout = $blob['layout'];
$postContext = $blob['post_context'];

/* ── Proof mode: render BOTH viewers and assert the §5 gating invariants ─ */
if ($proof) {
    exit(lg_standalone_proof($layout, $postContext, $blob));
}

/* ── Normal mode: render one viewer, emit a full standalone page ─────── */
[$viewer, $authed, $tierForShell, $viewerName] = lg_standalone_viewer($as, $postContext);
$articleHtml = lg_standalone_render_article($layout, $postContext, $viewer, $authed);

/* Engine CSS bundle (all cascade layers) — produced by CssBuilder, inlined. */
$css = $GLOBALS['LG_STANDALONE_LAST_CSS'] ?? '';

if (!$IS_CLI) header('Content-Type: text/html; charset=utf-8');
echo lg_standalone_page($postContext, $articleHtml, $css, $authed, $tierForShell, $viewerName, $as);


/* ════════════════════════════════════════════════════════════════════════
   Helpers
   ════════════════════════════════════════════════════════════════════════ */

/**
 * Map archive-poc's /whoami tier vocabulary onto a TierResolver viewer array.
 * Returns [viewer, authenticated, shellTier('public'|'lite'|'pro'), viewerName].
 *
 * Fail-closed (design §5): an unknown/absent `as` → anonymous public.
 */
function lg_standalone_viewer(string $as, array $pc): array {
    $TAX = ['lite' => 'looth-lite', 'pro' => 'looth-pro'];   // /whoami tier → taxonomy slug
    switch ($as) {
        case 'admin':
            return [
                ['is_admin' => true, 'is_delinquent' => false, 'tiers' => [], 'preview_role' => null],
                true, 'pro', 'Admin',
            ];
        case 'lite':
        case 'pro':
            return [
                ['is_admin' => false, 'is_delinquent' => false, 'tiers' => [$TAX[$as]], 'preview_role' => null],
                true, $as, 'Evan Gluck',
            ];
        case 'public':
        default:
            return [TierResolver::anonymous(), false, 'public', ''];
    }
}

/**
 * Run the portable engine for one viewer. Sets up the shim's PostContext +
 * the auth flag, builds the render $ctx, runs Pipeline, stashes the CSS bundle.
 * Returns the article HTML (gated for this viewer).
 */
function lg_standalone_render_article(array $layout, array $pc, array $viewer, bool $authed): string {
    // Expose PostContext to the shim. `layout` rides along so the post-header
    // read-time counter can read the block list back through get_post_meta().
    $GLOBALS['LG_PC'] = $pc + ['layout' => $layout];
    $GLOBALS['LG_VIEWER_AUTH'] = $authed;

    $ctx = [
        'viewer'         => $viewer,
        'editor_mode'    => false,          // standalone is read-only (design §1)
        'can_edit'       => false,
        'media_resolver' => 'lg_standalone_media_resolver',
        'post_id'        => (int) ($pc['post_id'] ?? 0),
        'post_tier'      => (string) ($pc['post_tier'] ?? ''),
    ];

    $brandTokens = Theme::defaultValues();
    $result = Pipeline::run($layout, $brandTokens, /* dashOverrides */ [], $ctx);
    $GLOBALS['LG_STANDALONE_LAST_CSS'] = (string) ($result['css'] ?? '');
    return (string) ($result['html'] ?? '');
}

/**
 * §5 gating self-check. Renders the blob as public AND as pro, then asserts:
 *   1. gated payload (the YouTube id of the gated embed) is ABSENT in public HTML
 *   2. gated payload is PRESENT in pro HTML
 *   3. public HTML shows the gate-CTA card (preview, not payload)
 *   4. the raw blob is never emitted (no _lg_layout markers / no full block JSON)
 * Prints a report and returns a process exit code (0 = all pass).
 */
function lg_standalone_proof(array $layout, array $pc, array $blob): int {
    // Find the gated embed + its YouTube id straight from the blob.
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

    [$pubViewer, $pubAuth] = lg_standalone_viewer('public', $pc);
    $pub = lg_standalone_render_article($layout, $pc, $pubViewer, $pubAuth);
    [$proViewer, $proAuth] = lg_standalone_viewer('pro', $pc);
    $pro = lg_standalone_render_article($layout, $pc, $proViewer, $proAuth);

    $checks = [];
    $checks[] = ['gated payload (YT id "' . $ytId . '") ABSENT in public HTML',
                 $ytId !== '' && strpos($pub, $ytId) === false];
    $checks[] = ['gated payload (YT id "' . $ytId . '") PRESENT in pro HTML',
                 $ytId !== '' && strpos($pro, $ytId) !== false];
    $checks[] = ['public sees gate-CTA card (lg-gate-cta)',
                 strpos($pub, 'lg-gate-cta') !== false];
    $checks[] = ['pro sees real embed payload (data-yt-id)',
                 strpos($pro, 'data-yt-id') !== false];
    $checks[] = ['pro does NOT get a gate-CTA in place of the embed',
                 strpos($pro, 'data-lg-gate="embed"') === false];
    // The wire never carries the raw blob: no editor markers, no verbatim block JSON.
    $checks[] = ['no editor markers leak to the wire (public)',
                 strpos($pub, '<lg-edit') === false];
    $checks[] = ['raw blob not echoed (no "post_context" key on the wire)',
                 strpos($pub, 'post_context') === false && strpos($pro, 'post_context') === false];

    $allPass = true;
    $out = ["layout-standalone gating proof — blob: " . ($blob['post_context']['title'] ?? '?'), str_repeat('─', 60)];
    foreach ($checks as [$label, $ok]) {
        $allPass = $allPass && $ok;
        $out[] = ($ok ? '  PASS  ' : '  FAIL  ') . $label;
    }
    $out[] = str_repeat('─', 60);
    $out[] = ($allPass ? 'RESULT: ALL PASS — gating holds at render, raw blob never on the wire.'
                       : 'RESULT: FAILURES ABOVE.');
    $out[] = sprintf('  public HTML: %d bytes · pro HTML: %d bytes', strlen($pub), strlen($pro));
    fwrite(STDOUT, implode("\n", $out) . "\n");
    return $allPass ? 0 : 1;
}

/** Assemble the full standalone HTML document around the rendered article. */
function lg_standalone_page(array $pc, string $articleHtml, string $css, bool $authed, string $tier, string $viewerName, string $as): string {
    $title = htmlspecialchars((string) ($pc['title'] ?? 'Looth Group'), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

    // The shared shell. Same partials archive-poc / bb-mirror use (consumer
    // contract §0a: pass active_nav + logout_url).
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
<!-- Shared shell chrome CSS (nginx maps /lg-shared/ → /srv/lg-shared/) -->
<link rel="stylesheet" href="/lg-shared/site-header.css">
<style>
/* lg-layout-v2 article bundle — produced by CssBuilder for THIS render, inlined.
   (Production would emit a cached <link> to a per-bundle file; inlined here so
   the PoC is a single self-contained document.) */
<?= $css ?>
</style>
<style>
/* Page chrome around the article column — the standalone host's responsibility,
   NOT part of the article CSS bundle. Mirrors archive-poc's body type. */
body { margin: 0; background: #f0eee8; color: #323532;
       font-family: 'Jost', system-ui, -apple-system, sans-serif; }
.lg-standalone-main { max-width: 760px; margin: 0 auto; padding: 24px 16px 64px; }
.lg-article { margin: 0 auto; }
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
        'active_nav'    => '',                              // an article is in no nav section
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

/** Emit a clean error in either SAPI and stop. */
function lg_standalone_fail(bool $isCli, string $msg): void {
    if ($isCli) { fwrite(STDERR, "render-standalone: $msg\n"); exit(2); }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "render-standalone: $msg\n";
    exit;
}
