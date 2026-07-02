<?php

declare(strict_types=1);

namespace BugBoard;

/**
 * Bounded in-memory report buffer.
 *
 * Reports are buffered during the request and delivered on flush (explicit
 * or at shutdown). On overflow the **newest** report is dropped — the older
 * reports are already buffered and likelier to describe the root cause.
 */
final class Buffer
{
    /** @var list<Payload> */
    private array $items = [];

    private int $dropped = 0;

    public function __construct(private readonly int $capacity) {}

    /** Buffer a report; returns false when the buffer is full (report dropped). */
    public function add(Payload $payload): bool
    {
        if (count($this->items) >= max(1, $this->capacity)) {
            $this->dropped++;

            return false;
        }

        $this->items[] = $payload;

        return true;
    }

    /**
     * Remove and return everything buffered (oldest first).
     *
     * @return list<Payload>
     */
    public function drain(): array
    {
        $items = $this->items;
        $this->items = [];

        return $items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** How many reports have been dropped to overflow since creation. */
    public function droppedCount(): int
    {
        return $this->dropped;
    }
}
