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
    if (!empty($filters['authors']))            $qs['author'] = implode(',', $filters['authors']);
    if (!empty($filters['q']))                  $qs['q']      = $filters['q'];
    $base = LG_BB_MIRROR_PUBLIC_PATH . '/';
    return htmlspecialchars($qs ? $base . '?' . http_build_query($qs) : $base);
}

/** Active Hub filters as a [param => value] map (for preserving them on sort
 *  links + pagination). Reads the rail's stashed filters; empty off the Hub. */
function hub_query_params(): array
{
    $f = $GLOBALS['__bb_hub_rail']['filters'] ?? null;
    if (!is_array($f)) return [];
    $out = [];
    if (!empty($f['types']))   $out['type']   = implode(',', $f['types']);
    if (!empty($f['cats']))    $out['cat']    = implode(',', $f['cats']);
    if (!empty($f['authors'])) $out['author'] = implode(',', $f['authors']);
    if (!empty($f['q']))       $out['q']      = $f['q'];
    return $out;
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

/** A /hub/ URL that flips a sticky mute, preserving current filters + sort. */
function hub_mute_url(array $filters, string $sort, string $facet, string $key): string
{
    $base = hub_url($filters, $sort); // already htmlspecialchars'd
    $sep  = strpos($base, '?') !== false ? '&amp;' : '?';
    return $base . $sep . 'mute_toggle=' . $facet . ':' . urlencode($key);
}

/** One rail row: clickable name (filter) + count + sticky mute switch. */
function hub_rail_row(string $facet, string $key, string $label, int $n, array $filters, array $muted, string $sort): void
{
    $mkey   = $facet === 'type' ? 'types' : 'cats';
    $on     = in_array($key, $filters[$mkey], true);     // filtered-to
    $is_mut = in_array($key, $muted[$mkey], true);       // sticky-muted
    $f_url  = hub_url(hub_toggle($filters, $facet, $key), $sort);
    $m_url  = hub_mute_url($filters, $sort, $facet === 'type' ? 't' : 'c', $key);
    ?>
    <div class="hub-rail__row<?= $on ? ' is-on' : '' ?><?= $is_mut ? ' is-muted' : '' ?>">
      <a class="hub-rail__nm" href="<?= $f_url ?>"><?= htmlspecialchars($label) ?></a>
      <span class="hub-rail__ct"><?= $n ?></span>
      <a class="hub-sw<?= $is_mut ? '' : ' is-on' ?>" href="<?= $m_url ?>"
         title="<?= $is_mut ? 'Unmute' : 'Mute' ?>" aria-label="<?= $is_mut ? 'Unmute ' : 'Mute ' ?><?= htmlspecialchars($label) ?>"></a>
    </div>
    <?php
}

/** The view toggles (Compact / Text size / Theme). Shared so the Hub rail and
 *  the scoped-forum sort bar render identical, JS-bindable markup. */
function hub_render_view_toggles(): void
{
    ?>
    <button class="feed-compact-toggle" type="button" aria-pressed="false"
            title="Toggle compact view" aria-label="Toggle compact view">
      <span class="feed-compact-toggle__icon" aria-hidden="true">&#9636;</span>
      <span class="feed-compact-toggle__label">Compact</span>
    </button>
    <button class="feed-text-toggle" type="button" aria-pressed="false" data-level="0"
            title="Cycle text size" aria-label="Cycle text size">
      <span class="feed-text-toggle__icon" aria-hidden="true">A</span>
      <span class="feed-text-toggle__label">Text size</span>
    </button>
    <button class="feed-theme-toggle" type="button" aria-pressed="false" data-level="0"
            title="Cycle color theme" aria-label="Cycle color theme">
      <span class="feed-theme-toggle__icon" aria-hidden="true">&#9681;</span>
      <span class="feed-theme-toggle__label">Theme</span>
    </button>
    <?php
}

/** Render the control rail into the left-nav slot. */
function hub_render_rail(array $facets, array $filters, array $muted, string $sort = 'new'): void
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
      <h4 class="hub-rail__h">Type <small>· toggle to mute</small></h4>
      <div class="hub-rail__group">
        <?php foreach ($type_order as $key):
          if (!isset($types[$key])) continue;
          hub_rail_row('type', (string)$key, hub_type_label((string)$key), (int)$types[$key], $filters, $muted, $sort);
        endforeach; ?>
      </div>

      <h4 class="hub-rail__h">Categories <small>· toggle to mute</small></h4>
      <div class="hub-rail__group">
        <?php foreach ($cats as $key => $n):
          hub_rail_row('cat', (string)$key, hub_cat_label((string)$key), (int)$n, $filters, $muted, $sort);
        endforeach; ?>
      </div>

      <h4 class="hub-rail__h">View</h4>
      <div class="hub-rail__view"><?php hub_render_view_toggles(); ?></div>
    </div>
    <?php
}

