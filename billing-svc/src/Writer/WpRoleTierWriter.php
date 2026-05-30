<?php

declare(strict_types=1);

namespace LGBilling\Writer;

use RuntimeException;

/**
 * STEP-1 target writer — converges tier onto WP roles (wp_capabilities),
 * the same place today's in-WP Arbiter writes.
 *
 * ⚠ DELIBERATELY NOT IMPLEMENTED THIS MILESTONE. The dark-launch scaffold must
 * NOT write wp_capabilities from outside WordPress (bootstrap "Do NOT" list;
 * §5b boundary). The live lg-patreon-stripe-poller keeps writing roles
 * untouched while we prove the lift with RecordingTierWriter + tests.
 *
 * When step 1 actually flips, this writer becomes the relocated grant path.
 * Two viable mechanisms (coordinator's call — NOT decided here):
 *   (a) loopback POST to a NEW internal WP endpoint that performs the
 *       add_role/remove_role (keeps WP the literal role-writer; billing-svc
 *       only decides), or
 *   (b) `wp eval`/CLI bridge run as the WP-owning OS user.
 * Either way it MUST preserve every WP side effect the in-WP Arbiter had and
 * that this scaffold intentionally left behind — see MIGRATION-NOTES.md
 * "Side effects NOT ported":
 *   - bp_set_member_type(starter|'') for directory visibility,
 *   - _lg_pending_welcome meta + WelcomeMailer on upgrade-to-paid,
 *   - the looth_tier_changed action (PurgeNotifier cache invalidation).
 *
 * Until then, constructing/using it throws so it can never silently no-op a
 * live grant.
 */
final class WpRoleTierWriter implements TierWriter
{
    public function apply(TierGrant $grant): TierGrantResult
    {
        throw new RuntimeException(
            'WpRoleTierWriter is a step-1 stub: billing-svc does not write '
            . 'wp_capabilities from outside WP in the dark-launch scaffold. '
            . 'See MIGRATION-NOTES.md.'
        );
    }
}
