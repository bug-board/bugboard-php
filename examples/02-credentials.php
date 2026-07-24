<?php

/**
 * Credentials: secret key vs publishable key.
 * ═══════════════════════════════════════════
 *
 * Demonstrates: both auth schemes and which one belongs on a server.
 * Key type:     both (compared side by side).
 *
 * BugBoard issues two kinds of key. On a server you almost always want a SECRET
 * key. The SDK picks the scheme from what you set:
 *
 *   keyId + signingSecret  → HMAC   (secret key)
 *   apiKey                 → bearer (publishable key)
 *   both set               → HMAC wins
 *   neither                → client disables itself (with a warning, not a throw)
 *
 * Get keys from your BugBoard project under Settings → API Keys.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BugBoard\ClientBuilder;
use BugBoard\Config;

// ─── Secret key (HMAC) — the server default ───────────────────────────────────
// The signing secret is used only to compute an HMAC over the request body; it
// NEVER travels on the wire. This is what you want in PHP.
$secret = new Config(
    keyId: getenv('BUGBOARD_KEY_ID') ?: null,          // bbk_…
    signingSecret: getenv('BUGBOARD_SIGNING_SECRET') ?: null, // bb_sec_…
    environment: 'production',
);

echo 'Secret config auth scheme: ' . $secret->authScheme() . "\n"; // "hmac"
echo 'Active? ' . ($secret->active() ? 'yes' : 'no') . "\n";

$client = ClientBuilder::create($secret);
$client->major('Server started with a secret key');

// ─── Publishable key (bearer) — rarely right in PHP ───────────────────────────
// A publishable key is transmitted as a bearer token on every request. It's
// designed to be public and write-only, which makes sense in a browser bundle
// and very little sense on a trusted server. Shown only for completeness.
$publishable = new Config(
    apiKey: getenv('BUGBOARD_API_KEY') ?: null, // bb_pub_…
);

echo 'Publishable config auth scheme: ' . $publishable->authScheme() . "\n"; // "bearer"

// ─── No credentials → disabled, not an error ──────────────────────────────────
// A client with no credentials disables itself and warns (turn on `debug` to see
// the warning). Reporting calls become silent no-ops — nothing throws.
$none = new Config();
echo 'No-credential config active? ' . ($none->active() ? 'yes' : 'no') . "\n"; // no
