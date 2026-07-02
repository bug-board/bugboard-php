<?php

declare(strict_types=1);

namespace BugBoard\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * PSR-18 test double: replays a queue of responses (or throwables) and
 * records every request it receives, including the raw body bytes.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface|Throwable> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<string> */
    public array $bodies = [];

    public function willRespond(ResponseInterface|Throwable ...$outcomes): self
    {
        foreach ($outcomes as $outcome) {
            $this->queue[] = $outcome;
        }

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->bodies[] = (string) $request->getBody();

        $outcome = array_shift($this->queue);

        if ($outcome === null) {
            throw new \LogicException('FakeHttpClient queue is empty.');
        }

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }
}
