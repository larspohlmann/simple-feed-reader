<?php

declare(strict_types=1);

namespace App\Service\Proxy\Crypto;

/**
 * The proxy password at rest. Without AI_KEY_SECRET the ciphertext is noise.
 * The three byte strings are base64 so one migration serves MySQL and SQLite.
 */
final readonly class SealedProxyPassword
{
    public function __construct(
        public string $ciphertext,
        public string $nonce,
        public string $salt,
        public int $version,
    ) {
    }
}
