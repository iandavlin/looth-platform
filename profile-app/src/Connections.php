<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * Connections (mutual friends / requests / blocks). ⚠️ SKELETON — Phase social
 * scaffold; method bodies are stubs. Keyed on looth_id (= users.uuid).
 * Plan: docs/plan-profile-2.0-social-layer.md. Do not wire until approved.
 *
 * MUTUAL ONLY (Ian, 2026-05-30): connections are symmetric — ONE row per pair,
 * queried both directions. There is NO `follow` feature; if a downstream feature
 * (feed, etc.) ever needs a follow signal it is AUTO-DERIVED from an accepted
 * connection (accepted = mutual follow), never a separate graph or UI.
 * `blocked` is a status, and hard-stops DM eligibility.
 */
final class Connections
{
    public const STATUS = ['pending', 'accepted', 'blocked'];

    /** Edge state between viewer and subject → drives the /u/ button label. */
    // returns one of: 'none'|'pending_out'|'pending_in'|'accepted'|'blocked'
    public static function edgeState(string $viewerUuid, string $subjectUuid): string
    {
        // TODO: SELECT status, requester_uuid FROM connections
        //   WHERE (requester=:v AND addressee=:s) OR (requester=:s AND addressee=:v)
        // map to none/pending_out/pending_in/accepted/blocked.
        return 'none';
    }

    /** A & B are accepted friends (symmetric). Gates contact-reveal + DM-preference. */
    public static function areConnected(string $aUuid, string $bUuid): bool
    {
        // TODO: EXISTS accepted friend row in either direction.
        return false;
    }

    public static function request(string $fromUuid, string $toUuid): array
    {
        // TODO: INSERT pending; ON CONFLICT no-op; reject if an edge exists either
        //   direction (mutual — reversed pair counts) or if blocked either way.
        //   On the NEW edge, Notifications::push(to, 'connection_request', connection_id).
        return ['ok' => false, 'todo' => true];
    }

    public static function accept(string $addresseeUuid, string $requesterUuid): array
    {
        // TODO: UPDATE ... SET status='accepted' WHERE pending row addressed to :addressee.
        //   Then Notifications::push(requester, 'connection_accept', connection_id).
        return ['ok' => false, 'todo' => true];
    }

    public static function decline(string $addresseeUuid, string $requesterUuid): array { return ['ok'=>false,'todo'=>true]; }
    public static function block(string $blockerUuid, string $blockedUuid): array      { return ['ok'=>false,'todo'=>true]; }
    // No follow()/unfollow(): connections are mutual-only; follow is auto-derived if
    // ever needed (accepted connection = mutual follow), never a user action.

    /** Accepted connections + pending-in/out — for the friends modal + counts. */
    public static function listFor(string $uuid): array
    {
        // TODO: grouped lists keyed on uuid; join users for name/avatar/slug.
        return ['accepted' => [], 'pending_in' => [], 'pending_out' => []];
    }

    public static function pendingCount(string $uuid): int
    {
        // TODO: COUNT(*) connections WHERE addressee=:u AND status='pending'.
        return 0;
    }

    /** Can $viewer DM $subject? RULED (Ian, 2026-05-30): CONNECTIONS-ONLY. */
    public static function canMessage(string $viewerUuid, string $subjectUuid): bool
    {
        // TODO: require an ACCEPTED mutual connection (areConnected) AND not blocked
        //   either way. No any-member DM — connect first, then message (mirrors BB).
        return false;
    }
}
