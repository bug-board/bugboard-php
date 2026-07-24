# BugBoard PHP — Examples

Focused, copy-paste-ready examples for every way to use `bugboard/sdk` — plain PHP, Laravel, and
Symfony. Each file stands on its own and is heavily commented; read the one that matches your
situation and adapt it.

For the long-form narrative — framework lifecycle details, delivery guarantees, troubleshooting —
see [`../docs/USAGE.md`](../docs/USAGE.md). These examples are the short form.

## Install

```bash
composer require bugboard/sdk
```

You also need a PSR-18 HTTP client. If your project has none, add Guzzle (it gets special treatment
so your `timeoutMs` is honored):

```bash
composer require guzzlehttp/guzzle
```

Each file assumes Composer's autoloader is loaded (`require __DIR__ . '/../vendor/autoload.php';`).
Run the standalone ones directly:

```bash
php examples/03-severity-and-priority.php
```

Set `logLocally: true` (or `enabled: false`) in any example to exercise it without sending real
network traffic — reports are printed instead of delivered.

## Which key do I use?

**On a server, you want a secret key.** A publishable key is designed to be public and write-only,
which makes sense in a browser bundle and very little sense in PHP.

| Key type | Config | Auth |
| --- | --- | --- |
| **Secret** (this is you) | `keyId` + `signingSecret` | HMAC — the signing secret never travels on the wire |
| **Publishable** (rarely PHP) | `apiKey` | Bearer token |

See [`02-credentials.php`](./02-credentials.php).

## The examples

| File | What it shows |
| --- | --- |
| [`01-plain-php-quickstart.php`](./01-plain-php-quickstart.php) | Build a client, report a caught exception, flush without adding request latency |
| [`02-credentials.php`](./02-credentials.php) | Secret key (HMAC) vs publishable key (bearer), and why the secret key is right on a server |
| [`03-severity-and-priority.php`](./03-severity-and-priority.php) | All 16 reporting methods and the description ladder |
| [`04-payload-encryption.php`](./04-payload-encryption.php) | Sealed-box payload encryption (X25519) with `ext-sodium` |
| [`05-before-send-scrubbing.php`](./05-before-send-scrubbing.php) | Scrub PII and drop reports with a `beforeSend` closure |
| [`06-global-exception-handler.php`](./06-global-exception-handler.php) | Automatic capture of uncaught exceptions and fatal errors |
| [`07-cli-worker.php`](./07-cli-worker.php) | Long-running worker: flush per unit of work; `droppedCount()` as a health metric |
| [`08-create-from-array.php`](./08-create-from-array.php) | Build from a loosely-typed config/env array with `createFromArray()` |
| [`09-full-http-control.php`](./09-full-http-control.php) | Pass an explicit Guzzle client + PSR-17 factories (proxy, TLS, real timeout) |
| [`10-quota-store.php`](./10-quota-store.php) | Persist quota suppression across PHP-FPM requests with a `QuotaStore` |
| [`11-laravel/`](./11-laravel/) | Facade/injection, reporting every unhandled exception, failed jobs, scheduled tasks |
| [`12-symfony/`](./12-symfony/) | Bundle config, terminate listener, exception & Messenger listeners, Monolog handler |
| [`13-testing.php`](./13-testing.php) | Assert on reports with a `FakeTransport`; disable or dry-run in tests |

## Two rules that apply to every example

1. **Reporting never throws and returns nothing.** A bug in your `beforeSend`, a full queue, an
   unencodable payload — none of it surfaces as an exception in your code. Failures go to the debug
   log.
2. **Keep titles stable.** Dedup is server-side and matches on the title; put variable data
   (ids, SQL, timestamps) in the description or tags, never the title.
