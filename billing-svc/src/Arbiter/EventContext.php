<?php

declare(strict_types=1);

namespace LGBilling\Arbiter;

/**
 * Per-run identity for a grant: the idempotency key, who triggered it, and
 * when. Supplied by the caller (poll tick, webhook, admin action) rather than
 * generated internally, because the event id MUST be deterministic for the
 * same underlying event — a random id would make every replay look new and
 * defeat the §5b-A replay guard.
 *
 * Convention for eventId: a stable hash of (user, source, tier, source-cursor),
 * NOT a timestamp or random value. Two polls that observe the same Patreon
 * state must produce the same eventId so the second is a no-op.
 */
final class EventContext
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $actor,
        public readonly string $ts,
    ) {
    }
}
