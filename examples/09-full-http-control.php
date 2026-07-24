<?php

/**
 * Full control over the HTTP stack.
 * ═════════════════════════════════
 *
 * Demonstrates: passing your own PSR-18 client and PSR-17 factories.
 * Key type:     secret (server-side).
 * Needs:        guzzlehttp/guzzle (composer require guzzlehttp/guzzle).
 *
 * bugboard/sdk deliberately does not pin an HTTP client — it accepts whatever
 * PSR-18 implementation your project already has. Pass one explicitly when you
 * need a proxy, custom TLS, connection pooling, or a real timeout on a non-Guzzle
 * client.
 *
 * IMPORTANT: when you build Guzzle yourself, set 'http_errors' => false. The
 * transport maps status codes to its own exception taxonomy and needs to see the
 * response, not a Guzzle exception. (When the SDK builds Guzzle for you, it does
 * this automatically.)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BugBoard\ClientBuilder;
use BugBoard\Config;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;

$factory = new HttpFactory(); // implements both RequestFactory and StreamFactory

$bugboard = ClientBuilder::create(
    new Config(
        keyId: getenv('BUGBOARD_KEY_ID') ?: null,
        signingSecret: getenv('BUGBOARD_SIGNING_SECRET') ?: null,
    ),
    new GuzzleClient([
        'timeout'     => 3,
        'proxy'       => 'http://proxy.internal:8080',
        'http_errors' => false, // required — the SDK reads status codes itself
    ]),
    $factory, // RequestFactoryInterface
    $factory, // StreamFactoryInterface
);

$bugboard->major('Reporting through a custom HTTP stack');
$bugboard->flush();

echo "Client built with an explicit Guzzle stack.\n";
