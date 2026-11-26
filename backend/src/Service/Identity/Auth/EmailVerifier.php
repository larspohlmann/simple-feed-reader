<?php

declare(strict_types=1);

namespace App\Service\Identity\Auth;

use App\Enum\RegistrationMethod;
use App\Enum\TokenPurpose;
use App\Enum\UserStatus;
use App\Event\UserAwaitingApproval;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class EmailVerifier
{
    public function __construct(
        private ActionTokenService $tokens,
        private RegistrationPolicy $policy,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    /** The status after verification: an admin may have approved the account while the mail was in flight. */
    public function verify(string $plainToken): UserStatus
    {
        $user = $this->tokens->consume($plainToken, TokenPurpose::VerifyEmail);
        $now = $this->clock->now();
        $user->markEmailVerified($now);

        // Re-verifying an already-approved account must not demote it back to the admin queue.
        if (UserStatus::PendingVerification !== $user->getStatus()) {
            $this->entityManager->flush();

            return $user->getStatus();
        }

        if ($this->policy->approvalRequired()) {
            $user->queueForApproval();
            $this->entityManager->flush();

            // After the flush: the account is now persisted in the queue, so
            // a listener that counts it sees the true number, and a failed
            // flush above means no notification goes out.
            $this->events->dispatch(new UserAwaitingApproval($user, RegistrationMethod::EmailPassword));

            return $user->getStatus();
        }

        $user->approve($now);
        $this->entityManager->flush();

        return $user->getStatus();
    }
}
