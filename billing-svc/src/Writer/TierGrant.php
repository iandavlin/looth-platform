<?php

declare(strict_types=1);

namespace LGBilling\Writer;

use LGBilling\Arbiter\TierDecision;

/**
 * An entitlement-grant instruction handed to the single TierWriter.
 *
 * design §5b-A: "writing a tier IS granting paid access." Every grant is
 * therefore identified, attributed, and idempotent:
 *   - $eventId   : idempotency key. Re-applying the same event is a no-op
 *                  (replay-safe — a captured+replayed grant must not re-grant
 *                  or extend). Maps to the audit row's event_id and the
 *                  processed-events guard.
 *   - $actor     : who/what initiated it ('arbiter:patreon-poll',
 *                  'arbiter:stripe-webhook', 'admin:<wp_user_id>', ...).
 *   - $source    : the winning source key for provenance ('patreon', 'stripe',
 *                  'manual_admin'), or null when the decision held at the floor.
 *
 * userUuid is the profile-app identity (step-2 target); wpUserId is today's
 * WP id (step-1 target). Both travel together so a single grant object works
 * for either writer across the migration without reshaping.
 */
final class TierGrant
{
    public function __construct(
        public readonly int $wpUserId,
        public readonly ?string $userUuid,
        public readonly ?string $oldTier,
        public readonly ?string $newTier,
        public readonly ?string $source,
        public readonly string $eventId,
        public readonly string $actor,
        public readonly string $ts,
    ) {
    }

    /**
     * Build a grant from an arbitration decision.
     *
     * @param string $eventId deterministic idempotency key (caller-supplied;
     *                         e.g. hash of user+source+tier+poll-cursor — never
     *                         a random value, or replays would each look new).
     */
    public static function fromDecision(
        TierDecision $decision,
        int $wpUserId,
        ?string $userUuid,
        string $eventId,
        string $actor,
        string $ts,
    ): self {
        return new self(
            wpUserId: $wpUserId,
            userUuid: $userUuid,
            oldTier: $decision->oldTier,
            newTier: $decision->winningTier,
            source: self::winningSourceKey($decision),
            eventId: $eventId,
            actor: $actor,
            ts: $ts,
        );
    }

    /** The source key whose tier equals the winner, for provenance. */
    private static function winningSourceKey(TierDecision $decision): ?string
    {
        if ($decision->winningTier === null) {
            return null;
        }
        foreach ($decision->sources as $key => $tier) {
            if ($tier === $decision->winningTier) {
                return (string) $key;
            }
        }
        return null;
    }
}
