<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\UserPasskey;

/**
 * The passkey credential-listing response body (#624).
 *
 * The two options factories already return their body in its final wire
 * shape — the identical `{options, handle}` for both ceremonies — so they
 * need no mapper here and their controller actions hand the array straight
 * to the response.
 */
final readonly class PasskeyJson
{
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
