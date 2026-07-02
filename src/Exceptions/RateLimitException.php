<?php

declare(strict_types=1);

namespace BugBoard\Exceptions;

/** 429 — the per-minute burst limit was exceeded. Retried, honoring Retry-After. */
final class RateLimitException extends BugBoardException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
