<?php

declare(strict_types=1);

namespace BugBoard;

use BugBoard\Laravel\CacheQuotaStore;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * A {@see QuotaStore} backed by any PSR-16 cache.
 *
 * `psr/simple-cache` is not a hard dependency of this SDK — this class is only
 * loaded if you construct it, so installing it is your choice. Symfony's cache
 * component implements PSR-16 out of the box; Laravel users want
 * {@see CacheQuotaStore} instead, since Laravel's cache
 * repository is not a PSR-16 implementation.
 *
 * Every method swallows cache failures. A cache that is down must degrade to an
 * open gate — reports flowing again — never to an exception inside the host app.
 */
final class Psr16QuotaStore implements QuotaStore
{
    /**
     * Namespaced so it cannot collide with the host application's own keys.
     * Not per-project: a client only ever talks to one project, and the key is
     * scoped to whatever cache the application handed us.
     */
    public const KEY = 'bugboard.quota.suppressed_until';

    public function __construct(
        private readonly CacheInterface $cache,
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
            $this->cache->set($this->key, $timestamp, $ttl);
        } catch (Throwable) {
            // Suppression is best-effort; a failed write just means the next
            // request tries the network again.
        }
    }

    public function clear(): void
    {
        try {
            $this->cache->delete($this->key);
        } catch (Throwable) {
            // See suppressUntil().
        }
    }
}
