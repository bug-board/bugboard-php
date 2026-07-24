<?php

/**
 * Build from a config/env array with createFromArray().
 * ═════════════════════════════════════════════════════
 *
 * Demonstrates: constructing a client when your configuration already lives in
 *               an array — a config file, container parameters, parsed .env.
 * Key type:     secret (server-side).
 *
 * createFromArray() accepts snake_case OR camelCase keys and casts loosely-typed
 * values — the string "true", a numeric-string sample rate, a CSV tag list —
 * which is exactly what env-file values look like. It's the same entry point the
 * Laravel and Symfony integrations use internally.
 *
 * The one option it CANNOT express is beforeSend (a Closure): pass a real closure
 * via the array or it's ignored. See 05-before-send-scrubbing.php for that path.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BugBoard\ClientBuilder;

$bugboard = ClientBuilder::createFromArray([
    'key_id'         => getenv('BUGBOARD_KEY_ID') ?: null,
    'signing_secret' => getenv('BUGBOARD_SIGNING_SECRET') ?: null,
    'environment'    => 'production',

    // All of these are strings — exactly as they'd arrive from an env file —
    // and are cast for you:
    'sample_rate'  => '0.5',        // string → float
    'enabled'      => 'true',       // string → bool
    'max_retries'  => '2',          // string → int
    'default_tags' => 'api,backend', // CSV string → ['api', 'backend']

    // A closure IS honored when passed directly (not via env, obviously):
    'before_send' => function (array $payload): ?array {
        return $payload;
    },
]);

$bugboard->major('Configured from an array');
$bugboard->flush();

echo "Client built from a loosely-typed array.\n";
