<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Onboarding\OnboardingSubscribeRequest;
use App\Entity\Tag;
use App\Entity\User;
use App\Http\TagJson;
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
     * Subscribes a picker selection. Fetches nothing: the new feeds are due
     * immediately and the frontend triggers the sweep after it has navigated
     * into the reader, so this request returns promptly however many feeds were
     * selected.
     */
    #[Route('/subscribe', name: 'api_onboarding_subscribe', methods: ['POST'])]
    public function subscribe(
        #[CurrentUser] User $user,
        #[MapRequestPayload] OnboardingSubscribeRequest $request,
    ): JsonResponse {
        $result = $this->subscriber->subscribe($user, $request->catalogFeedIds);

        return new JsonResponse([
            'subscribed' => $result->imported,
            'skipped' => $result->alreadySubscribed + $result->invalid + $result->skippedOverLimit,
            'skippedOverLimit' => $result->skippedOverLimit,
            'tagsCreated' => array_map(static fn (Tag $tag) => TagJson::one($tag), $result->tagsCreated),
        ]);
    }
}
