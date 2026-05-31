<?php
/**
 * bb-mirror chrome — header + footer wrappers + left nav.
 *
 * bb_mirror_chrome_header():
 *   Outputs doctype → <body open> → shared site header → searchbar →
 *   .bb-layout with left nav open. Template content goes in the content pane.
 *
 * bb_mirror_chrome_footer():
 *   Closes .bb-layout, emits shared site footer, closes <body>.
 *
 * Shared header from /srv/lg-shared/site-header.php (P3 partial).
 * Shared CSS at /lg-shared/site-header.css linked in <head>.
 *
 * Viewer state comes from lg_bb_mirror_whoami() — same loopback pattern
 * as archive-poc, defined in bb-mirror config.php.
 */

declare(strict_types=1);

/**
 * Cache-buster for static assets: filemtime so edits invalidate the browser
 * cache automatically. Falls back to a constant if the file can't be stat'd.
 */
function bb_mirror_asset_ver(string $filename): string
{
    $path = __DIR__ . '/' . $filename;
    $mt = @filemtime($path);
    return $mt ? (string)$mt : '1';
}

/**
 * Map a top-level forum slug to a category color key.
 */
function bb_mirror_cat_key(?string $parent_slug, ?string $own_slug = null): string
{
    $slug = $parent_slug ?? $own_slug ?? '';
    if ($slug === '') return 'general';

    if (str_contains($slug, 'acoustic'))                                            return 'acoustic';
    if (str_contains($slug, 'build') || str_contains($slug, 'construction'))        return 'builds';
    if (str_contains($slug, 'repair') || str_contains($slug, 'restoration'))       return 'repair';
    if (str_contains($slug, 'tool'))                                                return 'tools';
    if (str_contains($slug, 'business') || str_contains($slug, 'professional'))    return 'business';
    if (str_contains($slug, 'market') || str_contains($slug, 'buy')
        || str_contains($slug, 'sell') || str_contains($slug, 'classif'))          return 'market';
    if (str_contains($slug, 'sponsor'))                                             return 'sponsors';
    if (str_contains($slug, 'looth') && $slug !== 'looth-group-partners')          return 'looths';

    return 'general';
}

/**
 * Build a map of forum_id → category key for all public forums.
 */
function bb_mirror_forum_icon(string $slug, string $cat_key): string {
    static $leaf = [
        'share-your-repair-content'=>"\u{1F4F7}",'finish'=>"\u{2728}",'touring-tech'=>"\u{1F690}",
        'amps-pickups-and-pedals'=>"\u{1F39B}",'electric-2'=>"\u{26A1}",'neck-reset-database'=>"\u{1F4D0}",
        'acoustic'=>"\u{1F3B8}",'folk-bluegrass-irish-old-time-instruments'=>"\u{1FA95}",
        'design-and-testing'=>"\u{1F9EA}",'electric'=>"\u{26A1}",'acoustic-2'=>"\u{1F3B8}",'finish-2'=>"\u{2728}",
        'amps-pickups-and-pedals-2'=>"\u{1F39B}",'share-your-new-builds-content'=>"\u{1F4F7}",
        'folk-bluegrass-irish-old-time-instruments-2'=>"\u{1FA95}",
        '3d-printing'=>"\u{1F5A8}",'cad-cam'=>"\u{1F4D0}",'cnc'=>"\u{2699}",'tools-and-jigs'=>"\u{1F9F0}",
        'shop-organisation'=>"\u{1F5C4}",'plek-machine'=>"\u{1F916}",
        'paper-work-and-drudgery'=>"\u{1F4C4}",'general-buisness'=>"\u{1F4BC}",'job-postings'=>"\u{1F4CB}",
        'customer-relations'=>"\u{1F91D}",'resumes'=>"\u{1F4C4}",'buy-buy-buy'=>"\u{1F6D2}",'sell-sell-sell'=>"\u{1F4B5}",
        'stewmac'=>"\u{1F6E0}",'go-acoustic-audio'=>"\u{1F50A}",'total-vise'=>"\u{1F5DC}",'strings-micro-factory'=>"\u{1F3BB}",
    ];
    if (isset($leaf[$slug])) return $leaf[$slug];
    static $cat = ['repair'=>"\u{1F6E0}",'builds'=>"\u{1F528}",'acoustic'=>"\u{1F3B8}",'tools'=>"\u{1F9F0}",'business'=>"\u{1F4BC}",'market'=>"\u{1F3F7}",'general'=>"\u{1F4AC}",'sponsors'=>"\u{2B50}",'looths'=>"\u{1F4CD}"];
    return $cat[$cat_key] ?? "\u{2022}";
}

