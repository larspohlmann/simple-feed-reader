<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * A secret at rest. Without INSTANCE_SECRET_KEY the ciphertext is noise. The
 * three byte strings are base64 so one migration serves MySQL and SQLite.
 */
final readonly class SealedSecret
{
    public function __construct(
        public string $ciphertext,
        public string $nonce,
        public string $salt,
        public int $version,
    ) {
    }
}
