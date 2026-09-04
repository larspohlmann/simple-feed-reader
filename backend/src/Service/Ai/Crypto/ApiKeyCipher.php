<?php

declare(strict_types=1);

namespace App\Service\Ai\Crypto;

use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Seals an account's API key so a database dump alone reveals nothing.
 *
 * The master secret lives only in the environment. Every row carries its own
 * random salt, and the account id and the scheme version are bound into both
 * the key derivation and the AEAD's additional data. So a row cannot be moved
 * to another account, and its version cannot be lowered to reach an older
 * scheme once a second one exists.
 *
 * What this cannot do: protect a key from someone holding both the dump and
 * the environment file. The server has to use the key while the account holder
 * is absent, so the secret has to be reachable by the server.
 */
final readonly class ApiKeyCipher
{
    public const int CURRENT_VERSION = 1;

    private const int SALT_BYTES = 16;
    private const int MINIMUM_SECRET_LENGTH = 32;

    public function __construct(
        #[Autowire('%env(INSTANCE_SECRET_KEY)%')]
        private string $masterSecret,
    ) {
        // A short or empty secret would still derive a key and still encrypt,
        // so nothing downstream could notice. Fail at construction instead.
        if (\strlen($masterSecret) < self::MINIMUM_SECRET_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'INSTANCE_SECRET_KEY must be at least %d characters; got %d.',
                self::MINIMUM_SECRET_LENGTH,
                \strlen($masterSecret),
            ));
        }
    }

    public function seal(int $userId, string $plainApiKey): SealedApiKey
    {
        $salt = random_bytes(self::SALT_BYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $rowKey = $this->deriveRowKey($userId, self::CURRENT_VERSION, $salt);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plainApiKey,
            $this->binding($userId, self::CURRENT_VERSION),
            $nonce,
            $rowKey,
        );

        sodium_memzero($rowKey);

        return new SealedApiKey(
            base64_encode($ciphertext),
            base64_encode($nonce),
            base64_encode($salt),
            self::CURRENT_VERSION,
        );
    }

    public function open(int $userId, SealedApiKey $sealed): string
    {
        if (self::CURRENT_VERSION !== $sealed->version) {
            throw new ApiKeyUnreadableException(sprintf('Unknown key scheme version %d.', $sealed->version));
        }

        // Decode every field before deriving the row key: once $rowKey exists,
        // every exit must zero it, and a decode() thrown from inside the
        // decrypt() call's argument list would skip the memzero below.
        $salt = $this->decode($sealed->salt);
        $ciphertext = $this->decode($sealed->ciphertext);
        $nonce = $this->decode($sealed->nonce);

        $rowKey = $this->deriveRowKey($userId, $sealed->version, $salt);

        $plainApiKey = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $this->binding($userId, $sealed->version),
            $nonce,
            $rowKey,
        );

        sodium_memzero($rowKey);

        if (false === $plainApiKey) {
            throw new ApiKeyUnreadableException('The stored API key failed its integrity check.');
        }

        return $plainApiKey;
    }

    private function deriveRowKey(int $userId, int $version, string $salt): string
    {
        return hash_hkdf(
            'sha256',
            $this->masterSecret,
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $this->binding($userId, $version),
            $salt,
        );
    }

    /**
     * One string feeds both the HKDF info and the AEAD's additional data, so
     * the two can never drift apart.
     */
    private function binding(int $userId, int $version): string
    {
        return sprintf('ai-api-key|v%d|user:%d', $version, $userId);
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode($value, true);

        if (false === $decoded) {
            throw new ApiKeyUnreadableException('Stored key material is not valid base64.');
        }

        return $decoded;
    }
}
