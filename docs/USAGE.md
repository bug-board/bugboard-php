# BugBoard PHP — Usage Guide

A complete guide to installing, configuring, and using `bugboard/sdk` in **plain PHP**, **Laravel**,
and **Symfony**.

The [README](../README.md) is the quick start. This document is the long form: how the client is
wired in each framework, where reports actually get delivered in each request lifecycle, and the
gotchas specific to each one.

## Contents

- [Core concepts](#core-concepts) — read this first, everything else builds on it
- [Installation](#installation)
- [Credentials](#credentials)
- [Plain PHP](#plain-php)
- [Laravel](#laravel)
- [Symfony](#symfony)
- [Configuration reference](#configuration-reference)
- [Payload encryption](#payload-encryption)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)

---

## Core concepts

Four facts explain nearly everything about how this SDK behaves.

**1. There is no `report()` method — the method name is the classification.**

The client exposes exactly 16 methods, one per severity×priority pair. A bare severity name is the
medium-priority variant:

|              | low           | medium (default)              | high           |
| ------------ | ------------- | ----------------------------- | -------------- |
| **critical** | `criticalLow` | `critical` / `criticalMedium` | `criticalHigh` |
| **major**    | `majorLow`    | `major` / `majorMedium`       | `majorHigh`    |
| **moderate** | `moderateLow` | `moderate` / `moderateMedium` | `moderateHigh` |
| **minor**    | `minorLow`    | `minor` / `minorMedium`       | `minorHigh`    |

Every one takes the same arguments:

```php
$bugboard->criticalHigh(
    'Payment capture failed',        // string  — required, clamped to 255 chars
    $exception,                      // string|Throwable|null — optional
    ['payments', 'stripe'],          // array|string (CSV) — optional
);
```

Most applications only ever use the four medium methods: `critical`, `major`, `moderate`, `minor`.

**2. Reporting is fire-and-forget and never throws.**

A reporting call validates, clamps, and buffers the report, then returns. It does not perform I/O.
A monitoring SDK must not crash the app it monitors, so `Client::__call()` wraps the whole pipeline
in a `try`/`catch` backstop — a bug in your `beforeSend`, an unencodable payload, a full queue, none
of it surfaces as an exception in your code. Failures go to the debug log instead
([`Client.php:81-87`](../src/Client.php#L81-L87)).

**3. Delivery happens at flush time, not at call time.**

Buffered reports are delivered when someone calls `flush()`. The first buffered report registers a
`register_shutdown_function` hook ([`Client.php:161-169`](../src/Client.php#L161-L169)), so in
practice flushing is automatic — but *when* the shutdown hook runs relative to your response differs
per framework, which is the single most important thing to get right in each section below.

**4. Deduplication is server-side, so titles must be stable.**

A report whose title or description exactly matches an existing card increments that card's
occurrence count instead of creating a new card. This only works if your titles are deterministic:

```php
// Good — one card, occurrence count climbs
$bugboard->major('Stripe webhook signature verification failed');

// Bad — a new card per request, forever
$bugboard->major("Stripe webhook {$request->id} failed at " . now());
```

Put the variable parts in the description or tags, never in the title.

---

## Installation

```bash
composer require bugboard/sdk
```

**Requirements:**

- PHP 8.2+
- A [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client plus PSR-17 factories
- `ext-sodium`, only if you enable [payload encryption](#payload-encryption) (ships with PHP by
  default on most builds)

### About the HTTP client

`bugboard/sdk` deliberately does not pin an HTTP client. It requires the *virtual* packages
`psr/http-client-implementation` and `psr/http-factory-implementation`, which means Composer will
accept whatever implementation your project already has.

If your project has none, install Guzzle:

```bash
composer require guzzlehttp/guzzle
```

Guzzle gets special treatment: when it is installed,
[`ClientBuilder`](../src/ClientBuilder.php#L51-L62) constructs it directly rather than going through
discovery, so that your configured `timeoutMs` is actually applied to both the connect and the total
timeout. With any other PSR-18 client, discovery returns a client configured however *it* defaults —
`timeoutMs` is not enforced. If you use Symfony's HTTP client, cURL client, or anything else and you
care about the timeout, construct it yourself and pass it in explicitly (see
[Full control over the HTTP stack](#full-control-over-the-http-stack)).

Laravel projects already have Guzzle. Symfony projects usually have `symfony/http-client`, which is
PSR-18 capable via `psr/http-client` — you may want to add Guzzle anyway, or pass an explicit client.

---

## Credentials

BugBoard issues two kinds of key. **On a server, you want a secret key.**

| Key type        | Config                       | Auth                | Use it in                          |
| --------------- | ---------------------------- | ------------------- | ---------------------------------- |
| **Secret**      | `keyId` + `signingSecret`    | HMAC-signed request | Servers — this is you              |
| **Publishable** | `apiKey`                     | Bearer token        | Browser/client code — rarely PHP   |

With a secret key, the signing secret is used only to compute an HMAC over the request body; **it
never travels on the wire** ([`Transport.php:136-153`](../src/Transport.php#L136-L153)). A
publishable key is transmitted as a bearer token on every request — it is designed to be public and
write-only, which makes sense in a browser bundle and makes very little sense in PHP.

Get keys from your BugBoard project under **Settings → API Keys**.

```dotenv
BUGBOARD_KEY_ID=bbk_xxxxxxxx
BUGBOARD_SIGNING_SECRET=bb_sec_xxxxxxxx
```

The SDK picks the scheme from what is set — `keyId` + `signingSecret` → HMAC, otherwise `apiKey` →
bearer, otherwise **none**, and a client with no credentials disables itself with a warning rather
than throwing ([`Config.php:123-136`](../src/Config.php#L123-L136)). If you set both, HMAC wins.

> A missing key is therefore silent unless you look for it. If reports aren't appearing, turn on
> `debug` — that warning is the first thing it prints.

---

## Plain PHP

Use this section for vanilla PHP, Slim, CodeIgniter, CakePHP, WordPress, or any framework without a
dedicated integration.

### Build one client and share it

The client is stateful — it holds the buffer — so build it **once** per process and pass it around.
Building a client per report gives each one its own buffer and its own shutdown hook, which works
but is wasteful.

```php
// src/bugboard.php
use BugBoard\ClientBuilder;
use BugBoard\Config;

function bugboard(): \BugBoard\Client
{
    static $client = null;

    return $client ??= ClientBuilder::create(new Config(
        keyId: getenv('BUGBOARD_KEY_ID') ?: null,
        signingSecret: getenv('BUGBOARD_SIGNING_SECRET') ?: null,
        environment: getenv('APP_ENV') ?: 'production',
        release: getenv('APP_RELEASE') ?: null,
    ));
}
```

```php
require __DIR__ . '/src/bugboard.php';

try {
    $orders->capture($payment);
} catch (\Throwable $e) {
    bugboard()->criticalHigh('Payment capture failed', $e, ['payments', 'checkout']);
}
```

A container-based app should register it as a shared service instead of a static — the point is only
that there is one instance.

### Building from an array

If your configuration already lives in an array — a config file, container parameters, parsed
`.env` — skip the `Config` constructor. `createFromArray()` accepts **snake_case or camelCase** keys
and casts loosely-typed values (the string `"true"`, a numeric string sample rate), which is exactly
what env-file values look like:

```php
use BugBoard\ClientBuilder;

$bugboard = ClientBuilder::createFromArray([
    'key_id'         => getenv('BUGBOARD_KEY_ID') ?: null,
    'signing_secret' => getenv('BUGBOARD_SIGNING_SECRET') ?: null,
    'environment'    => 'production',
    'sample_rate'    => '0.5',   // string is fine, cast to float
    'enabled'        => 'true',  // string is fine, cast to bool
    'default_tags'   => 'api,backend',  // CSV string is fine, split to a list
]);
```

This is the same entry point the Laravel and Symfony integrations use internally. The one option it
cannot express is `beforeSend`, which is a `Closure` — pass a real closure or it is ignored
([`Config.php:114`](../src/Config.php#L114)).

### Delivery: where the shutdown hook fires

In plain PHP, the shutdown hook runs when the script ends — which under PHP-FPM is *before* the
response has been flushed to the client. That means delivering reports adds latency to the request,
and with `maxRetries: 3` and a slow BugBoard response that latency has a ceiling of several seconds.

Under PHP-FPM, hand the response to the user first:

```php
// after you have echoed the response
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

bugboard()->flush(); // now delivery costs the user nothing
```

`fastcgi_finish_request()` closes the connection and lets the worker continue. This is exactly what
Laravel's `terminating` phase does for you, and it's the main thing you're giving up by not using a
framework integration.

If you can't call it (mod_php, some SAPIs), the alternatives are to lower `maxRetries` and
`timeoutMs` so the worst case is bounded, or to accept the latency for what should be a rare path.

### CLI scripts, workers, and daemons

For a **short script**, the shutdown hook is enough — it fires when the script ends, and there is no
response latency to worry about.

For a **long-running worker**, the shutdown hook only fires when the process finally exits, which
could be days. Flush at the end of each unit of work:

```php
while ($job = $queue->pop()) {
    try {
        $job->handle();
    } catch (\Throwable $e) {
        bugboard()->major('Job failed: ' . $job::class, $e, ['queue']);
    } finally {
        bugboard()->flush(); // deliver per job, not per process lifetime
    }
}
```

Without this, a worker that reports faster than it exits will hit `maxQueueSize` (default 100) and
start dropping. `$bugboard->droppedCount()` tells you how many have been dropped and is worth
emitting as a health metric.

### A global exception handler

To get every uncaught error onto your board:

```php
set_exception_handler(function (\Throwable $e): void {
    bugboard()->critical($e->getMessage() ?: $e::class, $e);
});

register_shutdown_function(function (): void {
    $error = error_get_last();

    if ($error !== null && ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        bugboard()->criticalHigh($error['message'], sprintf('%s:%d', $error['file'], $error['line']));
        bugboard()->flush(); // we are already in shutdown; flush explicitly
    }
});
```

Note the explicit `flush()` in the fatal-error handler: the SDK's own shutdown hook may have been
registered before this one, in which case it has already run.

Use `$e->getMessage() ?: $e::class` rather than the message alone — an exception with an empty
message would otherwise produce a card with an empty title.

### Full control over the HTTP stack

`ClientBuilder::create()` takes an optional PSR-18 client and PSR-17 factories. Pass them when you
need a proxy, custom TLS settings, connection pooling, or a non-Guzzle client with a real timeout:

```php
use BugBoard\ClientBuilder;
use BugBoard\Config;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;

$factory = new HttpFactory();

$bugboard = ClientBuilder::create(
    new Config(keyId: '…', signingSecret: '…'),
    new GuzzleClient([
        'timeout' => 3,
        'proxy'   => 'http://proxy.internal:8080',
        'http_errors' => false, // important: the SDK reads status codes itself
    ]),
    $factory, // RequestFactoryInterface
    $factory, // StreamFactoryInterface
);
```

Set `http_errors => false` on Guzzle. The transport maps status codes to its own exception taxonomy
and needs to see the response, not a Guzzle exception.

---

## Laravel

Tested against Laravel 11 and 12. The package declares no `illuminate/*` constraint, so it installs
on older versions too — but only 11 and 12 are covered by the test suite.

### Setup

The package is auto-discovered via `composer.json`'s `extra.laravel` block — **no manual
registration**. The service provider binds a shared `Client` singleton and aliases it as `bugboard`
([`BugBoardServiceProvider.php:25-33`](../src/Laravel/BugBoardServiceProvider.php#L25-L33)).

Add credentials to `.env` and you are done:

```dotenv
BUGBOARD_KEY_ID=bbk_xxxxxxxx
BUGBOARD_SIGNING_SECRET=bb_sec_xxxxxxxx
```

Cards are tagged with your `APP_ENV` automatically — the shipped config defaults `environment` to
`env('APP_ENV')`.

If you have disabled auto-discovery (`dont-discover`), register it by hand in
`bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    BugBoard\Laravel\BugBoardServiceProvider::class,
];
```

### Reporting

Three equivalent styles — pick one and be consistent.

**Facade** (most common):

```php
use BugBoard\Laravel\Facades\BugBoard;

BugBoard::major('Checkout is slow');
BugBoard::critical('Payment failed', $e);
BugBoard::critical('Payment failed', $e, ['payments', 'checkout']);
```

**Injection** (better for testability — you can swap the binding):

```php
use BugBoard\Client as BugBoardClient;

public function store(Request $request, BugBoardClient $bugboard)
{
    $bugboard->moderate('Slow image upload', null, 'uploads');
}
```

**Container alias** (in code where neither fits):

```php
app('bugboard')->minor('Tooltip misaligned');
```

### Delivery: the terminating phase

This is the part that matters. The service provider registers an `$app->terminating()` callback
([`BugBoardServiceProvider.php:45-49`](../src/Laravel/BugBoardServiceProvider.php#L45-L49)), so
buffered reports are delivered **after the response has been sent to the browser**. Reporting adds
zero latency to the request.

The callback is guarded by `$app->resolved(Client::class)` — if nothing touched BugBoard during the
request, the terminating phase does no work at all.

Two consequences worth knowing:

- **Queued jobs and Artisan commands also terminate**, so reports from them are delivered the same
  way. A long-running `queue:work` process terminates the app after each job, so per-job flushing is
  already handled — you do not need the manual `flush()` loop described in the plain-PHP section.
- **Under Octane**, the container persists across requests, so the singleton — and its buffer — is
  reused. Terminating callbacks still run per request, so reports still go out per request. Verify
  with `BUGBOARD_DEBUG=true` on a staging boot if this matters to you; if you ever see reports
  arriving a request late, call `BugBoard::flush()` explicitly at the end of the operation.

### Configuration

Everything is env-driven out of the box. Publish the config file only when you need to change
something env can't express:

```bash
php artisan vendor:publish --tag=bugboard-config
```

That writes [`config/bugboard.php`](../config/bugboard.php), where every key reads from an env var:

```dotenv
BUGBOARD_ENABLED=true
BUGBOARD_ENVIRONMENT="${APP_ENV}"
BUGBOARD_RELEASE=1.4.2
BUGBOARD_DEFAULT_TAGS=api,backend
BUGBOARD_SAMPLE_RATE=1.0
BUGBOARD_MAX_QUEUE_SIZE=100
BUGBOARD_TIMEOUT_MS=5000
BUGBOARD_MAX_RETRIES=3
BUGBOARD_CAPTURE_LOCATION=true
BUGBOARD_DEBUG=false
BUGBOARD_LOG_LOCALLY=false
BUGBOARD_HIDE_API_RESPONSE=true
```

`before_send` is the exception — it's a closure, so it must go in the published file directly:

```php
// config/bugboard.php
'before_send' => function (array $payload): ?array {
    // Scrub emails out of descriptions
    $payload['description'] = preg_replace(
        '/[\w.+-]+@[\w-]+\.[\w.]+/',
        '[email]',
        $payload['description'] ?? ''
    ) ?: null;

    // Drop anything from the health-check path entirely
    if (str_contains($payload['title'], 'HealthCheck')) {
        return null;
    }

    return $payload;
},
```

> **`config:cache` and closures.** `php artisan config:cache` cannot serialize a closure and will
> fail loudly if `before_send` is in a cached config file. If you deploy with config caching (you
> should), don't put the closure in config. Bind a customized client in a service provider instead:
>
> ```php
> // app/Providers/AppServiceProvider.php
> use BugBoard\Client;
> use BugBoard\ClientBuilder;
>
> public function register(): void
> {
>     $this->app->singleton(Client::class, fn ($app) => ClientBuilder::createFromArray([
>         ...$app['config']->get('bugboard'),
>         'before_send' => fn (array $p): ?array => $this->scrub($p),
>     ]));
> }
> ```
>
> Because the package provider registers its singleton in `register()` and yours runs after it in
> `bootstrap/providers.php`, your binding wins. The terminating flush still applies — it resolves
> `Client::class`, whatever is bound to it.

### Reporting every unhandled exception

Laravel 11/12, in `bootstrap/app.php`:

```php
use BugBoard\Laravel\Facades\BugBoard;
use Illuminate\Foundation\Configuration\Exceptions;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(function (\Throwable $e) {
        BugBoard::critical($e->getMessage() ?: $e::class, $e);
    });
})
```

Laravel 10, in `app/Exceptions/Handler.php`:

```php
public function register(): void
{
    $this->reportable(function (\Throwable $e) {
        BugBoard::critical($e->getMessage() ?: $e::class, $e);
    });
}
```

**Filter before you do this.** A public app throws `NotFoundHttpException` and
`ValidationException` constantly, and you don't want those on your board — they'll burn your quota
and bury real bugs:

```php
$exceptions->report(function (\Throwable $e) {
    $ignore = [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Validation\ValidationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
    ];

    foreach ($ignore as $class) {
        if ($e instanceof $class) {
            return;
        }
    }

    // Severity from the class: infrastructure failures outrank the rest
    $method = match (true) {
        $e instanceof \PDOException,
        $e instanceof \Illuminate\Database\QueryException => 'criticalHigh',
        $e instanceof \Illuminate\Http\Client\ConnectionException => 'major',
        default => 'moderate',
    };

    BugBoard::{$method}($e->getMessage() ?: $e::class, $e, ['unhandled']);
});
```

Remember the dedup rule: `$e->getMessage()` for a query exception includes the SQL and bound values,
which vary per request and will create a new card each time. For noisy exception types, prefer a
stable synthetic title:

```php
BugBoard::criticalHigh('Database query failed: ' . $e::class, $e, ['database']);
```

### Failed queue jobs

```php
// app/Providers/AppServiceProvider.php
use BugBoard\Laravel\Facades\BugBoard;
use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\Events\JobFailed;

public function boot(): void
{
    Queue::failing(function (JobFailed $event) {
        BugBoard::major(
            'Queue job failed: ' . $event->job->resolveName(),
            $event->exception,
            ['queue', $event->connectionName],
        );
    });
}
```

`resolveName()` gives the job class, which is stable — so repeated failures of the same job
deduplicate into one card with a rising occurrence count. That's exactly what you want.

### Scheduled tasks

```php
// routes/console.php
Schedule::command('reports:generate')
    ->daily()
    ->onFailure(function () {
        BugBoard::major('Scheduled task failed: reports:generate', null, ['scheduler']);
    });
```

### Disabling it locally and in tests

```dotenv
# .env.local / .env.testing
BUGBOARD_ENABLED=false
```

Or keep the client live and print reports instead of sending them, which is useful when you want to
see *what* you would have reported:

```dotenv
BUGBOARD_LOG_LOCALLY=true
```

See [Testing](#testing) for asserting on reports in a test suite.

---

## Symfony

Supported on Symfony 6.4 and 7.x.

### Setup

Enable the bundle:

```php
// config/bundles.php
return [
    // …
    BugBoard\Symfony\BugBoardBundle::class => ['all' => true],
];
```

Configure it:

```yaml
# config/packages/bugboard.yaml
bugboard:
    key_id: '%env(BUGBOARD_KEY_ID)%'
    signing_secret: '%env(BUGBOARD_SIGNING_SECRET)%'
    environment: '%kernel.environment%'
```

```dotenv
# .env.local
BUGBOARD_KEY_ID=bbk_xxxxxxxx
BUGBOARD_SIGNING_SECRET=bb_sec_xxxxxxxx
```

The bundle registers `bugboard.client` and aliases `BugBoard\Client` to it, so the client is
autowirable anywhere ([`BugBoardBundle.php:53-62`](../src/Symfony/BugBoardBundle.php#L53-L62)).

Per-environment overrides work as usual:

```yaml
# config/packages/dev/bugboard.yaml
bugboard:
    enabled: false          # or: log_locally: true
    debug: true

# config/packages/prod/bugboard.yaml
bugboard:
    sample_rate: 0.25
    release: '%env(APP_RELEASE)%'
```

Run `php bin/console config:dump-reference bugboard` to see the full schema with defaults, and
`config:dump-reference bugboard --format=yaml` for a paste-ready file.

### Reporting

Autowire `BugBoard\Client` by constructor injection:

```php
use BugBoard\Client as BugBoardClient;

final class CheckoutController extends AbstractController
{
    public function __construct(private readonly BugBoardClient $bugboard)
    {
    }

    #[Route('/checkout', methods: ['POST'])]
    public function checkout(Request $request): Response
    {
        try {
            $this->payments->charge($request);
        } catch (\Throwable $e) {
            $this->bugboard->criticalHigh('Payment capture failed', $e, ['payments']);

            return $this->render('checkout/error.html.twig');
        }

        return $this->redirectToRoute('checkout_success');
    }
}
```

The service is `public()`, so `$container->get('bugboard.client')` works too — but autowiring is the
right default.

### Delivery: what the bundle does and does not do

**This is the most important difference from Laravel.** The Symfony bundle registers the service and
nothing else. There is no `kernel.terminate` listener. Delivery relies on the SDK's own
`register_shutdown_function` hook, which under PHP-FPM runs **before the response reaches the
client** — so reporting adds latency to the request, exactly as in the [plain PHP](#plain-php) case.

Add a terminate listener to get Laravel-like behavior. `kernel.terminate` fires after the response
has been sent:

```php
// src/EventListener/BugBoardFlushListener.php
namespace App\EventListener;

use BugBoard\Client as BugBoardClient;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::TERMINATE)]
final class BugBoardFlushListener
{
    public function __construct(private readonly BugBoardClient $bugboard)
    {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $this->bugboard->flush();
    }
}
```

`flush()` on an empty buffer is a no-op, so this costs nothing on requests that reported nothing.
Injecting the client here does instantiate it on every request, which is a negligible cost (the
constructor builds a buffer and a logger) — but if you would rather not, inject a
`ServiceLocator`/`Psr\Container` and only resolve when needed.

> **Worker runtimes.** Under FrankenPHP worker mode, RoadRunner, or Swoole, the process does not end
> per request, so the shutdown hook effectively never fires. A `kernel.terminate` listener is not
> optional there — it is the only thing that delivers your reports.

### Messenger: reporting failed messages

```php
// src/EventListener/MessengerFailureListener.php
namespace App\EventListener;

use BugBoard\Client as BugBoardClient;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener]
final class MessengerFailureListener
{
    public function __construct(private readonly BugBoardClient $bugboard)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return; // only report the final failure, not every attempt
        }

        $this->bugboard->major(
            'Message handling failed: ' . $event->getEnvelope()->getMessage()::class,
            $event->getThrowable(),
            ['messenger'],
        );

        // Workers are long-lived: the shutdown hook won't run for hours.
        $this->bugboard->flush();
    }
}
```

Both details matter. The `willRetry()` guard prevents one flaky message from producing four
identical reports, and the explicit `flush()` is required because a Messenger worker is a
long-running process — see [CLI scripts, workers, and daemons](#cli-scripts-workers-and-daemons).

### Reporting every unhandled exception

```php
// src/EventListener/ExceptionListener.php
namespace App\EventListener;

use BugBoard\Client as BugBoardClient;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[AsEventListener]
final class ExceptionListener
{
    public function __construct(private readonly BugBoardClient $bugboard)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        // 4xx are client mistakes, not your bugs — skip them.
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return;
        }

        $this->bugboard->critical(
            $exception->getMessage() ?: $exception::class,
            $exception,
            ['unhandled'],
        );
    }
}
```

### Monolog: routing existing logs to BugBoard

If you already log errors through Monolog, a custom handler puts them on your board without touching
call sites. Be deliberate about the level — `ERROR` and up is usually right; `WARNING` will flood you.

```php
// src/Logging/BugBoardHandler.php
namespace App\Logging;

use BugBoard\Client as BugBoardClient;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

final class BugBoardHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly BugBoardClient $bugboard,
        Level $level = Level::Error,
    ) {
        parent::__construct($level);
    }

    protected function write(LogRecord $record): void
    {
        $method = match (true) {
            $record->level->value >= Level::Critical->value => 'criticalHigh',
            $record->level->value >= Level::Error->value    => 'major',
            default                                          => 'moderate',
        };

        $this->bugboard->{$method}(
            $record->message,   // stable by design in Monolog — placeholders live in context
            $record->context['exception'] ?? null,
            ['monolog', $record->channel],
        );
    }
}
```

```yaml
# config/packages/prod/monolog.yaml
monolog:
    handlers:
        bugboard:
            type: service
            id: App\Logging\BugBoardHandler
            level: error
```

Monolog's `$record->message` keeps its `{placeholders}` uninterpolated, which is a good fit for
server-side dedup: `'User {id} not found'` is one card, not one per user.

The same pattern works in Laravel with a custom log channel (`'via' => BugBoardLogger::class`).

---

## Configuration reference

Every option, with the constructor (camelCase) name. Array-based construction accepts either
`snake_case` or `camelCase` ([`Config.php:77-120`](../src/Config.php#L77-L120)).

| Option                | Type       | Default                | Purpose                                                                          |
| --------------------- | ---------- | ---------------------- | -------------------------------------------------------------------------------- |
| `keyId`               | `?string`  | —                      | Public key id (`bbk_…`) for HMAC auth. Recommended for servers.                  |
| `signingSecret`       | `?string`  | —                      | Signing secret (`bb_sec_…`). Never transmitted.                                  |
| `apiKey`              | `?string`  | —                      | Publishable key (`bb_pub_…`), bearer auth. Client-side key; rarely right in PHP. |
| `encryptionPublicKey` | `?string`  | —                      | Base64 X25519 public key. When set, every payload is sealed in transit.          |
| `encryptionKeyId`     | `?string`  | —                      | `bbek_…` id echoed in the envelope (enables key rotation).                       |
| `enabled`             | `bool`     | `true`                 | Master switch.                                                                   |
| `captureLocation`     | `bool`     | `true`                 | Auto-capture the caller's file/line as `file_name`/`line_number`.                |
| `environment`         | `?string`  | —                      | Added to every card as tag `env:<value>`.                                        |
| `release`             | `?string`  | —                      | Added to every card as tag `release:<value>`.                                    |
| `defaultTags`         | `string[]` | `[]`                   | Merged into every card's tags. A CSV string is accepted via array config.        |
| `sampleRate`          | `float`    | `1.0`                  | Probability (0–1) a report is sent. Clamped into range.                          |
| `maxQueueSize`        | `int`      | `100`                  | Buffer cap; overflow drops the **newest** report.                                |
| `timeoutMs`           | `int`      | `5000`                 | Per-request timeout. Only honored with Guzzle or an explicit client.             |
| `maxRetries`          | `int`      | `3`                    | Retries for 429/5xx/network errors.                                              |
| `beforeSend`          | `?Closure` | —                      | Scrub or veto: return the payload array, or `null` to drop.                      |
| `debug`               | `bool`     | `false`                | Verbose internal logging via `error_log` (keys always redacted).                 |
| `logLocally`          | `bool`     | `false`                | Log each report instead of sending it (dry run).                                 |
| `hideApiResponse`     | `bool`     | `true`                 | Ask the server to omit the created card from its response.                       |
| `baseUrl`             | `string`   | `https://bugboard.dev` | Ingestion origin override. **Internal — for SDK tests.**                         |

`concurrency` and `flushIntervalMs` are accepted for cross-SDK config parity but have **no effect**
in PHP: there is no background timer and delivery is sequential at flush time.

### Choosing a sample rate

Sampling is per report, evaluated before the payload is even built
([`Client.php:119-125`](../src/Client.php#L119-L125)). Because dedup is server-side, sampling and
dedup interact in a way worth thinking about: at `sampleRate: 0.1`, a bug that happens 1000 times
still reliably produces its card — you just see an occurrence count of ~100. For a bug that happens
*twice*, there's a good chance you see nothing at all.

So: sample when your problem is volume from known-noisy paths, not to save quota generally. Start at
`1.0` and only lower it once you can see what your traffic actually produces.

### `beforeSend` in detail

The closure receives the payload as an array — the exact body about to be sent — and returns it
(mutated or not) or `null` to drop the report:

```php
beforeSend: function (array $payload): ?array {
    // $payload = [
    //     'severity' => 'critical', 'priority' => 'high',
    //     'title' => '…', 'tags' => ['…'],
    //     'description' => '…',      // present only if one was given
    //     'file_name' => '…',        // present only if captureLocation is on
    //     'line_number' => 42,
    // ]

    return $payload;
}
```

The return value is re-validated through `Payload::fromArray()`
([`Payload.php:83-101`](../src/Payload.php#L83-L101)), so a hook cannot produce an invalid request:
an unknown severity falls back to `moderate`, an unknown priority to `medium`, and every length
clamp is re-applied. You do not need to be careful about lengths.

`hideApiResponse` is deliberately *not* in the payload — it's a header, so it stays out of reach of
`beforeSend` and remains readable when the body is encrypted.

Keep the closure fast and total. It runs synchronously inside the reporting call, on your request
path. If it throws, the report is lost and the error goes to the debug log — the backstop catches
it, so your app is unaffected.

---

## Payload encryption

By default, report bodies are plaintext JSON over TLS. That's fine against network eavesdroppers,
but the body is readable at any TLS-terminating proxy, load balancer, or WAF between you and
BugBoard — and sometimes in their access logs.

Set an encryption key and every payload is sealed with a libsodium sealed box (X25519) before it
leaves your server ([`Transport.php:56-58`](../src/Transport.php#L56-L58)). The sealed envelope is
opaque to everything on the wire; only BugBoard holds the private key.

```dotenv
BUGBOARD_ENCRYPTION_PUBLIC_KEY=base64-x25519-public-key
BUGBOARD_ENCRYPTION_KEY_ID=bbek_xxxxxxxx
```

Generate the keypair under **Settings → API Keys → Payload encryption**. The `encryptionKeyId` is
echoed in the envelope so the server knows which key to decrypt with — which is what makes key
rotation possible.

Encryption happens **before** signing, so the HMAC covers the sealed bytes.

This requires `ext-sodium`. It ships with PHP on most builds; if `php -m` doesn't list it, the
README has [full per-platform install instructions](../README.md#installing-php-sodium-for-payload-encryption),
including the classic gotcha that CLI and FPM read different `php.ini` files — `php -m` can show
sodium while your web requests still fail.

---

## Testing

### Turn it off

```php
new Config(enabled: false);
```

```dotenv
# Laravel: .env.testing
BUGBOARD_ENABLED=false
```

```yaml
# Symfony: config/packages/test/bugboard.yaml
bugboard:
    enabled: false
```

`active()` returns false without credentials too, so a test environment with no keys is already
inert ([`Config.php:133-136`](../src/Config.php#L133-L136)). Being explicit is still better —
it documents the intent and survives someone adding keys to CI.

### Assert on what was reported

`TransportInterface` is a single-method seam. Pass a fake to `Client` directly — no HTTP, no
network, no builder:

```php
use BugBoard\Client;
use BugBoard\Config;
use BugBoard\Payload;
use BugBoard\TransportInterface;

final class FakeTransport implements TransportInterface
{
    /** @var list<Payload> */
    public array $sent = [];

    public function send(Payload $payload): void
    {
        $this->sent[] = $payload;
    }
}
```

```php
public function test_it_reports_a_failed_payment(): void
{
    $transport = new FakeTransport();
    $bugboard = new Client(
        new Config(keyId: 'bbk_test', signingSecret: 'bb_sec_test'),
        $transport,
    );

    (new CheckoutService($bugboard))->charge($failingOrder);

    $bugboard->flush(); // reports are buffered until flush

    $this->assertCount(1, $transport->sent);
    $this->assertSame('critical', $transport->sent[0]->severity);
    $this->assertSame('high', $transport->sent[0]->priority);
    $this->assertStringContainsString('Payment', $transport->sent[0]->title);
}
```

**Don't forget the `flush()`.** Reports sit in the buffer until then; without it `$transport->sent`
is empty and the test fails confusingly.

In **Laravel**, bind the fake so the facade and injected clients both use it:

```php
$transport = new FakeTransport();

$this->app->singleton(Client::class, fn () => new Client(
    new Config(keyId: 'bbk_test', signingSecret: 'bb_sec_test'),
    $transport,
));
```

In **Symfony**, override the service in the test container:

```yaml
# config/services_test.yaml
services:
    App\Tests\FakeTransport:
        public: true

    BugBoard\Client:
        public: true
        arguments:
            $config: '@bugboard.test_config'
            $transport: '@App\Tests\FakeTransport'
```

### Dry run

To exercise the real client — config resolution, payload building, `beforeSend` — without sending
anything, use `logLocally: true`. Reports are pretty-printed to the log instead of being
transmitted ([`Transport.php:41-49`](../src/Transport.php#L41-L49)). Useful in staging and while
developing a `beforeSend` scrubber.

---

## Troubleshooting

**Turn on `debug` first.** Nearly every question below is answered by one line of debug output:

```dotenv
BUGBOARD_DEBUG=true
```

Output goes to `error_log` — your PHP error log, `storage/logs/laravel.log` under Laravel's default
setup, or `var/log/dev.log` in Symfony, depending on configuration. Keys are always redacted
([`Client.php:52`](../src/Client.php#L52)), so debug output is safe to paste into an issue.

### Nothing arrives on the board

Work down this list:

1. **No credentials.** Debug prints `No credentials configured…`. `keyId` alone isn't enough — HMAC
   needs both `keyId` *and* `signingSecret`.
2. **Never flushed.** Long-running worker, Symfony without a terminate listener, or a process that
   was killed rather than exiting. Call `flush()` explicitly.
3. **`enabled` is false.** Check the resolved value, not the `.env` file — a stale `config:cache` in
   Laravel is a classic here (`php artisan config:clear`).
4. **Sampled out.** Debug prints `Report sampled out.` — check `sampleRate`.
5. **Dropped by `beforeSend`.** Debug prints `Report dropped by beforeSend.`
6. **Queue full.** Debug prints `Queue full (100); report dropped`. Check `droppedCount()`.
7. **Quota exhausted.** Debug prints `Report dropped: the project's monthly quota is exhausted.` The
   server accepted and dropped it — by design, and never retried.
8. **Auth rejected.** Debug shows a 401/403. Check the key is for the right project and hasn't been
   revoked.

### Reports arrive but the card count doesn't go up

Your titles aren't stable — see [Core concepts](#core-concepts). Interpolated ids, timestamps, or
exception messages containing SQL all produce a new card per occurrence. Move the variable part into
the description.

### The opposite: unrelated errors collapsing into one card

Titles are too generic. `'Request failed'` from four different call sites is one card. Add enough
specificity to distinguish them — the operation, the subsystem — while keeping it deterministic.

### Requests are slow after adding the SDK

You are flushing on the request path. Under Laravel this shouldn't happen (the terminating phase
handles it); under Symfony add the `kernel.terminate` listener; under plain PHP-FPM call
`fastcgi_finish_request()` before flushing. See the delivery sections above.

If you can't move the flush, bound the worst case with `timeoutMs` and `maxRetries` — with the
defaults, a fully unreachable BugBoard costs up to ~4 attempts × 5 s plus backoff.

### Encryption fails with "undefined function sodium_crypto_box_seal"

`ext-sodium` isn't loaded **in the runtime serving your app**. `php -m` reports on the CLI, which is
usually a different `php.ini` than FPM. Check `php-fpm -m | grep sodium` or a `phpinfo()` page, then
restart FPM. Full walkthrough in the [README](../README.md#installing-php-sodium-for-payload-encryption).

### `BadMethodCallException: Call to undefined method`

A typo in a reporting method name. The 16 are generated from the severity×priority table, and this
is the one case where the client *does* throw — deliberately, because it's a programming error found
at the first call, not a runtime failure ([`Client.php:77-79`](../src/Client.php#L77-L79)). Check
the spelling and casing: `criticalHigh`, not `criticalhigh` or `critical_high`.

---

## See also

- [README](../README.md) — quick start and installation
- [BugBoard API reference](https://bugboard.dev/docs/api-reference) — the wire contract
- [CONTRIBUTING.md](../CONTRIBUTING.md) — development setup
