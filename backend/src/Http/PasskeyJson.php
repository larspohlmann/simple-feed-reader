<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\UserPasskey;

/**
 * The passkey listing body (#624): the rows plus the three values the WebAuthn
 * Signal API needs (#727), which `register/options` already discloses to the
 * same authenticated user.
 *
 * The two options factories already return their body in its final wire
 * shape — the identical `{options, handle}` for both ceremonies — so they
 * need no mapper here and their controller actions hand the array straight
 * to the response.
 *
 * @phpstan-type PasskeyRow array{id: ?int, label: string, createdAt: string, lastUsedAt: ?string}
 * @phpstan-type PasskeyListingBody array{
 *     rpId: string, userHandle: ?string, acceptedCredentialIds: list<string>, passkeys: list<PasskeyRow>,
 * }
 */
final readonly class PasskeyJson
{
    /**
     * `acceptedCredentialIds` is ONE flat authoritative list the client hands
     * to the browser unchanged: a rebuilt or shortened list deletes valid
     * credentials. The handle comes from PasskeyCredentials::sharedHandle().
     *
     * @param list<UserPasskey> $passkeys
     *
     * @return PasskeyListingBody
     */
    public static function listing(string $relyingPartyId, ?string $userHandle, array $passkeys): array
    {
        return [
            'rpId' => $relyingPartyId,
            'userHandle' => $userHandle,
            'acceptedCredentialIds' => array_map(
                static fn (UserPasskey $passkey): string => $passkey->getCredentialId(),
                $passkeys,
            ),
            'passkeys' => array_map(self::passkey(...), $passkeys),
        ];
    }

    /**
     * @return PasskeyRow
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
