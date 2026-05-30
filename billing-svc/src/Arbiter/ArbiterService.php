<?php

declare(strict_types=1);

namespace LGBilling\Arbiter;

use LGBilling\Port\UserDirectory;
use LGBilling\Source\SourceReader;
use LGBilling\Source\SourceStore;
use LGBilling\Writer\TierGrant;
use LGBilling\Writer\TierGrantResult;
use LGBilling\Writer\TierWriter;

/**
 * Framework-free orchestrator — the WP-ism-free shape of LGMS\Arbiter::sync().
 *
 * Responsibilities, in order:
 *   1. Read the user's roles + payment_source via the UserDirectory port.
 *   2. Build the merged source map: persisted rows (SourceStore) overlaid with
 *      each live SourceReader (Patreon today), mirroring
 *      RoleSourceWriter::readAllForUser() — live readers overwrite stale
 *      persisted rows of the same key.
 *   3. Ask the pure Arbiter to DECIDE (no I/O, fail-closed).
 *   4. Hand an applied/skip decision to the SINGLE TierWriter, which is the
 *      only thing that grants. This service never writes tier itself.
 *
 * The WP side effects (bp_set_member_type, welcome meta/mailer,
 * looth_tier_changed purge) are intentionally NOT here — they re-attach in the
 * concrete WP-targeting writer at step 1. See MIGRATION-NOTES.md.
 */
final class ArbiterService
{
    /** @param list<SourceReader> $liveReaders */
    public function __construct(
        private readonly UserDirectory $users,
        private readonly SourceStore $store,
        private readonly array $liveReaders,
        private readonly TierWriter $writer,
    ) {
    }

    public function sync(int $wpUserId, EventContext $ctx, ?string $userUuid = null): TierGrantResult
    {
        $roles         = $this->users->roles($wpUserId);
        $paymentSource = $this->users->meta($wpUserId, 'payment_source');
        $sources       = $this->mergedSources($wpUserId);

        $decision = Arbiter::decide($roles, $paymentSource, $sources);

        if (!$decision->apply) {
            // Skip = do not touch the user, do not grant. No audit row: nothing
            // was attempted (matches the WP early-return guards).
            return new TierGrantResult(false, 'skipped:' . $decision->reason);
        }

        $grant = TierGrant::fromDecision(
            decision: $decision,
            wpUserId: $wpUserId,
            userUuid: $userUuid,
            eventId: $ctx->eventId,
            actor: $ctx->actor,
            ts: $ctx->ts,
        );

        return $this->writer->apply($grant);
    }

    /**
     * Persisted rows + live readers overlaid on top (live wins).
     *
     * @return array<string,?string>
     */
    private function mergedSources(int $wpUserId): array
    {
        $sources = $this->store->readAllForUser($wpUserId);
        foreach ($this->liveReaders as $reader) {
            $row = $reader->readForUser($wpUserId);
            if ($row !== null) {
                $sources[$reader->sourceKey()] = $row['tier'];
            }
        }
        return $sources;
    }
}
