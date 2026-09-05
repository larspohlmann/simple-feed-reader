<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\UserPasskey;

/**
 * The passkey listing body (#624), widened in #727 with the three values the
 * WebAuthn Signal API needs: the relying party id, the account's user handle,
 * and every accepted credential id as ONE flat authoritative list the client
 * hands to the browser unchanged — a rebuilt or shortened list makes the
 * browser delete valid credentials. `register/options` already discloses all
 * three to the same authenticated user, so this exposes nothing new.
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
     * `userHandle` is read off the rows, never minted: the browser ignores a
     * handle that matches nothing, so a fresh one would make the signal a
     * silent no-op. Null with no rows.
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
