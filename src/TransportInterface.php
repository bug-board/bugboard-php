<?php

declare(strict_types=1);

namespace BugBoard;

use BugBoard\Exceptions\BugBoardException;

/**
 * Delivers one report to BugBoard.
 *
 * Split out as an interface so the client can be tested (and extended) with
 * an in-memory transport.
 */
interface TransportInterface
{
    /**
     * Deliver one report. Returns on success or an accepted-then-dropped
     * quota response; throws a BugBoardException once retries are exhausted
     * or the failure is non-retryable.
     *
     * @throws BugBoardException
     */
    public function send(Payload $payload): void;
}
