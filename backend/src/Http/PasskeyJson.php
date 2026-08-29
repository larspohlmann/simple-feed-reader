<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\UserPasskey;

/**
 * The passkey enrolment and login response bodies (#624).
 * `RegistrationOptionsFactory` and `AssertionOptionsFactory` already return
 * their options body in its final wire shape, so `optionsResponse()` is a
 * pass-through today, shared by both rather than duplicated under two names —
 * the two factories return the identical `{options, handle}` shape for the
 * two different ceremonies. It stays its own mapper rather than a private
 * method on `PasskeyController` because ThinControllerRule forbids response
 * assembly there, and because the credential-listing (Task 8) response
 * belongs beside this one, not duplicated into a second convention —
 * `passkeys()` is shared by both the register action's response and that
 * later listing endpoint.
 */
final readonly class PasskeyJson
{
    /**
     * @param array{options: array<string, mixed>, handle: string} $ceremonyOptions
     *
     * @return array{options: array<string, mixed>, handle: string}
     */
    public static function optionsResponse(array $ceremonyOptions): array
    {
        return $ceremonyOptions;
    }

    /**
     * Never the public key: it is opaque, verification-only material a
     * client has no use for, and every field here — including `label` — is
     * one Task 8's revocation confirmation also needs to show.
     *
     * @param list<UserPasskey> $passkeys
     *
     * @return array{passkeys: list<array{id: ?int, label: string, createdAt: string, lastUsedAt: ?string}>}
     */
    public static function passkeys(array $passkeys): array
    {
        return ['passkeys' => array_map(self::passkey(...), $passkeys)];
    }

    /**
     * @return array{id: ?int, label: string, createdAt: string, lastUsedAt: ?string}
     */
    private static function passkey(UserPasskey $passkey): array
    {
        return [
            'id' => $passkey->getId(),
            'label' => $passkey->getLabel(),
            'createdAt' => $passkey->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastUsedAt' => $passkey->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
