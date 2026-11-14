<?php

declare(strict_types=1);

namespace App\Service\Identity\Passkey\PasskeyCount;

use App\Entity\User;

interface PasskeyCountInterface
{
    public function countForUser(User $user): int;
}
