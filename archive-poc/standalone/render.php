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
    /* Blob-miss fallback: not every managed-CPT post has a standalone blob (e.g.
       only a fraction of videos/sponsors are materialized). Rather than 404 the
       uncovered majority, hand the ORIGINAL permalink back to WordPress via an
       internal nginx location (X-Accel-Redirect). nginx re-runs the WP front
       controller for this REQUEST_URI; covered posts render standalone-fast,
       uncovered ones get the normal WP page. CLI keeps the hard 404 (proof/debug). */
    if (!$IS_CLI) {
        $origPath = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
        if ($origPath !== false && $origPath !== '') {
            header('X-Accel-Redirect: /__wp_render' . $origPath);
            exit;
        }
    }
    lg_standalone_fail($IS_CLI, 404, "not found: $postType/" . ($postId > 0 ? "#$postId" : $slug));
}

$blob = json_decode((string) $row['blob'], true);
if (!is_array($blob) || !is_array($blob['layout'] ?? null) || !is_array($blob['post_context'] ?? null)) {
    lg_standalone_fail($IS_CLI, 500, 'blob malformed');
}
$layout      = lg_standalone_normalize_blocks($blob['layout']);
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

/* ── Edit affordance ─────────────────────────────────────────────────────
   Admins/editors (edit_archive_poc cap) or the post's own author may jump to the
   WordPress FE editor via ?lg_edit=1 — nginx routes that flagged URL to WP, where
   the real plugin editor + capability check take over. Hidden in preview mode. */
$editUrl = '';
if (!$IS_CLI && $previewAs === '') {
    $who = lg_archive_poc_whoami();   // static-cached this request — no second HTTP call
    if (!empty($who['authenticated'])) {
        $capEdit  = (($who['capabilities']['edit_archive_poc'] ?? false) === true);
        $vid      = (int) ($who['wp_user_id'] ?? 0);
        $isAuthor = $vid > 0 && $vid === (int) ($postContext['author']['id'] ?? -1);
        if ($capEdit || $isAuthor) {
            $editUrl = rtrim((string) ($postContext['permalink'] ?? ''), '/') . '/?lg_edit=1';
        }
    }
}

/* ── Comments affordance ─────────────────────────────────────────────────
   Count baked at materialize. The modal iframes the WP comments-only view
   (?lg_comments=1). Shown when there are comments OR the thread is open; logged-out
   users still see the count (teaser) and the read-only thread + a login prompt. */
$commentsCount = (int) ($postContext['comments_count'] ?? 0);
$commentsOpen  = !empty($postContext['comments_open']);
$commentsUrl   = (!$IS_CLI && ($commentsCount > 0 || $commentsOpen))
    ? rtrim((string) ($postContext['permalink'] ?? ''), '/') . '/?lg_comments=1'
    : '';

/* ── Render ──────────────────────────────────────────────────────────── */
$articleHtml = lg_standalone_render_article($layout, $postContext, $viewer, $authed);
$css         = $GLOBALS['LG_STANDALONE_LAST_CSS'] ?? '';

if (!$IS_CLI) header('Content-Type: text/html; charset=utf-8');
echo lg_standalone_page($postContext, $articleHtml, $css, $authed, $shellTier, $viewerName, $previewAs, $editUrl, $commentsUrl, $commentsCount);


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
    // Brand tokens + dash style overrides from the materialized dash snapshot
    // (dash-theme.json — global lg_layout_v2_brand_palette + _block_styles, kept
    // fresh by the materializer / dash-save hook). The WP renderer reads these
    // from wp_options every render; standalone reads the snapshot (no WP boot).
    [$brandTokens, $dashOverrides] = lg_standalone_theme();
    $result = Pipeline::run($layout, $brandTokens, $dashOverrides, $ctx);
    $GLOBALS['LG_STANDALONE_LAST_CSS'] = (string) ($result['css'] ?? '');
    return lg_standalone_lazyload_imgs((string) ($result['html'] ?? ''));
}

