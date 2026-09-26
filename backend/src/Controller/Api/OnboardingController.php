<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Onboarding\OnboardingSubscribeRequest;
use App\Entity\User;
use App\Http\OnboardingJson;
use App\Service\Catalog\CatalogSubscriber;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/onboarding')]
final readonly class OnboardingController
{
    public function __construct(
        private CatalogSubscriber $subscriber,
    ) {
    }

    /**
     * Subscribes a picker selection and fetches nothing: the new feeds are due at once, and the frontend
     * triggers the sweep after navigating into the reader, so this returns promptly however many were picked.
     */
    #[Route('/subscribe', name: 'api_onboarding_subscribe', methods: ['POST'])]
    public function subscribe(
        #[CurrentUser] User $user,
        #[MapRequestPayload] OnboardingSubscribeRequest $request,
    ): JsonResponse {
        return new JsonResponse(OnboardingJson::subscribed(
            $this->subscriber->subscribe($user, $request->catalogFeedIds),
        ));
    }
}
