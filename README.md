# BugBoard SDK for PHP

[![CI](https://github.com/bug-board/bugboard-php/actions/workflows/ci.yml/badge.svg)](https://github.com/bug-board/bugboard-php/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/bugboard/sdk.svg)](https://packagist.org/packages/bugboard/sdk)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

The official [BugBoard](https://bugboard.dev) SDK for PHP. Report errors as **cards** on your
project board — from plain PHP, **Laravel**, **Symfony**, or any framework with a PSR-18 HTTP
client.

```php
use BugBoard\Laravel\Facades\BugBoard;

try {
    $payments->charge($order);
} catch (\Throwable $e) {
    BugBoard::criticalHigh('Payment failed', $e, ['payment', 'backend']);
}
```

Reporting is **fire-and-forget**: the call buffers the report and returns immediately, delivery
happens after your response is sent (with retries and backoff), and the SDK **never throws into
your app**.

## Requirements

- PHP 8.2+
- Any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client (Guzzle recommended; one is
  auto-installed via `php-http/discovery` if missing)
- `ext-sodium` only if you enable payload encryption (bundled with PHP by default)

## Installation

```bash
composer require bugboard/sdk
```

Set your credentials in `.env` (or the environment). Servers use a **secret key** — a key id
(`bbk_…`) plus a signing secret (`bb_sec_…`). Every request is HMAC-signed; **the secret never
travels on the wire**:

```dotenv
BUGBOARD_KEY_ID=bbk_xxxxxxxx
BUGBOARD_SIGNING_SECRET=bb_sec_xxxxxxxx
```

> Get keys from your BugBoard project under **Settings → API Keys** (create a **Secret** key).

## Laravel

The package is auto-discovered — no manual registration. Configure via `.env` (above) and report
from anywhere using the facade:

```php
use BugBoard\Laravel\Facades\BugBoard;

BugBoard::major('Checkout is slow'); // a title is all you need
BugBoard::critical('Payment failed', $e); // attach the caught Throwable
BugBoard::critical('Payment failed', $e, ['payments', 'checkout']);
```

Or inject the shared client instead of using the facade:

```php
use BugBoard\Client;

public function store(Request $request, Client $bugboard)
{
    $bugboard->moderate('Slow image upload', null, 'uploads');
}
```

Buffered reports are delivered when the app **terminates** — after the response has gone out —
so reporting never adds latency to a request. To customize defaults, publish the config:

```bash
php artisan vendor:publish --tag=bugboard-config
```

A good default for `config/bugboard.php` is already provided: it reads every option from env
(`BUGBOARD_ENABLED`, `BUGBOARD_SAMPLE_RATE`, `BUGBOARD_DEBUG`, …) and tags every card with your
`APP_ENV`.

Want every unhandled exception on your board? Add one line to your exception handler:

```php
// bootstrap/app.php (Laravel 11+)
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(function (\Throwable $e) {
        \BugBoard\Laravel\Facades\BugBoard::critical($e->getMessage() ?: $e::class, $e);
    });
})
```

## Symfony

Enable the bundle and configure it:

```php
// config/bundles.php
return [
    // …
    BugBoard\Symfony\BugBoardBundle::class => ['all' => true],
];
```

```yaml
# config/packages/bugboard.yaml
bugboard:
  key_id: '%env(BUGBOARD_KEY_ID)%'
  signing_secret: '%env(BUGBOARD_SIGNING_SECRET)%'
  environment: '%kernel.environment%'
```

Then autowire the client anywhere:

```php
use BugBoard\Client;

public function __construct(private readonly Client $bugboard) {}

$this->bugboard->major('Checkout is slow', $exception, ['checkout']);
```

## Plain PHP (any framework)

Build a client once and hold onto it — reports buffer during the request and are delivered by a
shutdown hook (or call `flush()` yourself):

```php
use BugBoard\ClientBuilder;
use BugBoard\Config;

$bugboard = ClientBuilder::create(new Config(
    keyId: getenv('BUGBOARD_KEY_ID') ?: null,
    signingSecret: getenv('BUGBOARD_SIGNING_SECRET') ?: null,
    environment: 'production',
));

$bugboard->minor('Tooltip misaligned', null, 'ui,polish');
$bugboard->flush(); // optional — the shutdown hook flushes automatically
```

`ClientBuilder::create()` accepts an explicit PSR-18 client + PSR-17 factories if you want to
control the HTTP stack (that's also the test seam); otherwise it uses Guzzle when installed and
PSR discovery as the fallback.

## The 16 reporting methods

Every method takes `(string $title, string|\Throwable|null $description = null, array|string $tags = [])`.
The method name sets the card's severity and priority — there is no generic `report()`:

|              | low           | medium (default)              | high           |
| ------------ | ------------- | ----------------------------- | -------------- |
| **critical** | `criticalLow` | `critical` / `criticalMedium` | `criticalHigh` |
| **major**    | `majorLow`    | `major` / `majorMedium`       | `majorHigh`    |
| **moderate** | `moderateLow` | `moderate` / `moderateMedium` | `moderateHigh` |
| **minor**    | `minorLow`    | `minor` / `minorMedium`       | `minorHigh`    |

Most apps only need the four medium-priority methods: `critical`, `major`, `moderate`, `minor`.
Tags accept an array (`['ui', 'android']`) or a CSV string (`'ui,android'`).

## Configuration

Option names mirror the shared SDK specification (identical across the official SDKs):

| Option                 | Type       | Default | Purpose                                                                   |
| ---------------------- | ---------- | ------- | -------------------------------------------------------------------------- |
| `keyId`                | `string`   | —       | Public key id (`bbk_…`) for HMAC auth. Recommended for servers.            |
| `signingSecret`        | `string`   | —       | Signing secret (`bb_sec_…`). Never transmitted.                            |
| `apiKey`               | `string`   | —       | Publishable key (`bb_pub_…`), bearer auth — for browser/mobile keys; rarely right in PHP. |
| `encryptionPublicKey`  | `string`   | —       | Base64 X25519 public key. When set, every payload is encrypted in transit. |
| `encryptionKeyId`      | `string`   | —       | `bbek_…` id echoed in the envelope (enables key rotation).                 |
| `enabled`              | `bool`     | `true`  | Master switch (e.g. disable in tests).                                     |
| `environment`          | `string`   | —       | Added to every card as tag `env:<value>`.                                  |
| `release`              | `string`   | —       | Added to every card as tag `release:<value>`.                              |
| `defaultTags`          | `string[]` | `[]`    | Merged into every card's tags.                                             |
| `sampleRate`           | `float`    | `1.0`   | Probability (0–1) a report is sent.                                        |
| `maxQueueSize`         | `int`      | `100`   | Buffer cap; overflow drops the newest report.                              |
| `timeoutMs`            | `int`      | `5000`  | Per-request timeout.                                                       |
| `maxRetries`           | `int`      | `3`     | Retries for 429/5xx/network errors (backoff + jitter, honors `Retry-After`). |
| `beforeSend`           | `Closure`  | —       | Scrub PII or veto a report — return the payload array, or `null` to drop.  |
| `debug`                | `bool`     | `false` | Verbose internal logging via `error_log` (keys always redacted).           |

`concurrency` and `flushIntervalMs` are accepted for cross-SDK parity; PHP's execution model
delivers sequentially at flush time.

### Scrubbing PII

```php
new Config(
    keyId: …,
    signingSecret: …,
    beforeSend: function (array $payload): ?array {
        $payload['description'] = preg_replace('/\S+@\S+/', '[email]', $payload['description'] ?? '') ?: null;

        return $payload; // or return null to drop the report
    },
);
```

## Encrypting sensitive reports

Set an encryption key and every payload is sealed with a libsodium sealed box (X25519) before it
leaves the server — opaque at proxies and in access logs; BugBoard decrypts on receipt:

```dotenv
BUGBOARD_ENCRYPTION_PUBLIC_KEY=base64-x25519-public-key
BUGBOARD_ENCRYPTION_KEY_ID=bbek_xxxxxxxx
```

Generate the keypair under **Settings → API Keys → Payload encryption**. No extra dependency is
needed — `sodium` ships with PHP.

## Delivery semantics

- **Never blocks, never throws.** Reporting methods buffer and return; delivery happens after
  the response (Laravel `terminating`, or `register_shutdown_function`). Failures surface via
  `error_log` when `debug` is on — never as exceptions in your app.
- **Retries** on 429/5xx/network errors with exponential backoff + jitter, honoring
  `Retry-After`. Other 4xx (bad key, invalid payload) are never retried.
- **Deduplication is server-side**: a report whose title or description exactly matches an
  existing card increments its occurrence count instead of creating a duplicate — use stable,
  deterministic titles (no timestamps or ids in the title).
- **Quota drops are silent by design**: when the project's monthly quota is exhausted the server
  accepts and drops the report — logged in debug mode, never retried.

## Contributing

Bug reports and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please read
our [Code of Conduct](CODE_OF_CONDUCT.md) and report security issues per [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE) © BugBoard
