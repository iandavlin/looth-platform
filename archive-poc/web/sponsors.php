<?php
/**
 * archive-poc/web/sponsors.php — /sponsors/ ("Our Sponsors") standalone page.
 *
 * Renders the sponsor listing from the indexed sponsor-page CPT (direct sqlite
 * read via lg_archive_poc_pdo(), no WP boot), each linking to its standalone
 * /sponsors/<slug>/ surface (the v2 sponsor page). The indexed `url` still
 * carries the old /sponsor-page/<slug>/ permalink, so we rederive the link from
 * the slug rather than trust the stale column. thumb_url is used as the logo
 * when the index has one; otherwise the card falls back to the sponsor name.
 *
 * TODO (logos): the index has no thumb_url for sponsors yet. Cleanest fill is to
 * backfill content_item.thumb_url from the brand store (/profile-api/v0/sponsor
 * logo_url) at INDEX time — not a per-request HTTP fetch from this public page.
 */
declare(strict_types=1);
require __DIR__ . '/_page-shell.php';
[$is_member, $tier] = lg_page_boot();

$sponsors = [];
try {
    $pdo  = lg_archive_poc_pdo();
    // Case-insensitive title sort: SQLite COLLATE NOCASE has no PG equivalent.
    $ci_order = lg_archive_poc_is_pg($pdo) ? 'lower(title)' : 'title COLLATE NOCASE';
    $stmt = $pdo->query(
        "SELECT title, url, thumb_url FROM content_item WHERE cpt='sponsor-page' ORDER BY $ci_order"
    );
    $sponsors = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('sponsors.php: ' . $e->getMessage());
}

$css = <<<'CSS'
.sponsor-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; margin-top: 8px; }
.sponsor-card {
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px;
  min-height: 130px; padding: 22px 18px; text-align: center; text-decoration: none;
  background: var(--lg-card-bg); border: 1px solid var(--lg-line); border-radius: 12px;
  color: var(--lg-ink); transition: transform .12s ease, box-shadow .12s ease;
}
.sponsor-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,.08); }
.sponsor-card__logo { max-width: 100%; max-height: 64px; object-fit: contain; }
.sponsor-card__name { font: 700 17px/1.25 var(--lg-font-serif); }
.sponsors-empty { color: var(--lg-mute); font: 400 16px/1.6 var(--lg-font-sans); }
CSS;

lg_page_open($is_member, 'Our Sponsors', 'The sponsors who support The Looth Group community.', 'view-content arc-sponsors-page', '', $css);
?>
<h1>Our Sponsors</h1>
<p class="lg-page-sub">The companies that support the Looth Group community.</p>

<?php if (!$sponsors): ?>
  <p class="sponsors-empty">Sponsor listings are coming soon.</p>
<?php else: ?>
  <div class="sponsor-grid">
    <?php foreach ($sponsors as $s):
        $name  = (string) ($s['title'] ?? 'Sponsor');
        $thumb = trim((string) ($s['thumb_url'] ?? ''));
        /* Link to the new /sponsors/<slug>/ surface. Derive the slug from the
           indexed permalink's last path segment so we don't depend on the index
           being re-pointed off the old /sponsor-page/ url. */
        $slug = trim((string) parse_url((string) ($s['url'] ?? ''), PHP_URL_PATH), '/');
        $slug = ($p = strrpos($slug, '/')) !== false ? substr($slug, $p + 1) : $slug;
        if ($slug === '') continue;
        $url = '/sponsors/' . rawurlencode($slug) . '/';
    ?>
    <a class="sponsor-card" href="<?= h($url) ?>">
      <?php if ($thumb !== ''): ?>
        <img class="sponsor-card__logo" src="<?= h($thumb) ?>" alt="<?= h($name) ?>" loading="lazy">
      <?php else: ?>
        <span class="sponsor-card__name"><?= h($name) ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
lg_page_close();
