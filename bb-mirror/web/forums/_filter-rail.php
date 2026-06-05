<?php
declare(strict_types=1);
/**
 * Hub control sidebar — the rail UI + active-filter chip bar (Option A: the
 * rail REPLACES the forum left-nav on the site-wide /hub/ feed).
 *
 * Increment 1: search box + Type/Category facets (counts + click-to-filter) +
 * Author filter input + chip bar (AND badge + Reset). Plain-link toggling — each
 * facet name is an <a> that adds/removes itself from the ?type=/?cat= CSV and
 * round-trips to the server, so it works with zero JS and keeps pagination
 * correct. Sticky mute toggles = increment 2; author type-ahead + profile-app
 * author header = increment 3.
 *
 * Depends on _hub-filters.php (labels + parse shape).
 */

require_once __DIR__ . '/_hub-filters.php';

/** Build a /hub/ URL from a filter selection + sort. */
function hub_url(array $filters, string $sort = 'new'): string
{
    $qs = [];
    if ($sort !== '' && $sort !== 'new')        $qs['sort']   = $sort;
    if (!empty($filters['types']))              $qs['type']   = implode(',', $filters['types']);
    if (!empty($filters['cats']))               $qs['cat']    = implode(',', $filters['cats']);
    if (!empty($filters['author']))             $qs['author'] = $filters['author'];
    $base = LG_BB_MIRROR_PUBLIC_PATH . '/';
    return htmlspecialchars($qs ? $base . '?' . http_build_query($qs) : $base);
}

/** Return $filters with $val toggled inside the 'types' or 'cats' list. */
function hub_toggle(array $filters, string $facet, string $val): array
{
    $key = $facet === 'type' ? 'types' : 'cats';
    $set = $filters[$key];
    $i   = array_search($val, $set, true);
    if ($i === false) $set[] = $val; else array_splice($set, $i, 1);
    $filters[$key] = array_values($set);
    return $filters;
}

/** Render the control rail into the left-nav slot. */
function hub_render_rail(array $facets, array $filters, string $sort = 'new'): void
{
    $types = $facets['types'] ?? [];
    $cats  = $facets['cats']  ?? [];

    // Order Type facet: known labels first (by definition order), then any extras
    // by count desc. "Discussions" leads.
    $type_order = array_keys(HUB_TYPE_LABELS);
    foreach (array_keys($types) as $k) if (!in_array($k, $type_order, true)) $type_order[] = $k;

    // Category facet order: by count desc.
    arsort($cats);
    ?>
    <div class="hub-rail">
      <form class="search-form search-form--sidebar" method="get" action="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/') ?>">
        <label class="search-form__label" for="q">Search the Hub</label>
        <input class="search-form__input" id="q" name="q" type="search"
               placeholder="Search the Hub…" value="<?= htmlspecialchars((string)($_GET['q'] ?? '')) ?>" autocomplete="off">
        <button class="search-form__btn" type="submit" aria-label="Search">&#9906;</button>
      </form>

      <h4 class="hub-rail__h">Type</h4>
      <div class="hub-rail__group">
        <?php foreach ($type_order as $key):
          if (!isset($types[$key])) continue;
          $on  = in_array($key, $filters['types'], true);
          $url = hub_url(hub_toggle($filters, 'type', $key), $sort); ?>
          <a class="hub-rail__row<?= $on ? ' is-on' : '' ?>" href="<?= $url ?>">
            <span class="hub-rail__nm"><?= htmlspecialchars(hub_type_label($key)) ?></span>
            <span class="hub-rail__ct"><?= (int)$types[$key] ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <h4 class="hub-rail__h">Categories</h4>
      <div class="hub-rail__group">
        <?php foreach ($cats as $key => $n):
          $on  = in_array($key, $filters['cats'], true);
          $url = hub_url(hub_toggle($filters, 'cat', $key), $sort); ?>
          <a class="hub-rail__row<?= $on ? ' is-on' : '' ?>" href="<?= $url ?>">
            <span class="hub-rail__nm"><?= htmlspecialchars(hub_cat_label((string)$key)) ?></span>
            <span class="hub-rail__ct"><?= (int)$n ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <h4 class="hub-rail__h">Authors</h4>
      <form class="search-form search-form--sidebar" method="get" action="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/') ?>">
        <?php // preserve current type/cat/sort when submitting an author filter
        if (!empty($filters['types'])) echo '<input type="hidden" name="type" value="' . htmlspecialchars(implode(',', $filters['types'])) . '">';
        if (!empty($filters['cats']))  echo '<input type="hidden" name="cat"  value="' . htmlspecialchars(implode(',', $filters['cats'])) . '">';
        if ($sort !== 'new')           echo '<input type="hidden" name="sort" value="' . htmlspecialchars($sort) . '">'; ?>
        <input class="search-form__input" name="author" type="search"
               placeholder="Filter by author…" value="<?= htmlspecialchars((string)($filters['author'] ?? '')) ?>" autocomplete="off">
      </form>
    </div>
    <?php
}

/** Render the active-filter chip bar at the top of the feed. */
function hub_render_chipbar(array $filters, string $sort = 'new'): void
{
    $chips = [];
    foreach ($filters['types'] as $v) $chips[] = ['Type', hub_type_label($v), hub_url(hub_toggle($filters, 'type', $v), $sort)];
    foreach ($filters['cats']  as $v) $chips[] = ['In',   hub_cat_label($v),  hub_url(hub_toggle($filters, 'cat',  $v), $sort)];
    if (!empty($filters['author'])) {
        $f = $filters; $f['author'] = '';
        $chips[] = ['By', $filters['author'], hub_url($f, $sort)];
    }
    if (!$chips) return;
    ?>
    <div class="hub-chipbar">
      <span class="hub-chipbar__lab">Filters</span>
      <span class="hub-chipbar__and">AND</span>
      <?php foreach ($chips as [$k, $v, $rm]): ?>
        <span class="hub-chip"><b><?= htmlspecialchars($k) ?></b> <?= htmlspecialchars($v) ?><a class="hub-chip__x" href="<?= $rm ?>" aria-label="Remove filter">&times;</a></span>
      <?php endforeach; ?>
      <a class="hub-chipbar__reset" href="<?= hub_url(['types' => [], 'cats' => [], 'author' => ''], $sort) ?>">Reset all</a>
    </div>
    <?php
}
