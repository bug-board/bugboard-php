<?php

/**
 * Plain PHP quickstart.
 * ═════════════════════
 *
 * Demonstrates: building one shared client and reporting a caught exception,
 *               without adding latency to your request under PHP-FPM.
 * Key type:     secret (keyId + signingSecret) — HMAC auth, the server default.
 * Use this for: vanilla PHP, Slim, CodeIgniter, CakePHP, WordPress, or any
 *               framework without a dedicated integration.
 *
 * Delivery: the client registers a shutdown hook the first time you report, so
 * flushing is automatic. But under PHP-FPM that hook runs BEFORE the response is
 * flushed to the client, adding delivery latency to the request. Call
 * fastcgi_finish_request() first (shown below) to hand the response over before
 * delivering — this is exactly what Laravel's terminating phase does for you.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BugBoard\Client;
use BugBoard\ClientBuilder;
use BugBoard\Config;

/**
 * Build the client ONCE per process and share it — it holds the buffer. A
 * static memo (or a container binding in a real app) is enough.
 */
function bugboard(): Client
{
    static $client = null;

    return $client ??= ClientBuilder::create(new Config(
        keyId: getenv('BUGBOARD_KEY_ID') ?: null,          // bbk_…
        signingSecret: getenv('BUGBOARD_SIGNING_SECRET') ?: null, // bb_sec_… (never transmitted)
        environment: getenv('APP_ENV') ?: 'production',
        release: getenv('APP_RELEASE') ?: null,
    ));
}

// ─── Report a caught exception ────────────────────────────────────────────────
try {
    // $orders->capture($payment);
    throw new RuntimeException('gateway timeout'); // simulate a failure
} catch (Throwable $e) {
    // Fire-and-forget: buffers the report and returns. Never throws.
    bugboard()->criticalHigh('Payment capture failed', $e, ['payments', 'checkout']);
}

// … echo your HTTP response here …
echo "Response sent to the user.\n";

// ─── Deliver AFTER the user has their response ────────────────────────────────
// fastcgi_finish_request() closes the connection and lets the worker continue,
// so delivery costs the user nothing. Guard it — it doesn't exist on every SAPI.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

bugboard()->flush();
