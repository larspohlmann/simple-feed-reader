<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\User;

final class AdminUserLimitsJson
{
    /** @return array{status: string, trialEndsAt: string|null} */
    public static function trial(User $user): array
    {
        return [
            'status' => $user->getStatus()->value,
            'trialEndsAt' => TrialEndJson::of($user),
        ];
    }

    /** @return array{maxSubscriptions: int|null} */
    public static function subscriptionLimit(User $user): array
    {
        return ['maxSubscriptions' => $user->getMaxSubscriptions()];
    }
}
