<?php

declare(strict_types=1);

namespace BugBoard\Tests;

use BugBoard\Buffer;
use BugBoard\Config;
use BugBoard\Payload;
use PHPUnit\Framework\TestCase;

final class BufferTest extends TestCase
{
    private function payload(string $title): Payload
    {
        return Payload::make('minor', 'medium', $title, null, [], new Config(apiKey: 'k'));
    }

    public function test_it_buffers_and_drains_in_order(): void
    {
        $buffer = new Buffer(10);
        $buffer->add($this->payload('a'));
        $buffer->add($this->payload('b'));

        $drained = $buffer->drain();

        $this->assertSame(['a', 'b'], array_map(static fn (Payload $p): string => $p->title, $drained));
        $this->assertSame(0, $buffer->count());
    }

    public function test_it_drops_the_newest_report_on_overflow(): void
    {
        $buffer = new Buffer(2);

        $this->assertTrue($buffer->add($this->payload('a')));
        $this->assertTrue($buffer->add($this->payload('b')));
        $this->assertFalse($buffer->add($this->payload('overflow')));

        $this->assertSame(1, $buffer->droppedCount());
        $this->assertSame(
            ['a', 'b'],
            array_map(static fn (Payload $p): string => $p->title, $buffer->drain()),
        );
    }

    public function test_drain_on_an_empty_buffer_returns_nothing(): void
    {
        $this->assertSame([], (new Buffer(5))->drain());
    }
}
