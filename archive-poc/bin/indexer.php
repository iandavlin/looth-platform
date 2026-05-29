<?php
require __DIR__.'/../config.php';
/**
 * archive-poc/bin/indexer.php — shared per-post normalization.
 *
 * Required by both bin/backfill.php (bulk) and api/v0/_sync.php (single-post,
 * incremental). Assumes WordPress is already booted and a writable PDO handle
 * is provided.
 *
 * Public API:
 *   archive_poc_kind_map(): array
 *   archive_poc_tag_taxonomies(): array
 *   archive_poc_index_post(PDO $db, int $post_id): array{action:string,kind:?string}
 *   archive_poc_delete_post(PDO $db, int $post_id): void
 *   archive_poc_upsert_person(PDO $db, int $user_id): void
 *   archive_poc_extract_v2_text(array|null $blocks): string
 *   archive_poc_clean_text(?string $html): string
 */

if (!function_exists('archive_poc_kind_map')) {
function archive_poc_kind_map(): array {
    // Authoritative kind map. Source of truth: the live ACF manifest at
    // /home/ubuntu/projects/acf-export-2026-05-25.json. Any CPT not listed
    // here is intentionally excluded (defunct or WP/BB internal). `topic`
    // is bbPress, added here because discussions are part of the index.
    return [
        // ACF-managed CPTs (13)
        'post-imgcap'     => 'article',
        'post-type-videos'=> 'video',
        'loothprint'      => 'loothprint',
        'loothcuts'       => 'loothprint',
        'document'        => 'loothprint',
        'event'           => 'event',
        'member-benefit'  => 'benefit',
        'sponsor-product' => 'benefit',
        'sponsor-page'    => 'misc',
        'sponsor-post'    => 'misc',
        'sponsor'         => 'misc',
        'useful_links'    => 'misc',
        'shorty'          => 'misc',
        // bbPress (not ACF)
        'topic'           => 'discussion',
    ];
}

function archive_poc_tag_taxonomies(): array {
    // 'category' is the built-in WP category taxonomy — intentionally excluded
    // (this site doesn't use it). 'post_tag' is the built-in tag taxonomy.
    // Everything else is a CPT-specific tag-style taxonomy.
    return [
        'post_tag','post-tag','topic-tag','hash-tag',
        'video-type','loothprint_type','loothprint-type','useful_link',
        'member-benefit-type','sponsor-post-type',
        'event_type','event-type','shared_category','featured',
        'business-type','language','region','shows',
        'mp2t_knowledge_domains','mp2t_musical_contexts','mp2t_work_on',
    ];
}

function archive_poc_clean_text(?string $html): string {
    if (!$html) return '';
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function archive_poc_extract_v2_text($blocks): string {
    if (!is_array($blocks)) return '';
    $out = [];
    $walk = function ($node) use (&$walk, &$out) {
        if (!is_array($node)) return;
        $type = $node['type'] ?? null;
        if ($type === 'wysiwyg' && isset($node['html']))         $out[] = archive_poc_clean_text($node['html']);
        if ($type === 'callout' && isset($node['body']))         $out[] = archive_poc_clean_text($node['body']);
        if ($type === 'transcript' && isset($node['text']))      $out[] = archive_poc_clean_text($node['text']);
        if ($type === 'post-header') {
            if (!empty($node['title']))   $out[] = $node['title'];
            if (!empty($node['tagline'])) $out[] = $node['tagline'];
        }
        if ($type === 'section-heading' && isset($node['text'])) $out[] = $node['text'];
        if ($type === 'image' && !empty($node['caption']))       $out[] = archive_poc_clean_text($node['caption']);
        if ($type === 'gallery' && !empty($node['image_text']))  $out[] = archive_poc_clean_text($node['image_text']);
        if ($type === 'columns' && !empty($node['columns'])) {
            foreach ($node['columns'] as $col) {
                if (!empty($col['blocks'])) foreach ($col['blocks'] as $b) $walk($b);
            }
        }
        if (!empty($node['blocks']) && is_array($node['blocks'])) {
            foreach ($node['blocks'] as $b) $walk($b);
        }
    };
    foreach ($blocks as $b) $walk($b);
    return trim(implode(' ', array_filter($out)));
}

function archive_poc_first_image_url(?string $html): ?string {
    if (!$html) return null;
    if (preg_match('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $html, $m)) return $m[1];
    return null;
}

/** First BuddyBoss media attachment for a post (topics use `bp_media_ids`
 *  postmeta as a comma-separated list of wp_bp_media row IDs). */
function archive_poc_first_bp_media_thumb(int $post_id): ?string {
    $ids_csv = (string) get_post_meta($post_id, 'bp_media_ids', true);
    if ($ids_csv === '') return null;
    $ids = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $ids_csv)))));
    if (!$ids) return null;
    global $wpdb;
    $ph = implode(',', array_fill(0, count($ids), '%d'));
    // Preserve the order from postmeta so we get the FIRST attachment.
    $sql = "SELECT attachment_id FROM {$wpdb->prefix}bp_media
            WHERE id IN ($ph) ORDER BY FIELD(id, $ph) LIMIT 1";
    $att_id = (int) $wpdb->get_var($wpdb->prepare($sql, ...array_merge($ids, $ids)));
    if (!$att_id) return null;
    return wp_get_attachment_image_url($att_id, 'full') ?: null;
}

