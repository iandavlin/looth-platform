<?php

declare(strict_types=1);

namespace LGBilling\Arbiter;

/**
 * Immutable result of Arbiter::decide() — the WP-ism-free arbitration verdict.
 *
 * This is the SEAM between "decide who gets what tier" (this service, pure,
 * testable) and "write the tier somewhere" (the single TierWriter). The
 * decision carries no side effects and never touches a store; the writer is
 * the only thing that grants.
 *
 * `apply === false`  -> skip entirely; do NOT touch the user (mirrors the WP
 *                       Arbiter's early `return` guards: no such user, looth4
 *                       protected, stripe-source-without-row).
 * `apply === true`   -> the writer should converge the user onto
 *                       `winningTier`. A null `winningTier` here means
 *                       "no source rows -> hold at the floor (looth1), strip
 *                       any paid roles" — fail-closed, NEVER a paid tier.
 */
final class TierDecision
{
    /**
     * @param array<string,?string> $sources  source => tier (post-merge), for audit/provenance.
     */
    public function __construct(
        public readonly bool $apply,
        public readonly ?string $winningTier,
        public readonly ?string $oldTier,
        public readonly bool $isUpgradeToPaid,
        public readonly string $reason,
        public readonly array $sources = [],
    ) {
    }

    public static function skip(string $reason): self
    {
        return new self(false, null, null, false, $reason, []);
    }

    /** True when this decision changes the user's tier (drives the purge/event). */
    public function isTransition(): bool
    {
        return $this->apply && $this->oldTier !== $this->winningTier;
    }
}
