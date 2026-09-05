<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\UserPasskey;

/**
 * The passkey listing body (#624), widened in #727 with the three values the
 * WebAuthn Signal API needs. `register/options` already discloses all three
 * to the same authenticated user, so this exposes nothing new.
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
     * credentials. `userHandle` is read off the rows, never minted — a handle
     * that matches nothing makes the signal a silent no-op — so it is null
     * with no rows.
     *
     * @param list<UserPasskey> $passkeys
     *
     * @return PasskeyListingBody
     */
    public static function listing(string $relyingPartyId, array $passkeys): array
    {
        return [
            'rpId' => $relyingPartyId,
            'userHandle' => ($passkeys[0] ?? null)?->getUserHandle(),
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