/** Mirror WP's wp_filter_content_tags: give content <img>s lazy-loading + async
 *  decoding so the browser doesn't eagerly fetch two-dozen images on load. Only
 *  touches imgs that don't already declare a loading strategy — so the post-header
 *  hero (loading="eager" fetchpriority="high") and the avatar (already lazy) are
 *  left as-is. srcset/sizes is a separate, materializer-side concern (size variants
 *  aren't in the blob yet). */
function lg_standalone_lazyload_imgs(string $html): string {
    if (strpos($html, '<img') === false) return $html;
    return (string) preg_replace_callback(
        '~<img\b(?![^>]*\bloading=)[^>]*>~i',
        function (array $m): string {
            return preg_replace('~\s*/?>$~', '', $m[0]) . ' loading="lazy" decoding="async">';
        },
        $html
    );
}

/** Load the dash theme snapshot → [resolved brand tokens, dash style overrides].
 *  Falls back to engine defaults if the snapshot is missing/unreadable. */
function lg_standalone_theme(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $brand = []; $styles = [];
    $f = __DIR__ . '/dash-theme.json';
    if (is_readable($f)) {
        $j = json_decode((string) file_get_contents($f), true);
        if (is_array($j)) {
            $brand  = is_array($j['brand']  ?? null) ? $j['brand']  : [];
            $styles = is_array($j['styles'] ?? null) ? $j['styles'] : [];
        }
    }
    $cache = [Theme::resolve($brand), $styles];
    return $cache;
}

/** Defensive layout normalization: the engine reads block props flattened onto the
 *  block node ($args = $block), but a few posts store them under a nested {props:{…}}
 *  wrapper the engine never looks inside (so variant/tagline/etc. silently vanish).
 *  Flatten any such wrapper here so both formats render identically. No-op for the
 *  already-flattened majority. Recurses into container children (blocks / columns). */
function lg_standalone_normalize_blocks(array $layout): array {
    $walk = function (array $blocks) use (&$walk): array {
        foreach ($blocks as &$b) {
            if (!is_array($b)) continue;
            if (isset($b['props']) && is_array($b['props'])) { $b += $b['props']; unset($b['props']); }
            if (isset($b['blocks']) && is_array($b['blocks'])) $b['blocks'] = $walk($b['blocks']);
            if (isset($b['columns']) && is_array($b['columns'])) {
                foreach ($b['columns'] as &$col) {
                    if (is_array($col) && isset($col['blocks']) && is_array($col['blocks'])) $col['blocks'] = $walk($col['blocks']);
                }
                unset($col);
            }
        }
        unset($b);
        return $blocks;
    };
    if (isset($layout['blocks']) && is_array($layout['blocks'])) $layout['blocks'] = $walk($layout['blocks']);
    return $layout;
}

/** Externalize the engine CSS bundle to a content-hashed, cacheable file under
 *  /archive-poc/assets/ and return its URL. The bundle is global + deterministic
 *  (CssBuilder over Manifest::all() + the shared theme), so it's byte-identical for
 *  every standalone page → written once, then served from browser cache on every
 *  subsequent navigation. Replaces re-inlining ~105KB per page. Returns '' on write
 *  failure so the caller can fall back to inline. */
function lg_standalone_css_href(string $css): string {
    if ($css === '') return '';
    $dir  = __DIR__ . '/../web/assets';
    $hash = substr(md5($css), 0, 16);
    $file = "$dir/lg-v2-bundle.$hash.css";
    $url  = "/archive-poc/assets/lg-v2-bundle.$hash.css";
    if (is_file($file)) return $url;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return '';
    // Atomic write: a concurrent reader never sees a half-written bundle.
    $tmp = "$file.tmp." . getmypid();
    if (@file_put_contents($tmp, $css) === false) return '';
    if (!@rename($tmp, $file)) { @unlink($tmp); return is_file($file) ? $url : ''; }
    return $url;
}

