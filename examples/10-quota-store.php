<?php

/**
 * Persisting quota suppression across requests.
 * ═════════════════════════════════════════════
 *
 * Demonstrates: making quota suppression survive the end of a PHP-FPM request.
 * Key type:     secret (server-side).
 *
 * When your account's event allowance runs out — or a project is paused or
 * archived — the server accepts the report, discards it, and answers 200 with a
 * `dropped` flag, so its billing/lifecycle state never surfaces as a failure in
 * the app being monitored. The SDK reads that flag, stops sending, and resumes
 * on its own once the window passes (next midnight UTC for allowance; 30 minutes
 * for a paused/archived project).
 *
 * The catch under PHP-FPM: a fresh process per request means a gate held only in
 * memory re-opens on every request — so on a busy site there's no meaningful
 * suppression. Persisting the deadline in a shared cache fixes that.
 *
 * On Laravel and Symfony this is ALREADY wired to your application cache —
 * nothing to do. Standalone, pass a QuotaStore.
 *
 * Needs: psr/simple-cache + any PSR-16 cache implementation.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BugBoard\ClientBuilder;
use BugBoard\Config;
use BugBoard\Psr16QuotaStore;
use Psr\SimpleCache\CacheInterface;

/** @var CacheInterface $psr16Cache Your app's PSR-16 cache (Redis, APCu file cache, …). */
$psr16Cache = getYourPsr16Cache();

$bugboard = ClientBuilder::create(
    new Config(
        keyId: getenv('BUGBOARD_KEY_ID') ?: null,
        signingSecret: getenv('BUGBOARD_SIGNING_SECRET') ?: null,
    ),
    quotaStore: new Psr16QuotaStore($psr16Cache),
);

$bugboard->major('Reporting with cross-request quota suppression');
$bugboard->flush();

/*
 * - Psr16QuotaStore works with any PSR-16 cache. For anything else (Redis
 *   directly, APCu, a file) implement the three-method QuotaStore interface
 *   yourself: suppressedUntil(), suppressUntil(int), clear().
 * - A cache that is unreachable degrades to an OPEN gate — reports flow again as
 *   if there were no store. Suppression is an optimization; losing it must never
 *   cost you a report.
 * - Without a store the gate still works for the life of the process, which is
 *   all a CLI command, a queue worker, or an Octane process needs.
 */

// Stub so this file is self-contained; replace with your real cache.
function getYourPsr16Cache(): CacheInterface
{
    return new class implements CacheInterface {
        /** @var array<string, mixed> */
        private array $store = [];

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->store[$key] ?? $default;
        }

        public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
        {
            $this->store[$key] = $value;

            return true;
        }

        public function delete(string $key): bool
        {
            unset($this->store[$key]);

            return true;
        }

        public function clear(): bool
        {
            $this->store = [];

            return true;
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            return [];
        }

        public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
        {
            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            return true;
        }

        public function has(string $key): bool
        {
            return isset($this->store[$key]);
        }
    };
}
