<?php

declare(strict_types=1);

namespace BugBoard\Tests;

use BugBoard\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function test_defaults_match_the_shared_spec(): void
    {
        $config = new Config;

        $this->assertTrue($config->enabled);
        $this->assertSame(1.0, $config->sampleRate);
        $this->assertSame(100, $config->maxQueueSize);
        $this->assertSame(3, $config->concurrency);
        $this->assertSame(2000, $config->flushIntervalMs);
        $this->assertSame(5000, $config->timeoutMs);
        $this->assertSame(3, $config->maxRetries);
        $this->assertFalse($config->debug);
        $this->assertSame('https://bugboard.dev/api/v1/tasks', $config->endpoint);
    }

    public function test_auth_scheme_is_picked_from_the_configured_credentials(): void
    {
        $this->assertSame('bearer', (new Config(apiKey: 'bb_pub_x'))->authScheme());
        $this->assertSame('hmac', (new Config(keyId: 'bbk_x', signingSecret: 'bb_sec_x'))->authScheme());
        $this->assertSame('none', (new Config)->authScheme());
    }

    public function test_secret_key_wins_when_both_credentials_are_set(): void
    {
        $config = new Config(apiKey: 'bb_pub_x', keyId: 'bbk_x', signingSecret: 'bb_sec_x');

        $this->assertSame('hmac', $config->authScheme());
    }

    public function test_active_requires_credentials_and_the_enabled_flag(): void
    {
        $this->assertTrue((new Config(apiKey: 'bb_pub_x'))->active());
        $this->assertFalse((new Config(apiKey: 'bb_pub_x', enabled: false))->active());
        $this->assertFalse((new Config)->active());
    }

    public function test_signing_path_follows_the_endpoint(): void
    {
        $this->assertSame('/api/v1/tasks', (new Config)->path());
        $this->assertSame('/api/v1/tasks', (new Config(endpoint: 'http://127.0.0.1:8080/api/v1/tasks'))->path());
    }

    public function test_sample_rate_is_clamped_into_the_unit_interval(): void
    {
        $this->assertSame(1.0, (new Config(sampleRate: 7.0))->effectiveSampleRate());
        $this->assertSame(0.0, (new Config(sampleRate: -1.0))->effectiveSampleRate());
    }

    public function test_from_array_accepts_snake_case_keys_and_loose_types(): void
    {
        $config = Config::fromArray([
            'key_id' => 'bbk_x',
            'signing_secret' => 'bb_sec_x',
            'enabled' => '1',
            'default_tags' => 'web, api ,web',
            'sample_rate' => '0.25',
            'max_queue_size' => '50',
            'timeout_ms' => '1000',
            'max_retries' => '2',
            'debug' => 'true',
        ]);

        $this->assertSame('hmac', $config->authScheme());
        $this->assertTrue($config->enabled);
        $this->assertSame(['web', 'api'], $config->defaultTags);
        $this->assertSame(0.25, $config->sampleRate);
        $this->assertSame(50, $config->maxQueueSize);
        $this->assertSame(1000, $config->timeoutMs);
        $this->assertSame(2, $config->maxRetries);
        $this->assertTrue($config->debug);
    }

    public function test_from_array_treats_blank_strings_as_absent(): void
    {
        $config = Config::fromArray(['api_key' => '', 'key_id' => '  ', 'signing_secret' => null]);

        $this->assertSame('none', $config->authScheme());
    }

    public function test_from_array_accepts_camel_case_keys(): void
    {
        $config = Config::fromArray(['apiKey' => 'bb_pub_x', 'sampleRate' => 0.5]);

        $this->assertSame('bearer', $config->authScheme());
        $this->assertSame(0.5, $config->sampleRate);
    }
}
