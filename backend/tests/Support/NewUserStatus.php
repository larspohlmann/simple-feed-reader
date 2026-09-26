<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Enum\UserStatus;

final class NewUserStatus
{
    public static function apply(User $newUser, UserStatus $status, \DateTimeImmutable $approvedAt): void
    {
        match ($status) {
            UserStatus::Active => $newUser->approve($approvedAt),
            UserStatus::PendingApproval => $newUser->queueForApproval(),
            UserStatus::Rejected => $newUser->reject(),
            UserStatus::Suspended => $newUser->suspend(),
            UserStatus::PendingVerification => null,
        };
    }
}
