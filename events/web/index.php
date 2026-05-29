<?php
/**
 * events — standalone front controller for the events landing surface.
 *
 * nginx routes /events/ here on a dedicated FPM pool (no WP boot). Renders a
 * PUBLIC listing of `event` CPT posts (upcoming + past, region filter) on the
 * shared /srv/lg-shared/ chrome. Cards link to each event's v2 detail page,
 * where the per-event Zoom gate lives. The Zoom URL is never emitted here.
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/events-query.php';
require '/srv/lg-shared/site-header.php';
require '/srv/lg-shared/site-footer.php';

/* nginx alias + try_files + fastcgi-php.conf does not reliably preserve $args,
   but $request_uri is intact — parse it for the region filter (bb-mirror note). */
$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$qs = parse_url($request_uri, PHP_URL_QUERY) ?: '';
$query = [];
if ($qs !== '') parse_str($qs, $query);
$active_region = isset($query['ev_region']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower((string)$query['ev_region'])) : '';

$h        = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
$base_url = LG_EVENTS_PUBLIC_PATH . '/';
$asset_v  = (string)(@filemtime(__DIR__ . '/events.css') ?: '1');

/* ---------- viewer state for the header (cached whoami; listing is public) ---------- */
$who    = lg_events_whoami();
$authed = ($who['authenticated'] ?? false) === true;
$ctx    = [
    'authenticated' => $authed,
    'tier'          => (string)($who['tier'] ?? 'public'),
    'display_name'  => (string)($who['display_name'] ?? ''),
    'avatar_url'    => $who['avatar_url'] ?? null,
    'capabilities'  => (array)($who['capabilities'] ?? []),
    'msg_unread'    => null,
    'notif_unread'  => null,
    'logo_url'      => LG_EVENTS_LOGO,
    'profile_url'   => '/members/me/',
    'active_nav'    => 'events',                                   // coord §0a
    'logout_url'    => $authed ? '/wp-login.php?action=logout' : null,
];

$regions = lg_events_regions();

/* Render one bucket of cards. */
$render_bucket = static function (bool $past) use ($active_region, $h): void {
    $rows = lg_events_list($past, $active_region);
    if (!$rows) {
        echo '<p class="lg-evland__empty">' . ($past ? 'No past events.' : 'No upcoming events scheduled — check back soon.') . '</p>';
        return;
    }
    echo '<div class="lg-evland__grid">';
    foreach ($rows as $r) {
        $when = $r['when'];
        ?>
        <a class="lg-evland__card" href="<?= $h((string)$r['url']) ?>">
            <div class="lg-evland__thumb"<?= $r['thumb'] !== '' ? ' style="background-image:url(' . $h((string)$r['thumb']) . ')"' : '' ?>>
                <?php if ($when['mon'] !== ''): ?>
                    <span class="lg-evland__pill"><span class="lg-evland__mon"><?= $h($when['mon']) ?></span><span class="lg-evland__day"><?= $h($when['day']) ?></span></span>
                <?php endif; ?>
            </div>
            <div class="lg-evland__body">
                <h3 class="lg-evland__title"><?= $h((string)$r['title']) ?></h3>
                <?php if ($when['line'] !== ''): ?><p class="lg-evland__when"><?= $h($when['line']) ?></p><?php endif; ?>
                <div class="lg-evland__meta">
                    <?php if ($r['region'] !== ''): ?><span class="lg-evland__region">📍 <?= $h((string)$r['region']) ?></span><?php endif; ?>
                    <?php if ($r['tier_label'] !== ''): ?><span class="lg-evland__tier lg-evland__tier--<?= $h(strtolower((string)$r['tier_label'])) ?>"><?= $h((string)$r['tier_label']) ?></span><?php endif; ?>
                    <span class="lg-evland__cta">Details &rarr;</span>
                </div>
            </div>
        </a>
        <?php
    }
    echo '</div>';
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Events — The Looth Group</title>
<link rel="stylesheet" href="/lg-shared/site-header.css">
<link rel="stylesheet" href="<?= $h(LG_EVENTS_PUBLIC_PATH) ?>/events.css?v=<?= $h($asset_v) ?>">
</head>
<body class="lg-events-landing-page">

<?php lg_shared_render_site_header($ctx); ?>

<main id="lg-main" class="lg-evland">
    <h1 class="lg-evland__head">Events</h1>
    <p class="lg-evland__sub">Live builds, clinics, and community calls. Click any event for details and the join link.</p>

    <div class="lg-evland__filters">
        <a class="lg-evland__chip <?= $active_region === '' ? 'lg-evland__chip--active' : '' ?>" href="<?= $h($base_url) ?>">All regions</a>
        <?php foreach ($regions as $slug => $name): ?>
            <a class="lg-evland__chip <?= $active_region === $slug ? 'lg-evland__chip--active' : '' ?>"
               href="<?= $h($base_url . '?ev_region=' . rawurlencode($slug)) ?>"><?= $h($name) ?></a>
        <?php endforeach; ?>
    </div>

    <section class="lg-evland__section">
        <h2 class="lg-evland__section-h">Upcoming</h2>
        <?php $render_bucket(false); ?>
    </section>

    <section class="lg-evland__section">
        <h2 class="lg-evland__section-h">Past events</h2>
        <?php $render_bucket(true); ?>
    </section>
</main>

<?php lg_shared_render_site_footer(['logo_url' => LG_EVENTS_LOGO]); ?>

</body>
</html>
