<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings\Crypto;

/**
 * The mail password at rest. Without INSTANCE_SECRET_KEY the ciphertext is noise.
 * The byte strings are base64 so one migration serves MySQL and SQLite.
 */
final readonly class SealedMailPassword
{
    public function __construct(
        public string $ciphertext,
        public string $nonce,
        public string $salt,
        public int $version,
    ) {
    }
}