function bb_mirror_build_cat_map(array $rows): array
{
    $slugs   = [];
    $parents = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $slugs[$id]   = (string)$r['slug'];
        $parents[$id] = $r['parent_forum_id'] !== null ? (int)$r['parent_forum_id'] : null;
    }

    $map = [];
    foreach ($rows as $r) {
        $id        = (int)$r['id'];
        $parent_id = $parents[$id];
        if ($parent_id === null) {
            $map[$id] = bb_mirror_cat_key(null, $slugs[$id]);
        } else {
            $parent_slug = $slugs[$parent_id] ?? $slugs[$id];
            $map[$id] = bb_mirror_cat_key($parent_slug, $slugs[$id]);
        }
    }
    return $map;
}

function bb_mirror_left_nav(): void
{
    $db   = bb_mirror_db();
    $rows = $db->query("
        SELECT id, slug, title, parent_forum_id, menu_order, forum_type
          FROM forum
         WHERE visibility = 'public' AND status IN ('open','closed')
           AND id NOT IN (67251, 3876)
         ORDER BY parent_forum_id NULLS FIRST, menu_order ASC
    ")->fetchAll();

    $children = [];
    $top      = [];
    foreach ($rows as $r) {
        if ($r['parent_forum_id'] === null) $top[] = $r;
        else $children[(int)$r['parent_forum_id']][] = $r;
    }

    $containers = [];
    $general    = [];
    $sponsors   = [];
    $local      = [];
    foreach ($top as $t) {
        $kids       = $children[(int)$t['id']] ?? [];
        $slug       = (string)$t['slug'];
        $is_local   = str_contains($slug, 'looth') && $slug !== 'looth-group-partners';
        $is_sponsor = ((int)$t['id'] === 34044 || str_contains($slug, 'sponsor'));
        if ($kids || $t['forum_type'] === 'category') {
            $containers[] = ['parent' => $t, 'kids' => $kids];
        } elseif ($is_local) {
            $local[] = $t;
        } elseif ($is_sponsor) {
            $sponsors[] = $t;
        } else {
            $general[] = $t;
        }
    }

    $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $prefix = LG_BB_MIRROR_PUBLIC_PATH;
    $rel    = ltrim(str_starts_with($uri, $prefix) ? substr($uri, strlen($prefix)) : $uri, '/');
    $segs   = array_values(array_filter(explode('/', $rel)));
    $active = $segs[0] ?? '';

    $active_forum_id = null;
    if (count($segs) === 2) {
        $dis = $db->prepare(
            "SELECT t.forum_id FROM forums.topic t
               JOIN forums.forum f ON f.id = t.forum_id
              WHERE f.slug = ? AND t.slug = ?
                AND t.status = 'publish'
              LIMIT 1"
        );
        $dis->execute([$segs[0], $segs[1]]);
        $drow = $dis->fetch();
        if ($drow) $active_forum_id = (int)$drow['forum_id'];
    }

    $root_href   = htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/');
    $root_active = ($active === '');
    ?>
    <nav class="nav-tree" aria-label="Forum navigation">

      <button class="nav-new-post" id="ntm-open" type="button" aria-haspopup="dialog">+ New post</button>

      <a class="nav-tree__item nav-tree__root <?= $root_active ? 'nav-tree__item--active' : '' ?>"
         href="<?= $root_href ?>">
        All activity
      </a>

      <?php
      $render_link = function (array $f, string $extra_class = '', string $cat_key = '') use ($active, &$active_forum_id): void {
          $href    = htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/' . $f['slug'] . '/');
          $is_act  = $active_forum_id !== null
              ? ((int)$f['id'] === $active_forum_id)
              : ($active === $f['slug']);
          $classes = 'nav-tree__item ' . $extra_class . ($is_act ? ' nav-tree__item--active' : '');
          echo '<a class="' . trim($classes) . '" href="' . $href . '">'
             . '<span class="nav-tree__icon" aria-hidden="true">' . bb_mirror_forum_icon((string)$f['slug'], $cat_key) . '</span>'
             . htmlspecialchars($f['title'])
             . '</a>' . "\n";
      };
      ?>

      <?php foreach ($containers as $c):
          $cat_key  = bb_mirror_cat_key(null, (string)$c['parent']['slug']);
          $cat_href = htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/' . $c['parent']['slug'] . '/');

          // Open if active forum is this category or any of its children
          $sec_active = false;
          if ($active === (string)$c['parent']['slug']
              || ($active_forum_id !== null && (int)$c['parent']['id'] === $active_forum_id)) {
              $sec_active = true;
          } else {
              foreach ($c['kids'] as $kid) {
                  if ($active === (string)$kid['slug']
                      || ($active_forum_id !== null && (int)$kid['id'] === $active_forum_id)) {
                      $sec_active = true; break;
                  }
              }
          }
      ?>
        <div class="nav-tree__section<?= $sec_active ? ' nav-tree__section--open' : '' ?>">
          <div class="nav-tree__section-head">
            <a class="nav-tree__section-label nav-section--<?= $cat_key ?>"
               href="<?= $cat_href ?>"><span class="nav-tree__icon" aria-hidden="true"><?= bb_mirror_forum_icon((string)$c['parent']['slug'], $cat_key) ?></span><?= htmlspecialchars($c['parent']['title']) ?></a>
            <button class="nav-tree__section-toggle" type="button"
                    aria-expanded="<?= $sec_active ? 'true' : 'false' ?>">&#9658;</button>
          </div>
          <div class="nav-tree__section-body">
            <?php foreach ($c['kids'] as $kid) $render_link($kid, 'nav-tree__item--child nav-section--' . $cat_key, $cat_key); ?>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if ($general): ?>
        <div class="nav-tree__section">
          <span class="nav-tree__section-label nav-section--general"><span class="nav-tree__icon" aria-hidden="true"><?= bb_mirror_forum_icon('','general') ?></span>General</span>
          <?php foreach ($general as $f) $render_link($f, 'nav-tree__item--child nav-section--general', 'general'); ?>
        </div>
      <?php endif; ?>

      <?php if ($sponsors): ?>
        <div class="nav-tree__section">
          <span class="nav-tree__section-label nav-section--sponsors"><span class="nav-tree__icon" aria-hidden="true"><?= bb_mirror_forum_icon('','sponsors') ?></span>Sponsors</span>
          <?php foreach ($sponsors as $f) $render_link($f, 'nav-tree__item--child nav-section--sponsors', 'sponsors'); ?>
        </div>
      <?php endif; ?>

      <?php if ($local): ?>
        <div class="nav-tree__section">
          <span class="nav-tree__section-label nav-section--looths"><span class="nav-tree__icon" aria-hidden="true"><?= bb_mirror_forum_icon('','looths') ?></span>Local Looths</span>
          <?php foreach ($local as $f) $render_link($f, 'nav-tree__item--child nav-section--looths', 'looths'); ?>
        </div>
      <?php endif; ?>

    </nav>
    <?php
}

function bb_mirror_new_topic_modal(): void
{
    $db = bb_mirror_db();

    // Postable LEAF forums for the <select>. Excludes category containers AND any
    // forum that has children (the placeholder parents that just hold subforums) —
    // you post to a subforum, never to its container.
    $forums = $db->query("
        SELECT f.id, f.slug, f.title, f.parent_forum_id, f.menu_order,
               p.title AS parent_title
          FROM forum f
          LEFT JOIN forum p ON p.id = f.parent_forum_id
         WHERE f.visibility = 'public' AND f.status = 'open' AND f.forum_type = 'forum'
           AND f.id NOT IN (67251, 3876)
           AND f.id NOT IN (SELECT parent_forum_id FROM forum WHERE parent_forum_id IS NOT NULL)
         ORDER BY COALESCE(f.parent_forum_id, f.id), f.menu_order ASC
    ")->fetchAll();

    // Detect currently-scoped forum from URL (same logic as nav active highlight)
    $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $prefix = LG_BB_MIRROR_PUBLIC_PATH;
    $rel    = ltrim(str_starts_with($uri, $prefix) ? substr($uri, strlen($prefix)) : $uri, '/');
    $segs   = array_values(array_filter(explode('/', $rel)));
    $active_slug = count($segs) === 1 ? $segs[0] : '';  // only pre-select on 1-segment forum feeds

    $uri_fid = null;
    if (preg_match('/[?&]fid=(\d+)/', $_SERVER['REQUEST_URI'] ?? '', $m)) {
        $uri_fid = (int)$m[1];
    }

    $current_forum_id = 0;
    if ($uri_fid !== null) {
        $current_forum_id = $uri_fid;
    } elseif ($active_slug !== '') {
        foreach ($forums as $f) {
            if ($f['slug'] === $active_slug) { $current_forum_id = (int)$f['id']; break; }
        }
    }

    $rest_base = 'https://' . LG_BB_MIRROR_HOST . '/wp-json/buddyboss/v1';
    $login_url = 'https://' . LG_BB_MIRROR_HOST . '/wp-login.php';
    ?>
<div class="ntm-overlay" id="ntm-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="ntm-heading">
  <div class="ntm-backdrop" id="ntm-backdrop"></div>
  <div class="ntm-dialog">
    <h2 class="ntm-heading" id="ntm-heading">New post</h2>

    <div class="ntm-state ntm-state--loading" id="ntm-loading" hidden>
      Loading…
    </div>

    <div class="ntm-state ntm-state--anon" id="ntm-anon" hidden>
      <p class="ntm-anon__msg">Sign in to post to the forums.</p>
      <a class="ntm-anon__link" href="<?= htmlspecialchars($login_url) ?>">Sign in</a>
    </div>

    <form class="ntm-form" id="ntm-form" novalidate hidden
          data-rest-base="<?= htmlspecialchars($rest_base) ?>"
          data-current-forum="<?= $current_forum_id ?>"
          data-public-path="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH) ?>">

      <label class="ntm-label" for="ntm-forum">Forum</label>
      <select class="ntm-select" id="ntm-forum" name="forum_id" required>
        <option value="">— choose a forum —</option>
        <?php
        $cur_group_pid = false;
        foreach ($forums as $f):
            $pid = $f['parent_forum_id'] !== null ? (int)$f['parent_forum_id'] : null;
            if ($pid !== $cur_group_pid) {
                if ($cur_group_pid !== false) echo '</optgroup>';
                $label = $pid !== null ? htmlspecialchars((string)$f['parent_title']) : 'General';
                echo '<optgroup label="' . $label . '">';
                $cur_group_pid = $pid;
            }
            $sel = ((int)$f['id'] === $current_forum_id) ? ' selected' : '';
            echo '<option value="' . (int)$f['id'] . '"' . $sel
               . ' data-slug="' . htmlspecialchars($f['slug']) . '">'
               . htmlspecialchars($f['title'])
               . '</option>' . "\n";
        endforeach;
        if ($cur_group_pid !== false) echo '</optgroup>';
        ?>
      </select>

      <label class="ntm-label" for="ntm-title-in">Title</label>
      <input class="ntm-input" id="ntm-title-in" name="title" type="text"
             required placeholder="What's this about?">

      <label class="ntm-label">Body <span class="ntm-label__opt">(optional — formatting, images & links)</span></label>
      <!-- Quill mounts here; falls back to the plain textarea if Quill fails to load -->
      <div class="ntm-editor" id="ntm-editor"></div>
      <textarea class="ntm-textarea ntm-textarea--fallback" id="ntm-content" name="content" rows="6"
                placeholder="Share details, ask a question…" hidden></textarea>
      <p class="ntm-paste-hint">Tip: paste a YouTube, Vimeo, or Instagram link on its own line to embed it.</p>

      <label class="ntm-label" for="ntm-tags">Tags <span class="ntm-label__opt">(optional, comma-separated)</span></label>
      <input class="ntm-input" id="ntm-tags" name="topic_tags" type="text"
             placeholder="e.g. neck reset, fret press, martin d18" autocomplete="off">

      <div class="ntm-row">
        <button type="submit" class="ntm-submit" id="ntm-submit">Post</button>
        <button type="button" class="ntm-cancel" id="ntm-cancel">Cancel</button>
        <span class="ntm-status" id="ntm-status" aria-live="polite"></span>
      </div>
    </form>
  </div>
</div>

<!-- Feed reply modal — opened by a card's "Reply" button (see forums.js §4b). -->
<div class="ntm-overlay" id="frm-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="frm-heading">
  <div class="ntm-backdrop" id="frm-backdrop"></div>
  <div class="ntm-dialog">
    <h2 class="ntm-heading" id="frm-heading">Reply</h2>
    <p class="frm-context" id="frm-context" hidden>Replying to <span class="frm-context__title"></span></p>

    <div class="ntm-state ntm-state--loading" id="frm-loading" hidden>Loading…</div>

    <div class="ntm-state ntm-state--anon" id="frm-anon" hidden>
      <p class="ntm-anon__msg">Sign in to reply.</p>
      <a class="ntm-anon__link" href="<?= htmlspecialchars($login_url) ?>">Sign in</a>
    </div>

    <form class="ntm-form" id="frm-form" novalidate hidden
          data-rest-base="<?= htmlspecialchars($rest_base) ?>">
      <input type="hidden" id="frm-topic-id" name="topic_id" value="">
      <input type="hidden" id="frm-forum-id" name="forum_id" value="">
      <label class="ntm-label">Your reply <span class="ntm-label__opt">(formatting, images &amp; links)</span></label>
      <!-- Quill mounts here (same editor as the new-topic modal); falls back to the textarea -->
      <div class="ntm-editor" id="frm-editor"></div>
      <textarea class="ntm-textarea ntm-textarea--fallback" id="frm-content" name="content" rows="5"
                placeholder="Share your thoughts…" hidden></textarea>
      <p class="ntm-paste-hint">Tip: paste a YouTube, Vimeo, or Instagram link on its own line to embed it.</p>
      <div class="ntm-row">
        <button type="submit" class="ntm-submit" id="frm-submit">Post reply</button>
        <button type="button" class="ntm-cancel" id="frm-cancel">Cancel</button>
        <span class="ntm-status" id="frm-status" aria-live="polite"></span>
      </div>
    </form>
  </div>
</div>
    <?php
}

/**
 * Viewer assembly — inline-verify fast path + whoami fallback.
 * design-shim-replacement.md §4 Step A. Both builders return the SAME shape so
 * bb_mirror_chrome_header() is source-agnostic. Defined here (not config.php)
 * because config.php is bb-mirror-owned; lg_bb_mirror_whoami() is already loaded
 * from config.php before this file runs.
 */
if (!function_exists('lg_bb_mirror_viewer_from_claims')) {
function lg_bb_mirror_viewer_from_claims(array $claims): array {
    // Identity + display straight from the verified token (§0c). Tier from the
    // lg_tier cookie hint (validated against the known set). Capabilities left
    // empty — bb-mirror renders no mod UI (single-mod model §3f); any sensitive
    // cap check reconciles via /whoami at the action site, not in the header.
    $lg   = (string)($_COOKIE['lg_tier'] ?? 'public');
    $tier = in_array($lg, ['public', 'lite', 'pro'], true) ? $lg : 'public';
    return [
        'authenticated' => true,
        'user_uuid'     => $claims['sub'] ?? null,
        'wp_user_id'    => isset($claims['wp_user_id']) ? (int)$claims['wp_user_id'] : null,
        'slug'          => $claims['slug'] ?? null,
        'display_name'  => (string)($claims['display_name'] ?? ''),
        'avatar_url'    => $claims['avatar_url'] ?? null,
        'tier'          => $tier,
        'capabilities'  => [],
    ];
}
}

if (!function_exists('lg_bb_mirror_viewer_from_whoami')) {
function lg_bb_mirror_viewer_from_whoami(): array {
    // Existing loopback (lg_bb_mirror_whoami in config.php), normalized to the
    // shared shape. Retired in Step B once looth_id is universal — NOT this turn.
    $w = lg_bb_mirror_whoami();
    return [
        'authenticated' => ($w['authenticated'] ?? false) === true,
        'user_uuid'     => $w['user_uuid'] ?? null,
        'wp_user_id'    => $w['wp_user_id'] ?? null,
        'slug'          => $w['slug'] ?? null,
        'display_name'  => (string)($w['display_name'] ?? ''),
        'avatar_url'    => $w['avatar_url'] ?? null,
        'tier'          => (string)($w['tier'] ?? 'public'),
        'capabilities'  => (array)($w['capabilities'] ?? []),
    ];
}
}

function bb_mirror_chrome_header(string $page_title = 'Forums'): void
{
    require_once '/srv/lg-shared/site-header.php';

    // Inline-verify fast path (design §4 Step A): verify looth_id locally with
    // the RS256 public key — no WP-boot loopback. Fall back to the whoami shim
    // when the cookie is absent/invalid so nothing breaks mid-rollout. The
    // is_readable guard keeps bb-mirror working even before the helper deploys.
    $verify_helper = '/srv/lg-shared/jwt-verify.php';
    $claims = null;
    if (is_readable($verify_helper)) {
        require_once $verify_helper;
        if (function_exists('lg_shared_verify_looth_id')) {
            $claims = lg_shared_verify_looth_id($_COOKIE['looth_id'] ?? null);
        }
    }
    if ($claims !== null) {
        error_log('[shim-inline] JWT verified for ' . ($claims['sub'] ?? '?'));
        $viewer = lg_bb_mirror_viewer_from_claims($claims);
    } else {
        error_log('[shim-fallback] looth_id absent/invalid, using whoami');
        $viewer = lg_bb_mirror_viewer_from_whoami();
    }
    $authed = $viewer['authenticated'];
    $tier   = (string)$viewer['tier'];
    $caps   = (array)$viewer['capabilities'];
    $dname  = (string)$viewer['display_name'];
    $avatar = $viewer['avatar_url'] ?? null;

    if ($authed && $dname === '') {
        foreach ($_COOKIE as $name => $val) {
            if (str_starts_with($name, 'wordpress_logged_in_')) {
                $parts = explode('|', urldecode($val), 4);
                if (!empty($parts[0])) { $dname = $parts[0]; break; }
            }
        }
    }

    $logo_url = LG_BB_MIRROR_ENV === 'dev'
        ? 'https://dev.loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png'
        : 'https://loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png';

    $title = htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $title ?> — Looth Group</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<link rel="stylesheet" href="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH) ?>/forums.css?v=<?= bb_mirror_asset_ver('forums.css') ?>">
</head>
<body class="bb-mirror">

<!-- Fixed triangle-corner hamburger (top-left, always on top) -->
<button class="corner-hamburger" id="bb-ham"
        aria-label="Toggle navigation" aria-expanded="true">
  <span class="corner-hamburger__icon" aria-hidden="true">&#9776;</span>
</button>

<!-- Mobile drawer backdrop -->
<div class="nav-overlay" id="bb-overlay" aria-hidden="true"></div>

<?php
    lg_shared_render_site_header([
        'authenticated'      => $authed,
        'active_nav'         => 'forum',   // light the Forum nav item (§0a)
        'tier'               => $tier,
        'display_name'       => $dname,
        'avatar_url'         => lg_bb_mirror_safe_avatar($avatar),   // non-gated default
        'capabilities'       => $caps,
        'msg_unread'         => null,
        'notif_unread'       => null,
        'logo_url'           => $logo_url,
        'profile_url'        => '/profile/edit',
    ]);
?>

<nav class="bb-mirror__searchbar" aria-label="Forum search">
  <form class="search-form" method="get" action="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/') ?>">
    <label class="search-form__label" for="q">Search forums</label>
    <input class="search-form__input" id="q" name="q" type="search"
           placeholder="Search topics + replies…"
           value="<?= htmlspecialchars((string)($_GET['q'] ?? '')) ?>"
           autocomplete="off">
    <button class="search-form__btn" type="submit" aria-label="Search">&#9906;</button>
  </form>
</nav>

<div class="bb-layout">
  <aside class="bb-layout__nav" id="bb-nav">
    <?php bb_mirror_left_nav(); ?>
  </aside>
  <main class="bb-layout__content bb-mirror__main" id="lg-main">
<?php
}

function bb_mirror_chrome_footer(): void
{
    require_once '/srv/lg-shared/site-footer.php';

    $logo_url = LG_BB_MIRROR_ENV === 'dev'
        ? 'https://dev.loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png'
        : 'https://loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png';
    ?>
  </main><!-- .bb-layout__content -->
</div><!-- .bb-layout -->

<?php lg_shared_render_site_footer(['logo_url' => $logo_url]); ?>

<?php bb_mirror_new_topic_modal(); ?>

<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js" defer></script>
<!-- Single source of the forum base path for forums.js (self-links, lazy fetches). -->
<script>window.LG_FORUM_BASE = <?= json_encode(LG_BB_MIRROR_PUBLIC_PATH) ?>;</script>
<script src="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH) ?>/forums.js?v=<?= bb_mirror_asset_ver('forums.js') ?>" defer></script>
</body>
</html>
<?php
}
