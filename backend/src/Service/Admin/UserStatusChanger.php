<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Service\Mail\AccountMailerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * The admin's three account status transitions: approve, reject, suspend.
 *
 * approve() carries the mail rule — do not "fix" the cases that stay silent,
 * they are deliberate:
 * The "your account has been approved" mail means "you have been granted
 * access for the first time". Classify any new status against that sentence
 * rather than against the list below — and check the claim, since an earlier
 * version of this comment got `rejected` wrong by grouping it with suspended
 * on the strength of the grouping rather than the sentence.
 * MAILS — the user has never had access, and now does:
 *   - pending_approval: verified their address, waited in the queue.
 *   - pending_verification: never confirmed their address; approving
 *     overrides double opt-in (see below), but the grant is just as real.
 *   - rejected: an admin declined them and has now changed their mind.
 *     Rejection is only reachable FROM pending_approval, so a rejected user
 *     has never once had access — this is a first-time grant, and the one
 *     case where the user is certainly waiting to hear, having applied and
 *     seen nothing happen. Silence here left them holding a working account
 *     they had no reason to try.
 * SILENT — nothing was granted that the user did not already have:
 *   - suspended: a genuine RESTORATION of access they used to have. approve()
 *     is deliberately the only way back, rather than an /unsuspend endpoint
 *     for something an admin does once a year, but telling a returning user
 *     they were "approved" would only confuse.
 *   - active: a no-op, which is what makes a double-click safe.
 * Approving a pending_verification account overrides double opt-in: that
 * address was never confirmed, so the approval mail may go somewhere nobody
 * proved they control. That is a real admin decision, made deliberately — the
 * queue lists every status — and the mail itself is harmless.
 * approvedAt is stamped on every successful activation, reinstatement
 * included: it is the audit trail for when access was last granted, which is
 * more useful than preserving the date of the first one.
 * approve() intentionally calls no self-guard, unlike reject() and suspend().
 * Activating an account cannot lock anybody out.
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
        $isFirstTimeGrant = \in_array(
            $user->getStatus(),
            [UserStatus::PendingApproval, UserStatus::PendingVerification, UserStatus::Rejected],
            true,
        );

        $user->setStatus(UserStatus::Active);
        $user->setApprovedAt($this->clock->now());
        $this->entityManager->flush();

        if ($isFirstTimeGrant) {
            $this->mailer->sendApproved($user);
        }
    }

    public function reject(User $user, User $admin): void
    {
        $this->selfActionGuard->ensureNotSelf($user, $admin);

        $user->setStatus(UserStatus::Rejected);
        $this->entityManager->flush();
    }

    public function suspend(User $user, User $admin): void
    {
        $this->selfActionGuard->ensureNotSelf($user, $admin);

        $user->setStatus(UserStatus::Suspended);
        $this->entityManager->flush();
    }
}
