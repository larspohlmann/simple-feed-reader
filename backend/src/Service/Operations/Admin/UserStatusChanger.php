<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Service\Mail\AccountMailer\AccountMailerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * approve() mails only a first-time grant; rejected counts, since rejection is reachable only from pending_approval.
 * Reinstating a suspended account stays silent: "your account has been approved" would misdescribe it.
 */
final readonly class UserStatusChanger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private AccountMailerInterface $mailer,
        private SelfActionGuard $selfActionGuard,
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function approve(User $user): void
    {
        $isFirstTimeGrant = match ($user->getStatus()) {
            UserStatus::PendingApproval, UserStatus::PendingVerification, UserStatus::Rejected => true,
            UserStatus::Suspended, UserStatus::Active => false,
        };

        $user->approve($this->clock->now());
        $this->entityManager->flush();

        if ($isFirstTimeGrant) {
            $this->mailer->sendApproved($user);
        }
    }

    public function reject(User $user, User $admin): void
    {
        $this->selfActionGuard->ensureNotSelf($user, $admin);

        $user->reject();
        $this->entityManager->flush();
    }

    public function suspend(User $user, User $admin): void
    {
        $this->selfActionGuard->ensureNotSelf($user, $admin);

        $user->suspend();
        $this->entityManager->flush();
    }
}
