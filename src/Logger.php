<?php

declare(strict_types=1);

namespace BugBoard;

/**
 * Internal debug logger.
 *
 * Silent unless `debug: true` — a monitoring SDK must never spam the logs of
 * the app it watches. Key material is redacted from every message so a debug
 * session can never leak a secret.
 */
final class Logger
{
    /** @var list<string> */
    private array $secrets;

    /** @param list<string|null> $secrets */
    public function __construct(
        private readonly bool $enabled,
        array $secrets = [],
    ) {
        $this->secrets = array_values(array_filter($secrets, static fn (?string $secret): bool => $secret !== null && $secret !== ''));
    }

    public function debug(string $message): void
    {
        $this->write('debug', $message);
    }

    public function warn(string $message): void
    {
        $this->write('warn', $message);
    }

    public function error(string $message): void
    {
        $this->write('error', $message);
    }

    private function write(string $level, string $message): void
    {
        if (! $this->enabled) {
            return;
        }

        error_log(sprintf('[bugboard] %s: %s', $level, $this->redact($message)));
    }

    private function redact(string $message): string
    {
        $message = str_replace($this->secrets, '[redacted]', $message);

        return (string) preg_replace('/bb_(sec|pub)_[A-Za-z0-9]+/', '[redacted]', $message);
    }
}
