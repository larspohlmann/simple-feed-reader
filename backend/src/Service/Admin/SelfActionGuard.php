<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Exception\ValidationException;

/**
 * Guards against an admin removing their own access. The admin UI is the only
 * way back in, so an admin who rejects or suspends their own account is not
 * recoverable without database access.
 *
 * approve() deliberately has no such guard — see the note there. Activating an
 * account cannot lock anybody out, so the reject and suspend actions call this
 * and approve does not.
 */
final readonly class SelfActionGuard
{
    public function ensureNotSelf(User $target, User $admin): void
    {
        if ($target->getId() === $admin->getId()) {
            throw new ValidationException(['id' => ['You cannot change your own account status.']]);
        }
    }
}
