<?php
/**
 * archive-poc/web/calendar.php — /calendar/ standalone events list.
 *
 * Ian's call: reuse the existing event data, but drop the region grouping/tags
 * and present a single top-to-bottom (north→south) chronological column.
 * Upcoming events first (soonest at top), then past events (most-recent first).
 * Direct sqlite read via lg_archive_poc_pdo(), no WP boot. Each event links to
 * its standalone /event/<slug>/ surface.
 */
declare(strict_types=1);
require __DIR__ . '/_page-shell.php';
[$is_member, $tier] = lg_page_boot();

$now    = time();
$events = [];
try {
    $pdo  = lg_archive_poc_pdo();
    $stmt = $pdo->prepare(
        "SELECT title, url, event_start_at, event_end_at, event_join_url
           FROM content_item
          WHERE cpt='event' AND event_start_at IS NOT NULL AND event_start_at > 0
          ORDER BY (event_start_at >= :now) DESC,
                   CASE WHEN event_start_at >= :now THEN event_start_at END ASC,
                   event_start_at DESC"
    );
    $stmt->execute([':now' => $now]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('calendar.php: ' . $e->getMessage());
}

$css = <<<'CSS'
.cal-list { display: flex; flex-direction: column; gap: 12px; margin-top: 8px; }
.cal-row {
  display: flex; align-items: stretch; gap: 18px;
  padding: 16px 18px; background: var(--lg-card-bg);
  border: 1px solid var(--lg-line); border-radius: 12px;
}
.cal-row--past { opacity: .68; }
.cal-date {
  flex: 0 0 64px; display: flex; flex-direction: column; align-items: center; justify-content: center;
  border-right: 1px solid var(--lg-line); padding-right: 16px; line-height: 1.05; text-align: center;
}
.cal-date__mon { font: 700 12px/1 var(--lg-font-sans); text-transform: uppercase; letter-spacing: .05em; color: var(--lg-rust); }
.cal-date__day { font: 700 24px/1.1 var(--lg-font-serif); color: var(--lg-ink); }
.cal-date__yr  { font: 400 12px/1 var(--lg-font-sans); color: var(--lg-mute); }
.cal-body { display: flex; flex-direction: column; gap: 4px; justify-content: center; min-width: 0; }
.cal-body__title { font: 700 17px/1.25 var(--lg-font-serif); }
.cal-body__title a { color: var(--lg-ink); text-decoration: none; }
.cal-body__title a:hover { color: var(--lg-sage-d); text-decoration: underline; }
.cal-body__time { font: 400 13px/1.4 var(--lg-font-sans); color: var(--lg-mute); }
.cal-body__join { font: 600 13px/1.4 var(--lg-font-sans); }
.cal-empty { color: var(--lg-mute); font: 400 16px/1.6 var(--lg-font-sans); }
CSS;

lg_page_open($is_member, 'Calendar', 'Upcoming and past events from The Looth Group community.', 'view-content arc-calendar-page', '', $css);
?>
<h1>Calendar</h1>
<p class="lg-page-sub">Upcoming and past Looth Group events.</p>

<?php if (!$events): ?>
  <p class="cal-empty">No events to show yet.</p>
<?php else: ?>
  <div class="cal-list">
    <?php foreach ($events as $e):
        $start = (int) $e['event_start_at'];
        $url   = (string) ($e['url'] ?? '');
        $title = (string) ($e['title'] ?? 'Event');
        $join  = trim((string) ($e['event_join_url'] ?? ''));
        $past  = $start < $now;
    ?>
    <div class="cal-row<?= $past ? ' cal-row--past' : '' ?>">
      <div class="cal-date">
        <span class="cal-date__mon"><?= h(date('M', $start)) ?></span>
        <span class="cal-date__day"><?= h(date('j', $start)) ?></span>
        <span class="cal-date__yr"><?= h(date('Y', $start)) ?></span>
      </div>
      <div class="cal-body">
        <div class="cal-body__title">
          <?php if ($url !== ''): ?><a href="<?= h($url) ?>"><?= h($title) ?></a><?php else: ?><?= h($title) ?><?php endif; ?>
        </div>
        <div class="cal-body__time"><?= h(date('l, M j, Y · g:i A', $start)) ?></div>
        <?php if ($join !== ''): ?>
          <div class="cal-body__join"><a href="<?= h($join) ?>" target="_blank" rel="noopener">Join link →</a></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
lg_page_close();
