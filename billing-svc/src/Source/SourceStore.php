<?php

declare(strict_types=1);

namespace LGBilling\Source;

/**
 * Persisted per-source tier opinions — the framework-free equivalent of the
 * WP plugin's lg_role_sources table (read via RoleSourceWriter::readAllForUser).
 *
 * Stripe and manual_admin opinions are PERSISTED here (rows: source => tier,
 * tier nullable = lapsed). The Patreon opinion is NOT persisted — it is read
 * live by PatreonSourceReader and merged on top (overwriting any stale
 * persisted 'patreon' row), exactly as the WP RoleSourceWriter did.
 *
 * In billing-svc these rows live in the `billing` pg schema's lg_role_sources
 * table (see src/Schema/schema.sql). This port keeps the Arbiter unaware of
 * the storage engine.
 */
interface SourceStore
{
    /**
     * @return array<string,?string> source => tier (tier null = lapsed/known-empty)
     */
    public function readAllForUser(int $userId): array;
}
