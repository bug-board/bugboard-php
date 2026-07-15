<?php

declare(strict_types=1);

namespace BugBoard\Tests;

use BugBoard\Config;
use BugBoard\Payload;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PayloadTest extends TestCase
{
    private function plainConfig(): Config
    {
        return new Config(apiKey: 'bb_pub_x');
    }

    public function test_it_builds_the_minimal_body(): void
    {
        $payload = Payload::make('critical', 'high', 'Checkout failed', null, [], $this->plainConfig());

        $this->assertSame([
            'severity' => 'critical',
            'priority' => 'high',
            'title' => 'Checkout failed',
            'tags' => [],
        ], $payload->toArray());
    }

    public function test_title_is_clamped_to_255_characters(): void
    {
        $payload = Payload::make('minor', 'low', str_repeat('T', 300), null, [], $this->plainConfig());

        $this->assertSame(255, mb_strlen($payload->title));
    }

    public function test_throwables_become_class_message_and_trace(): void
    {
        $payload = Payload::make('major', 'medium', 'Boom', new RuntimeException('kaput'), [], $this->plainConfig());

        $this->assertStringContainsString('RuntimeException: kaput', (string) $payload->description);
        $this->assertStringContainsString(basename(__FILE__), (string) $payload->description);
    }

    public function test_oversized_descriptions_are_truncated_below_the_server_cap(): void
    {
        $payload = Payload::make('minor', 'low', 'T', str_repeat('x', 70000), [], $this->plainConfig());

        $this->assertLessThanOrEqual(60000, mb_strlen((string) $payload->description));
    }

    public function test_blank_descriptions_are_omitted(): void
    {
        $payload = Payload::make('minor', 'low', 'T', "  \n ", [], $this->plainConfig());

        $this->assertNull($payload->description);
        $this->assertArrayNotHasKey('description', $payload->toArray());
    }

    public function test_tags_accept_arrays_and_csv_strings(): void
    {
        $config = $this->plainConfig();

        $this->assertSame(['ui', 'android'], Payload::make('minor', 'low', 'T', null, ['ui', 'android'], $config)->tags);
        $this->assertSame(['ui', 'android'], Payload::make('minor', 'low', 'T', null, 'ui, android', $config)->tags);
    }

    public function test_tags_are_trimmed_deduped_and_clamped(): void
    {
        $payload = Payload::make('minor', 'low', 'T', null, [' ui ', '', 'ui', str_repeat('x', 80)], $this->plainConfig());

        $this->assertSame(['ui', str_repeat('x', 50)], $payload->tags);
    }

    public function test_environment_release_and_default_tags_are_folded_in(): void
    {
        $config = new Config(apiKey: 'k', environment: 'production', release: '1.4.2', defaultTags: ['web']);

        $payload = Payload::make('moderate', 'medium', 'T', null, ['ui', 'web'], $config);

        $this->assertSame(['web', 'env:production', 'release:1.4.2', 'ui'], $payload->tags);
    }

    public function test_to_json_produces_the_exact_wire_bytes(): void
    {
        $payload = Payload::make('major', 'medium', 'SDK smoke test', null, [], $this->plainConfig());

        $this->assertSame(
            '{"severity":"major","priority":"medium","title":"SDK smoke test","tags":[]}',
            $payload->toJson(),
        );
    }

    public function test_from_array_revalidates_a_before_send_result(): void
    {
        $payload = Payload::fromArray([
            'severity' => 'not-a-severity',
            'priority' => 'urgent',
            'title' => str_repeat('T', 300),
            'description' => 'scrubbed',
            'tags' => 'a,b,a',
        ]);

        $this->assertSame('moderate', $payload->severity);
        $this->assertSame('medium', $payload->priority);
        $this->assertSame(255, mb_strlen($payload->title));
        $this->assertSame('scrubbed', $payload->description);
        $this->assertSame(['a', 'b'], $payload->tags);
    }

    public function test_call_site_is_added_to_the_body_when_present(): void
    {
        $payload = Payload::make(
            'critical',
            'high',
            'Payment error',
            null,
            [],
            $this->plainConfig(),
            ['file' => '/app/src/Checkout.php', 'line' => 42],
        );

        $body = $payload->toArray();
        $this->assertSame('/app/src/Checkout.php', $body['file_name']);
        $this->assertSame(42, $body['line_number']);
    }

    public function test_call_site_is_omitted_when_absent(): void
    {
        $payload = Payload::make('critical', 'high', 'Payment error', null, [], $this->plainConfig());

        $body = $payload->toArray();
        $this->assertArrayNotHasKey('file_name', $body);
        $this->assertArrayNotHasKey('line_number', $body);
    }

    public function test_from_array_preserves_the_call_site(): void
    {
        $payload = Payload::fromArray([
            'severity' => 'critical',
            'priority' => 'high',
            'title' => 'Payment error',
            'tags' => [],
            'file_name' => '/app/src/Checkout.php',
            'line_number' => 42,
        ]);

        $this->assertSame('/app/src/Checkout.php', $payload->fileName);
        $this->assertSame(42, $payload->lineNumber);
    }
}
