<?php

declare(strict_types=1);

namespace App\Service\Identity\Passkey\Model;

use App\Entity\UserPasskey;

final readonly class AccountPasskeysModel
{
    /** @param list<UserPasskey> $passkeys */
    public function __construct(
        public string $relyingPartyId,
        public ?string $userHandle,
        public array $passkeys,
    ) {
    }
}
