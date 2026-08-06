<?php

declare(strict_types=1);

namespace App\Service\Ai\Crypto;

/**
 * An API key at rest. Everything here is safe to store: without the master
 * secret from the environment, the ciphertext is noise.
 *
 * The three byte strings are base64 so one migration serves both MySQL and
 * SQLite — see the spec's persistence section.
 */
final readonly class SealedApiKey
{
    public function __construct(
        public string $ciphertext,
        public string $nonce,
        public string $salt,
        public int $version,
    ) {
    }
}
