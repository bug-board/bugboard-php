<?php

declare(strict_types=1);

namespace BugBoard\Laravel;

use BugBoard\Psr16QuotaStore;
use BugBoard\QuotaStore;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * A {@see QuotaStore} backed by Laravel's cache.
 *
 * Laravel's cache repository is not a PSR-16 implementation (`put()` takes the
 * TTL in a different position and `get()` has different miss semantics), so it
 * gets its own adapter rather than going through
 * {@see Psr16QuotaStore}.
 *
 * Every method swallows cache failures. A cache that is down must degrade to an
 * open gate — reports flowing again — never to an exception inside the host app.
 */
final class CacheQuotaStore implements QuotaStore
{
    public const KEY = 'bugboard.quota.suppressed_until';

    public function __construct(
        private readonly Repository $cache,
        private readonly string $key = self::KEY,
    ) {}

    public function suppressedUntil(): ?int
    {
        try {
            $value = $this->cache->get($this->key);
        } catch (Throwable) {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public function suppressUntil(int $timestamp): void
    {
        // The entry's TTL is the suppression window itself, so an expired gate
        // cleans itself up even if this process never runs again.
        $ttl = max(1, $timestamp - time());

        try {
            $this->cache->put($this->key, $timestamp, $ttl);
        } catch (Throwable) {
            // Suppression is best-effort; a failed write just means the next
            // request tries the network again.
        }
    }

    public function clear(): void
    {
        try {
            $this->cache->forget($this->key);
        } catch (Throwable) {
            // See suppressUntil().
        }
    }
}
