<?php

declare(strict_types=1);

namespace BugBoard;

use BugBoard\Exceptions\AuthException;
use BugBoard\Exceptions\BugBoardException;
use BugBoard\Exceptions\RateLimitException;
use BugBoard\Exceptions\ServerException;
use BugBoard\Exceptions\ValidationException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * PSR-18 transport with the shared resilience policy: retries with
 * exponential backoff + jitter for 429/5xx/network failures, `Retry-After`
 * support, and quota-drop awareness. Other 4xx (bad key, invalid payload)
 * are config bugs and are never retried.
 */
final class Transport implements TransportInterface
{
    private const BASE_BACKOFF_MS = 500;

    private const MAX_BACKOFF_MS = 30000;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly Logger $logger,
    ) {}

    public function send(Payload $payload): void
    {
        // Dry-run mode: log the readable payload locally instead of sending it.
        if ($this->config->logLocally) {
            $this->logger->local('Report (log-only, not sent): '.json_encode(
                $payload->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }

        // Serialize (and optionally encrypt) once per report; the same bytes
        // are transmitted on every attempt. Encrypt first, then sign — the
        // HMAC signature covers the envelope bytes.
        $body = $payload->toJson();

        if ($this->config->encryptionPublicKey !== null) {
            $body = Encrypter::seal($body, $this->config->encryptionPublicKey, $this->config->encryptionKeyId);
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                $this->attemptOnce($body);

                return;
            } catch (BugBoardException $exception) {
                $retryable = $exception instanceof RateLimitException || $exception instanceof ServerException;

                if (! $retryable || $attempt >= max(0, $this->config->maxRetries)) {
                    throw $exception;
                }

                $delayMs = $this->backoffDelayMs(
                    $attempt,
                    $exception instanceof RateLimitException ? $exception->retryAfter : null,
                );
                $this->logger->debug(sprintf(
                    'Attempt %d failed (%s); retrying in %dms.',
                    $attempt + 1,
                    $exception->getMessage(),
                    $delayMs,
                ));
                usleep($delayMs * 1000);
            }
        }
    }

    /** @throws BugBoardException */
    private function attemptOnce(string $body): void
    {
        // Auth headers are computed per attempt so HMAC timestamps stay
        // within the server's ±300 s replay window across retries.
        $request = $this->requestFactory->createRequest('POST', $this->config->endpoint())
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        foreach ($this->authHeaders($body) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new ServerException('Network error while reporting to BugBoard: '.$exception->getMessage(), 0, $exception);
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $data = $this->decode($response);

            if (($data['quota_exceeded'] ?? false) === true) {
                // Not an error: the monthly quota is exhausted and the server
                // accepted-then-dropped the report. Never retried (§6).
                $this->logger->warn("Report dropped: the project's monthly quota is exhausted.");
            } elseif (($data['deduplicated'] ?? false) === true) {
                $this->logger->debug('Report deduplicated into an existing card.');
            } else {
                $this->logger->debug('Report delivered.');
            }

            return;
        }

        throw $this->toException($response);
    }

    /** @return array<string, string> */
    private function authHeaders(string $body): array
    {
        if ($this->config->authScheme() === 'hmac') {
            return Signer::headers(
                (string) $this->config->keyId,
                (string) $this->config->signingSecret,
                'POST',
                $this->config->path(),
                $body,
            );
        }

        if ($this->config->authScheme() === 'bearer') {
            return ['Authorization' => 'Bearer '.$this->config->apiKey];
        }

        return [];
    }

    /** Map a failed response to the SDK exception taxonomy (API reference §6). */
    private function toException(ResponseInterface $response): BugBoardException
    {
        $status = $response->getStatusCode();
        $data = $this->decode($response);
        $message = is_string($data['message'] ?? null) ? $data['message'] : 'HTTP '.$status;

        if ($status === 401 || $status === 403) {
            return new AuthException($message);
        }

        if ($status === 422) {
            /** @var array<string, list<string>> $fieldErrors */
            $fieldErrors = is_array($data['errors'] ?? null) ? $data['errors'] : [];

            return new ValidationException($message, $fieldErrors);
        }

        if ($status === 429) {
            return new RateLimitException($message, $this->retryAfter($response));
        }

        return new ServerException($message);
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        try {
            $data = json_decode((string) $response->getBody(), true);
        } catch (Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function retryAfter(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Retry-After');

        return is_numeric($header) && (int) $header >= 0 ? (int) $header : null;
    }

    /** Exponential backoff with equal jitter, so bursts don't retry in lockstep. */
    private function backoffDelayMs(int $attempt, ?int $retryAfterSeconds): int
    {
        if ($retryAfterSeconds !== null) {
            return $retryAfterSeconds * 1000;
        }

        $exponential = min(self::BASE_BACKOFF_MS * (2 ** $attempt), self::MAX_BACKOFF_MS);

        return intdiv((int) $exponential, 2) + random_int(0, intdiv((int) $exponential, 2));
    }
}
