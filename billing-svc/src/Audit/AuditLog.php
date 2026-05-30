<?php

declare(strict_types=1);

namespace LGBilling\Audit;

use LGBilling\Writer\TierGrant;

/**
 * Append-only audit sink for tier grants (design §5b-E).
 *
 * Every grant attempt — applied, no-op, or refused — is recorded so that a
 * fraudulent or buggy entitlement change is detectable after the fact and
 * reversible. The backing store (billing.tier_grant_audit, see
 * src/Schema/schema.sql) is INSERT-only at the DB-grant level: even the
 * billing-svc role has no UPDATE/DELETE on it.
 *
 * `record()` returns the audit row id (string) so the caller can thread it
 * into the TierGrantResult for traceability.
 */
interface AuditLog
{
    public function record(TierGrant $grant, bool $applied, string $outcome): string;

    /**
     * Has this event id already been recorded as APPLIED? Backs the
     * idempotency/replay guard — the chokepoint that makes re-applying a
     * captured grant a no-op.
     */
    public function alreadyApplied(string $eventId): bool;
}
