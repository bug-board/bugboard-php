<?php

declare(strict_types=1);

namespace BugBoard\Tests\Support;

use BugBoard\QuotaStore;

/** An in-memory {@see QuotaStore}, standing in for a shared cache. */
final class ArrayQuotaStore implements QuotaStore
{
    public ?int $value = null;

    public int $writes = 0;

    public function suppressedUntil(): ?int
    {
        return $this->value;
    }

    public function suppressUntil(int $timestamp): void
    {
        $this->value = $timestamp;
        $this->writes++;
    }

    public function clear(): void
    {
        $this->value = null;
    }
}
