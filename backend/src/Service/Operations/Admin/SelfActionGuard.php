<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Exception\ValidationException;

/** An admin who rejected or suspended their own account could get back in only through the database. */
final readonly class SelfActionGuard
{
    public function ensureNotSelf(User $target, User $admin): void
    {
        if ($target->getId() === $admin->getId()) {
            throw new ValidationException(['id' => ['You cannot change your own account status.']]);
        }
    }

    public function ensureNotSelfDeletion(User $target, User $admin): void
    {
        if ($target->getId() === $admin->getId()) {
            throw new ValidationException(['id' => ['You cannot delete your own account here. Use account settings.']]);
        }
    }
}
