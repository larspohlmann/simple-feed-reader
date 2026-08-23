<?php

declare(strict_types=1);

namespace App\Service\Proxy\Crypto;

use App\Service\Proxy\Crypto\Exception\ProxyPasswordUnreadableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Seals the instance-wide proxy password. Same construction as ApiKeyCipher but
 * bound to a fixed instance identity instead of a user id: there is exactly one
 * proxy row. The distinct binding string keeps this secret cryptographically
 * separate from the AI keys even though both derive from AI_KEY_SECRET.
 */
final readonly class ProxyPasswordCipher
{
    public const int CURRENT_VERSION = 1;

    private const int SALT_BYTES = 16;
    private const int MINIMUM_SECRET_LENGTH = 32;

    public function __construct(
        #[Autowire('%env(AI_KEY_SECRET)%')]
        private string $masterSecret,
    ) {
        if (\strlen($masterSecret) < self::MINIMUM_SECRET_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'AI_KEY_SECRET must be at least %d characters; got %d.',
                self::MINIMUM_SECRET_LENGTH,
                \strlen($masterSecret),
            ));
        }
    }

    public function seal(string $plainPassword): SealedProxyPassword
    {
        $salt = random_bytes(self::SALT_BYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $rowKey = $this->deriveRowKey(self::CURRENT_VERSION, $salt);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plainPassword,
            $this->binding(self::CURRENT_VERSION),
            $nonce,
            $rowKey,
        );

        sodium_memzero($rowKey);

        return new SealedProxyPassword(
            base64_encode($ciphertext),
            base64_encode($nonce),
            base64_encode($salt),
            self::CURRENT_VERSION,
        );
    }

    public function open(SealedProxyPassword $sealed): string
    {
        if (self::CURRENT_VERSION !== $sealed->version) {
            throw new ProxyPasswordUnreadableException(sprintf('Unknown scheme version %d.', $sealed->version));
        }

        $salt = $this->decode($sealed->salt);
        $ciphertext = $this->decode($sealed->ciphertext);
        $nonce = $this->decode($sealed->nonce);

        $rowKey = $this->deriveRowKey($sealed->version, $salt);

        $plainPassword = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $this->binding($sealed->version),
            $nonce,
            $rowKey,
        );

        sodium_memzero($rowKey);

        if (false === $plainPassword) {
            throw new ProxyPasswordUnreadableException('The stored proxy password failed its integrity check.');
        }

        return $plainPassword;
    }

    private function deriveRowKey(int $version, string $salt): string
    {
        return hash_hkdf(
            'sha256',
            $this->masterSecret,
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $this->binding($version),
            $salt,
        );
    }

    private function binding(int $version): string
    {
        return sprintf('proxy-password|v%d|instance', $version);
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode($value, true);

        if (false === $decoded) {
            throw new ProxyPasswordUnreadableException('Stored proxy secret is not valid base64.');
        }

        return $decoded;
    }
}
