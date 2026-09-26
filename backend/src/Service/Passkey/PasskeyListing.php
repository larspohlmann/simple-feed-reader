<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\User;
use App\Repository\UserPasskeyRepository;
use App\Service\Settings\PasskeyRelyingParty;

/** One account's passkeys, with the relying party id and shared handle the WebAuthn Signal API needs (#727). */
final readonly class PasskeyListing
{
    public function __construct(
        private UserPasskeyRepository $passkeys,
        private PasskeyCredentials $credentials,
        private PasskeyRelyingParty $relyingParty,
    ) {
    }

    public function forUser(User $user): AccountPasskeys
    {
        $rows = $this->passkeys->findForUser($user);

        return new AccountPasskeys($this->relyingParty->id(), $this->credentials->sharedHandle($rows), $rows);
    }
}
