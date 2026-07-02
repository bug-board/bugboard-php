<?php

declare(strict_types=1);

namespace BugBoard;

/**
 * HMAC request signing for secret keys (API reference §3.2).
 *
 * The signing secret never travels on the wire — each request carries a
 * key id, a timestamp, and an HMAC-SHA256 signature over:
 *
 *     timestamp + "." + UPPERCASE(method) + "." + path + "." + sha256_hex(body)
 *
 * The server rejects timestamps outside ±300 s, so headers are computed
 * fresh for every attempt (including retries).
 */
final class Signer
{
    /**
     * Signature headers for one attempt. `$body` must be the exact string
     * transmitted — re-serializing the JSON differently breaks the signature.
     *
     * @return array<string, string>
     */
    public static function headers(
        string $keyId,
        string $signingSecret,
        string $method,
        string $path,
        string $body,
        ?int $timestamp = null,
    ): array {
        $timestamp = (string) ($timestamp ?? time());

        $signature = hash_hmac(
            'sha256',
            $timestamp.'.'.strtoupper($method).'.'.$path.'.'.hash('sha256', $body),
            $signingSecret,
        );

        return [
            'X-Bb-Key-Id' => $keyId,
            'X-Bb-Timestamp' => $timestamp,
            'X-Bb-Signature' => $signature,
        ];
    }
}
