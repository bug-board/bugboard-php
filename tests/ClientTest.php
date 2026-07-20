<?php

declare(strict_types=1);

namespace BugBoard\Tests;

use BadMethodCallException;
use BugBoard\Client;
use BugBoard\Config;
use BugBoard\Payload;
use BugBoard\Tests\Support\ArrayableRequest;
use BugBoard\Tests\Support\CollectingTransport;
use BugBoard\Tests\Support\ThrowingSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClientTest extends TestCase
{
    private function client(?Config $config = null, ?CollectingTransport $transport = null): Client
    {
        return new Client(
            $config ?? new Config(keyId: 'bbk_test', signingSecret: 'bb_sec_test'),
            $transport ?? new CollectingTransport,
        );
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function methodMatrix(): array
    {
        return [
            ['critical', 'critical', 'medium'],
            ['criticalLow', 'critical', 'low'],
            ['criticalMedium', 'critical', 'medium'],
            ['criticalHigh', 'critical', 'high'],
            ['major', 'major', 'medium'],
            ['majorLow', 'major', 'low'],
            ['majorMedium', 'major', 'medium'],
            ['majorHigh', 'major', 'high'],
            ['moderate', 'moderate', 'medium'],
            ['moderateLow', 'moderate', 'low'],
            ['moderateMedium', 'moderate', 'medium'],
            ['moderateHigh', 'moderate', 'high'],
            ['minor', 'minor', 'medium'],
            ['minorLow', 'minor', 'low'],
            ['minorMedium', 'minor', 'medium'],
            ['minorHigh', 'minor', 'high'],
        ];
    }

    #[DataProvider('methodMatrix')]
    public function test_each_reporting_method_maps_to_its_severity_and_priority(
        string $method,
        string $severity,
        string $priority,
    ): void {
        $transport = new CollectingTransport;
        $client = $this->client(transport: $transport);

        $client->{$method}('Something happened');
        $client->flush();

        $this->assertCount(1, $transport->sent);
        $this->assertSame($severity, $transport->sent[0]->severity);
        $this->assertSame($priority, $transport->sent[0]->priority);
        $this->assertSame('Something happened', $transport->sent[0]->title);
    }

    public function test_description_and_tags_pass_through(): void
    {
        $transport = new CollectingTransport;
        $client = $this->client(transport: $transport);

        $client->major('Checkout is slow', 'p95 went from 2s to 9s', 'checkout,perf');
        $client->flush();

        $this->assertSame('p95 went from 2s to 9s', $transport->sent[0]->description);
        $this->assertSame(['checkout', 'perf'], $transport->sent[0]->tags);
    }

    public function test_non_string_descriptions_reach_the_transport_instead_of_being_swallowed(): void
    {
        $transport = new CollectingTransport;
        $client = $this->client(transport: $transport);

        // Before descriptions were typed mixed, this raised a TypeError that
        // Client::__call() swallowed into a debug-gated log line — the report
        // shipped with its description silently missing.
        $client->critical('Test', ['user_id' => 42, 'cart' => ['a', 'b']]);
        $client->flush();

        $this->assertCount(1, $transport->sent);
        $this->assertStringContainsString('"user_id": 42', (string) $transport->sent[0]->description);
    }

    public function test_an_arrayable_request_is_reported_as_its_input(): void
    {
        $transport = new CollectingTransport;
        $client = $this->client(transport: $transport);

        $client->critical('Test', new ArrayableRequest(['coupon' => 'SAVE10']));
        $client->flush();

        $this->assertSame("{\n  \"coupon\": \"SAVE10\"\n}", $transport->sent[0]->description);
    }

    public function test_a_before_send_hook_may_return_a_non_string_description(): void
    {
        $transport = new CollectingTransport;
        $config = new Config(
            keyId: 'bbk_test',
            signingSecret: 'bb_sec_test',
            beforeSend: static function (array $payload): array {
                $payload['description'] = ['redacted' => true];

                return $payload;
            },
        );

        $client = $this->client($config, $transport);
        $client->major('Login failed', 'user-42');
        $client->flush();

        $this->assertStringContainsString('"redacted": true', (string) $transport->sent[0]->description);
    }

    public function test_captures_the_caller_file_and_line_by_default(): void
    {
        $transport = new CollectingTransport;
        $client = $this->client(transport: $transport);

        $line = __LINE__ + 1;
        $client->criticalHigh('Payment error');
        $client->flush();

        $this->assertSame(__FILE__, $transport->sent[0]->fileName);
        $this->assertSame($line, $transport->sent[0]->lineNumber);
    }

    public function test_captures_the_caller_from_inside_a_catch(): void
    {
        $transport = new CollectingTransport;
        $client = $this->client(transport: $transport);

        try {
            throw new RuntimeException('boom');
        } catch (RuntimeException) {
            $line = __LINE__ + 1;
            $client->critical('caught');
        }
        $client->flush();

        $this->assertSame(__FILE__, $transport->sent[0]->fileName);
        $this->assertSame($line, $transport->sent[0]->lineNumber);
    }

    public function test_call_site_is_omitted_when_capture_location_is_off(): void
    {
        $transport = new CollectingTransport;
        $client = $this->client(
            new Config(keyId: 'k', signingSecret: 's', captureLocation: false),
            $transport,
        );

        $client->criticalHigh('Payment error');
        $client->flush();

        $this->assertNull($transport->sent[0]->fileName);
        $this->assertNull($transport->sent[0]->lineNumber);
    }

    public function test_call_site_survives_the_before_send_round_trip(): void
    {
        $transport = new CollectingTransport;
        $config = new Config(
            keyId: 'k',
            signingSecret: 's',
            // A hook that returns the payload untouched still round-trips through fromArray().
            beforeSend: static fn (array $payload): array => $payload,
        );

        $client = $this->client($config, $transport);
        $line = __LINE__ + 1;
        $client->criticalHigh('Payment error');
        $client->flush();

        $this->assertSame(__FILE__, $transport->sent[0]->fileName);
        $this->assertSame($line, $transport->sent[0]->lineNumber);
    }

    public function test_reports_are_buffered_until_flush(): void
    {
        $transport = new CollectingTransport;
        $client = $this->client(transport: $transport);

        $client->minor('buffered');
        $this->assertCount(0, $transport->sent);

        $client->flush();
        $this->assertCount(1, $transport->sent);

        $client->flush();
        $this->assertCount(1, $transport->sent); // nothing left to deliver
    }

    public function test_unknown_methods_raise_a_bad_method_call(): void
    {
        $this->expectException(BadMethodCallException::class);

        /** @phpstan-ignore-next-line intentionally calling an undefined method */
        $this->client()->criticalUrgent('nope');
    }

    public function test_nothing_is_sent_when_disabled(): void
    {
        $transport = new CollectingTransport;
        $client = $this->client(new Config(keyId: 'k', signingSecret: 's', enabled: false), $transport);

        $client->critical('nope');
        $client->flush();

        $this->assertCount(0, $transport->sent);
    }

    public function test_nothing_is_sent_without_credentials(): void
    {
        $transport = new CollectingTransport;
        $client = $this->client(new Config, $transport);

        $client->critical('nope');
        $client->flush();

        $this->assertCount(0, $transport->sent);
    }

    public function test_a_sample_rate_of_zero_drops_everything(): void
    {
        $transport = new CollectingTransport;
        $client = $this->client(new Config(keyId: 'k', signingSecret: 's', sampleRate: 0.0), $transport);

        for ($i = 0; $i < 20; $i++) {
            $client->minor('sampled out');
        }
        $client->flush();

        $this->assertCount(0, $transport->sent);
    }

    public function test_before_send_can_scrub_the_payload(): void
    {
        $transport = new CollectingTransport;
        $config = new Config(
            keyId: 'k',
            signingSecret: 's',
            beforeSend: function (array $payload): array {
                $payload['description'] = str_replace('user-42', '[user]', (string) $payload['description']);

                return $payload;
            },
        );

        $client = $this->client($config, $transport);
        $client->major('Login failed', 'user-42 could not log in');
        $client->flush();

        $this->assertSame('[user] could not log in', $transport->sent[0]->description);
    }

    public function test_before_send_can_drop_a_report(): void
    {
        $transport = new CollectingTransport;
        $config = new Config(keyId: 'k', signingSecret: 's', beforeSend: static fn (array $payload): ?array => null);

        $client = $this->client($config, $transport);
        $client->major('vetoed');
        $client->flush();

        $this->assertCount(0, $transport->sent);
    }

    public function test_overflow_drops_the_newest_report_and_counts_it(): void
    {
        $transport = new CollectingTransport;
        $client = $this->client(new Config(keyId: 'k', signingSecret: 's', maxQueueSize: 2), $transport);

        $client->minor('a');
        $client->minor('b');
        $client->minor('overflow');
        $client->flush();

        $this->assertSame(['a', 'b'], array_map(static fn (Payload $p): string => $p->title, $transport->sent));
        $this->assertSame(1, $client->droppedCount());
    }

    public function test_reporting_never_throws_even_when_everything_is_broken(): void
    {
        $client = $this->client(
            new Config(keyId: 'k', signingSecret: 's', beforeSend: static function (): ?array {
                throw new RuntimeException('user bug');
            }),
        );

        $client->critical('still safe', new RuntimeException('cause'));

        // Every pathological description the ladder has to survive. A throw in
        // describe() would be caught by __call() and silently drop the report.
        $handle = fopen('php://memory', 'r');
        $cyclic = ['a' => 1];
        $cyclic['self'] = &$cyclic;

        foreach ([$handle, $cyclic, new ThrowingSerializer, NAN, false, static fn () => 1] as $value) {
            $client->critical('still safe', $value);
        }

        fclose($handle);

        $failing = $this->client(transport: new CollectingTransport(failing: true));
        $failing->critical('boom');
        $failing->flush(); // delivery fails internally, but never throws

        $this->addToAssertionCount(1);
    }
}
