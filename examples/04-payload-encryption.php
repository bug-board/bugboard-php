<?php

/**
 * Payload encryption (sealed box, X25519).
 * ════════════════════════════════════════
 *
 * Demonstrates: end-to-end encrypting report bodies so they're opaque on the wire.
 * Key type:     secret (server-side), with encryption fields added.
 * Requires:     ext-sodium (ships with PHP on most builds; see the note below).
 *
 * By default, report bodies are plaintext JSON over TLS. That's fine against
 * network eavesdroppers, but the body is readable at any TLS-terminating proxy,
 * load balancer, or WAF between you and BugBoard — and sometimes in their logs.
 *
 * Set an encryption key and every payload is sealed with a libsodium sealed box
 * (X25519) BEFORE it leaves your server. The envelope is opaque to everything on
 * the wire; only BugBoard holds the private key. Encryption happens before
 * signing, so the HMAC covers the sealed bytes.
 *
 * Generate the keypair under Settings → API Keys → Payload encryption. The
 * encryptionKeyId is echoed in the envelope so the server knows which key to
 * decrypt with — that's what makes key rotation possible.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BugBoard\ClientBuilder;
use BugBoard\Config;

// Fail fast with a clear message if sodium isn't loaded in THIS runtime.
if (! extension_loaded('sodium')) {
    fwrite(STDERR, "ext-sodium is not loaded — payload encryption is unavailable.\n");
    exit(1);
}

$bugboard = ClientBuilder::create(new Config(
    keyId: getenv('BUGBOARD_KEY_ID') ?: null,
    signingSecret: getenv('BUGBOARD_SIGNING_SECRET') ?: null,

    // Turn on encryption by setting both fields:
    encryptionPublicKey: getenv('BUGBOARD_ENCRYPTION_PUBLIC_KEY') ?: null, // base64 X25519
    encryptionKeyId: getenv('BUGBOARD_ENCRYPTION_KEY_ID') ?: null,        // bbek_…

    environment: 'production',
));

// From here on, reporting is identical — the encryption is transparent.
$bugboard->criticalHigh('Payment capture failed', new RuntimeException('gateway timeout'), ['payments']);

$bugboard->flush();

/*
 * Gotcha: CLI and FPM read DIFFERENT php.ini files. `php -m` can list sodium on
 * the CLI while your web requests still fail with
 * "undefined function sodium_crypto_box_seal". Check `php-fpm -m | grep sodium`
 * or a phpinfo() page, then restart FPM. Full install walkthrough in the README.
 */
