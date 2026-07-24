<?php

/**
 * Automatic exception handling in plain PHP.
 * ══════════════════════════════════════════
 *
 * Demonstrates: getting EVERY uncaught exception and fatal error onto your board
 *               without wrapping each call site in try/catch.
 * Key type:     secret (server-side).
 * Use this for: vanilla PHP or any framework without a dedicated integration.
 *               (Laravel and Symfony have their own hooks — see 11 and 12.)
 *
 * Two handlers cover the two ways a script dies:
 *   - set_exception_handler   → uncaught exceptions
 *   - register_shutdown_function + error_get_last → fatal errors (E_ERROR, parse,
 *     etc.) that are NOT catchable as exceptions
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BugBoard\Client;
use BugBoard\ClientBuilder;
use BugBoard\Config;

function bugboard(): Client
{
    static $client = null;

    return $client ??= ClientBuilder::create(new Config(
        keyId: getenv('BUGBOARD_KEY_ID') ?: null,
        signingSecret: getenv('BUGBOARD_SIGNING_SECRET') ?: null,
        environment: getenv('APP_ENV') ?: 'production',
    ));
}

// ─── Uncaught exceptions ──────────────────────────────────────────────────────
set_exception_handler(function (Throwable $e): void {
    // Use `getMessage() ?: class` so an exception with an empty message doesn't
    // produce a card with an empty title.
    bugboard()->critical($e->getMessage() ?: $e::class, $e);
});

// ─── Fatal errors ─────────────────────────────────────────────────────────────
register_shutdown_function(function (): void {
    $error = error_get_last();

    if ($error !== null && ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        bugboard()->criticalHigh(
            $error['message'],
            sprintf('%s:%d', $error['file'], $error['line']),
        );

        // The SDK's OWN shutdown hook may have been registered before this one,
        // in which case it has already run — so flush explicitly here.
        bugboard()->flush();
    }
});

// ─── From here, uncaught errors are reported automatically ────────────────────
echo "Handlers installed. Any uncaught exception or fatal error is now reported.\n";

// Uncomment to see it fire:
// throw new RuntimeException('boom');
