<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\User;
use App\Repository\UserPasskeyRepository;
use App\Service\Passkey\Model\AccountPasskeysModel;
use App\Service\Settings\PasskeyRelyingParty\PasskeyRelyingPartyInterface;

/** One account's passkeys, with the relying party id and shared handle the WebAuthn Signal API needs. */
final readonly class PasskeyListing
{
    public function __construct(
        private UserPasskeyRepository $passkeys,
        private PasskeyCredentials $credentials,
        private PasskeyRelyingPartyInterface $relyingParty,
    ) {
    }

    public function forUser(User $user): AccountPasskeysModel
    {
        $rows = $this->passkeys->findForUser($user);

        return new AccountPasskeysModel($this->relyingParty->id(), $this->credentials->sharedHandle($rows), $rows);
    }
}
