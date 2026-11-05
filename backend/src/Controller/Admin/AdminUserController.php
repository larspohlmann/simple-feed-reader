<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\AdminUserDetail;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Exception\ValidationException;
use App\Http\AdminUserJson;
use App\Repository\SubscriptionCountsByUserId;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use App\Service\Admin\UserStatistics;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/users')]
final readonly class AdminUserController
{
    public function __construct(
        private UserRepository $users,
        private SubscriptionRepository $subscriptions,
        private SubscriptionCountsByUserId $subscriptionCounts,
        private TagRepository $tags,
        private UserIdentityRepository $identities,
        private UserStatistics $statistics,
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
                $this->subscriptionCounts->forUserIds($userIds),
                $this->tags->countsByUserIds($userIds),
            ),
        ]);
    }

    #[Route('/{id}', name: 'api_admin_users_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        $user = $this->users->getById($id);
        $userId = $user->requireId();

        // Loaded once and passed to every mapper: the subscription x tag join is this endpoint's heaviest query.
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
}
