<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\SealedSecret;

final readonly class SealedBlueskySessionModel
{
    public function __construct(
        public SealedSecret $accessJwt,
        public string $handle,
        public string $pdsUrl,
    ) {
    }

    /** @return array{ciphertext: string, nonce: string, salt: string, version: int, handle: string, pdsUrl: string} */
    public function toCacheEntry(): array
    {
        return [
            'ciphertext' => $this->accessJwt->ciphertext,
            'nonce' => $this->accessJwt->nonce,
            'salt' => $this->accessJwt->salt,
            'version' => $this->accessJwt->version,
            'handle' => $this->handle,
            'pdsUrl' => $this->pdsUrl,
        ];
    }

    public static function fromCacheEntryOrNull(mixed $stored): ?self
    {
        if (!\is_array($stored)) {
            return null;
        }
        $texts = array_map(
            static fn (string $key): ?string => \is_string($stored[$key] ?? null) ? $stored[$key] : null,
            [
                'ciphertext' => 'ciphertext',
                'nonce' => 'nonce',
                'salt' => 'salt',
                'handle' => 'handle',
                'pdsUrl' => 'pdsUrl',
            ],
        );
        $version = $stored['version'] ?? null;
        if (\in_array(null, $texts, true) || !\is_int($version)) {
            return null;
        }

        return new self(
            new SealedSecret(
                (string) $texts['ciphertext'],
                (string) $texts['nonce'],
                (string) $texts['salt'],
                $version,
            ),
            (string) $texts['handle'],
            (string) $texts['pdsUrl'],
        );
    }
}
