<?php

declare(strict_types=1);

namespace BugBoard\Tests\Support;

use BugBoard\Exceptions\BugBoardException;
use BugBoard\Payload;
use BugBoard\TransportInterface;

/** In-memory transport: records delivered payloads, optionally failing every send. */
final class CollectingTransport implements TransportInterface
{
    /** @var list<Payload> */
    public array $sent = [];

    public function __construct(private readonly bool $failing = false) {}

    public function send(Payload $payload): void
    {
        if ($this->failing) {
            throw new BugBoardException('transport is failing');
        }

        $this->sent[] = $payload;
    }
}
