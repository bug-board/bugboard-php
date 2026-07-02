<?php

declare(strict_types=1);

namespace BugBoard\Tests\Support;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/** A PSR-18 network failure (DNS, connect timeout, …) for retry tests. */
final class FakeNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(private readonly RequestInterface $request)
    {
        parent::__construct('connection refused');
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
