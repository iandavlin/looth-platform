<?php

declare(strict_types=1);

namespace LGBilling\Source;

/**
 * A source of tier opinion for a user. Each reader answers "what tier does
 * source X think user Y has, right now?" — or null if X doesn't manage Y.
 *
 * Mirrors the WP plugin's source model (lg_role_sources rows + the live
 * Patreon adapter). The Arbiter merges all readers and picks the winner.
 */
interface SourceReader
{
    /**
     * @return array{source:string,tier:string,tier_id:?string}|null
     *   null = this source does not manage the user (no row materialised).
     *   Otherwise the source's current opinion. `tier` is a looth* role.
     */
    public function readForUser(int $userId): ?array;

    /** Stable source key, e.g. 'patreon', 'stripe', 'manual_admin'. */
    public function sourceKey(): string;
}
