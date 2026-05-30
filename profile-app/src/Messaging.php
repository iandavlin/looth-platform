<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * Messaging — thin, async member↔member DMs. ⚠️ SKELETON — bodies are stubs.
 * threads / messages / recipients on postgres; NOT realtime. Identity via
 * /whoami (uuid). Every write asserts the actor is a thread participant.
 * Plan: docs/plan-profile-2.0-social-layer.md.
 */
final class Messaging
{
    /** Thread list for the messages modal: peers, last snippet, unread, last_message_at. */
    public static function threadsFor(string $uuid, int $limit = 30, int $offset = 0): array
    {
        // TODO: JOIN message_recipients (user=:u, not deleted) → threads ORDER BY last_message_at DESC.
        return [];
    }

    /** One thread's messages (paginated). Marks read for $viewerUuid as a side effect. */
    public static function thread(string $viewerUuid, int $threadId, int $limit = 50): array
    {
        // TODO: assert viewer is a recipient; SELECT messages; then markRead().
        return ['thread' => null, 'messages' => []];
    }

    /** Send. Creates a thread if $threadId null (DM to $toUuid). Returns the message. */
    public static function send(string $senderUuid, ?int $threadId, ?string $toUuid, string $body): array
    {
        // GATE (Ian, 2026-05-30): messaging is CONNECTIONS-ONLY.
        //   - NEW thread (to $toUuid): require Connections::canMessage($sender,$to)
        //     === an ACCEPTED mutual connection, not blocked. No any-member DM.
        //   - EXISTING thread: assert $sender is a recipient; still reject if the peer
        //     has since blocked (canMessage) — connect first, then message.
        // TODO: validate per above; INSERT message; bump threads.last_message_at;
        //       recipients.unread_count++ for others; Notifications::push(peer,'message',thread_id).
        return ['ok' => false, 'todo' => true];
    }

    public static function markRead(string $viewerUuid, int $threadId): void
    {
        // TODO: recipients SET unread_count=0, last_read_at=now() WHERE thread=:t AND user=:v.
    }

    /** Total unread across threads → header messages badge. */
    public static function unreadCount(string $uuid): int
    {
        // TODO: SUM(unread_count) FROM message_recipients WHERE user=:u AND NOT is_deleted.
        return 0;
    }
}
