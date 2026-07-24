# Symfony integration

Supported on Symfony 6.4 and 7.x. These are real files at the paths a Symfony app expects; copy the
parts you need into your project.

**Setup:** enable the bundle in [`config/bundles.php`](./config/bundles.php) and configure it in
[`config/packages/bugboard.yaml`](./config/packages/bugboard.yaml). The bundle registers
`bugboard.client` and aliases `BugBoard\Client` to it, so the client is autowirable anywhere.

## The most important difference from Laravel

The bundle registers the service and **nothing else** — there is no `kernel.terminate` listener out
of the box. Delivery relies on the SDK's own shutdown hook, which under PHP-FPM runs *before* the
response reaches the client, adding latency to the request.

Add [`BugBoardFlushListener`](./src/EventListener/BugBoardFlushListener.php) to get Laravel-like
behavior (flush after the response is sent). Under FrankenPHP worker mode, RoadRunner, or Swoole it
is **not optional** — the process doesn't end per request, so the shutdown hook never fires and this
listener is the only thing that delivers your reports.

| File | What it shows |
| --- | --- |
| [`config/bundles.php`](./config/bundles.php) · [`config/packages/bugboard.yaml`](./config/packages/bugboard.yaml) | Enabling and configuring the bundle |
| [`src/Controller/CheckoutController.php`](./src/Controller/CheckoutController.php) | Reporting via constructor autowiring |
| [`src/EventListener/BugBoardFlushListener.php`](./src/EventListener/BugBoardFlushListener.php) | Flushing after the response (the key listener) |
| [`src/EventListener/ExceptionListener.php`](./src/EventListener/ExceptionListener.php) | Reporting every unhandled exception (skipping 4xx) |
| [`src/EventListener/MessengerFailureListener.php`](./src/EventListener/MessengerFailureListener.php) | Reporting the final failure of a Messenger message |
| [`src/Logging/BugBoardHandler.php`](./src/Logging/BugBoardHandler.php) | Routing existing Monolog `ERROR`+ logs to your board |

These files import `Symfony\…` / `Monolog\…`, so they run inside a Symfony app, not standalone here.
