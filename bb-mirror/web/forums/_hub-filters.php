<?php
declare(strict_types=1);
/**
 * Hub control-sidebar — filter parsing, facet counts, and the server-side
 * AND filter applied to the unified feed UNION. Site-wide /hub/ only.
 *
 * Model (hub-filter-nav-spec.md): AND across Type ∩ Category ∩ Author.
 *  - Type:     content kinds + "discussions" (forum topics). Multi = OR within.
 *  - Category: forum categories (repair/builds/…). Content carries NO category
 *              (content_item.forum_label is empty), so a Category filter narrows
 *              to forum threads only — content drops out under strict AND.
 *  - Author:   single author, matched by NAME (topic person ids and content WP
 *              user ids are different id spaces; the profile-app user_uuid
 *              unification is a later cross-lane increment).
 *
 * Filtering runs in SQL on the union's outer WHERE so pagination stays correct
 * (we never render-then-hide). Facet counts are computed over the tier-gated
 * unified set, independent of the current selection (matches the mockup).
 */

// Content-kind -> display label for the Type facet. Unlisted kinds title-case.
const HUB_TYPE_LABELS = [
    'discussions'  => 'Discussions',
    'video'        => 'Videos',
    'article'      => 'Articles',
    'loothprint'   => 'Loothprints',
    'event'        => 'Events',
    'sponsor-post' => 'Sponsors',
    'useful_links' => 'Useful Links',
    'shorty'       => 'Shorts',
    'benefit'      => 'Benefits',
    'loothcuts'    => 'Loothcuts',
    'document'     => 'Documents',
    'misc'         => 'Misc',
];

// Category key -> display label (keys produced by bb_mirror_cat_key()).
const HUB_CAT_LABELS = [
    'repair'      => 'Repair & Restoration',
    'builds'      => 'New Builds',
    'acoustic'    => 'Acoustic',
    'tools'       => 'Tools, Spaces & Robots',
    'business'    => 'Business',
    'market'      => 'Market Place',
    'sponsors'    => 'Sponsor Forums',
    'looths'      => 'Local Looths',
    'suggestions' => 'Suggestions',
    'general'     => 'General',
];

function hub_type_label(string $key): string
{
    return HUB_TYPE_LABELS[$key] ?? ucwords(str_replace(['_', '-'], ' ', $key));
}
function hub_cat_label(string $key): string
{
    return HUB_CAT_LABELS[$key] ?? ucfirst($key);
}

/** Parse the active filter selection from the request. */
function hub_filters_parse(): array
{
    $csv = function (string $k): array {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string)($_GET[$k] ?? ''))
        ), fn($s) => $s !== ''));
    };
    return [
        'types'  => $csv('type'),                       // e.g. ['video','discussions']
        'cats'   => $csv('cat'),                         // e.g. ['repair','builds']
        'author' => trim((string)($_GET['author'] ?? '')), // single, by name
    ];
}

/**
 * Facet counts over the tier-gated unified set.
 * Returns ['types' => [key=>count, …, 'discussions'=>N], 'cats' => [catkey=>count]].
 * $forum_cat_map: forum_id => cat_key (from bb_mirror_build_cat_map()).
 */
function hub_facet_counts(PDO $db, array $content_tiers, array $forum_cat_map): array
{
    // -- Type counts: content kinds + Discussions (forum topics) --
    $tier_ph = [];
    foreach ($content_tiers as $i => $t) $tier_ph[] = ':ft' . $i;
    $tin = $tier_ph ? implode(',', $tier_ph) : "''";

    $tc = $db->prepare("SELECT kind, count(*) AS n FROM discovery.content_item
                         WHERE tier IN ($tin) GROUP BY kind");
    foreach ($content_tiers as $i => $t) $tc->bindValue(':ft' . $i, $t);
    $tc->execute();
    $types = [];
    foreach ($tc->fetchAll() as $r) $types[(string)$r['kind']] = (int)$r['n'];

    $disc = (int)$db->query("
        SELECT count(*) FROM topic t JOIN forum f ON f.id = t.forum_id
         WHERE t.status='publish' AND f.visibility='public' AND t.forum_id NOT IN (3876)
    ")->fetchColumn();
    $types['discussions'] = $disc;

    // -- Category counts: forum topics folded by cat_key (PHP-derived taxonomy) --
    $cats = [];
    $rows = $db->query("
        SELECT t.forum_id, count(*) AS n
          FROM topic t JOIN forum f ON f.id = t.forum_id
         WHERE t.status='publish' AND f.visibility='public' AND t.forum_id NOT IN (3876)
         GROUP BY t.forum_id
    ")->fetchAll();
    foreach ($rows as $r) {
        $key = $forum_cat_map[(int)$r['forum_id']] ?? 'general';
        $cats[$key] = ($cats[$key] ?? 0) + (int)$r['n'];
    }

    return ['types' => $types, 'cats' => $cats];
}

/** cat_key => [forum_id, …] inverted from the forum cat-map. */
function hub_cat_forum_ids(array $forum_cat_map): array
{
    $out = [];
    foreach ($forum_cat_map as $fid => $key) $out[$key][] = (int)$fid;
    return $out;
}

/**
 * Build the server-side AND filter for the union's outer WHERE.
 * Returns [sql_fragment, named_binds]. sql_fragment is '' when no filter is set.
 * Operates on the union's output columns: card_type, content_kind, forum_id,
 * author_name.
 */
function hub_filter_where(array $filters, array $forum_cat_map, ?int &$content_visible = null): array
{
    $and   = [];
    $binds = [];
    $content_visible = 1; // does the current filter permit content rows at all?

    // -- Type: OR within (discussions => topics; kinds => content) --
    if ($filters['types']) {
        $or = [];
        $kinds = [];
        $want_disc = false;
        foreach ($filters['types'] as $t) {
            if ($t === 'discussions') { $want_disc = true; continue; }
            $kinds[] = $t;
        }
        if ($want_disc) $or[] = "u.card_type = 'topic'";
        if ($kinds) {
            $ph = [];
            foreach ($kinds as $i => $k) { $ph[] = ":hk$i"; $binds[":hk$i"] = $k; }
            $or[] = "(u.card_type = 'content' AND u.content_kind IN (" . implode(',', $ph) . "))";
        }
        $and[] = $or ? '(' . implode(' OR ', $or) . ')' : '1=0';
        // content shown only if at least one content kind was chosen
        if (!$kinds) $content_visible = 0;
    }

    // -- Category: forum threads only (content has no category) --
    if ($filters['cats']) {
        $cat_forums = hub_cat_forum_ids($forum_cat_map);
        $ids = [];
        foreach ($filters['cats'] as $c) {
            foreach ($cat_forums[$c] ?? [] as $fid) $ids[] = (int)$fid;
        }
        $ids = array_values(array_unique($ids));
        // forum ids are trusted ints from the cat-map -> safe to inline.
        $and[] = $ids
            ? "(u.card_type = 'topic' AND u.forum_id IN (" . implode(',', $ids) . "))"
            : '1=0';
        $content_visible = 0; // a category filter excludes content entirely
    }

    // -- Author: single, by name (across both sources) --
    if ($filters['author'] !== '') {
        $and[] = 'u.author_name = :hauthor';
        $binds[':hauthor'] = $filters['author'];
    }

    return [$and ? 'WHERE ' . implode(' AND ', $and) : '', $binds];
}
