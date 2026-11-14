<?php

declare(strict_types=1);

namespace App\Service\Passkey\SignInIdentities;

use App\Entity\User;

interface SignInIdentitiesInterface
{
    public function existsForUser(User $user): bool;
}