function archive_poc_resolve_thumb(int $post_id, string $body_html): ?string {
    $tid = (int) get_post_thumbnail_id($post_id);
    $url = null;
    if ($tid > 0) $url = wp_get_attachment_image_url($tid, 'full') ?: null;
    // BuddyBoss media attachments (topics commonly use these instead of
    // featured images or inline <img> tags).
    if (!$url) $url = archive_poc_first_bp_media_thumb($post_id);
    if (!$url) $url = archive_poc_first_image_url($body_html);
    return $url ?: null;
}

/**
 * Extract event-specific fields from postmeta. Returns ['start','end','region','join_url'],
 * any of which may be null. Handles two CPT shapes:
 *   - ajde_events: evcal_srow / evcal_erow (already unix timestamps)
 *   - event / international-loothi: events_start_date_and_time_ (YYYYMMDD)
 *     + time_of_event ("HH:MM:SS" or "H:MM am/pm") + events_end_date_and_time
 */
function archive_poc_extract_event_fields(int $post_id): array {
    $out = ['start' => null, 'end' => null, 'region' => null, 'join_url' => null];

    // ajde_events shape — direct unix
    $srow = get_post_meta($post_id, 'evcal_srow', true);
    $erow = get_post_meta($post_id, 'evcal_erow', true);
    if (is_numeric($srow) && (int)$srow > 0) $out['start'] = (int) $srow;
    if (is_numeric($erow) && (int)$erow > 0) $out['end']   = (int) $erow;

    // Newer event CPT shape
    if ($out['start'] === null) {
        $sd = (string) get_post_meta($post_id, 'events_start_date_and_time_', true);
        $st = (string) get_post_meta($post_id, 'time_of_event', true);
        if ($sd !== '') {
            // Build "YYYY-MM-DD HH:MM:SS" from "YYYYMMDD" + optional time
            $date = (strlen($sd) === 8) ? substr($sd,0,4).'-'.substr($sd,4,2).'-'.substr($sd,6,2) : $sd;
            $time = $st !== '' ? $st : '00:00:00';
            $ts = strtotime("$date $time UTC");
            if ($ts) $out['start'] = $ts;
        }
    }
    if ($out['end'] === null) {
        $ed = (string) get_post_meta($post_id, 'events_end_date_and_time', true);
        if ($ed !== '') {
            $date = (strlen($ed) === 8) ? substr($ed,0,4).'-'.substr($ed,4,2).'-'.substr($ed,6,2) : $ed;
            $ts = strtotime("$date 23:59:59 UTC");
            if ($ts) $out['end'] = $ts;
        }
        // Fallback: 2-hour default duration if start is known
        if ($out['end'] === null && $out['start'] !== null) $out['end'] = $out['start'] + 7200;
    }

    // Region (term id → term name). get_term() returns WP_Error for some valid
    // legacy term IDs; query wp_terms directly to be robust.
    $rid = get_post_meta($post_id, 'region', true);
    if (is_numeric($rid) && (int)$rid > 0) {
        global $wpdb;
        $name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->terms} WHERE term_id = %d", (int)$rid));
        if ($name) $out['region'] = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // Join URL (Zoom etc.)
    $zoom = (string) get_post_meta($post_id, 'zoom_url_for_looth_group_virtual_event', true);
    if ($zoom !== '') $out['join_url'] = $zoom;

    return $out;
}

