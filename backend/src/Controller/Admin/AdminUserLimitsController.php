<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\SetSubscriptionLimitRequest;
use App\Dto\Admin\StartTrialRequest;
use App\Http\AdminUserLimitsJson;
use App\Repository\UserRepository;
use App\Service\Admin\UserLimits;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/users')]
final readonly class AdminUserLimitsController
{
    public function __construct(
        private UserRepository $users,
        private UserLimits $userLimits,
    ) {
    }

    #[Route('/{id}/trial', name: 'api_admin_users_start_trial', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function startTrial(int $id, #[MapRequestPayload] StartTrialRequest $request): JsonResponse
    {
        $user = $this->users->getById($id);
        $this->userLimits->startTrial($user, $request->days);

        return new JsonResponse(AdminUserLimitsJson::trial($user));
    }

    #[Route('/{id}/trial', name: 'api_admin_users_clear_trial', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function clearTrial(int $id): JsonResponse
    {
        $user = $this->users->getById($id);
        $this->userLimits->clearTrial($user);

        return new JsonResponse(AdminUserLimitsJson::trial($user));
    }

    #[Route(
        '/{id}/subscription-limit',
        name: 'api_admin_users_set_subscription_limit',
        methods: ['PUT'],
        requirements: ['id' => '\d+'],
    )]
    public function setSubscriptionLimit(
        int $id,
        #[MapRequestPayload] SetSubscriptionLimitRequest $request,
    ): JsonResponse {
        $user = $this->users->getById($id);
        $this->userLimits->setSubscriptionLimit($user, $request->maxSubscriptions);

        return new JsonResponse(AdminUserLimitsJson::subscriptionLimit($user));
    }
}
