<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\AdminUserDetail;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Exception\ValidationException;
use App\Http\AdminUserJson;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use App\Service\Admin\SelfActionGuard;
use App\Service\Admin\UserStatistics;
use App\Service\Mail\AccountMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The approval queue. Access is enforced by ROLE_ADMIN on ^/api/admin/ in
 * security.yaml — the Angular route guard is UX only.
 */
#[Route('/api/admin/users')]
final readonly class AdminUserController
{
    public function __construct(
        private UserRepository $users,
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
        private UserIdentityRepository $identities,
        private EntityManagerInterface $em,
        private AccountMailer $mailer,
        private ClockInterface $clock,
        private UserStatistics $statistics,
        private SelfActionGuard $selfActionGuard,
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

        $userIds = array_values(array_filter(
            array_map(static fn (User $user): ?int => $user->getId(), $users),
            static fn (?int $id): bool => null !== $id,
        ));

        return new JsonResponse([
            'users' => AdminUserJson::listRows(
                $users,
                $this->identities->providersByUserId($users),
                $this->subscriptions->countsByUserIds($userIds),
                $this->tags->countsByUserIds($userIds),
            ),
        ]);
    }

    /**
     * Everything the admin detail screen shows about one account.
     *
     * Hand-built like list(), and for the same reason: a column added to User
     * later must not reach an admin's browser merely because it exists. Note
     * what is absent — the password hash and every token column.
     */
    #[Route('/{id}', name: 'api_admin_users_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        $user = $this->users->getById($id);
        $userId = (int) $user->getId();

        // Loaded once and threaded through every mapper below, rather than
        // re-read per section: findForUserWithTags() is this endpoint's heaviest
        // query (the subscription x tag join set), and this is the one screen
        // that loads a whole library. See
        // AdminUserControllerTest::testTheDetailListsCostTheSameNumberOfQueriesHoweverManySubscriptionsAndTagsExist.
        $subscriptions = AdminUserJson::positionOrdered($this->subscriptions->findForUserWithTags($userId));
        $tags = $this->tags->findForUser($userId);
        $footprint = $this->statistics->forUser($user, $subscriptions, $tags);
        $identities = $this->identities->providersByUserId([$user])[$userId] ?? [];

        return new JsonResponse(new AdminUserDetail(
            user: AdminUserJson::account($user, $identities),
            footprint: AdminUserJson::footprint($footprint),
            tags: AdminUserJson::tags($tags, $subscriptions),
            subscriptions: AdminUserJson::subscriptions($subscriptions),
        ));
    }

    /**
     * Activates an account. The rule for the mail — do not "fix" the cases that
     * stay silent, they are deliberate:
     * The "your account has been approved" mail means "you have been granted
     * access for the first time". Classify any new status against that
     * sentence rather than against the list below — and check the claim, since
     * an earlier version of this comment got `rejected` wrong by grouping it
     * with suspended on the strength of the grouping rather than the sentence.
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
     *   - suspended: a genuine RESTORATION of access they used to have. This
     *     route is deliberately the only way back, rather than an /unsuspend
     *     endpoint for something an admin does once a year, but telling a
     *     returning user they were "approved" would only confuse.
     *   - active: a no-op, which is what makes a double-click safe.
     * Approving a pending_verification account overrides double opt-in: that
     * address was never confirmed, so the approval mail may go somewhere nobody
     * proved they control. That is a real admin decision, made deliberately —
     * the queue lists every status — and the mail itself is harmless.
     * approvedAt is stamped on every successful activation, reinstatement
     * included: it is the audit trail for when access was last granted, which
     * is more useful than preserving the date of the first one.
     * There is intentionally no self-guard here, unlike reject and suspend.
     * Activating an account cannot lock anybody out.
     *
     * @throws TransportExceptionInterface
     */
    #[Route('/{id}/approve', name: 'api_admin_users_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(int $id): JsonResponse
    {
        $user = $this->users->getById($id);
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
        $user = $this->users->getById($id);
        $this->selfActionGuard->ensureNotSelf($user, $admin);

        $user->setStatus(UserStatus::Rejected);
        $this->em->flush();

        return new JsonResponse(['status' => $user->getStatus()->value]);
    }

    #[Route('/{id}/suspend', name: 'api_admin_users_suspend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function suspend(int $id, #[CurrentUser] User $admin): JsonResponse
    {
        $user = $this->users->getById($id);
        $this->selfActionGuard->ensureNotSelf($user, $admin);

        $user->setStatus(UserStatus::Suspended);
        $this->em->flush();

        return new JsonResponse(['status' => $user->getStatus()->value]);
    }
}
