<?php

declare(strict_types=1);

namespace BugBoard;

use Closure;

/**
 * Immutable client configuration.
 *
 * Provide either a publishable key (`apiKey`, bearer auth) or a secret key
 * (`keyId` + `signingSecret`, HMAC auth) — the SDK picks the scheme from
 * which is set. Every option name mirrors the shared SDK specification, so
 * configuration is identical across the official SDKs.
 */
final readonly class Config
{
    /** BugBoard's ingestion origin. */
    public const DEFAULT_BASE_URL = 'https://bugboard.dev';

    /** The ingestion route, appended to the base URL. Also the signed request path. */
    public const API_PATH = '/api/v1/tasks';

    /**
     * @param  string|null  $apiKey  Publishable key (`bb_pub_…`), sent as a bearer token. Browser/mobile-facing keys; rarely right for PHP.
     * @param  string|null  $keyId  Public key id (`bbk_…`) identifying which secret key signed the request.
     * @param  string|null  $signingSecret  Signing secret (`bb_sec_…`). Only used to compute signatures; never transmitted.
     * @param  string|null  $encryptionPublicKey  Base64 X25519 public key. When set, every payload is encrypted in transit.
     * @param  string|null  $encryptionKeyId  Encryption key id (`bbek_…`) echoed in the envelope (enables key rotation).
     * @param  bool  $enabled  Master switch — set false to disable reporting entirely (e.g. in tests).
     * @param  bool  $captureLocation  Auto-capture the caller's file/line (sent as `file_name`/`line_number`). Default true.
     * @param  string|null  $environment  Added to every card as tag `env:<value>`.
     * @param  string|null  $release  Added to every card as tag `release:<value>`.
     * @param  list<string>  $defaultTags  Tags merged into every card.
     * @param  float  $sampleRate  Probability (0–1) that a report is sent.
     * @param  int  $maxQueueSize  Buffer cap; overflow drops the newest report.
     * @param  int  $concurrency  Accepted for cross-SDK config parity; PHP delivers sequentially on flush.
     * @param  int  $flushIntervalMs  Accepted for cross-SDK config parity; PHP flushes on shutdown or via flush().
     * @param  int  $timeoutMs  Per-request timeout in milliseconds.
     * @param  int  $maxRetries  Retry attempts for 429/5xx/network failures. Other 4xx are never retried.
     * @param  Closure(array<string, mixed>): (array<string, mixed>|null)|null  $beforeSend  Scrub PII or veto a report — return the (mutated) payload array, or null to drop it.
     * @param  bool  $debug  Verbose internal logging (keys always redacted).
     * @param  string  $baseUrl  Ingestion origin override, e.g. `http://localhost:8000`. Only the origin is used; the SDK appends `/api/v1/tasks`. @internal For SDK tests only.
     */
    public function __construct(
        public ?string $apiKey = null,
        public ?string $keyId = null,
        public ?string $signingSecret = null,
        public ?string $encryptionPublicKey = null,
        public ?string $encryptionKeyId = null,
        public bool $enabled = true,
        public bool $captureLocation = true,
        public ?string $environment = null,
        public ?string $release = null,
        public array $defaultTags = [],
        public float $sampleRate = 1.0,
        public int $maxQueueSize = 100,
        public int $concurrency = 3,
        public int $flushIntervalMs = 2000,
        public int $timeoutMs = 5000,
        public int $maxRetries = 3,
        public ?Closure $beforeSend = null,
        public bool $debug = false,
        public bool $logLocally = false,
        public string $baseUrl = self::DEFAULT_BASE_URL,
    ) {}

    /**
     * Build a Config from a plain options array (snake_case or camelCase
     * keys), casting loosely-typed values from env files or framework config.
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        $get = static fn (string $snake, string $camel): mixed => $options[$snake] ?? $options[$camel] ?? null;

        $string = static function (mixed $value): ?string {
            $value = is_scalar($value) ? trim((string) $value) : null;

            return ($value === null || $value === '') ? null : $value;
        };

        $tags = $get('default_tags', 'defaultTags');
        if (is_string($tags)) {
            $tags = array_values(array_unique(array_filter(
                array_map(trim(...), explode(',', $tags)),
                static fn (string $tag): bool => $tag !== '',
            )));
        }

        $beforeSend = $get('before_send', 'beforeSend');

        return new self(
            apiKey: $string($get('api_key', 'apiKey')),
            keyId: $string($get('key_id', 'keyId')),
            signingSecret: $string($get('signing_secret', 'signingSecret')),
            encryptionPublicKey: $string($get('encryption_public_key', 'encryptionPublicKey')),
            encryptionKeyId: $string($get('encryption_key_id', 'encryptionKeyId')),
            enabled: filter_var($get('enabled', 'enabled') ?? true, FILTER_VALIDATE_BOOL),
            captureLocation: filter_var($get('capture_location', 'captureLocation') ?? true, FILTER_VALIDATE_BOOL),
            environment: $string($get('environment', 'environment')),
            release: $string($get('release', 'release')),
            defaultTags: is_array($tags) ? array_values(array_map(strval(...), $tags)) : [],
            sampleRate: is_numeric($value = $get('sample_rate', 'sampleRate')) ? (float) $value : 1.0,
            maxQueueSize: is_numeric($value = $get('max_queue_size', 'maxQueueSize')) ? (int) $value : 100,
            concurrency: is_numeric($value = $get('concurrency', 'concurrency')) ? (int) $value : 3,
            flushIntervalMs: is_numeric($value = $get('flush_interval_ms', 'flushIntervalMs')) ? (int) $value : 2000,
            timeoutMs: is_numeric($value = $get('timeout_ms', 'timeoutMs')) ? (int) $value : 5000,
            maxRetries: is_numeric($value = $get('max_retries', 'maxRetries')) ? (int) $value : 3,
            beforeSend: $beforeSend instanceof Closure ? $beforeSend : null,
            debug: filter_var($get('debug', 'debug') ?? false, FILTER_VALIDATE_BOOL),
            logLocally: filter_var($get('log_locally', 'logLocally') ?? false, FILTER_VALIDATE_BOOL),
            baseUrl: $string($get('base_url', 'baseUrl')) ?? self::DEFAULT_BASE_URL,
        );
    }

    /** The auth scheme implied by the configured credentials. */
    public function authScheme(): string
    {
        if ($this->keyId !== null && $this->signingSecret !== null) {
            return 'hmac';
        }

        return $this->apiKey !== null ? 'bearer' : 'none';
    }

    /** Whether reporting can actually happen (enabled and credentialed). */
    public function active(): bool
    {
        return $this->enabled && $this->authScheme() !== 'none';
    }

    /**
     * The origin of the configured base URL (`scheme://host[:port]`), or null
     * when it isn't an absolute URL.
     */
    public function origin(): ?string
    {
        $parts = parse_url($this->baseUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port;
    }

    /**
     * The full ingestion URL. Only the base URL's origin is kept, so a trailing
     * slash or a stray path prefix can't change the route we sign. An
     * unparseable base URL falls back to BugBoard rather than throwing.
     */
    public function endpoint(): string
    {
        return ($this->origin() ?? self::DEFAULT_BASE_URL).self::API_PATH;
    }

    /** The request path used for HMAC signing (leading slash, no query string). */
    public function path(): string
    {
        return self::API_PATH;
    }

    /** Sample rate clamped into [0, 1]. */
    public function effectiveSampleRate(): float
    {
        return max(0.0, min(1.0, $this->sampleRate));
    }
}
