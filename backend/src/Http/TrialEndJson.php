<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\User;

final class TrialEndJson
{
    public static function of(User $user): ?string
    {
        return $user->getTrialEndsAt()?->format(\DateTimeInterface::ATOM);
    }
}
