<?php

declare(strict_types=1);

namespace BugBoard\Tests;

use BugBoard\Config;
use BugBoard\Exceptions\AuthException;
use BugBoard\Exceptions\RateLimitException;
use BugBoard\Exceptions\ServerException;
use BugBoard\Exceptions\ValidationException;
use BugBoard\Logger;
use BugBoard\Payload;
use BugBoard\Tests\Support\FakeHttpClient;
use BugBoard\Tests\Support\FakeNetworkException;
use BugBoard\Transport;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient;
    }

    private function transport(Config $config): Transport
    {
        $factory = new HttpFactory;

        return new Transport($config, $this->http, $factory, $factory, new Logger(false));
    }

    private function payload(): Payload
    {
        return Payload::make('major', 'medium', 'SDK smoke test', null, [], new Config(apiKey: 'k'));
    }

    /** @param array<string, mixed> $body */
    private function response(int $status, array $body = [], array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, (string) json_encode($body));
    }

    public function test_it_posts_json_with_bearer_auth(): void
    {
        $this->http->willRespond($this->response(201, ['data' => ['id' => 1]]));

        $this->transport(new Config(apiKey: 'bb_pub_test'))->send($this->payload());

        $request = $this->http->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://bugboard.dev/api/v1/tasks', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('Bearer bb_pub_test', $request->getHeaderLine('Authorization'));
        $this->assertSame($this->payload()->toJson(), $this->http->bodies[0]);
    }

    public function test_it_signs_requests_with_a_secret_key(): void
    {
        $this->http->willRespond($this->response(201));

        $this->transport(new Config(keyId: 'bbk_x', signingSecret: 'bb_sec_x'))->send($this->payload());

        $request = $this->http->requests[0];
        $timestamp = $request->getHeaderLine('X-Bb-Timestamp');
        $expected = hash_hmac(
            'sha256',
            $timestamp.'.POST./api/v1/tasks.'.hash('sha256', $this->http->bodies[0]),
            'bb_sec_x',
        );

        $this->assertSame('bbk_x', $request->getHeaderLine('X-Bb-Key-Id'));
        $this->assertSame($expected, $request->getHeaderLine('X-Bb-Signature'));
        $this->assertSame('', $request->getHeaderLine('Authorization'));
    }

    public function test_it_retries_5xx_and_eventually_succeeds(): void
    {
        $this->http->willRespond(
            $this->response(503, ['message' => 'down']),
            $this->response(201),
        );

        $this->transport(new Config(apiKey: 'k'))->send($this->payload());

        $this->assertCount(2, $this->http->requests);
    }

    public function test_it_retries_network_errors(): void
    {
        $this->http->willRespond(
            new FakeNetworkException(new Request('POST', 'https://bugboard.dev/api/v1/tasks')),
            $this->response(201),
        );

        $this->transport(new Config(apiKey: 'k'))->send($this->payload());

        $this->assertCount(2, $this->http->requests);
    }

    public function test_it_honors_retry_after_on_429(): void
    {
        $this->http->willRespond(
            $this->response(429, ['message' => 'slow down'], ['Retry-After' => '0']),
            $this->response(201),
        );

        $start = microtime(true);
        $this->transport(new Config(apiKey: 'k'))->send($this->payload());

        $this->assertCount(2, $this->http->requests);
        // Retry-After: 0 means no default backoff sleep was applied.
        $this->assertLessThan(0.4, microtime(true) - $start);
    }

    public function test_it_gives_up_after_max_retries(): void
    {
        $this->http->willRespond(
            $this->response(500, ['message' => 'kaput']),
            $this->response(500, ['message' => 'kaput']),
        );

        $this->expectException(ServerException::class);

        try {
            $this->transport(new Config(apiKey: 'k', maxRetries: 1))->send($this->payload());
        } finally {
            $this->assertCount(2, $this->http->requests); // initial attempt + 1 retry
        }
    }

    public function test_it_never_retries_auth_failures(): void
    {
        $this->http->willRespond($this->response(401, ['message' => 'Invalid or missing project API key.']));

        try {
            $this->transport(new Config(apiKey: 'k'))->send($this->payload());
            $this->fail('Expected AuthException.');
        } catch (AuthException $exception) {
            $this->assertSame('Invalid or missing project API key.', $exception->getMessage());
        }

        $this->assertCount(1, $this->http->requests);
    }

    public function test_it_never_retries_validation_failures_and_carries_field_errors(): void
    {
        $this->http->willRespond(
            $this->response(422, ['message' => 'invalid', 'errors' => ['title' => ['Too long.']]]),
        );

        try {
            $this->transport(new Config(apiKey: 'k'))->send($this->payload());
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame(['title' => ['Too long.']], $exception->fieldErrors);
        }

        $this->assertCount(1, $this->http->requests);
    }

    public function test_a_429_that_exhausts_retries_carries_retry_after(): void
    {
        $this->http->willRespond($this->response(429, ['message' => 'limited'], ['Retry-After' => '3']));

        try {
            $this->transport(new Config(apiKey: 'k', maxRetries: 0))->send($this->payload());
            $this->fail('Expected RateLimitException.');
        } catch (RateLimitException $exception) {
            $this->assertSame(3, $exception->retryAfter);
        }
    }

    public function test_a_quota_drop_is_success_and_is_never_retried(): void
    {
        $this->http->willRespond($this->response(200, ['quota_exceeded' => true]));

        $this->transport(new Config(apiKey: 'k'))->send($this->payload());

        $this->assertCount(1, $this->http->requests);
    }

    public function test_encryption_seals_the_body_and_signs_the_envelope(): void
    {
        if (! function_exists('sodium_crypto_box_seal')) {
            $this->markTestSkipped('ext-sodium is not available.');
        }

        $keyPair = sodium_crypto_box_keypair();
        $this->http->willRespond($this->response(201));

        $config = new Config(
            keyId: 'bbk_x',
            signingSecret: 'bb_sec_x',
            encryptionPublicKey: base64_encode(sodium_crypto_box_publickey($keyPair)),
            encryptionKeyId: 'bbek_x',
        );
        $this->transport($config)->send($this->payload());

        $wireBody = $this->http->bodies[0];
        $this->assertStringContainsString('"encrypted"', $wireBody);
        $this->assertStringNotContainsString('SDK smoke test', $wireBody);

        // The signature covers the envelope bytes (encrypt first, then sign).
        $request = $this->http->requests[0];
        $expected = hash_hmac(
            'sha256',
            $request->getHeaderLine('X-Bb-Timestamp').'.POST./api/v1/tasks.'.hash('sha256', $wireBody),
            'bb_sec_x',
        );
        $this->assertSame($expected, $request->getHeaderLine('X-Bb-Signature'));

        // And BugBoard (holding the private key) can recover the original payload.
        /** @var array{encrypted: array{ciphertext: string}} $envelope */
        $envelope = json_decode($wireBody, true);
        $opened = sodium_crypto_box_seal_open(
            (string) base64_decode($envelope['encrypted']['ciphertext'], true),
            $keyPair,
        );
        $this->assertSame($this->payload()->toJson(), $opened);
    }
}
