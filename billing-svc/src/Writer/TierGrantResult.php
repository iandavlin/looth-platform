<?php

declare(strict_types=1);

namespace LGBilling\Writer;

/**
 * Outcome of TierWriter::apply().
 *
 *   $applied === true   -> the grant was written (a fresh, non-replay event
 *                          that changed or set the tier).
 *   $applied === false  -> intentionally not written. $reason says why:
 *                          'duplicate-event' (idempotent replay), 'no-op'
 *                          (old === new), or a fail-closed refusal.
 *
 * Every non-replay grant attempt leaves an audit row regardless of $applied,
 * so a refused or no-op grant is still detectable after the fact (§5b-E).
 */
final class TierGrantResult
{
    public function __construct(
        public readonly bool $applied,
        public readonly string $reason,
        public readonly ?string $auditId = null,
    ) {
    }

    public static function applied(?string $auditId = null): self
    {
        return new self(true, 'applied', $auditId);
    }

    public static function duplicate(): self
    {
        return new self(false, 'duplicate-event');
    }

    public static function noop(): self
    {
        return new self(false, 'no-op');
    }
}
