<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\SealedSecret;

final readonly class SealedBlueskySessionModel
{
    public function __construct(
        public SealedSecret $accessJwt,
        public string $handle,
    ) {
    }

    /** @return array{ciphertext: string, nonce: string, salt: string, version: int, handle: string} */
    public function toCacheEntry(): array
    {
        return [
            'ciphertext' => $this->accessJwt->ciphertext,
            'nonce' => $this->accessJwt->nonce,
            'salt' => $this->accessJwt->salt,
            'version' => $this->accessJwt->version,
            'handle' => $this->handle,
        ];
    }

    public static function fromCacheEntryOrNull(mixed $stored): ?self
    {
        if (!\is_array($stored)) {
            return null;
        }
        $ciphertext = self::textOrNull($stored, 'ciphertext');
        $nonce = self::textOrNull($stored, 'nonce');
        $salt = self::textOrNull($stored, 'salt');
        $handle = self::textOrNull($stored, 'handle');
        $version = $stored['version'] ?? null;
        if (null === $ciphertext || null === $nonce || null === $salt || null === $handle || !\is_int($version)) {
            return null;
        }

        return new self(new SealedSecret($ciphertext, $nonce, $salt, $version), $handle);
    }

    /** @param array<mixed> $stored */
    private static function textOrNull(array $stored, string $key): ?string
    {
        return \is_string($stored[$key] ?? null) ? $stored[$key] : null;
    }
}
