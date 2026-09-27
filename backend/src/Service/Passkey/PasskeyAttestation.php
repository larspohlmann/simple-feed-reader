<?php

declare(strict_types=1);

namespace App\Service\Passkey;

final readonly class PasskeyAttestation
{
    /** @param array<string, mixed> $credential */
    public function __construct(
        public string $handle,
        public array $credential,
        public string $label,
    ) {
    }
}
