<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\User;
use App\Enum\RegistrationMethod;

/**
 * A user has just entered UserStatus::PendingApproval and needs an admin to act.
 *
 * Dispatched AFTER the transition is flushed, so a listener that counts the
 * queue sees this user in it, and a failed flush produces no notification.
 */
final readonly class UserAwaitingApproval
{
    public function __construct(
        public User $user,
        public RegistrationMethod $method,
        public ?string $oauthProvider = null,
    ) {
    }
}
