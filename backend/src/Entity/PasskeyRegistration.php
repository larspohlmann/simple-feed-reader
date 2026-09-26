<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class PasskeyRegistration
{
    /** @param list<string> $transports */
    public function __construct(
        public string $credentialId,
        public string $userHandle,
        public string $publicKey,
        public int $signatureCounter,
        public ?string $aaguid,
        public array $transports,
        public string $label,
        public \DateTimeImmutable $registeredAt,
    ) {
    }
}
