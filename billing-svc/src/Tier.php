<?php

declare(strict_types=1);

namespace LGBilling;

/**
 * Tier vocabulary — the looth1..4 role names, in ascending entitlement order.
 *
 * This MUST stay byte-identical to the WP plugin's notion of tiers
 * (lg-patreon-stripe-poller/src/Arbiter.php::TIER_ROLES) so the relocated
 * Arbiter computes the same winner. The ascending order is also what the
 * Arbiter's strcmp()-based "highest wins" comparison depends on:
 *
 *     'looth1' < 'looth2' < 'looth3' < 'looth4'   (lexicographic == ordinal here)
 *
 * Public-vocabulary mapping (STRANGLER-COORDINATION.md §1) lives with the
 * tier-serving endpoint, not here — this class is only the grant-side roles.
 *
 *   looth1 = public (starter, free — granted on signup, never payment-backed)
 *   looth2 = lite
 *   looth3 = pro
 *   looth4 = pro (comp / admin-only; Arbiter NEVER source-drives it)
 */
final class Tier
{
    public const LOOTH1 = 'looth1';
    public const LOOTH2 = 'looth2';
    public const LOOTH3 = 'looth3';
    public const LOOTH4 = 'looth4';

    /** Ascending entitlement order — identity with the WP Arbiter's TIER_ROLES. */
    public const ROLES = [ self::LOOTH1, self::LOOTH2, self::LOOTH3, self::LOOTH4 ];

    /**
     * The free/starter floor. Fail-closed default: every ambiguous or
     * least-privilege resolution lands here (or null, which the writer
     * treats as "leave the user at this floor"). NEVER a paid tier.
     */
    public const FLOOR = self::LOOTH1;

    /** Paid tiers — the ones a forged grant would be valuable to fake. */
    public const PAID = [ self::LOOTH2, self::LOOTH3, self::LOOTH4 ];

    public static function isTierRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    public static function isPaid(?string $tier): bool
    {
        return $tier !== null && in_array($tier, self::PAID, true);
    }
}
