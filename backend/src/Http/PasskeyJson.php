<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\UserPasskey;
use App\Service\Passkey\Model\AccountPasskeysModel;

/**
 * The passkey listing: the rows plus the three values the WebAuthn Signal API needs, which `register/options` already
 * discloses to the same user. The options factories return their final wire shape, so they need no mapper.
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
     * @return PasskeyListingBody
     */
    public static function listing(AccountPasskeysModel $account): array
    {
        return [
            'rpId' => $account->relyingPartyId,
            'userHandle' => $account->userHandle,
            'acceptedCredentialIds' => array_map(
                static fn (UserPasskey $passkey): string => $passkey->getCredentialId(),
                $account->passkeys,
            ),
            'passkeys' => array_map(self::passkey(...), $account->passkeys),
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
