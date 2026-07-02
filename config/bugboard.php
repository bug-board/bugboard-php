<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| BugBoard (Laravel)
|--------------------------------------------------------------------------
|
| Publishable with:
|   php artisan vendor:publish --tag=bugboard-config
|
| Servers should use a secret key: BUGBOARD_KEY_ID + BUGBOARD_SIGNING_SECRET.
| Requests are HMAC-signed and the secret never leaves the server. The
| publishable-key option (api_key) exists for parity but belongs in
| browser/mobile clients, not in PHP.
|
*/

return [

    // Secret key (recommended for servers): key id + signing secret.
    'key_id' => env('BUGBOARD_KEY_ID'),
    'signing_secret' => env('BUGBOARD_SIGNING_SECRET'),

    // Publishable key (bearer auth) — only if you cannot use a secret key.
    'api_key' => env('BUGBOARD_API_KEY'),

    // Optional payload encryption: when the public key is set, every report
    // body is sealed (libsodium sealed box) before it leaves the server.
    'encryption_public_key' => env('BUGBOARD_ENCRYPTION_PUBLIC_KEY'),
    'encryption_key_id' => env('BUGBOARD_ENCRYPTION_KEY_ID'),

    // Master switch — handy to disable reporting in local/testing envs.
    'enabled' => env('BUGBOARD_ENABLED', true),

    // Folded into every card's tags as env:<value> / release:<value>.
    'environment' => env('BUGBOARD_ENVIRONMENT', env('APP_ENV')),
    'release' => env('BUGBOARD_RELEASE'),

    // Extra tags merged into every card (array, or CSV string via env).
    'default_tags' => env('BUGBOARD_DEFAULT_TAGS', []),

    // Probability (0–1) that a report is sent — sample under heavy load.
    'sample_rate' => env('BUGBOARD_SAMPLE_RATE', 1.0),

    // Delivery tuning.
    'max_queue_size' => env('BUGBOARD_MAX_QUEUE_SIZE', 100),
    'timeout_ms' => env('BUGBOARD_TIMEOUT_MS', 5000),
    'max_retries' => env('BUGBOARD_MAX_RETRIES', 3),

    // Verbose internal logging (keys always redacted).
    'debug' => env('BUGBOARD_DEBUG', false),

];
