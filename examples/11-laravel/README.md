# Laravel integration

Tested against Laravel 11 and 12. These are real files laid out at the paths a Laravel app expects;
copy the parts you need into your project.

**Setup is nearly nothing.** The package auto-discovers, binds a shared `Client` singleton, aliases
it as `bugboard`, and defaults `environment` to `APP_ENV`. Add credentials and you're done:

```dotenv
BUGBOARD_KEY_ID=bbk_xxxxxxxx
BUGBOARD_SIGNING_SECRET=bb_sec_xxxxxxxx
```

**Delivery is free.** The service provider registers an `$app->terminating()` callback, so buffered
reports go out *after* the response reaches the browser — zero request latency. Queued jobs and
Artisan commands terminate too, so their reports are delivered the same way (no manual flush loop).

| File | What it shows |
| --- | --- |
| [`app/Http/Controllers/CheckoutController.php`](./app/Http/Controllers/CheckoutController.php) | The three reporting styles: facade, injection, container alias |
| [`bootstrap/app.php`](./bootstrap/app.php) | Reporting every unhandled exception, with a filter and class-based severity |
| [`app/Providers/AppServiceProvider.php`](./app/Providers/AppServiceProvider.php) | Failed-job reporting, plus a `beforeSend` scrubber that survives `config:cache` |
| [`routes/console.php`](./routes/console.php) | Reporting a failed scheduled task |

Disable locally / in tests with `BUGBOARD_ENABLED=false`, or keep it live and print instead of send
with `BUGBOARD_LOG_LOCALLY=true`. See [`../13-testing.php`](../13-testing.php) for asserting on reports.

These files import `Illuminate\…`, so they run inside a Laravel app, not standalone here.
