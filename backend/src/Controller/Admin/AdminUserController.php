<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\UserStatus;
use App\Exception\ValidationException;
use App\Repository\UserRepository;
use App\Service\Mail\AccountMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The approval queue. Access is enforced by ROLE_ADMIN on ^/api/admin/ in
 * security.yaml — the Angular route guard is UX only.
 */
#[Route('/api/admin/users')]
final class AdminUserController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AccountMailer $mailer,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'api_admin_users_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $statusFilter = $request->query->get('status');
        $statuses = null;

        if (\is_string($statusFilter) && '' !== $statusFilter) {
            $status = UserStatus::tryFrom($statusFilter);
            if (null === $status) {
                throw new ValidationException(['status' => ['Unknown account status.']]);
            }
            $statuses = [$status];
        }

        $users = $this->users->findForAdminList($statuses);
        $providersByUserId = $this->providersByUserId($users);

        return new JsonResponse([
            'users' => array_map(
                // Hand-built, like GET /api/me: a column added later must not
                // reach an admin's browser just because it exists.
                static fn (User $user): array => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'status' => $user->getStatus()->value,
                    'roles' => $user->getRoles(),
                    'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    'approvedAt' => $user->getApprovedAt()?->format(\DateTimeInterface::ATOM),
                    // How this person signed up. An OAuth account has no
                    // verification mail for the admin to chase and may carry a
                    // synthetic <provider>-<hash>@oauth.invalid address, and
                    // both of those read as anomalies without this column.
                    'identities' => $providersByUserId[$user->getId()] ?? [],
                ],
                $users,
            ),
        ]);
    }

    /**
     * The sign-in providers of every listed user, read in ONE query and indexed
     * by user id.
     *
     * User holds no ORM association to UserIdentity — Plan 1 kept that
     * relationship one-directional and lets the database FK cascade the deletes
     * — so there is nothing to traverse, and the obvious per-row lookup would
     * be an N+1 that no assertion on the response body could ever catch. It is
     * pinned by a query count instead: see
     * AdminUserControllerTest::testTheProviderColumnCostsOneQueryHoweverManyUsersAreListed.
     *
     * Only the provider NAME is selected. The row also holds the address the
     * provider last reported, and that is deliberately left out: it is a second
     * address for the same person, of no use in deciding an approval, and the
     * hand-built row above exists precisely to keep columns from reaching an
     * admin's browser merely because they exist.
     *
     * @param list<User> $users
     *
     * @return array<int, list<string>>
     */
    private function providersByUserId(array $users): array
    {
        // An empty IN () is a syntax error on both engines, and there is
        // nothing to ask about anyway — a status filter matching nobody is an
        // ordinary outcome, not an edge case.
        if ([] === $users) {
            return [];
        }

        /** @var list<array{userId: int|string, provider: string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(i.user) AS userId', 'i.provider')
            ->from(UserIdentity::class, 'i')
            ->andWhere('i.user IN (:users)')
            ->setParameter('users', $users)
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[(int) $row['userId']][] = $row['provider'];
        }

        return $byUser;
    }

    /**
     * Activates an account. The rule for the mail — do not "fix" the cases that
     * stay silent, they are deliberate:
     *
     * The "your account has been approved" mail means "you have been granted
     * access for the first time". Classify any new status against that
     * sentence rather than against the list below — and check the claim, since
     * an earlier version of this comment got `rejected` wrong by grouping it
     * with suspended on the strength of the grouping rather than the sentence.
     *
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
     *
     * SILENT — nothing was granted that the user did not already have:
     *   - suspended: a genuine RESTORATION of access they used to have. This
     *     route is deliberately the only way back, rather than an /unsuspend
     *     endpoint for something an admin does once a year, but telling a
     *     returning user they were "approved" would only confuse.
     *   - active: a no-op, which is what makes a double-click safe.
     *
     * Approving a pending_verification account overrides double opt-in: that
     * address was never confirmed, so the approval mail may go somewhere nobody
     * proved they control. That is a real admin decision, made deliberately —
     * the queue lists every status — and the mail itself is harmless.
     *
     * approvedAt is stamped on every successful activation, reinstatement
     * included: it is the audit trail for when access was last granted, which
     * is more useful than preserving the date of the first one.
     *
     * There is intentionally no self-guard here, unlike reject and suspend.
     * Activating an account cannot lock anybody out.
     */
    #[Route('/{id}/approve', name: 'api_admin_users_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(int $id): JsonResponse
    {
        $user = $this->requireUser($id);
        $isFirstTimeGrant = \in_array(
            $user->getStatus(),
            [UserStatus::PendingApproval, UserStatus::PendingVerification, UserStatus::Rejected],
            true,
        );

        $user->setStatus(UserStatus::Active);
        $user->setApprovedAt($this->clock->now());
        $this->em->flush();

        if ($isFirstTimeGrant) {
            $this->mailer->sendApproved($user);
        }

        return new JsonResponse(['status' => $user->getStatus()->value]);
    }

    #[Route('/{id}/reject', name: 'api_admin_users_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(int $id, #[CurrentUser] User $admin): JsonResponse
    {
        $user = $this->requireNotSelf($id, $admin);

        $user->setStatus(UserStatus::Rejected);
        $this->em->flush();

        return new JsonResponse(['status' => $user->getStatus()->value]);
    }

    #[Route('/{id}/suspend', name: 'api_admin_users_suspend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function suspend(int $id, #[CurrentUser] User $admin): JsonResponse
    {
        $user = $this->requireNotSelf($id, $admin);

        $user->setStatus(UserStatus::Suspended);
        $this->em->flush();

        return new JsonResponse(['status' => $user->getStatus()->value]);
    }

    private function requireUser(int $id): User
    {
        return $this->users->find($id) ?? throw new NotFoundHttpException('User not found.');
    }

    /**
     * Guards against an admin removing their own access. The admin UI is the
     * only way back in, so this is not recoverable without database access.
     *
     * approve() deliberately has no such guard — see the note there.
     */
    private function requireNotSelf(int $id, User $admin): User
    {
        $user = $this->requireUser($id);

        if ($user->getId() === $admin->getId()) {
            throw new ValidationException(['id' => ['You cannot change your own account status.']]);
        }

        return $user;
    }
}
