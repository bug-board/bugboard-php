<?php

declare(strict_types=1);

namespace BugBoard;

use BugBoard\Exceptions\BugBoardException;

/**
 * Optional payload encryption (API reference §11).
 *
 * When an encryption public key is configured, every report body is sealed
 * with a libsodium sealed box (X25519 + XSalsa20-Poly1305) before it leaves
 * the server, so it is opaque at proxies and in access logs. BugBoard
 * decrypts on receipt.
 *
 * Encryption changes the body, not the auth: HMAC signatures are computed
 * over the envelope this produces (encrypt first, then sign).
 */
final class Encrypter
{
    /**
     * Seal a plaintext request body into the transport envelope (§11.3):
     *
     *     { "encrypted": { "v": 1, "alg": "x25519-sealedbox", "key_id"?, "ciphertext" } }
     */
    public static function seal(string $body, string $publicKeyBase64, ?string $keyId = null): string
    {
        if (! function_exists('sodium_crypto_box_seal')) {
            throw new BugBoardException('Payload encryption requires the sodium extension (ext-sodium).');
        }

        $publicKey = base64_decode($publicKeyBase64, true);

        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new BugBoardException('encryptionPublicKey is not a valid base64 X25519 public key.');
        }

        $envelope = [
            'v' => 1,
            'alg' => 'x25519-sealedbox',
        ];

        if ($keyId !== null) {
            $envelope['key_id'] = $keyId;
        }

        $envelope['ciphertext'] = base64_encode(sodium_crypto_box_seal($body, $publicKey));

        return json_encode(['encrypted' => $envelope], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
