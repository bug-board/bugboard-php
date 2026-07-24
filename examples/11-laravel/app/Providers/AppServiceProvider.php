<?php

declare(strict_types=1);

namespace App\Providers;

use BugBoard\Client;
use BugBoard\ClientBuilder;
use BugBoard\Laravel\Facades\BugBoard;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * A `beforeSend` scrubber that survives `config:cache`.
     *
     * `before_send` is a closure, so it can't live in a cached config file
     * (`php artisan config:cache` can't serialize it). Bind a customized client
     * here instead — this provider runs after the package's in
     * bootstrap/providers.php, so your binding wins. The terminating flush still
     * applies; it resolves whatever is bound to Client::class.
     */
    public function register(): void
    {
        $this->app->singleton(Client::class, fn ($app) => ClientBuilder::createFromArray([
            ...$app['config']->get('bugboard'),
            'before_send' => fn (array $p): ?array => $this->scrub($p),
        ]));
    }

    /**
     * Report failed queue jobs.
     */
    public function boot(): void
    {
        Queue::failing(function (JobFailed $event) {
            // resolveName() is the job class — stable, so repeated failures of the
            // same job dedupe into one card with a rising occurrence count.
            BugBoard::major(
                'Queue job failed: ' . $event->job->resolveName(),
                $event->exception,
                ['queue', $event->connectionName],
            );
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function scrub(array $payload): ?array
    {
        $payload['description'] = preg_replace(
            '/[\w.+-]+@[\w-]+\.[\w.]+/',
            '[email]',
            $payload['description'] ?? ''
        ) ?: null;

        return $payload;
    }
}
