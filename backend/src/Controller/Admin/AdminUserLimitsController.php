<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\SetSubscriptionLimitRequest;
use App\Dto\Admin\StartTrialRequest;
use App\Repository\UserRepository;
use App\Service\Admin\UserLimits;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin's per-account limit controls: start or clear a trial, and set or
 * clear the per-user subscription cap. Split out of AdminUserController so
 * that controller's constructor does not grow past PHPStorm/PHPMD's
 * ExcessiveParameterList threshold — these three actions need only the two
 * collaborators below. Access is enforced by ROLE_ADMIN on ^/api/admin/ in
 * security.yaml, the same as every other controller under that prefix.
 */
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

        return new JsonResponse([
            'status' => $user->getStatus()->value,
            'trialEndsAt' => $user->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}/trial', name: 'api_admin_users_clear_trial', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function clearTrial(int $id): JsonResponse
    {
        $user = $this->users->getById($id);
        $this->userLimits->clearTrial($user);

        return new JsonResponse([
            'status' => $user->getStatus()->value,
            'trialEndsAt' => $user->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
        ]);
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

        return new JsonResponse(['maxSubscriptions' => $user->getMaxSubscriptions()]);
    }
}
