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
 * approve() carries the mail rule — the silent cases are deliberate, do not
 * "fix" them. The "your account has been approved" mail means "granted
 * access for the first time"; classify any new status against that
 * sentence, not the list below (an earlier version of this comment grouped
 * `rejected` with suspended by list position instead, and got it wrong).
 *
 * MAILS — the user never had access, and now does:
 *   - pending_approval: verified their address, waited in the queue.
 *   - pending_verification: never confirmed their address; approving
 *     overrides double opt-in, but the grant is just as real. That override
 *     is a deliberate admin decision — the queue lists every status — and
 *     the mail itself is harmless.
 *   - rejected: an admin declined them and changed their mind. Rejection is
 *     only reachable FROM pending_approval, so a rejected user has never had
 *     access — a first-time grant, and the case where the user is certainly
 *     waiting to hear. Silence left them holding a working account they had
 *     no reason to try.
 *
 * SILENT — nothing was granted that the user did not already have:
 *   - suspended: a genuine RESTORATION of access they used to have. approve()
 *     is deliberately the only way back (no /unsuspend endpoint for a
 *     once-a-year action), but telling a returning user they were "approved"
 *     would only confuse.
 *   - active: a no-op, which makes a double-click safe.
 *
 * approvedAt is stamped on every successful activation, reinstatement
 * included: the audit trail for when access was last granted, more useful
 * than preserving the first date.
 *
 * approve() intentionally calls no self-guard, unlike reject() and
 * suspend(): activating an account cannot lock anybody out.
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
