<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\PasskeyRegistration;

final readonly class PasskeyRegistrations
{
    /** @param list<string> $transports */
    public static function any(
        string $credentialId = 'Y3JlZC1hYmM',
        string $userHandle = 'aGFuZGxl',
        string $publicKey = 'cHVibGljLWtleQ',
        int $signatureCounter = 0,
        ?string $aaguid = null,
        array $transports = [],
        string $label = 'Key',
        ?\DateTimeImmutable $registeredAt = null,
    ): PasskeyRegistration {
        return new PasskeyRegistration(
            credentialId: $credentialId,
            userHandle: $userHandle,
            publicKey: $publicKey,
            signatureCounter: $signatureCounter,
            aaguid: $aaguid,
            transports: $transports,
            label: $label,
            registeredAt: $registeredAt ?? new \DateTimeImmutable(),
        );
    }
}
