<?php
/**
 * archive-poc/web/sponsors.php — /sponsors/ ("Our Sponsors") standalone page.
 *
 * Renders the sponsor listing from the indexed sponsor-page CPT (direct sqlite
 * read via lg_archive_poc_pdo(), no WP boot), each linking to its standalone
 * /sponsor-page/<slug>/ surface. thumb_url is used as the logo when the index
 * has one; otherwise the card falls back to the sponsor name.
 */
declare(strict_types=1);
require __DIR__ . '/_page-shell.php';
[$is_member, $tier] = lg_page_boot();

$sponsors = [];
try {
    $pdo  = lg_archive_poc_pdo();
    $stmt = $pdo->query(
        "SELECT title, url, thumb_url FROM content_item WHERE cpt='sponsor-page' ORDER BY title COLLATE NOCASE"
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
        $url   = (string) ($s['url'] ?? '');
        $name  = (string) ($s['title'] ?? 'Sponsor');
        $thumb = trim((string) ($s['thumb_url'] ?? ''));
        if ($url === '') continue;
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
