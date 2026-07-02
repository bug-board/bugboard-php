<?php

declare(strict_types=1);

namespace BugBoard\Tests;

use BugBoard\Encrypter;
use BugBoard\Exceptions\BugBoardException;
use PHPUnit\Framework\TestCase;

final class EncrypterTest extends TestCase
{
    protected function setUp(): void
    {
        if (! function_exists('sodium_crypto_box_seal')) {
            $this->markTestSkipped('ext-sodium is not available.');
        }
    }

    public function test_it_produces_the_spec_envelope_and_a_ciphertext_only_the_private_key_opens(): void
    {
        $keyPair = sodium_crypto_box_keypair();
        $publicKeyBase64 = base64_encode(sodium_crypto_box_publickey($keyPair));
        $plaintext = '{"severity":"major","title":"Encrypted smoke test"}';

        $body = Encrypter::seal($plaintext, $publicKeyBase64, 'bbek_test');

        /** @var array{encrypted: array{v: int, alg: string, key_id?: string, ciphertext: string}} $envelope */
        $envelope = json_decode($body, true);

        $this->assertSame(1, $envelope['encrypted']['v']);
        $this->assertSame('x25519-sealedbox', $envelope['encrypted']['alg']);
        $this->assertSame('bbek_test', $envelope['encrypted']['key_id']);
        $this->assertStringNotContainsString('Encrypted smoke test', $body);

        $opened = sodium_crypto_box_seal_open(
            (string) base64_decode($envelope['encrypted']['ciphertext'], true),
            $keyPair,
        );

        $this->assertSame($plaintext, $opened);
    }

    public function test_key_id_is_omitted_when_not_configured(): void
    {
        $keyPair = sodium_crypto_box_keypair();
        $body = Encrypter::seal('{}', base64_encode(sodium_crypto_box_publickey($keyPair)));

        /** @var array{encrypted: array<string, mixed>} $envelope */
        $envelope = json_decode($body, true);

        $this->assertArrayNotHasKey('key_id', $envelope['encrypted']);
    }

    public function test_an_invalid_public_key_is_rejected(): void
    {
        $this->expectException(BugBoardException::class);

        Encrypter::seal('{}', 'not-a-valid-key');
    }
}
