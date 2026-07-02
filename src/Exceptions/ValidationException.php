<?php

declare(strict_types=1);

namespace BugBoard\Exceptions;

/** 422 — the payload failed validation. Carries the per-field error map. Never retried. */
final class ValidationException extends BugBoardException
{
    /** @param array<string, list<string>> $fieldErrors */
    public function __construct(
        string $message,
        public readonly array $fieldErrors = [],
    ) {
        parent::__construct($message);
    }
}
