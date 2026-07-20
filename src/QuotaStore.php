<?php

declare(strict_types=1);

namespace BugBoard;

use BugBoard\Laravel\CacheQuotaStore;

/**
 * Somewhere a {@see QuotaGate} can keep its suppression deadline so it
 * survives the end of a request.
 *
 * PHP-FPM builds a fresh process per request, so a gate held only in memory
 * re-opens constantly and a busy site keeps sending reports the server has
 * already said it will discard. Backing the gate with the application's cache
 * is what makes the suppression real; without a store the gate still works,
 * but only within a single request (which is all a CLI command, a queue worker
 * or an Octane process needs).
 *
 * Deliberately narrower than PSR-16 so the core SDK stays dependency-free —
 * see {@see Psr16QuotaStore} and {@see CacheQuotaStore} for
 * the adapters.
 */
interface QuotaStore
{
    /**
     * The Unix timestamp reporting is suppressed until, or null when nothing
     * is stored (or the stored deadline has expired).
     *
     * Must never throw: a broken cache has to degrade to an open gate, not
     * take down the application the SDK is monitoring.
     */
    public function suppressedUntil(): ?int;

    /** Record a suppression deadline as a Unix timestamp. Must never throw. */
    public function suppressUntil(int $timestamp): void;

    /** Forget any stored deadline. Must never throw. */
    public function clear(): void;
}
