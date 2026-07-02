<?php

declare(strict_types=1);

namespace BugBoard\Laravel;

use BugBoard\Client;
use BugBoard\ClientBuilder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel integration.
 *
 * Auto-discovered via composer; binds a shared Client singleton configured
 * from `config/bugboard.php`, and flushes buffered reports after the
 * response is sent so reporting never adds latency to a request.
 */
final class BugBoardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/bugboard.php', 'bugboard');

        $this->app->singleton(Client::class, function (Application $app): Client {
            /** @var array<string, mixed> $options */
            $options = (array) $app->make('config')->get('bugboard', []);

            return ClientBuilder::createFromArray($options);
        });

        $this->app->alias(Client::class, 'bugboard');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/bugboard.php' => $this->app->configPath('bugboard.php'),
            ], 'bugboard-config');
        }

        // Deliver buffered reports after the response has gone out. Only
        // flushes when the client was actually used during this request.
        $this->app->terminating(function (Application $app): void {
            if ($app->resolved(Client::class)) {
                $app->make(Client::class)->flush();
            }
        });
    }
}
