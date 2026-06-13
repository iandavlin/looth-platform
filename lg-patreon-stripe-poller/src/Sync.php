<?php

declare(strict_types=1);

namespace LGMS;

use LGMS\Repos\CustomerRepo;
use LGMS\Repos\EntitlementRepo;
use LGMS\Wp\UserProvisioner;
use Throwable;

/**
 * Per-customer sync orchestrator. Idempotent.
 *
 *   1. Find/provision WP user (writes wp_user_bridge)
 *   2. Compute current active tier from entitlements
 *   3. Report (wp_user_id, 'stripe', tier) to lg_role_sources
 *   4. Run arbiter to write wp_capabilities
 *
 * Called from:
 *   - Tick::run() pass 2 (cron)
 *   - REST endpoint /sync-customer (Slim post-checkout)
 *   - REST endpoint /run-now (admin)
 */
final class Sync
{
    /** @return array{ok:bool, message?:string, wp_user_id?:int, tier?:?string} */
    public static function customer(int $customerId): array
    {
        $customer = CustomerRepo::findById( $customerId );
        if ( ! $customer ) {
            return [ 'ok' => false, 'message' => "customer {$customerId} not found" ];
        }

        try {
            $wpUserId = UserProvisioner::findOrProvision(
                $customerId,
                (string) $customer['email'],
                $customer['name'] !== null ? (string) $customer['name'] : null,
            );
        } catch ( Throwable $e ) {
            return [ 'ok' => false, 'message' => 'provision failed: ' . $e->getMessage() ];
        }

        $tier = EntitlementRepo::activeTier( $customerId );
        RoleSourceWriter::report( $wpUserId, 'stripe', $tier );
        $arb = Arbiter::sync( $wpUserId );

        return [
            'ok'           => true,
            'wp_user_id'   => $wpUserId,
            'tier'         => $tier,
            'arbiter'      => $arb,
        ];
    }

    /** Sweep every active customer. Called from cron. */
    public static function all(): array
    {
        $stmt    = Db::pdo()->query( 'SELECT id FROM customers WHERE deleted_at IS NULL' );
        $results = [];
        foreach ( $stmt->fetchAll( \PDO::FETCH_COLUMN ) as $cid ) {
            $results[ (int) $cid ] = self::customer( (int) $cid );
        }
        return $results;
    }

    /**
     * Re-arbitrate every Patreon-sourced member.
     *
     * all() only visits lg_membership customers (Stripe), so a Patreon-only
     * member is never re-run through the Arbiter by the poller's own cron.
     * Role/source drift — a lapsed manual grant, a stale non-winning tier
     * role left over from a prior tier, a missed BB member-type transition —
     * would then sit uncorrected until the opt-in LGPO Patreon API cron next
     * ran (and that cron is off whenever lgpo_auto_sync_enabled is unchecked).
     * This makes the poller self-sufficient.
     *
     * Re-arbitrates from PERSISTED sources only; it does NOT re-poll Patreon
     * for pledge churn (active→former) — that is the LGPO sync engine's job
     * (it hits the campaign-members API). This only keeps WP roles consistent
     * with whatever the sources already say.
     *
     * @return array{swept:int, errors:int}
     */
    public static function allPatreon(): array
    {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
            'payment_source',
            'patreon'
        ) );

        $swept  = 0;
        $errors = 0;
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id <= 0 ) {
                continue;
            }
            try {
                Arbiter::sync( $id );
                $swept++;
            } catch ( Throwable $e ) {
                $errors++;
            }
        }
        return [ 'swept' => $swept, 'errors' => $errors ];
    }
}