function archive_poc_resolve_tier(int $post_id, string $kind): string {
    // Source: the 'tier' WP taxonomy. Query wpdb directly because the taxonomy
    // isn't always registered when --skip-plugins is used (e.g. during bulk
    // reindexing) — wp_get_object_terms() returns WP_Error in that case.
    global $wpdb;
    $slugs = $wpdb->get_col($wpdb->prepare("
        SELECT t.slug FROM {$wpdb->terms} t
        JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
        JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
        WHERE tt.taxonomy = 'tier' AND tr.object_id = %d
    ", $post_id));
    if (!$slugs) return 'public';
    // Most restrictive wins.
    $rank = ['public' => 0, 'looth-lite' => 1, 'lite' => 1, 'looth-pro' => 2, 'pro' => 2];
    $best = 'public'; $best_rank = -1;
    foreach ($slugs as $slug) {
        $r = $rank[$slug] ?? -1;
        if ($r > $best_rank) { $best_rank = $r; $best = $slug; }
    }
    return match ($best) {
        'looth-pro', 'pro'   => 'pro',
        'looth-lite', 'lite' => 'lite',
        default              => 'public',
    };
}

function archive_poc_upsert_person(PDO $db, int $user_id): void {
    $u = get_userdata($user_id);
    if (!$u) return;
    $avatar = get_avatar_url($user_id) ?: null;
    $ins = $db->prepare('INSERT OR REPLACE INTO person(id, display_name, slug, avatar_url) VALUES(?,?,?,?)');
    $ins->execute([$user_id, $u->display_name ?: $u->user_login, $u->user_login, $avatar]);
}

function archive_poc_delete_post(PDO $db, int $post_id): void {
    $db->beginTransaction();
    $db->prepare('DELETE FROM content_tag WHERE content_id = ?')->execute([$post_id]);
    $db->prepare('DELETE FROM content_fts  WHERE rowid       = ?')->execute([$post_id]);
    $db->prepare('DELETE FROM content_item WHERE id          = ?')->execute([$post_id]);
    $db->commit();
}

/**
 * Index a single post by ID. Returns ['action' => upsert|delete|skip, 'kind' => string|null].
 * Idempotent: replaces any existing row + tag relationships for the same id.
 */
function archive_poc_index_post(PDO $db, int $post_id): array {
    global $wpdb;
    $KIND_MAP = archive_poc_kind_map();

    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') {
        archive_poc_delete_post($db, $post_id);
        return ['action' => 'delete', 'kind' => null];
    }

    // Topic replies are folded into the parent topic; reindex parent on reply changes.
    if ($post->post_type === 'reply') {
        $topic_id = (int) get_post_meta($post_id, '_bbp_topic_id', true);
        if ($topic_id > 0) return archive_poc_index_post($db, $topic_id);
        return ['action' => 'skip', 'kind' => null];
    }

    $cpt = $post->post_type;
    $kind = $KIND_MAP[$cpt] ?? null;
    if (!$kind) {
        archive_poc_delete_post($db, $post_id);
        return ['action' => 'delete', 'kind' => null];
    }

    // Author
    $author_id = (int) $post->post_author;
    if ($author_id > 0) archive_poc_upsert_person($db, $author_id);
    $author = $author_id > 0 ? get_userdata($author_id) : null;
    $author_name = $author ? ($author->display_name ?: $author->user_login) : null;

    // Body extraction
    $v2_raw = get_post_meta($post_id, '_lg_layout_v2', true);
    $body_text = '';
    $v2 = null;
    if (is_array($v2_raw)) {
        $v2 = $v2_raw;
    } elseif (is_string($v2_raw) && $v2_raw !== '') {
        $v2 = json_decode($v2_raw, true);
    }
    if (is_array($v2) && !empty($v2['blocks'])) {
        $body_text = archive_poc_extract_v2_text($v2['blocks']);
    }
    if ($body_text === '') {
        $extra = '';
        if ($kind === 'profile') {
            $extra .= ' ' . (string) get_post_meta($post_id, 'short_bio', true);
            $extra .= ' ' . (string) get_post_meta($post_id, 'long_bio', true);
        }
        if ($kind === 'benefit') {
            $extra .= ' ' . (string) get_post_meta($post_id, 'introduction', true);
            $extra .= ' ' . (string) get_post_meta($post_id, 'member_benefits_full_details', true);
        }
        $body_text = archive_poc_clean_text($post->post_content . ' ' . $extra);
    }

    // Excerpt
    $excerpt = trim((string) $post->post_excerpt);
    if ($excerpt === '') {
        $excerpt = mb_substr($body_text, 0, 220);
        if (mb_strlen($body_text) > 220) $excerpt .= '…';
    }

    $thumb = archive_poc_resolve_thumb($post_id, $post->post_content);
    $tier  = archive_poc_resolve_tier($post_id, $kind);
    $url   = get_permalink($post_id) ?: '';

    $last_active = null; $reply_count = 0;
    $forum_label = null; $subforum_label = null;
    if ($kind === 'discussion') {
        $la = get_post_meta($post_id, '_bbp_last_active_time', true);
        $last_active = is_string($la) && $la !== '' ? (strtotime($la) ?: null) : null;
        $reply_count = (int) get_post_meta($post_id, '_bbp_reply_count', true);

        // Forum hierarchy. bbPress: topic.post_parent = immediate forum.
        // If that forum has a non-zero post_parent, it's a sub-forum nested
        // under a top-level forum. We surface both as pills.
        $immediate = (int) $post->post_parent;
        if ($immediate > 0) {
            $imm_parent = (int) (wp_get_post_parent_id($immediate) ?: 0);
            if ($imm_parent > 0) {
                $forum_label    = (string) (get_the_title($imm_parent) ?: '');
                $subforum_label = (string) (get_the_title($immediate)  ?: '');
            } else {
                $forum_label    = (string) (get_the_title($immediate) ?: '');
            }
        }
    }

    $has_download = 0;
    if ($kind === 'loothprint') {
        foreach (['3d_file','file_upload','pdf_url','download_url'] as $k) {
            if (get_post_meta($post_id, $k, true)) { $has_download = 1; break; }
        }
    }

    // Engagement counters (single-row queries).
    $like_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(CASE WHEN status='like' THEN 1 WHEN status='unlike' THEN -1 ELSE 0 END)
         FROM {$wpdb->prefix}ulike WHERE post_id = %d", $post_id));
    if ($like_count < 0) $like_count = 0;
    $view_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}burst_statistics WHERE page_id = %d", $post_id));

    $published_at = strtotime($post->post_date_gmt) ?: time();

    $db->beginTransaction();
    // Replace row
    $db->prepare('DELETE FROM content_tag WHERE content_id = ?')->execute([$post_id]);
    $db->prepare('DELETE FROM content_fts  WHERE rowid       = ?')->execute([$post_id]);
    $db->prepare('DELETE FROM content_item WHERE id          = ?')->execute([$post_id]);

    $event_fields = ($kind === 'event') ? archive_poc_extract_event_fields($post_id) : ['start'=>null,'end'=>null,'region'=>null,'join_url'=>null];

    $db->prepare("
        INSERT INTO content_item
        (id, source, kind, subkind, cpt, title, slug, url, excerpt, body_text,
         thumb_url, thumb_broken, author_id, author_name, tier, published_at,
         last_activity, reply_count, like_count, view_count, duration_min, has_download,
         event_start_at, event_end_at, event_region, event_join_url,
         forum_label, subforum_label)
        VALUES (?, 'wp', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $post_id, $kind, null, $cpt,
        $post->post_title ?: '(untitled)',
        $post->post_name ?: ('p-' . $post_id),
        $url, $excerpt, $body_text,
        $thumb, $author_id ?: null, $author_name,
        $tier, $published_at,
        $last_active, $reply_count,
        $like_count, $view_count,
        null, $has_download,
        $event_fields['start'], $event_fields['end'], $event_fields['region'], $event_fields['join_url'],
        $forum_label, $subforum_label,
    ]);

    // Tags — query wpdb directly. Same reason as tier: wp_get_object_terms
    // returns WP_Error when --skip-plugins is active because the taxonomy
    // isn't registered.
    $tag_labels = [];
    $taxonomies = archive_poc_tag_taxonomies();
    $tax_ph = implode(',', array_fill(0, count($taxonomies), '%s'));
    $terms_rows = $wpdb->get_results($wpdb->prepare("
        SELECT t.term_id, t.slug, t.name
        FROM {$wpdb->term_relationships} tr
        JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
        JOIN {$wpdb->terms} t           ON t.term_id          = tt.term_id
        WHERE tr.object_id = %d AND tt.taxonomy IN ($tax_ph)
    ", array_merge([$post_id], $taxonomies)), ARRAY_A);
    if ($terms_rows) {
        $ins_tag  = $db->prepare('INSERT OR IGNORE INTO tag(id, slug, label) VALUES(?,?,?)');
        $ins_ctag = $db->prepare('INSERT OR IGNORE INTO content_tag(content_id, tag_id) VALUES(?,?)');
        foreach ($terms_rows as $tr) {
            $tid = (int) $tr['term_id'];
            $ins_tag->execute([$tid, $tr['slug'], $tr['name']]);
            $ins_ctag->execute([$post_id, $tid]);
            $tag_labels[] = $tr['name'];
        }
    }

    // FTS row
    $db->prepare("INSERT INTO content_fts(rowid, title, body_text, author_name, tag_text)
                  VALUES (?, ?, ?, ?, ?)")
        ->execute([$post_id, $post->post_title, $body_text, $author_name ?: '', implode(' ', $tag_labels)]);

    $db->commit();

    return ['action' => 'upsert', 'kind' => $kind];
}
}  // end function_exists guard
