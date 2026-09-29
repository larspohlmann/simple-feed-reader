<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Entity\SealedSecret;
use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Crypto\Model\SecretBindingModel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Seals a secret so a database dump alone reveals nothing: the master key lives only in the environment, and the
 * binding enters both the key derivation and the AEAD data. Threat model: docs/security.md#stored-secrets.
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

    public function seal(SecretBindingModel $binding, string $plaintext): SealedSecret
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

    public function open(SecretBindingModel $binding, SealedSecret $sealed): string
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

    private function deriveRowKey(SecretBindingModel $binding, int $version, string $salt): string
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
