<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Enum\TokenPurpose;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Security\PasswordWorkEqualizer;
use App\Service\Mail\AccountMailer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
        private PasswordWorkEqualizer $work,
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
            // Identical bytes are not enough. A fresh signup pays for an
            // argon2id hash (~174 ms) that this path would otherwise skip
            // entirely, and a gap that size is a reliable oracle over the
            // network no matter how equal the responses look. Spend the same
            // work before returning. See App\Security\PasswordWorkEqualizer,
            // which login has used for the same reason since Task 11.
            $this->work->spendOneHash();

            return;
        }

        $now = $this->clock->now();
        $user = new User($email, $now);
        $user->setStatus(UserStatus::PendingVerification);
        $user->setPasswordHash($this->hasher->hashPassword($user, $plainPassword), $now);

        $this->em->persist($user);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Lost a race: between the SELECT above and this INSERT, another
            // request registered the same address. A double-clicked submit
            // button is enough to cause it, and without this catch the loser
            // gets an opaque 500 where the winner got a 202 — a broken user
            // action, and a response that differs from the duplicate path and
            // so leaks that a signup for this address was in flight.
            //
            // The winner has already sent the verification mail, so the right
            // move is to say nothing at all. Doctrine closes the EntityManager
            // on a failed flush, which is safe here only because this is the
            // last database work in the request; the controller does nothing
            // afterwards but serialise a fixed array.
            return;
        }

        $this->mailer->sendVerification(
            $user,
            $this->tokens->issue($user, TokenPurpose::VerifyEmail),
        );
    }

    /**
     * Returns the account's status *after* verification, so the caller can
     * report what is actually true rather than what is usually true.
     *
     * The distinction matters: an admin may have approved the account between
     * the mail being sent and the link being clicked, in which case the user is
     * already Active. Answering a blanket "pending_approval" there would tell
     * someone who can sign in right now to sit and wait for an approval that
     * already happened.
     *
     * @return UserStatus|null null when the token is unknown, used, or expired
     */
    public function verifyEmail(string $plainToken): ?UserStatus
    {
        $user = $this->tokens->consume($plainToken, TokenPurpose::VerifyEmail);
        if (null === $user) {
            return null;
        }

        // Re-verifying an already-approved account must not demote it back to
        // the admin queue.
        if (UserStatus::PendingVerification === $user->getStatus()) {
            $user->setStatus(UserStatus::PendingApproval);
            $this->em->flush();
        }

        return $user->getStatus();
    }

    /**
     * Always reports success to the caller. Whether the address exists, and
     * whether its account is in a state that may reset, stays private.
     *
     * Deliberately does NOT call PasswordWorkEqualizer, unlike register().
     * The asymmetry is not an oversight — it is the whole point. Nothing on
     * this endpoint hashes a password: the eligible path issues a token and
     * queues mail, and the two short paths return after a SELECT. Adding a
     * dummy hash to the short paths would make "unknown address" ~174 ms
     * SLOWER than "account exists and got a mail", manufacturing a far louder
     * oracle than the one being closed, pointing the other way.
     *
     * What actually closed the gap here was deferring the SMTP round trip past
     * the response (see DeferredMailer). What remains between the paths is one
     * INSERT and one UPDATE for the token — sub-millisecond, and far under
     * network jitter. Measured rather than assumed; see the timing figures in
     * the task report.
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

        // Stamping the change is what evicts tokens minted before it — see
        // App\Security\PasswordChangeTokenInvalidator. Without it this method
        // changes the password and leaves whoever stole a token still signed
        // in, which is the opposite of what a reset is for.
        $user->setPasswordHash($this->hasher->hashPassword($user, $plainPassword), $this->clock->now());
        $this->em->flush();

        return true;
    }
}
