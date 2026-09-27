<?php

declare(strict_types=1);

namespace App\Entity;

/** A secret at rest. The three byte strings are base64, so one column type serves MySQL and SQLite. */
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
