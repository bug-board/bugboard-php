<?php

declare(strict_types=1);

namespace BugBoard\Tests\Laravel;

use BugBoard\Client;
use BugBoard\Config;
use BugBoard\Laravel\BugBoardServiceProvider;
use BugBoard\Laravel\Facades\BugBoard;
use BugBoard\Tests\Support\CollectingTransport;
use Orchestra\Testbench\TestCase;

final class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BugBoardServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['BugBoard' => BugBoard::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('bugboard.key_id', 'bbk_test');
        $app['config']->set('bugboard.signing_secret', 'bb_sec_test');
    }

    public function test_the_client_is_bound_as_a_singleton(): void
    {
        $client = $this->app->make(Client::class);

        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame($client, $this->app->make(Client::class));
        $this->assertSame($client, $this->app->make('bugboard'));
    }

    public function test_the_config_defaults_are_merged(): void
    {
        $this->assertSame('bbk_test', config('bugboard.key_id'));
        $this->assertSame(1.0, (float) config('bugboard.sample_rate'));
        $this->assertSame(100, (int) config('bugboard.max_queue_size'));
    }

    public function test_the_facade_reports_through_the_shared_client(): void
    {
        $transport = new CollectingTransport;
        $this->app->instance(
            Client::class,
            new Client(new Config(keyId: 'bbk_test', signingSecret: 'bb_sec_test'), $transport),
        );

        BugBoard::criticalHigh('Payment failed', null, ['payment', 'backend']);
        BugBoard::flush();

        $this->assertCount(1, $transport->sent);
        $this->assertSame('critical', $transport->sent[0]->severity);
        $this->assertSame('high', $transport->sent[0]->priority);
        $this->assertSame(['payment', 'backend'], $transport->sent[0]->tags);
    }

    public function test_buffered_reports_are_flushed_when_the_app_terminates(): void
    {
        $transport = new CollectingTransport;
        $this->app->instance(
            Client::class,
            new Client(new Config(keyId: 'bbk_test', signingSecret: 'bb_sec_test'), $transport),
        );

        BugBoard::minor('buffered until terminate');
        $this->assertCount(0, $transport->sent);

        $this->app->terminate();

        $this->assertCount(1, $transport->sent);
    }

    public function test_the_config_is_publishable(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'bugboard-config'])->assertSuccessful();

        $published = $this->app->configPath('bugboard.php');
        $this->assertFileExists($published);
        unlink($published);
    }
}
