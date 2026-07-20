<?php

declare(strict_types=1);

namespace BugBoard\Tests;

use BugBoard\Config;
use BugBoard\Payload;
use BugBoard\Tests\Support\ArrayableRequest;
use BugBoard\Tests\Support\ThrowingSerializer;
use Generator;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stringable;

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

    public function test_arrays_are_pretty_printed_with_two_space_indent(): void
    {
        $payload = Payload::make('minor', 'low', 'T', ['a' => 1, 'b' => [1, 2]], [], $this->plainConfig());

        $this->assertSame("{\n  \"a\": 1,\n  \"b\": [\n    1,\n    2\n  ]\n}", $payload->description);
    }

    /** @return array<string, array{0: mixed, 1: string|null}> */
    public static function scalarDescriptions(): array
    {
        return [
            'true' => [true, 'true'],
            'false' => [false, 'false'],
            'zero' => [0, '0'],
            'float one' => [1.0, '1'],
            'float precision' => [0.1 + 0.2, '0.30000000000000004'],
            'exponent' => [1.0E+25, '1e+25'],
            'small exponent' => [1.0E-7, '1e-7'],
            'nan' => [NAN, 'NaN'],
            'infinity' => [INF, 'Infinity'],
            'negative infinity' => [-INF, '-Infinity'],
            'empty string' => ['', null],
            'blank string' => ["  \n ", null],
        ];
    }

    #[DataProvider('scalarDescriptions')]
    public function test_scalars_render_the_same_text_as_the_js_sdk(mixed $input, ?string $expected): void
    {
        $payload = Payload::make('minor', 'low', 'T', $input, [], $this->plainConfig());

        $this->assertSame($expected, $payload->description);
    }

    public function test_a_false_description_is_not_swallowed_as_blank(): void
    {
        // (string) false is "" — a naive cast would trim to empty and drop the
        // description entirely.
        $payload = Payload::make('minor', 'low', 'T', false, [], $this->plainConfig());

        $this->assertSame('false', $payload->description);
    }

    public function test_arrayable_objects_are_unwrapped_before_the_to_string_rung(): void
    {
        $payload = Payload::make('major', 'high', 'T', new ArrayableRequest, [], $this->plainConfig());

        $this->assertSame("{\n  \"email\": \"a@b.c\",\n  \"qty\": 2\n}", $payload->description);
        $this->assertStringNotContainsString('RAW HTTP MESSAGE', (string) $payload->description);
    }

    public function test_json_serializable_objects_use_their_own_representation(): void
    {
        $value = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['ok' => true];
            }
        };

        $payload = Payload::make('minor', 'low', 'T', $value, [], $this->plainConfig());

        $this->assertSame("{\n  \"ok\": true\n}", $payload->description);
    }

    public function test_stringable_objects_render_their_string(): void
    {
        $value = new class implements Stringable
        {
            public function __toString(): string
            {
                return 'rendered';
            }
        };

        $payload = Payload::make('minor', 'low', 'T', $value, [], $this->plainConfig());

        $this->assertSame('rendered', $payload->description);
    }

    public function test_traversables_are_materialized(): void
    {
        $generator = (static function (): Generator {
            yield 'a' => 1;
            yield 'b' => 2;
        })();

        $payload = Payload::make('minor', 'low', 'T', $generator, [], $this->plainConfig());

        $this->assertSame("{\n  \"a\": 1,\n  \"b\": 2\n}", $payload->description);
    }

    public function test_an_infinite_generator_does_not_hang_the_report(): void
    {
        $generator = (static function (): Generator {
            $i = 0;

            while (true) {
                yield $i++;
            }
        })();

        $description = (string) Payload::make('minor', 'low', 'T', $generator, [], $this->plainConfig())->description;

        $this->assertStringContainsString('… truncated', $description);
        $this->assertLessThanOrEqual(60000, mb_strlen($description));
    }

    public function test_recursive_arrays_keep_everything_but_the_cycle(): void
    {
        $cyclic = ['a' => 1];
        $cyclic['self'] = &$cyclic;

        $description = (string) Payload::make('minor', 'low', 'T', $cyclic, [], $this->plainConfig())->description;

        $this->assertStringContainsString('"a": 1', $description);
        $this->assertStringContainsString('"self": null', $description);
    }

    public function test_malformed_utf8_is_substituted_rather_than_dropped(): void
    {
        $description = (string) Payload::make('minor', 'low', 'T', ['k' => "\xB1\x31"], [], $this->plainConfig())->description;

        $this->assertStringContainsString('1', $description);
        $this->assertJson($description);
    }

    public function test_a_throwing_serializer_falls_back_to_the_class_name(): void
    {
        $payload = Payload::make('minor', 'low', 'T', new ThrowingSerializer, [], $this->plainConfig());

        $this->assertSame('['.ThrowingSerializer::class.']', $payload->description);
    }

    public function test_resources_and_opaque_objects_are_named(): void
    {
        $handle = fopen('php://memory', 'r');
        $resource = Payload::make('minor', 'low', 'T', $handle, [], $this->plainConfig());
        $this->assertSame('[resource (stream)]', $resource->description);
        fclose($handle);

        // All-private state json_encodes to an uninformative "{}".
        $opaque = new class
        {
            private int $hidden = 1;
        };
        $named = Payload::make('minor', 'low', 'T', $opaque, [], $this->plainConfig());
        $this->assertStringStartsWith('[class@anonymous', (string) $named->description);

        // A genuinely empty object stays "{}", matching the JS SDK.
        $empty = Payload::make('minor', 'low', 'T', new \stdClass, [], $this->plainConfig());
        $this->assertSame('{}', $empty->description);
    }

    public function test_a_truncated_description_is_marked_and_stays_within_the_cap(): void
    {
        $payload = Payload::make('minor', 'low', 'T', ['blob' => str_repeat('x', 70000)], [], $this->plainConfig());

        $this->assertSame(60000, mb_strlen((string) $payload->description));
        $this->assertStringEndsWith("\n… truncated", (string) $payload->description);
    }

    public function test_from_array_coerces_a_non_string_description_from_a_hook(): void
    {
        $payload = Payload::fromArray([
            'severity' => 'major',
            'priority' => 'high',
            'title' => 'T',
            'description' => ['scrubbed' => true],
            'tags' => [],
        ]);

        $this->assertSame("{\n  \"scrubbed\": true\n}", $payload->description);
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