/**
 * Search + author filter for the feed toolbar (moved out of the rail). Two
 * compact GET forms that preserve the current filters/sort so search and
 * author-filter compose with the active facets.
 */
function hub_render_toolbar_search(array $filters, string $sort = 'new'): void
{
    $keep = function (array $skip) use ($filters, $sort): string {
        $h = '';
        if (!in_array('type', $skip, true)   && !empty($filters['types']))   $h .= '<input type="hidden" name="type" value="' . htmlspecialchars(implode(',', $filters['types'])) . '">';
        if (!in_array('cat', $skip, true)    && !empty($filters['cats']))    $h .= '<input type="hidden" name="cat" value="'  . htmlspecialchars(implode(',', $filters['cats']))  . '">';
        if (!in_array('author', $skip, true) && !empty($filters['authors'])) $h .= '<input type="hidden" name="author" value="' . htmlspecialchars(implode(',', $filters['authors'])) . '">';
        if ($sort !== 'new')                                                 $h .= '<input type="hidden" name="sort" value="' . htmlspecialchars($sort) . '">';
        return $h;
    };
    $action = htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/');
    // JS reads these to compose suggest fetches + append-author URLs (graceful
    // no-JS fallback: the q form plain-searches; the author form sets one author).
    ?>
    <div class="feed-toolbar-search" data-hub-suggest-base="<?= $action ?>">
      <form class="hub-tsearch hub-tsearch--q" method="get" action="<?= $action ?>" role="search" autocomplete="off">
        <?= $keep(['author']) ?>
        <span class="hub-tsearch__ico" aria-hidden="true">&#9906;</span>
        <input class="hub-tsearch__in" name="q" type="search" placeholder="Search the Hub…"
               value="<?= htmlspecialchars((string)($_GET['q'] ?? '')) ?>" autocomplete="off"
               aria-label="Search the Hub" data-hub-search>
      </form>
      <form class="hub-tsearch hub-tsearch--author" method="get" action="<?= $action ?>" role="search" autocomplete="off">
        <?= $keep(['author']) ?>
        <span class="hub-tsearch__ico" aria-hidden="true">&#128100;</span>
        <input class="hub-tsearch__in" name="author" type="search" placeholder="Add an author…"
               value="" autocomplete="off" aria-label="Filter by author" data-hub-author>
        <div class="hub-suggest" data-hub-suggest="author" hidden></div>
      </form>
    </div>
    <?php
}

/** Render the active-filter + muted chip bar at the top of the feed. */
function hub_render_chipbar(array $filters, array $muted, string $sort = 'new'): void
{
    // Active (transient) filter chips — removing returns to the unfiltered set.
    $chips = [];
    if (!empty($filters['q'])) {
        $f = $filters; $f['q'] = '';
        $chips[] = ['Search', $filters['q'], hub_url($f, $sort)];
    }
    foreach ($filters['types'] as $v) $chips[] = ['Type', hub_type_label($v), hub_url(hub_toggle($filters, 'type', $v), $sort)];
    foreach ($filters['cats']  as $v) $chips[] = ['In',   hub_cat_label($v),  hub_url(hub_toggle($filters, 'cat',  $v), $sort)];
    foreach ($filters['authors'] as $a) {
        $f = $filters; $f['authors'] = array_values(array_diff($filters['authors'], [$a]));
        $chips[] = ['By', $a, hub_url($f, $sort)];
    }
    // Sticky "Muted" chips — removing un-mutes (distinct styling, always shown).
    $mchips = [];
    foreach ($muted['types'] as $v) $mchips[] = ['Muted', hub_type_label($v), hub_mute_url($filters, $sort, 't', $v)];
    foreach ($muted['cats']  as $v) $mchips[] = ['Muted', hub_cat_label($v),  hub_mute_url($filters, $sort, 'c', $v)];

    if (!$chips && !$mchips) return;
    ?>
    <div class="hub-chipbar">
      <?php if ($chips): ?>
        <span class="hub-chipbar__lab">Filters</span>
        <span class="hub-chipbar__and">AND</span>
        <?php foreach ($chips as [$k, $v, $rm]): ?>
          <span class="hub-chip"><b><?= htmlspecialchars($k) ?></b> <?= htmlspecialchars($v) ?><a class="hub-chip__x" href="<?= $rm ?>" aria-label="Remove filter">&times;</a></span>
        <?php endforeach; ?>
        <a class="hub-chipbar__reset" href="<?= hub_url(['types' => [], 'cats' => [], 'authors' => [], 'q' => ''], $sort) ?>">Reset all</a>
      <?php endif; ?>
      <?php foreach ($mchips as [$k, $v, $rm]): ?>
        <span class="hub-chip hub-chip--muted"><b><?= htmlspecialchars($k) ?></b> <?= htmlspecialchars($v) ?><a class="hub-chip__x" href="<?= $rm ?>" aria-label="Unmute">&times;</a></span>
      <?php endforeach; ?>
    </div>
    <?php
}
