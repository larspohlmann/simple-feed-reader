<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Service\Crypto\Exception\SecretUnreadableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Seals a secret so a database dump alone reveals nothing. The master secret
 * lives only in the environment; every row carries its own random salt, and the
 * binding (what the secret is, whose it is, which scheme version) enters both
 * the key derivation and the AEAD's additional data.
 *
 * What this cannot do: protect a secret from someone holding both the dump and
 * the environment file. The server has to use the secret while its owner is
 * absent, so the secret has to be reachable by the server.
 */
final readonly class InstanceSecretCipher
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

    public function seal(SecretBinding $binding, string $plaintext): SealedSecret
    {
        $salt = random_bytes(self::SALT_BYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $rowKey = $this->deriveRowKey($binding, self::CURRENT_VERSION, $salt);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $binding->render(self::CURRENT_VERSION),
            $nonce,
            $rowKey,
        );

        sodium_memzero($rowKey);

        return new SealedSecret(
            base64_encode($ciphertext),
            base64_encode($nonce),
            base64_encode($salt),
            self::CURRENT_VERSION,
        );
    }

    public function open(SecretBinding $binding, SealedSecret $sealed): string
    {
        if (self::CURRENT_VERSION !== $sealed->version) {
            throw new SecretUnreadableException(sprintf('Unknown scheme version %d.', $sealed->version));
        }

        // Decode every field before deriving the row key: once $rowKey exists,
        // every exit must zero it, and a decode() thrown from inside the
        // decrypt() call's argument list would skip the memzero below.
        $salt = $this->decode($sealed->salt);
        $ciphertext = $this->decode($sealed->ciphertext);
        $nonce = $this->decode($sealed->nonce);

        $rowKey = $this->deriveRowKey($binding, $sealed->version, $salt);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $binding->render($sealed->version),
            $nonce,
            $rowKey,
        );

        sodium_memzero($rowKey);

        if (false === $plaintext) {
            throw new SecretUnreadableException('The stored secret failed its integrity check.');
        }

        return $plaintext;
    }

    private function deriveRowKey(SecretBinding $binding, int $version, string $salt): string
    {
        return hash_hkdf(
            'sha256',
            $this->masterSecret,
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $binding->render($version),
            $salt,
        );
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode($value, true);

        if (false === $decoded) {
            throw new SecretUnreadableException('Stored secret material is not valid base64.');
        }

        return $decoded;
    }
}
