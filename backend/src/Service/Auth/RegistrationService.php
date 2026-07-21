<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Enum\TokenPurpose;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\Mail\AccountMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class RegistrationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private UserPasswordHasherInterface $hasher,
        private ActionTokenService $tokens,
        private AccountMailer $mailer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Silently does nothing when the address is already registered. The caller
     * returns the same 202 either way — a different response here would let
     * anyone test which addresses hold accounts.
     */
    public function register(string $email, string $plainPassword): void
    {
        if (null !== $this->users->findOneByEmail($email)) {
            return;
        }

        $user = new User($email, $this->clock->now());
        $user->setStatus(UserStatus::PendingVerification);
        $user->setPasswordHash($this->hasher->hashPassword($user, $plainPassword));

        $this->em->persist($user);
        $this->em->flush();

        $this->mailer->sendVerification(
            $user,
            $this->tokens->issue($user, TokenPurpose::VerifyEmail),
        );
    }

    /** @return bool false when the token is unknown, used, or expired */
    public function verifyEmail(string $plainToken): bool
    {
        $user = $this->tokens->consume($plainToken, TokenPurpose::VerifyEmail);
        if (null === $user) {
            return false;
        }

        // Re-verifying an already-approved account must not demote it back to
        // the admin queue.
        if (UserStatus::PendingVerification === $user->getStatus()) {
            $user->setStatus(UserStatus::PendingApproval);
            $this->em->flush();
        }

        return true;
    }

    /**
     * Always reports success to the caller. Whether the address exists, and
     * whether its account is in a state that may reset, stays private.
     */
    public function requestPasswordReset(string $email): void
    {
        $user = $this->users->findOneByEmail($email);
        if (null === $user) {
            return;
        }

        // A pending or rejected account has no password worth resetting, and
        // sending the mail would confirm the address exists.
        if (!\in_array($user->getStatus(), [UserStatus::Active, UserStatus::Suspended], true)) {
            return;
        }

        $this->mailer->sendPasswordReset(
            $user,
            $this->tokens->issue($user, TokenPurpose::ResetPassword),
        );
    }

    public function resetPassword(string $plainToken, string $plainPassword): bool
    {
        $user = $this->tokens->consume($plainToken, TokenPurpose::ResetPassword);
        if (null === $user) {
            return false;
        }

        $user->setPasswordHash($this->hasher->hashPassword($user, $plainPassword));
        $this->em->flush();

        return true;
    }
}
