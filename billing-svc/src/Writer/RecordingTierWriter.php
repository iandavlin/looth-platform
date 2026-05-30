<?php

declare(strict_types=1);

namespace LGBilling\Writer;

use LGBilling\Audit\AuditLog;
use LGBilling\Tier;

/**
 * In-memory TierWriter that fully implements the single-writer contract —
 * idempotency, audit, and fail-closed convergence — WITHOUT touching any live
 * store. It is the reference implementation the pg-backed writers
 * (WpRoleTierWriter step-1, MemberTierWriter step-2) follow, and the writer
 * the unit tests drive.
 *
 * Convergence rule (fail-closed, §5b-C): a null newTier is NOT "leave as-is" —
 * it means "hold at the floor", so the recorded effective tier becomes
 * Tier::FLOOR (looth1) and any paid tier is dropped. Absence of a winning
 * source never preserves a paid tier.
 *
 * This class deliberately has NO database, NO Stripe, NO WP. It proves the
 * seam's behaviour in isolation; the real writers add only the store mutation.
 */
final class RecordingTierWriter implements TierWriter
{
    /** @var array<int,string> wpUserId => effective tier currently held. */
    private array $effective = [];

    public function __construct(private readonly AuditLog $audit)
    {
    }

    public function apply(TierGrant $grant): TierGrantResult
    {
        // Replay-safe: a previously-applied event is a no-op. We still record
        // the replay attempt so captured-and-replayed grants are visible.
        if ($this->audit->alreadyApplied($grant->eventId)) {
            $this->audit->record($grant, false, 'duplicate-event');
            return TierGrantResult::duplicate();
        }

        // Fail-closed convergence: null winner -> floor, never preserved-paid.
        $target = $grant->newTier ?? Tier::FLOOR;

        // Defensive: a writer must never elevate to a tier the grant didn't
        // name. (The Arbiter already guarantees validity; this is belt-and-
        // braces at the single-writer chokepoint.)
        if (!Tier::isTierRole($target)) {
            $this->audit->record($grant, false, 'invalid-target-refused');
            return new TierGrantResult(false, 'invalid-target-refused');
        }

        $current = $this->effective[$grant->wpUserId] ?? null;
        if ($current === $target) {
            $this->audit->record($grant, false, 'no-op');
            return TierGrantResult::noop();
        }

        $this->effective[$grant->wpUserId] = $target;
        $auditId = $this->audit->record($grant, true, 'applied');
        return TierGrantResult::applied($auditId);
    }

    /** Test/inspection accessor — the effective tier this writer holds. */
    public function effectiveTier(int $wpUserId): ?string
    {
        return $this->effective[$wpUserId] ?? null;
    }
}
