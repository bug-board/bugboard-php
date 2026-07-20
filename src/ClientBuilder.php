<?php

declare(strict_types=1);

namespace BugBoard;

use GuzzleHttp\Client as GuzzleClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Wires a Client from a Config.
 *
 * By default the HTTP stack is auto-discovered (any PSR-18 client + PSR-17
 * factories); pass explicit instances to control it — that is also the test
 * seam. When Guzzle is installed it is constructed directly so the
 * configured request timeout is honored.
 *
 * Pass a {@see QuotaStore} to keep quota suppression across requests; without
 * one it lasts only for the life of the process.
 */
final class ClientBuilder
{
    public static function create(
        Config $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?QuotaStore $quotaStore = null,
    ): Client {
        $httpClient ??= self::defaultHttpClient($config);
        $requestFactory ??= Psr17FactoryDiscovery::findRequestFactory();
        $streamFactory ??= Psr17FactoryDiscovery::findStreamFactory();

        $logger = new Logger($config->debug, [$config->signingSecret, $config->apiKey]);

        // One gate shared by both, so the client's pre-buffer check and the
        // transport's pre-send check see the same state.
        $quota = new QuotaGate($logger, $quotaStore);
        $transport = new Transport($config, $httpClient, $requestFactory, $streamFactory, $logger, $quota);

        return new Client($config, $transport, $quota);
    }

    /**
     * Convenience for framework config: build from a plain options array
     * (snake_case or camelCase keys, loosely typed values).
     *
     * @param  array<string, mixed>  $options
     */
    public static function createFromArray(array $options, ?QuotaStore $quotaStore = null): Client
    {
        return self::create(Config::fromArray($options), quotaStore: $quotaStore);
    }

    private static function defaultHttpClient(Config $config): ClientInterface
    {
        if (class_exists(GuzzleClient::class)) {
            return new GuzzleClient([
                'timeout' => $config->timeoutMs / 1000,
                'connect_timeout' => $config->timeoutMs / 1000,
                'http_errors' => false,
            ]);
        }

        return Psr18ClientDiscovery::find();
    }
}
