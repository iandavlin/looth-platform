<?php

declare(strict_types=1);

namespace LGBilling\Port;

/**
 * Read-only view of a member's WP-side state, abstracted so the ported
 * Arbiter + PatreonSourceReader carry NO WordPress function calls.
 *
 * In the WP plugin this data came from get_userdata()->roles and
 * get_user_meta(). The port lets the same arbitration logic run:
 *   - in tests, against fixtures (no WP);
 *   - in step-1, against a thin adapter that reads WP over the existing
 *     loopback / a CLI `wp eval`-style bridge (read-only — billing-svc
 *     never writes WP from outside WP this milestone);
 *   - in step-2, against the profile-app member store, once tier authority
 *     inverts.
 *
 * It is READ-ONLY by design. Granting (the write side) goes exclusively
 * through LGBilling\Writer\TierWriter — the single-writer seam. Nothing
 * here can mutate entitlement.
 */
interface UserDirectory
{
    /**
     * @return list<string>|null  The user's WP roles, or null if no such user.
     *                            null is the "no such user" signal the WP
     *                            Arbiter got from a failed get_user_by('id').
     */
    public function roles(int $userId): ?array;

    /**
     * A single user_meta value, or null if unset/empty.
     * Mirrors get_user_meta($id, $key, true) returning '' for missing keys —
     * adapters MUST normalise '' to null so callers test against null only.
     */
    public function meta(int $userId, string $key): ?string;
}
