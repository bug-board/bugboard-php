<?php

declare(strict_types=1);

namespace BugBoard\Tests\Support;

use JsonSerializable;
use RuntimeException;

/** User code that explodes mid-serialization. describe() must still return. */
final class ThrowingSerializer implements JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        throw new RuntimeException('serializer exploded');
    }
}
