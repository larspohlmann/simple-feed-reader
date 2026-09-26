<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Enum\UserStatus;

final class NewUserStatus
{
    public static function apply(User $newUser, UserStatus $status, \DateTimeImmutable $approvedAt): void
    {
        if (UserStatus::Active === $status) {
            $newUser->approve($approvedAt);

            return;
        }
        if (UserStatus::PendingApproval === $status) {
            $newUser->queueForApproval();

            return;
        }
        if (UserStatus::Rejected === $status) {
            $newUser->reject();

            return;
        }
        if (UserStatus::Suspended === $status) {
            $newUser->suspend();
        }
    }
}
