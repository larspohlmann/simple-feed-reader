<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\PasskeyRegistration;

/**
 * `PasskeyRegistration`'s eight fields are mostly filler for tests that
 * exercise something else entirely — listing, removal policy, sign-in
 * availability — so `any()` carries the values none of those tests care
 * about and lets a call site name only the ones it does.
 */
final readonly class PasskeyRegistrations
{
    /**
     * @param list<string> $transports
     */
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
