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
use App\Service\Account\AccountDeleter;
use App\Service\Admin\UserStatistics;
use App\Service\Admin\UserStatusChanger;
use App\Service\Auth\PasswordResetter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        private UserStatistics $statistics,
        private UserStatusChanger $statusChanger,
        private PasswordResetter $passwordResetter,
        private AccountDeleter $accountDeleter,
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

        // Loaded once and threaded through every mapper below, not re-read per
        // section: findForUserWithTags() is this endpoint's heaviest query (the
        // subscription x tag join) and this screen loads a whole library. See
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
            limits: AdminUserJson::limits($user),
        ));
    }

    /**
     * Activates an account — first-time grant, silent reinstatement, or silent
     * no-op depending on the account's current status. The mail rule and the
     * reasoning behind each case live on UserStatusChanger::approve(), which
     * owns the decision.
     *
     * @throws TransportExceptionInterface
     */
    #[Route('/{id}/approve', name: 'api_admin_users_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(int $id): JsonResponse
    {
        $user = $this->users->getById($id);
        $this->statusChanger->approve($user);

        return new JsonResponse(['status' => $user->getStatus()->value]);
    }

    #[Route('/{id}/reject', name: 'api_admin_users_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(int $id, #[CurrentUser] User $admin): JsonResponse
    {
        $user = $this->users->getById($id);
        $this->statusChanger->reject($user, $admin);

        return new JsonResponse(['status' => $user->getStatus()->value]);
    }

    #[Route('/{id}/suspend', name: 'api_admin_users_suspend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function suspend(int $id, #[CurrentUser] User $admin): JsonResponse
    {
        $user = $this->users->getById($id);
        $this->statusChanger->suspend($user, $admin);

        return new JsonResponse(['status' => $user->getStatus()->value]);
    }

    /**
     * No SelfActionGuard: unlike reject/suspend, generating oneself a new
     * password cannot lock anybody out — the admin ends up with a working
     * account either way.
     */
    #[Route(
        '/{id}/reset-password',
        name: 'api_admin_users_reset_password',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function resetPassword(int $id): JsonResponse
    {
        $user = $this->users->getById($id);

        // Returned once, in the response body only, for the admin to relay out of
        // band. The supported recovery path when the instance sends no mail.
        return new JsonResponse(['password' => $this->passwordResetter->generateAndSet($user)]);
    }

    /**
     * Hard deletion. The self-delete and last-admin guards live on AccountDeleter,
     * which owns the decision; this action only resolves the target and delegates.
     */
    #[Route('/{id}', name: 'api_admin_users_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] User $admin): JsonResponse
    {
        $this->accountDeleter->deleteAsAdmin($this->users->getById($id), $admin);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
