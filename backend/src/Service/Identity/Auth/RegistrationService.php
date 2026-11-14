<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Enum\RegistrationMethod;
use App\Enum\TokenPurpose;
use App\Enum\UserStatus;
use App\Event\UserAwaitingApproval;
use App\Security\PasswordWorkEqualizerInterface;
use App\Service\Auth\Factory\SignupUserFactory;
use App\Service\Auth\UserByEmail\UserByEmailInterface;
use App\Service\Mail\AccountMailer\AccountMailerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Random\RandomException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class RegistrationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserByEmailInterface $users,
        private ActionTokenService $tokens,
        private AccountMailerInterface $mailer,
        private PasswordWorkEqualizerInterface $work,
        private EventDispatcherInterface $events,
        private SignupUserFactory $signupUserFactory,
        private PasswordResetter $passwords,
    ) {
    }

    /**
     * Does nothing for a taken address: the caller answers 202 either way, so no response tells which addresses hold
     * accounts. The new status comes from RegistrationPolicy::prospectiveStatusForEmailSignup().
     *
     * @throws TransportExceptionInterface
     * @throws RandomException
     */
    public function register(string $email, string $plainPassword, string $locale = 'en'): void
    {
        if (null !== $this->users->findOneByEmail($email)) {
            // A fresh signup hashes a password; spend the same work so a taken address does not answer faster.
            $this->work->spendOneHash();

            return;
        }

        $user = $this->signupUserFactory->create($email, $plainPassword, $locale);
        $this->entityManager->persist($user);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent signup for the same address; the
            // winner already did the post-flush work. Saying nothing keeps this
            // path's response identical to the duplicate path above.
            return;
        }

        $this->completeRegistration($user);
    }

    /**
     * The one post-flush side effect that each resulting status implies. Active
     * needs none — the account can log in already.
     */
    private function completeRegistration(User $user): void
    {
        match ($user->getStatus()) {
            UserStatus::PendingVerification => $this->mailer->sendVerification(
                $user,
                $this->tokens->issue($user, TokenPurpose::VerifyEmail),
            ),
            UserStatus::PendingApproval => $this->events->dispatch(
                new UserAwaitingApproval($user, RegistrationMethod::EmailPassword),
            ),
            default => null,
        };
    }

    /**
     * Always reports success. No PasswordWorkEqualizer here, on purpose: nothing on this path hashes, and a dummy
     * hash would make an unknown address the slow case. Why: docs/security.md#login-timing
     *
     * @throws TransportExceptionInterface
     * @throws RandomException
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

    /**
     * Re-sends the address-verification mail for an account that has not yet proved its address. A no-op once
     * verified, so the endpoint is safe to call idempotently. Mail is skipped by the gated mailer when disabled.
     *
     * @throws TransportExceptionInterface
     * @throws RandomException
     */
    public function resendVerification(User $user): void
    {
        if ($user->isEmailVerified()) {
            return;
        }

        $this->mailer->sendVerification($user, $this->tokens->issue($user, TokenPurpose::VerifyEmail));
    }

    public function resetPassword(string $plainToken, string $plainPassword): void
    {
        $user = $this->tokens->consume($plainToken, TokenPurpose::ResetPassword);

        $this->passwords->setPassword($user, $plainPassword);
    }
}
