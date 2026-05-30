<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * Connections (friends / follow / requests / blocks). ⚠️ SKELETON — Phase social
 * scaffold; method bodies are stubs. Keyed on looth_id (= users.uuid).
 * Plan: docs/plan-profile-2.0-social-layer.md. Do not wire until approved.
 *
 * friend = symmetric (one row, query both directions). follow = directional.
 * `blocked` is a status, and hard-stops DM eligibility.
 */
final class Connections
{
    public const STATUS = ['pending', 'accepted', 'blocked'];
    public const TYPES  = ['friend', 'follow'];

    /** Edge state between viewer and subject → drives the /u/ button label. */
    // returns one of: 'none'|'pending_out'|'pending_in'|'accepted'|'blocked'
    public static function edgeState(string $viewerUuid, string $subjectUuid): string
    {
        // TODO: SELECT status, requester_uuid FROM connections
        //   WHERE type='friend' AND ((requester=:v AND addressee=:s) OR (requester=:s AND addressee=:v))
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
        // TODO: INSERT pending friend; ON CONFLICT no-op; reject if blocked either way.
        return ['ok' => false, 'todo' => true];
    }

    public static function accept(string $addresseeUuid, string $requesterUuid): array
    {
        // TODO: UPDATE ... SET status='accepted' WHERE pending row addressed to :addressee.
        return ['ok' => false, 'todo' => true];
    }

    public static function decline(string $addresseeUuid, string $requesterUuid): array { return ['ok'=>false,'todo'=>true]; }
    public static function block(string $blockerUuid, string $blockedUuid): array      { return ['ok'=>false,'todo'=>true]; }
    public static function follow(string $followerUuid, string $targetUuid): array     { return ['ok'=>false,'todo'=>true]; }
    public static function unfollow(string $followerUuid, string $targetUuid): array   { return ['ok'=>false,'todo'=>true]; }

    /** Accepted friends, pending-in/out, following — for the friends modal + counts. */
    public static function listFor(string $uuid): array
    {
        // TODO: grouped lists keyed on uuid; join users for name/avatar/slug.
        return ['accepted' => [], 'pending_in' => [], 'pending_out' => [], 'following' => []];
    }

    public static function pendingCount(string $uuid): int
    {
        // TODO: COUNT(*) connections WHERE addressee=:u AND status='pending'.
        return 0;
    }

    /** Can $viewer DM $subject? Knob (Ian): any-member vs connections-only. blocks hard-stop. */
    public static function canMessage(string $viewerUuid, string $subjectUuid): bool
    {
        // TODO: false if blocked either way; else per the who-can-DM rule (default: any member).
        return false;
    }
}
