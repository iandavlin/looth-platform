<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../api/v0/_reactions.php';   // palette + card store helpers (require_once _comments.php)
/**
 * archive-poc/bin/migrate-bb-reactions.php — wp_bb_user_reactions → discovery.card_reactions.
 *
 * Migrates the REAL legacy BuddyBoss reactions (6.8k rows on live) into the
 * one-table-two-writers discovery reaction store. This is "the real backend" — NOT
 * BuddyBoss AJAX. Runs as looth-dev (peer auth) booting WP READ-ONLY, same pattern
 * as backfill-comments.php (it needs WP to read wp_bb_user_reactions + the bb_reaction
 * CPT palette + the wp_id→uuid bridge).
 *
 *   sudo -u looth-dev php bin/migrate-bb-reactions.php            # dev FIXTURE (LIMIT)
 *   sudo -u looth-dev php bin/migrate-bb-reactions.php --all      # full run (CUTOVER)
 *
 * PALETTE: reaction_id → slug is derived from the bb_reaction CPT's menu_order joined
 * to lg_reactions_palette() (the Ian-approved 7-set; order 0-6 == menu_order 0-6). The
 * 3 custom-image reactions are already vendored WP-free in web/reactions/.
 *
 * TARGET: a legacy reaction hangs on a bp_activity id (item_type='activity'); the card
 * store is keyed (post_type, item_id). The bp_activity→card map is owned + populated by
 * the bb-mirror lane in discovery.bb_activity_target (see sql/bb-activity-target.pg.sql).
 * Reactions on activities with no card row there (pure activity_update/share) are
 * reported + skipped. item_type='activity_comment' (BuddyBoss activity-comment
 * reactions, a different surface than discovery.comments) is OUT OF SCOPE here —
 * counted + skipped, flagged for a separate decision.
 *
 * Idempotent: keyed on card_reactions' actor_key unique; re-running upserts. Source is
 * ordered by date_created ASC so when several activities collapse onto one card for the
 * same actor, the LATEST reaction wins (one reaction per user per card).
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$ALL   = in_array('--all', $argv, true);
$LIMIT = 200;   // dev fixture cap

if (!function_exists('wp_get_current_user')) {
    if (!isset($_SERVER['HTTP_HOST']))   $_SERVER['HTTP_HOST']   = LG_ARCHIVE_POC_HOST;
    if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '/';
    if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
    require LG_ARCHIVE_POC_WP_LOAD;
}
global $wpdb;
$wpdb->suppress_errors(true);

$pdo = lg_comments_pdo();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
    fwrite(STDERR, "requires LG_ARCHIVE_POC_DSN with pgsql driver\n"); exit(1);
}

// --- reaction_id → slug, from the bb_reaction CPT menu_order × the approved palette --
$palette = lg_reactions_palette();          // index 0-6 == menu_order 0-6
$slugByReactionId = [];
$rx = $wpdb->get_results(
    "SELECT ID, menu_order FROM {$wpdb->posts} WHERE post_type='bb_reaction' AND post_status='publish'",
    ARRAY_A) ?: [];
foreach ($rx as $r) {
    $mo = (int) $r['menu_order'];
    if (isset($palette[$mo]['slug'])) $slugByReactionId[(int) $r['ID']] = $palette[$mo]['slug'];
}
fprintf(STDERR, "[migrate-bb-reactions] palette: %d bb_reaction → slug mappings\n", count($slugByReactionId));
if (!$slugByReactionId) { fwrite(STDERR, "no bb_reaction palette found — aborting\n"); exit(1); }

// --- bp_activity → card target map (populated by the bb-mirror lane) -----------------
$targetByActivity = [];
foreach ($pdo->query('SELECT activity_id, post_type, item_id FROM bb_activity_target')
              ->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $targetByActivity[(int) $t['activity_id']] = [(string) $t['post_type'], (int) $t['item_id']];
}
fprintf(STDERR, "[migrate-bb-reactions] target map: %d bp_activity → card rows\n", count($targetByActivity));
if (!$targetByActivity) {
    fwrite(STDERR, "  ! bb_activity_target is EMPTY — the bb-mirror lane must populate it first.\n");
    fwrite(STDERR, "  ! 'activity' reactions cannot be placed without it. Nothing to migrate; exiting 0.\n");
    exit(0);
}

// --- Source reactions (cards only; activity_comment is out of scope) -----------------
$cmtReactions = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM wp_bb_user_reactions WHERE item_type='activity_comment'");
if ($cmtReactions) {
    fprintf(STDERR, "[migrate-bb-reactions] NOTE: %d 'activity_comment' reactions skipped "
        . "(BuddyBoss activity-comment surface ≠ discovery.comments — separate decision)\n", $cmtReactions);
}

$limitSql = $ALL ? '' : ('LIMIT ' . (int) $LIMIT);
$src = $wpdb->get_results(
    "SELECT user_id, reaction_id, item_id AS activity_id, date_created
       FROM wp_bb_user_reactions
      WHERE item_type='activity'
      ORDER BY date_created ASC $limitSql", ARRAY_A) ?: [];
fprintf(STDERR, "[migrate-bb-reactions] %s: %d source 'activity' rows\n", $ALL ? 'FULL' : 'fixture', count($src));
if (!$src) exit(0);

// --- Resolve author uuids in batch ---------------------------------------------------
$wpIds = [];
foreach ($src as $r) $wpIds[(int) $r['user_id']] = true;
$uuidByWp = $wpIds ? lg_comments_uuids_for_wp_ids(array_keys($wpIds)) : [];
fprintf(STDERR, "[migrate-bb-reactions] %d distinct reactors, %d bridged to uuid\n",
        count($wpIds), count($uuidByWp));

// --- Upsert ------------------------------------------------------------------------
$ins = $pdo->prepare(
    "INSERT INTO discovery.card_reactions (post_type, item_id, user_wp_id, user_uuid, slug, created_at)
     VALUES (?,?,?,?::uuid,?,?)
     ON CONFLICT (post_type, item_id, actor_key) DO UPDATE SET
        slug       = EXCLUDED.slug,
        created_at = EXCLUDED.created_at,
        user_wp_id = COALESCE(card_reactions.user_wp_id, EXCLUDED.user_wp_id),
        user_uuid  = COALESCE(card_reactions.user_uuid,  EXCLUDED.user_uuid)");

$migrated = 0; $noSlug = 0; $noTarget = 0;
$pdo->beginTransaction();
foreach ($src as $r) {
    $slug = $slugByReactionId[(int) $r['reaction_id']] ?? null;
    if ($slug === null) { $noSlug++; continue; }
    $tgt  = $targetByActivity[(int) $r['activity_id']] ?? null;
    if ($tgt === null) { $noTarget++; continue; }   // activity has no Hub card (e.g. activity_update)

    $wid     = (int) $r['user_id'];
    $uuid    = $uuidByWp[$wid] ?? null;
    $created = gmdate('Y-m-d H:i:sP', strtotime((string) $r['date_created'] . ' UTC'));
    $ins->execute([$tgt[0], $tgt[1], $wid > 0 ? $wid : null, $uuid, $slug, $created]);
    $migrated++;
}
$pdo->commit();

fprintf(STDERR, "[migrate-bb-reactions] done: %d migrated, %d skipped(no card target), "
    . "%d skipped(unknown reaction_id)\n", $migrated, $noTarget, $noSlug);
