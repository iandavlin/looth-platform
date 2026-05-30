<?php

declare(strict_types=1);

namespace LGBilling\Writer;

use RuntimeException;

/**
 * STEP-2 target writer — the inversion: billing-svc becomes the SOLE writer of
 * tier into profile-app's `member_tier` table, and the WP user-context loopback
 * retires. This is "WP stops being the tier authority."
 *
 * ⚠ DELIBERATELY NOT IMPLEMENTED THIS MILESTONE. Tier authority does not invert
 * until step 2 gets its own dev soak. The member_tier table does not yet exist
 * in profile_app; this writer is the documented landing spot.
 *
 * Security shape this writer will enforce (design §5b-A, planned — NOT built):
 *   - billing-svc pg role: INSERT/UPDATE on profile_app.member_tier and the
 *     billing.tier_grant_audit table, NOTHING else.
 *   - profile-app web role: SELECT-only on member_tier — it CANNOT self-grant.
 *   - internal channel auth: shared secret /etc/lg-internal-secret, hash_equals,
 *     loopback-only (must 403 from off-box).
 *   - idempotency via TierGrant.eventId; immutable audit row per grant.
 *   - revocation propagates promptly (the existing looth_tier_changed purge
 *     wiring keeps firing on downgrades, not just upgrades — §5b-E).
 *
 * Until step 2, constructing/using it throws so it can never silently grant.
 */
final class MemberTierWriter implements TierWriter
{
    public function apply(TierGrant $grant): TierGrantResult
    {
        throw new RuntimeException(
            'MemberTierWriter is a step-2 stub: tier authority has not yet '
            . 'inverted into profile_app.member_tier. See MIGRATION-NOTES.md.'
        );
    }
}
