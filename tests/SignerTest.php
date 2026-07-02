<?php

declare(strict_types=1);

namespace BugBoard\Tests;

use BugBoard\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    /**
     * Reference vector shared with the JavaScript SDK's signer.test.ts,
     * generated with the openssl recipe from the API reference §10 — all
     * SDKs must produce these exact bytes for the same inputs.
     */
    public function test_it_matches_the_openssl_reference_vector(): void
    {
        $headers = Signer::headers(
            keyId: 'bbk_test123',
            signingSecret: 'bb_sec_0123456789abcdef',
            method: 'POST',
            path: '/api/v1/tasks',
            body: '{"severity":"major","title":"SDK smoke test"}',
            timestamp: 1750000000,
        );

        $this->assertSame([
            'X-Bb-Key-Id' => 'bbk_test123',
            'X-Bb-Timestamp' => '1750000000',
            'X-Bb-Signature' => 'c9436e5c768e0cbea09119c0b112088f348f45aeb1c1ffcccecd62e65e2f3fc1',
        ], $headers);
    }

    public function test_the_method_is_uppercased_in_the_signing_payload(): void
    {
        $lower = Signer::headers('k', 's', 'post', '/api/v1/tasks', '{}', 1);
        $upper = Signer::headers('k', 's', 'POST', '/api/v1/tasks', '{}', 1);

        $this->assertSame($upper['X-Bb-Signature'], $lower['X-Bb-Signature']);
    }

    public function test_the_signature_changes_with_the_body(): void
    {
        $a = Signer::headers('k', 's', 'POST', '/api/v1/tasks', '{"a":1}', 1);
        $b = Signer::headers('k', 's', 'POST', '/api/v1/tasks', '{"a":2}', 1);

        $this->assertNotSame($a['X-Bb-Signature'], $b['X-Bb-Signature']);
    }

    public function test_it_uses_the_current_unix_time_by_default(): void
    {
        $before = time();
        $headers = Signer::headers('k', 's', 'POST', '/api/v1/tasks', '{}');
        $after = time();

        $timestamp = (int) $headers['X-Bb-Timestamp'];
        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }
}