function lg_standalone_page(array $pc, string $articleHtml, string $css, bool $authed, string $tier, string $viewerName, string $previewAs, string $editUrl = '', string $commentsUrl = '', int $commentsCount = 0): string {
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
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<?php $cssHref = lg_standalone_css_href($css); ?>
<?php if ($cssHref !== ''): ?>
<link rel="stylesheet" href="<?= $cssHref ?>">
<?php else: /* write failed (e.g. assets dir not writable) — fall back to inline */ ?>
<style>
<?= $css ?>
</style>
<?php endif; ?>
<style>
body { margin: 0; background: #f0eee8; color: #323532;
       font-family: 'Jost', system-ui, -apple-system, sans-serif; }
.lg-standalone-main { max-width: 760px; margin: 0 auto; padding: 24px 16px 64px; }
.lg-standalone-edit { position: fixed; right: 18px; bottom: 18px; z-index: 50;
  display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px;
  background: #323532; color: #f0eee8; border-radius: 999px; font-size: 14px;
  font-weight: 600; text-decoration: none; box-shadow: 0 2px 10px rgba(0,0,0,.25); }
.lg-standalone-edit:hover { background: #1a1a1a; }
.lg-standalone-comments { position: fixed; left: 18px; bottom: 18px; z-index: 50;
  display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; background: #fff;
  color: #323532; border: 1px solid #d8d2c4; border-radius: 999px; font-size: 14px;
  font-weight: 600; text-decoration: none; box-shadow: 0 2px 10px rgba(0,0,0,.15); }
.lg-standalone-comments:hover { background: #f4f1e8; }
.lg-cmodal[hidden] { display: none; }
.lg-cmodal { position: fixed; inset: 0; z-index: 100; display: flex; align-items: center; justify-content: center; }
.lg-cmodal__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.45); }
.lg-cmodal__panel { position: relative; width: min(720px,94vw); height: min(80vh,900px); background: #fff;
  border-radius: 12px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,.35); display: flex; flex-direction: column; }
.lg-cmodal__head { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px;
  border-bottom: 1px solid #e3ddd0; font-weight: 700; }
.lg-cmodal__close { background: none; border: 0; font-size: 22px; line-height: 1; cursor: pointer; color: #323532; }
.lg-cmodal__frame { flex: 1; width: 100%; border: 0; }
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
<?php if ($editUrl !== ''): ?>
<a class="lg-standalone-edit" href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>" title="Edit this post">&#9998; Edit</a>
<?php endif; ?>
<?php if ($commentsUrl !== ''): ?>
<button type="button" class="lg-standalone-comments" id="lg-comments-btn" aria-haspopup="dialog" aria-controls="lg-cmodal">
  &#128172; <span><?= $commentsCount > 0 ? number_format($commentsCount) . ' comment' . ($commentsCount === 1 ? '' : 's') : 'Comments' ?></span>
</button>
<div class="lg-cmodal" id="lg-cmodal" role="dialog" aria-modal="true" aria-label="Comments" hidden>
  <div class="lg-cmodal__backdrop" data-lg-cmodal-close></div>
  <div class="lg-cmodal__panel">
    <div class="lg-cmodal__head"><span>Comments</span><button type="button" class="lg-cmodal__close" data-lg-cmodal-close aria-label="Close">&times;</button></div>
    <iframe class="lg-cmodal__frame" id="lg-cmodal-frame" title="Comments" data-src="<?= htmlspecialchars($commentsUrl, ENT_QUOTES, 'UTF-8') ?>"></iframe>
  </div>
</div>
<script>
(function(){
  var btn=document.getElementById('lg-comments-btn'),
      modal=document.getElementById('lg-cmodal'),
      frame=document.getElementById('lg-cmodal-frame');
  if(!btn||!modal||!frame)return;
  function openModal(){ if(!frame.src) frame.src=frame.getAttribute('data-src'); modal.hidden=false; }
  function closeModal(){ modal.hidden=true; }
  btn.addEventListener('click',openModal);
  modal.addEventListener('click',function(e){ if(e.target.hasAttribute('data-lg-cmodal-close'))closeModal(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&!modal.hidden)closeModal(); });
})();
</script>
<?php endif; ?>
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
