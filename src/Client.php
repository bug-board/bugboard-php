<?php

declare(strict_types=1);

namespace BugBoard;

use BadMethodCallException;
use Throwable;

/**
 * The BugBoard client.
 *
 * The reporting surface is exactly 16 severity×priority methods (generated
 * from the tables below — a bare severity name is shorthand for the
 * medium-priority variant). Every reporting method is fire-and-forget: it
 * buffers the report and returns immediately, delivery happens on flush
 * (explicit or registered at shutdown), and it **never throws** — a
 * monitoring SDK must not crash the app it monitors.
 *
 * @method void critical(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method void criticalLow(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method void criticalMedium(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method void criticalHigh(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method void major(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method void majorLow(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method void majorMedium(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method void majorHigh(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method void moderate(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method void moderateLow(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method void moderateMedium(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method void moderateHigh(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method void minor(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method void minorLow(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method void minorMedium(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method void minorHigh(string $title, mixed $description = null, array<int, string>|string $tags = [])
 */
final class Client
{
    private const PRIORITY_SUFFIXES = ['Low' => 'low', 'Medium' => 'medium', 'High' => 'high', '' => 'medium'];

    private readonly Buffer $buffer;

    private readonly Logger $logger;

    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly Config $config,
        private readonly TransportInterface $transport,
    ) {
        $this->buffer = new Buffer($config->maxQueueSize);
        $this->logger = new Logger($config->debug, [$config->signingSecret, $config->apiKey]);

        if ($config->enabled && $config->authScheme() === 'none') {
            $this->logger->warn('No credentials configured (set apiKey, or keyId + signingSecret) — reporting is disabled.');
        }

        if ($config->origin() === null) {
            $this->logger->warn(sprintf(
                'baseUrl "%s" is not an absolute URL — falling back to %s.',
                $config->baseUrl,
                Config::DEFAULT_BASE_URL,
            ));
        }
    }

    /**
     * Route the 16 generated reporting methods (`critical`, `criticalHigh`,
     * `minorLow`, …) to the report pipeline.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): void
    {
        $target = self::methodTable()[$method] ?? null;

        if ($target === null) {
            throw new BadMethodCallException(sprintf('Call to undefined method %s::%s().', self::class, $method));
        }

        try {
            $this->report($target[0], $target[1], ...$arguments);
        } catch (Throwable $exception) {
            // Absolute backstop: reporting must never throw into the host app.
            $this->logger->error('Failed to queue report: '.$exception->getMessage());
        }
    }

    /** Deliver everything buffered, now. Never throws; failures go to the debug log. */
    public function flush(): void
    {
        foreach ($this->buffer->drain() as $payload) {
            try {
                $this->transport->send($payload);
            } catch (Throwable $exception) {
                $this->logger->error('Failed to deliver report: '.$exception->getMessage());
            }
        }
    }

    /** How many reports have been dropped to buffer overflow. */
    public function droppedCount(): int
    {
        return $this->buffer->droppedCount();
    }

    /** @param array<int, string>|string $tags */
    private function report(
        string $severity,
        string $priority,
        string $title,
        mixed $description = null,
        array|string $tags = [],
    ): void {
        if (! $this->config->active()) {
            return;
        }

        $rate = $this->config->effectiveSampleRate();

        if ($rate < 1.0 && (mt_rand() / mt_getrandmax()) >= $rate) {
            $this->logger->debug('Report sampled out.');

            return;
        }

        $location = $this->config->captureLocation ? Location::capture() : null;

        $payload = Payload::make($severity, $priority, $title, $description, $tags, $this->config, $location);

        if ($this->config->beforeSend !== null) {
            $result = ($this->config->beforeSend)($payload->toArray());

            if ($result === null) {
                $this->logger->debug('Report dropped by beforeSend.');

                return;
            }

            $payload = Payload::fromArray($result);
        }

        if (! $this->buffer->add($payload)) {
            $this->logger->warn(sprintf(
                'Queue full (%d); report dropped (%d dropped so far).',
                $this->config->maxQueueSize,
                $this->buffer->droppedCount(),
            ));

            return;
        }

        $this->registerShutdownFlush();
    }

    /**
     * Graceful-shutdown flush: buffered reports are delivered after the
     * request finishes, so reporting never adds latency inside the request
     * and nothing is lost when the script exits.
     */
    private function registerShutdownFlush(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;
        register_shutdown_function($this->flush(...));
    }

    /** @return array<string, array{0: string, 1: string}> */
    private static function methodTable(): array
    {
        static $table = null;

        if ($table === null) {
            $table = [];

            foreach (Payload::SEVERITIES as $severity) {
                foreach (self::PRIORITY_SUFFIXES as $suffix => $priority) {
                    $table[$severity.$suffix] = [$severity, $priority];
                }
            }
        }

        return $table;
    }
}
