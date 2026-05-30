<?php

declare(strict_types=1);

namespace LGBilling\Source;

use LGBilling\Port\UserDirectory;
use LGBilling\Tier;

/**
 * Framework-free port of lg-patreon-stripe-poller's
 * LGMS\Patreon\PatreonSourceReader.
 *
 * Faithful copy of the WP reader's behaviour, with every WP call replaced by
 * a UserDirectory lookup:
 *   get_user_meta($id,'payment_source',true) -> $users->meta($id,'payment_source')
 *   get_userdata($id)->roles                 -> $users->roles($id)
 *   get_user_meta($id,'lgpo_patreon_tier_id')-> $users->meta($id,'lgpo_patreon_tier_id')
 *
 * Read-only adapter: surfaces Patreon-attributed tier state that LGPO
 * (lg-patreon-onboard) caches on-box. Makes NO Patreon API calls — LGPO
 * still owns the polling cron in this milestone (we copy, not move).
 *
 * Coexistence with Stripe is preserved exactly: returns null for any
 * payment_source other than 'patreon', so a Stripe-owned user never gets a
 * patreon source row materialised.
 */
final class PatreonSourceReader implements SourceReader
{
    /** Highest-first scan order — matches the WP reader exactly. */
    private const ROLE_SCAN = [ Tier::LOOTH3, Tier::LOOTH2, Tier::LOOTH1 ];

    public function __construct(private readonly UserDirectory $users)
    {
    }

    public function sourceKey(): string
    {
        return 'patreon';
    }

    /**
     * @return array{source:string,tier:string,tier_id:?string}|null
     */
    public function readForUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $paymentSource = $this->users->meta($userId, 'payment_source');
        if ($paymentSource !== 'patreon') {
            return null;
        }

        $roles = $this->users->roles($userId);
        if ($roles === null) {
            // WP: get_userdata() falsy -> null (no such user).
            return null;
        }

        $tier = Tier::LOOTH1;
        foreach (self::ROLE_SCAN as $r) {
            if (in_array($r, $roles, true)) {
                $tier = $r;
                break;
            }
        }

        $tierId = $this->users->meta($userId, 'lgpo_patreon_tier_id');

        return [
            'source'  => 'patreon',
            'tier'    => $tier,
            'tier_id' => is_string($tierId) && $tierId !== '' ? $tierId : null,
        ];
    }
}
