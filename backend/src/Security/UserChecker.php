<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The api firewall's checker, run on every request as the provider reloads the user: a suspension or an expired
 * trial takes effect on the next request, not when the 7-day token expires.
 */
final readonly class UserChecker implements UserCheckerInterface
{
    public function __construct(private TrialExpiryGuard $trialExpiryGuard)
    {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        $this->trialExpiryGuard->enforce($user);

        if (UserStatus::Active !== $user->getStatus()) {
            throw new AccountStatusException($user->getStatus()->value);
        }
    }

    // Empty, but the signature carries $token because UserCheckerInterface is
    // adding `?TokenInterface $token` to checkPostAuth in its next major, and
    // Symfony's DebugClassLoader deprecates implementations that omit it.
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
