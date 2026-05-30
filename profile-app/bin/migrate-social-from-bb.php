<?php
declare(strict_types=1);

/**
 * SOCIAL crib — seed connections + messaging from BuddyPress, ONE pass, history
 * preserved. ⚠️ SKELETON, does nothing yet; `--commit` hard-guarded off. Joins
 * the slice-4 crib (orchestrated by bin/migrate-crib-slice4.php). CUT-DAY-REQUIRED.
 * Plan: docs/plan-profile-2.0-social-layer.md §3.
 *
 * RUNS ONLY AFTER sql/2026-05-30-social-layer.sql is reviewed dev-final + applied.
 * Reads BB MySQL (unix socket, LG_PROFILE_APP_MYSQL_DB), maps wp_user_id →
 * users.uuid via wp_user_bridge (same pattern as migrate-socials.php).
 * bp_thread_id / bp_message_id UNIQUE → idempotent re-runs.
 *
 * Usage (once implemented):  php bin/migrate-social-from-bb.php [--commit]
 * Default = DRY RUN: walk + count, assert ≈ snapshot (1,881 msgs / 370 threads /
 * 219 senders; friend-graph count from wp_bp_friends). No writes without --commit.
 */

require __DIR__ . '/../config.php';

$COMMIT = in_array('--commit', $argv, true);

fwrite(STDERR, "migrate-social-from-bb: SKELETON — not implemented. No-op.\n");
fwrite(STDERR, $COMMIT
    ? "  (--commit passed, but the body is a stub; refusing to write.)\n"
    : "  (dry-run; nothing to do yet.)\n");
exit(2);

/* ---- TODO once schema approved -------------------------------------------------
 * CONNECTIONS:
 *   wp_bp_friends (initiator_user_id, friend_user_id, is_confirmed):
 *     bridge both → uuids (skip if either missing);
 *     is_confirmed=1 → status 'accepted', else 'pending' (requester=initiator);
 *     INSERT connections(type='friend') ON CONFLICT DO NOTHING.
 *   wp_bp_follow (leader_id, follower_id) IF the BP-Follow plugin table exists:
 *     INSERT connections(type='follow', requester=follower, addressee=leader, status='accepted').
 *
 * MESSAGING (BP has no threads table — thread_id is implicit):
 *   threads:    SELECT DISTINCT thread_id, MIN(date_sent) subj-src, MAX(date_sent) last
 *               FROM wp_bp_messages_messages → message_threads(bp_thread_id, subject, last_message_at).
 *   messages:   wp_bp_messages_messages (sender_id, thread_id, subject, message, date_sent)
 *               → messages(thread_id=lookup(bp_thread_id), sender_uuid=bridge(sender_id),
 *                          body, created_at, bp_message_id=id).
 *   recipients: wp_bp_messages_recipients (user_id, thread_id, unread_count, sender_only, is_deleted)
 *               → message_recipients(thread_id=lookup, user_uuid=bridge(user_id),
 *                          unread_count, is_deleted). Skip non-bridged.
 *
 * REPORT: counts vs snapshot; spot-check one known thread end-to-end; per-user
 *   pending/accepted tallies. Only --commit writes; wrap per-thread in a txn.
 * --------------------------------------------------------------------------------- */
