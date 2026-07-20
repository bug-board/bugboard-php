<?php

declare(strict_types=1);

namespace BugBoard\Tests;

use BugBoard\Config;
use BugBoard\Logger;
use BugBoard\Payload;
use BugBoard\QuotaGate;
use BugBoard\QuotaStore;
use BugBoard\Tests\Support\ArrayQuotaStore;
use BugBoard\Tests\Support\FakeHttpClient;
use BugBoard\Transport;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QuotaGateTest extends TestCase
{
    /** A gate whose clock is frozen at epoch 0. */
    private function gate(?QuotaStore $store = null): QuotaGate
    {
        return new QuotaGate(new Logger(false), $store, static fn (): int => 0);
    }

    public function test_it_reads_the_current_dropped_and_reason_contract(): void
    {
        $this->assertSame('quota', QuotaGate::reasonFrom(['dropped' => true, 'reason' => 'quota']));
        $this->assertSame('paused', QuotaGate::reasonFrom(['dropped' => true, 'reason' => 'paused']));
        $this->assertSame('archived', QuotaGate::reasonFrom(['dropped' => true, 'reason' => 'archived']));
    }

    public function test_the_bare_legacy_flag_is_treated_as_a_quota_drop(): void
    {
        // An older server sends only `quota_exceeded`, which never meant
        // anything but a spent allowance.
        $this->assertSame('quota', QuotaGate::reasonFrom(['quota_exceeded' => true]));
    }

    public function test_an_unrecognized_reason_is_not_trusted(): void
    {
        $this->assertSame('unknown', QuotaGate::reasonFrom(['dropped' => true, 'reason' => 'something_new']));
    }

    public function test_a_normal_success_is_not_a_drop(): void
    {
        $this->assertNull(QuotaGate::reasonFrom([]));
        $this->assertNull(QuotaGate::reasonFrom(['deduplicated' => true]));
        $this->assertNull(QuotaGate::reasonFrom(['dropped' => false, 'quota_exceeded' => false]));
    }

    public function test_it_is_open_until_something_arms_it(): void
    {
        $this->assertFalse($this->gate()->shouldDiscard());
    }

    public function test_it_suppresses_reports_once_armed(): void
    {
        $gate = $this->gate();
        $gate->arm('quota');

        $this->assertTrue($gate->shouldDiscard());
        $this->assertTrue($gate->shouldDiscard());
    }

    public function test_a_quota_drop_suppresses_until_the_next_utc_midnight(): void
    {
        // 2026-07-20T09:00:00Z — the pool refills at 2026-07-21T00:00:00Z.
        $now = (int) strtotime('2026-07-20 09:00:00 UTC');
        $gate = new QuotaGate(new Logger(false), null, static function () use (&$now): int {
            return $now;
        });

        $gate->arm('quota');
        $this->assertTrue($gate->shouldDiscard());

        $now = (int) strtotime('2026-07-20 23:59:59 UTC');
        $this->assertTrue($gate->shouldDiscard());

        $now = (int) strtotime('2026-07-21 00:00:00 UTC');
        $this->assertFalse($gate->shouldDiscard());
    }

    public function test_a_lifecycle_drop_suppresses_for_half_an_hour_not_until_midnight(): void
    {
        $now = (int) strtotime('2026-07-20 09:00:00 UTC');
        $gate = new QuotaGate(new Logger(false), null, static function () use (&$now): int {
            return $now;
        });

        $gate->arm('paused');

        $now += 29 * 60;
        $this->assertTrue($gate->shouldDiscard());

        $now += 2 * 60;
        $this->assertFalse($gate->shouldDiscard());
    }

    public function test_it_lets_one_report_through_as_a_probe_once_the_window_passes(): void
    {
        $now = 0;
        $gate = new QuotaGate(new Logger(false), null, static function () use (&$now): int {
            return $now;
        });

        $gate->arm('archived');
        $this->assertTrue($gate->shouldDiscard());

        $now += 31 * 60;
        // The probe goes out...
        $this->assertFalse($gate->shouldDiscard());

        // ...and if nothing changed, the server's response re-arms the gate.
        $gate->arm('archived');
        $this->assertTrue($gate->shouldDiscard());
    }

    public function test_a_longer_closure_extends_a_shorter_one(): void
    {
        $now = (int) strtotime('2026-07-20 09:00:00 UTC');
        $gate = new QuotaGate(new Logger(false), null, static function () use (&$now): int {
            return $now;
        });

        $gate->arm('paused'); // 30 minutes
        $gate->arm('quota');  // until the next UTC midnight, which is further out

        $now += 3600;
        $this->assertTrue($gate->shouldDiscard());
    }

    public function test_a_store_carries_suppression_into_a_new_process(): void
    {
        $now = (int) strtotime('2026-07-20 09:00:00 UTC');
        $store = new ArrayQuotaStore;

        // The request that discovers the drop.
        $first = new QuotaGate(new Logger(false), $store, static function () use (&$now): int {
            return $now;
        });
        $first->arm('quota');

        // A brand-new process — as PHP-FPM builds for the next request — reads
        // the deadline back rather than starting with an open gate.
        $second = new QuotaGate(new Logger(false), $store, static function () use (&$now): int {
            return $now;
        });
        $this->assertTrue($second->shouldDiscard());
    }

    public function test_an_expired_stored_deadline_reopens_the_gate_and_clears_the_store(): void
    {
        $now = (int) strtotime('2026-07-20 09:00:00 UTC');
        $store = new ArrayQuotaStore;
        $store->value = $now - 1;

        $gate = new QuotaGate(new Logger(false), $store, static function () use (&$now): int {
            return $now;
        });

        $this->assertFalse($gate->shouldDiscard());
        $this->assertNull($store->value);
    }

    public function test_a_broken_store_degrades_to_an_open_gate(): void
    {
        $store = new class implements QuotaStore
        {
            public function suppressedUntil(): ?int
            {
                throw new RuntimeException('cache is down');
            }

            public function suppressUntil(int $timestamp): void {}

            public function clear(): void {}
        };

        // A cache outage must never cost the host app its reports.
        $this->assertFalse((new QuotaGate(new Logger(false), $store))->shouldDiscard());
    }

    public function test_the_transport_stops_sending_after_a_drop(): void
    {
        $http = new FakeHttpClient;
        $http->willRespond(new Response(
            200,
            ['Content-Type' => 'application/json'],
            (string) json_encode(['dropped' => true, 'reason' => 'quota'])
        ));

        $factory = new HttpFactory;
        $config = new Config(apiKey: 'bb_pub_test');
        $logger = new Logger(false);
        $transport = new Transport($config, $http, $factory, $factory, $logger, new QuotaGate($logger));

        $payload = Payload::make('major', 'medium', 'SDK smoke test', null, [], $config);

        $transport->send($payload);
        $this->assertCount(1, $http->requests);

        // Every report after the drop is discarded before reaching the network.
        $transport->send($payload);
        $transport->send($payload);
        $this->assertCount(1, $http->requests);
    }

    public function test_the_transport_keeps_sending_when_reports_are_accepted(): void
    {
        $http = new FakeHttpClient;
        $http->willRespond(
            new Response(201, ['Content-Type' => 'application/json'], '{}'),
            new Response(201, ['Content-Type' => 'application/json'], '{}'),
        );

        $factory = new HttpFactory;
        $config = new Config(apiKey: 'bb_pub_test');
        $logger = new Logger(false);
        $transport = new Transport($config, $http, $factory, $factory, $logger, new QuotaGate($logger));

        $payload = Payload::make('major', 'medium', 'SDK smoke test', null, [], $config);

        $transport->send($payload);
        $transport->send($payload);

        $this->assertCount(2, $http->requests);
    }
}
