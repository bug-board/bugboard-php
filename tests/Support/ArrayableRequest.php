<?php

declare(strict_types=1);

namespace BugBoard\Tests\Support;

use Illuminate\Contracts\Support\Arrayable;
use Stringable;

/**
 * Stands in for Illuminate\Http\Request: Arrayable and Stringable at the same
 * time, with all state non-public — so json_encode() alone yields "{}" and the
 * Arrayable rung has to win over the __toString one.
 *
 * @implements Arrayable<string, mixed>
 */
final class ArrayableRequest implements Arrayable, Stringable
{
    /** @param array<string, mixed> $input */
    public function __construct(private readonly array $input = ['email' => 'a@b.c', 'qty' => 2]) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->input;
    }

    public function __toString(): string
    {
        return 'RAW HTTP MESSAGE — the __toString rung must not win';
    }
}
