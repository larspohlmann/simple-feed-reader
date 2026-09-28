<?php

declare(strict_types=1);

namespace App\Service\Passkey\Model;

final readonly class PasskeyAttestationModel
{
    /** @param array<string, mixed> $credential */
    public function __construct(
        public string $handle,
        public array $credential,
        public string $label,
    ) {
    }
}
