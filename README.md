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
- Any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client plus PSR-17 factories. Guzzle is
  the recommendation (`composer require guzzlehttp/guzzle`); if you don't pick one,
  `php-http/discovery` finds whatever PSR-18 client your project already has.
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
use BugBoard\Client as BugBoardClient;

public function store(Request $request, BugBoardClient $bugboard)
{
    $bugboard->moderate('Slow image upload', null, 'uploads');
}
```

Buffered reports are delivered when the app **terminates** — after the response has gone out —
so reporting never adds latency to a request. To customize defaults, publish the config:

```bash
php artisan vendor:publish --tag=bugboard-config
```

A good default for `config/bugboard.php` is already provided: every option it exposes is env-driven
(`BUGBOARD_ENABLED`, `BUGBOARD_SAMPLE_RATE`, `BUGBOARD_DEBUG`, …) and cards are tagged with your
`APP_ENV` out of the box.

`beforeSend` is the one option env can't express — it's a closure, so add it to the published config
file directly:

```php
// config/bugboard.php
'before_send' => function (array $payload): ?array {
    $payload['description'] = preg_replace('/\S+@\S+/', '[email]', $payload['description'] ?? '') ?: null;

    return $payload; // or return null to drop the report
},
```

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
use BugBoard\Client as BugBoardClient;

public function __construct(private readonly BugBoardClient $bugboard) {}

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
control the HTTP stack; otherwise it uses Guzzle when installed and PSR discovery as the fallback.

If your config already lives in an array (from a config file, a container, `.env` parsing), skip the
`Config` constructor — `ClientBuilder::createFromArray()` takes `snake_case` **or** `camelCase` keys
and is exactly what the Laravel and Symfony integrations use internally:

```php
$bugboard = ClientBuilder::createFromArray([
    'key_id' => getenv('BUGBOARD_KEY_ID') ?: null,
    'signing_secret' => getenv('BUGBOARD_SIGNING_SECRET') ?: null,
    'environment' => 'production',
    'sample_rate' => 0.5,
]);
```

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
Tags accept an array (`['ui', 'checkout']`) or a CSV string (`'ui,checkout'`).

`$client->droppedCount()` reports how many reports the buffer discarded (see `maxQueueSize` below) —
useful as a health metric if you sample heavily or report in tight loops.

## Configuration

Every option, its type, and its default:

| Option                | Type       | Default | Purpose                                                                                   |
| --------------------- | ---------- | ------- | ----------------------------------------------------------------------------------------- |
| `keyId`               | `string`   | —       | Public key id (`bbk_…`) for HMAC auth. Recommended for servers.                           |
| `signingSecret`       | `string`   | —       | Signing secret (`bb_sec_…`). Never transmitted.                                           |
| `apiKey`              | `string`   | —       | Publishable key (`bb_pub_…`), bearer auth — a client-side key; rarely right in PHP.       |
| `encryptionPublicKey` | `string`   | —       | Base64 X25519 public key. When set, every payload is encrypted in transit.                |
| `encryptionKeyId`     | `string`   | —       | `bbek_…` id echoed in the envelope (enables key rotation).                                |
| `enabled`             | `bool`     | `true`  | Master switch (e.g. disable in tests).                                                    |
| `environment`         | `string`   | —       | Added to every card as tag `env:<value>`.                                                 |
| `release`             | `string`   | —       | Added to every card as tag `release:<value>`.                                             |
| `defaultTags`         | `string[]` | `[]`    | Merged into every card's tags.                                                            |
| `sampleRate`          | `float`    | `1.0`   | Probability (0–1) a report is sent.                                                       |
| `maxQueueSize`        | `int`      | `100`   | Buffer cap; overflow drops the newest report.                                             |
| `timeoutMs`           | `int`      | `5000`  | Per-request timeout.                                                                      |
| `maxRetries`          | `int`      | `3`     | Retries for 429/5xx/network errors (backoff + jitter, honors `Retry-After`).              |
| `beforeSend`          | `Closure`  | —       | Scrub PII or veto a report — return the payload array, or `null` to drop.                 |
| `debug`               | `bool`     | `false` | Verbose internal logging via `error_log` (keys always redacted).                          |
| `logLocally`          | `bool`     | `false` | Log each report locally instead of sending it (dry run).                                  |
| `captureLocation`     | `bool`     | `true`  | Auto-capture the caller's file/line as `file_name`/`line_number`.                         |
| `hideApiResponse`     | `bool`     | `true`  | Ask the server to omit the card from its response (not echoed back).                      |

`concurrency` and `flushIntervalMs` are accepted but have no effect: PHP's execution model
delivers reports sequentially at flush time.

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

### Exceptions

Delivery failures are caught inside the SDK and surfaced on the debug log — **they are never thrown
into your app**. The taxonomy in `BugBoard\Exceptions` exists so that a custom `TransportInterface`,
or your own logging around it, can tell the cases apart:

| Exception             | Raised on                      | Extra                                          |
| --------------------- | ------------------------------ | ---------------------------------------------- |
| `BugBoardException`   | base class for the four below  | —                                              |
| `AuthException`       | 401 / 403 — bad or revoked key | —                                              |
| `ValidationException` | 422 — the payload was rejected | `$fieldErrors` (`array<string, list<string>>`) |
| `RateLimitException`  | 429 — too many reports         | `$retryAfter` (`?int`, seconds)                |
| `ServerException`     | 5xx, network failure, timeout  | —                                              |

## Testing

Disable reporting entirely with `enabled: false`, or keep the client live but print reports instead
of sending them with `logLocally: true`.

To assert on what your code reported, inject a fake transport — `TransportInterface` is a
single-method seam:

```php
use BugBoard\Client;
use BugBoard\Config;
use BugBoard\Payload;
use BugBoard\TransportInterface;

$transport = new class implements TransportInterface
{
    /** @var list<Payload> */
    public array $sent = [];

    public function send(Payload $payload): void
    {
        $this->sent[] = $payload;
    }
};

$bugboard = new Client(new Config(keyId: 'bbk_test', signingSecret: 'bb_sec_test'), $transport);
$bugboard->critical('Payment failed');
$bugboard->flush();

// $transport->sent[0]->severity === 'critical'
```

## Contributing

Bug reports and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please read
our [Code of Conduct](CODE_OF_CONDUCT.md) and report security issues per [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE) © BugBoard
