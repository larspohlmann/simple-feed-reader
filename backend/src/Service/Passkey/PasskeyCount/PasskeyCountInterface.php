<?php

declare(strict_types=1);

namespace App\Service\Passkey\PasskeyCount;

use App\Entity\User;

interface PasskeyCountInterface
{
    public function countForUser(User $user): int;
}
