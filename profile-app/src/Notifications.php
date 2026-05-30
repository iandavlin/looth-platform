<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * Notifications — the bell backend. ⚠️ SKELETON — bodies are stubs.
 * profile-app owns the DATA + counts; lg-shell renders the bell + modal, which
 * READ these via api/v0/me-notifications + me-social-counts. Plan: social-layer.
 * Table: sql/2026-05-30-social-layer.sql → `notifications`.
 *
 * RULINGS (Ian, 2026-05-30):
 *  - START FRESH: no BB history port. The crib seeds only CURRENT-UNREAD state at
 *    cut — one row per unread DM thread + one per pending connection request — so
 *    the bell isn't empty. (49,603 BP rows are NOT migrated.)
 *  - 9+ BADGE: the count endpoint returns the TRUE integer; the "9+" cap is a
 *    DISPLAY concern (lg-shell / me-social-counts presentation), not stored here.
 *  - 30-DAY RETENTION: a cron auto-deletes notifications older than 30 days (the
 *    DM/connection itself persists; only the bell alert prunes — keeps the table
 *    lean, unlike BB's unbounded growth). See prune() + bin/prune-notifications
 *    (cron, NOT this turn).
 *
 * Types: 'message' | 'connection_request' | 'connection_accept' (extensible).
 * Dedup is app-layer here (the table has no cross-type unique): collapse to ONE
 * unread row per (user, thread) for messages; one per (user, connection) for
 * request/accept.
 */
final class Notifications
{
    public const TYPES = ['message', 'connection_request', 'connection_accept'];

    /**
     * Raise (or refresh) a notification. Upserts to dedup:
     *  - 'message'                       → one unread row per (user_uuid, thread_id)
     *  - 'connection_request'|'_accept'  → one row per (user_uuid, connection_id)
     * $refId is thread_id for 'message', else connection_id.
     */
    public static function push(string $userUuid, string $type, int $refId, ?string $actorUuid = null): void
    {
        // TODO: map $type → thread_id|connection_id column; INSERT ... ON CONFLICT
        //   (dedup target) DO UPDATE SET is_read=false, created_at=now(), actor_uuid=...
        //   so a re-fire bumps an existing unread row to the top rather than piling up.
    }

    /** Recent-first feed for the modal (joins actor for name/avatar/slug). */
    public static function listFor(string $uuid, int $limit = 30, int $offset = 0): array
    {
        // TODO: SELECT * FROM notifications WHERE user_uuid=:u ORDER BY created_at DESC
        //   LIMIT/OFFSET; hydrate actor + referent (thread peer / connection) for render.
        return [];
    }

    /** True unread count → feeds me-social-counts (display caps at 9+, not here). */
    public static function unreadCount(string $uuid): int
    {
        // TODO: COUNT(*) FROM notifications WHERE user_uuid=:u AND is_read=false.
        return 0;
    }

    /** Mark one notification read (must belong to $viewerUuid). */
    public static function markRead(string $viewerUuid, int $id): void
    {
        // TODO: UPDATE notifications SET is_read=true, read_at=now()
        //   WHERE id=:id AND user_uuid=:v.
    }

    /** Mark all of a user's notifications read (modal "mark all read"). */
    public static function markAllRead(string $viewerUuid): void
    {
        // TODO: UPDATE notifications SET is_read=true, read_at=now()
        //   WHERE user_uuid=:v AND is_read=false.
    }

    /**
     * Retention prune (30-day ruling). Called by cron (bin/prune-notifications),
     * NOT on the request path. Deletes by age regardless of read state; the
     * underlying DM/connection is untouched.
     */
    public static function prune(int $olderThanDays = 30): int
    {
        // TODO: DELETE FROM notifications WHERE created_at < now() - (:days || ' days')::interval;
        //   return affected row count for the cron log.
        return 0;
    }
}
