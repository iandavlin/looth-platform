<?php

declare(strict_types=1);

namespace LGBilling\Audit;

use LGBilling\Writer\TierGrant;

/**
 * In-memory AuditLog — used by the unit tests and as the reference shape for
 * the pg-backed implementation (PdoAuditLog, step 1/2).
 *
 * Holds the same columns the immutable billing.tier_grant_audit table will:
 * (id, user_uuid, wp_user_id, old, new, source, event_id, actor, ts, applied,
 * outcome). Append-only — there is no mutate/delete method, mirroring the
 * INSERT-only DB grant.
 */
final class InMemoryAuditLog implements AuditLog
{
    /** @var list<array<string,mixed>> */
    private array $rows = [];
    private int $seq = 0;

    public function record(TierGrant $grant, bool $applied, string $outcome): string
    {
        $id = 'audit-' . (++$this->seq);
        $this->rows[] = [
            'id'         => $id,
            'user_uuid'  => $grant->userUuid,
            'wp_user_id' => $grant->wpUserId,
            'old'        => $grant->oldTier,
            'new'        => $grant->newTier,
            'source'     => $grant->source,
            'event_id'   => $grant->eventId,
            'actor'      => $grant->actor,
            'ts'         => $grant->ts,
            'applied'    => $applied,
            'outcome'    => $outcome,
        ];
        return $id;
    }

    public function alreadyApplied(string $eventId): bool
    {
        foreach ($this->rows as $row) {
            if ($row['event_id'] === $eventId && $row['applied'] === true) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string,mixed>> test/inspection accessor. */
    public function all(): array
    {
        return $this->rows;
    }
}
