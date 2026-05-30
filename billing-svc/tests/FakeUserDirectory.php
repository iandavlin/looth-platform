<?php

declare(strict_types=1);

namespace LGBilling\Tests;

use LGBilling\Port\UserDirectory;

/**
 * Test double for the UserDirectory port. Seed roles + meta per user; an
 * unseeded user returns null roles (the "no such user" signal).
 */
final class FakeUserDirectory implements UserDirectory
{
    /** @var array<int,list<string>> */
    private array $roles = [];
    /** @var array<int,array<string,string>> */
    private array $meta = [];

    /** @param list<string> $roles */
    public function seedUser(int $id, array $roles, array $meta = []): void
    {
        $this->roles[$id] = $roles;
        $this->meta[$id]  = $meta;
    }

    public function roles(int $userId): ?array
    {
        return $this->roles[$userId] ?? null;
    }

    public function meta(int $userId, string $key): ?string
    {
        $v = $this->meta[$userId][$key] ?? '';
        return $v === '' ? null : $v;
    }
}
