<?php

declare(strict_types=1);

namespace LGBilling\Arbiter;

use LGBilling\Tier;

/**
 * Framework-free port of lg-patreon-stripe-poller's LGMS\Arbiter.
 *
 * The WP Arbiter::sync() did two jobs welded together:
 *   (1) DECIDE the winning tier from source rows (pure logic), and
 *   (2) APPLY it via WP side effects (add_role/remove_role, bp_set_member_type,
 *       welcome-meta stamp, WelcomeMailer, the looth_tier_changed action).
 *
 * This port keeps (1) here as pure, WP-free, fully-testable code and pushes
 * (2) across the TierWriter seam (the single-writer grant path). decide()
 * takes already-read inputs (roles, payment_source, merged source map) and
 * returns a TierDecision — it reads nothing and writes nothing.
 *
 * The three private helpers (computeWinningTier / currentTier / isUpgradeToPaid)
 * are copied byte-for-byte in behaviour from the WP Arbiter so a parity test
 * can assert identical winners for identical source maps.
 *
 * ── FAIL-CLOSED CONTRACT (design §5b-C, the security keystone) ──────────────
 * The winning tier defaults to looth1/public (or null = "hold at floor") on
 * null / empty / ambiguous / unparseable sources. It NEVER resolves to a paid
 * tier from absence-of-data. computeWinningTier() is the chokepoint that
 * guarantees this; ArbiterTest asserts it FIRST, before any happy path.
 */
final class Arbiter
{
    /** Identity with WP Arbiter::TIER_ROLES. */
    private const TIER_ROLES = Tier::ROLES;

    /**
     * Decide the tier for a user from already-read inputs. Pure: no I/O.
     *
     * Faithfully reproduces the WP Arbiter::sync() guard ladder and winner
     * computation, minus the WP write side effects (those move to TierWriter).
     *
     * @param list<string>|null     $roles           WP roles, or null = no such user.
     * @param string|null           $paymentSource   user_meta 'payment_source', or null.
     * @param array<string,?string> $sources         merged source => tier map
     *                                                (RoleSourceWriter::readAllForUser equivalent).
     */
    public static function decide(?array $roles, ?string $paymentSource, array $sources): TierDecision
    {
        // WP: get_user_by('id') falsy -> { ok:false, reason:'no such WP user' }.
        if ($roles === null) {
            return TierDecision::skip('no such WP user');
        }

        // WP: looth4 protected — never touched.
        if (in_array(Tier::LOOTH4, $roles, true)) {
            return TierDecision::skip('looth4 protected, skipped');
        }

        // WP: Stripe-source coexistence guard. A user with payment_source=stripe
        // and NO looth1 role owns their role via the Stripe pipeline; if they
        // have no current stripe source row, computing from empty sources would
        // silently downgrade them. Skip instead. (Faithful to the WP
        // array_intersect($roles, ['looth1']) emptiness check.)
        if ($paymentSource === 'stripe'
            && empty(array_intersect($roles, [ Tier::LOOTH1 ]))) {
            return TierDecision::skip('stripe-source w/o source row, skipped');
        }

        $oldTier = self::currentTier($roles);
        $winning = self::computeWinningTier($sources);

        return new TierDecision(
            apply: true,
            winningTier: $winning,
            oldTier: $oldTier,
            isUpgradeToPaid: self::isUpgradeToPaid($oldTier, $winning),
            reason: 'arbitrated',
            sources: $sources,
        );
    }

    /**
     * Highest looth* role currently on the user (lookup, no write).
     * Returns null if none of the tier roles are present.
     *
     * Byte-faithful to WP Arbiter::currentTier().
     */
    public static function currentTier(array $roles): ?string
    {
        $best = null;
        foreach (self::TIER_ROLES as $role) {
            if (in_array($role, $roles, true)) {
                if ($best === null || strcmp($role, $best) > 0) {
                    $best = $role;
                }
            }
        }
        return $best;
    }

    /**
     * True when $old -> $new is a real upgrade INTO a paid tier (looth2+).
     * looth1 is the free starter and does not count.
     *
     * Byte-faithful to WP Arbiter::isUpgradeToPaid().
     */
    public static function isUpgradeToPaid(?string $old, ?string $new): bool
    {
        if ($new === null || $new === Tier::LOOTH1) {
            return false;
        }
        if (!in_array($new, [ Tier::LOOTH2, Tier::LOOTH3, Tier::LOOTH4 ], true)) {
            return false;
        }
        if ($old === null) {
            return true; // first-ever tier assignment, paid
        }
        return strcmp($new, $old) > 0;
    }

    /**
     * Highest of looth1..4 across sources reporting non-null, valid tiers.
     *
     * FAIL-CLOSED chokepoint (byte-faithful to WP Arbiter::computeWinningTier):
     *   - no rows at all          -> null  ("don't touch" / hold at floor)
     *   - rows present, none valid -> looth1 (lapsed -> free floor)
     *   - null tiers / non-looth*  -> ignored (never elevate)
     * In no case does absence, nullness, or garbage produce a PAID tier.
     *
     * @param array<string,?string> $sources source => tier
     */
    public static function computeWinningTier(array $sources): ?string
    {
        if ($sources === []) {
            return null;
        }
        $best = null;
        foreach ($sources as $tier) {
            if ($tier === null) {
                continue;
            }
            if (!in_array($tier, self::TIER_ROLES, true)) {
                continue;
            }
            if ($best === null || strcmp($tier, $best) > 0) {
                $best = $tier;
            }
        }
        return $best ?? Tier::FLOOR;
    }
}
