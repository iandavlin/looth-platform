<?php

declare(strict_types=1);

namespace LGBilling\Source;

/**
 * In-memory SourceStore for tests + the reference shape of the pg-backed
 * PdoSourceStore (step 1). Seed persisted source rows per user.
 */
final class InMemorySourceStore implements SourceStore
{
    /** @var array<int,array<string,?string>> */
    private array $rows = [];

    /** @param array<string,?string> $sources source => tier */
    public function seed(int $userId, array $sources): void
    {
        $this->rows[$userId] = $sources;
    }

    public function readAllForUser(int $userId): array
    {
        return $this->rows[$userId] ?? [];
    }
}
