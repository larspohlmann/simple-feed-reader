<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Social\SocialSearchQueryRequest;
use App\Entity\User;
use App\Http\SocialSearchPreviewJson;
use App\Http\SubscriptionJson;
use App\Repository\SubscriptionRepository;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\SearchFeedEditor;
use App\Service\Social\SocialAccountForUser;
use App\Service\Social\SocialSearch\SocialSearchInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final readonly class SocialSearchController
{
    public function __construct(
        private SocialAccountForUser $socialAccount,
        private RateLimitGuard $rateLimitGuard,
        #[Target('socialSearchPreviewLimiter')]
        private RateLimiterFactoryInterface $socialSearchPreviewLimiter,
        private SocialSearchInterface $socialSearch,
        private SocialSearchPreviewJson $searchPreviewJson,
        private SubscriptionRepository $subscriptions,
        private SearchFeedEditor $searchFeedEditor,
    ) {
    }

    #[Route(
        '/api/me/social-accounts/{id}/search-preview',
        name: 'api_me_social_accounts_search_preview',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function preview(
        #[CurrentUser] User $user,
        int $id,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        SocialSearchQueryRequest $request,
    ): JsonResponse {
        $account = $this->socialAccount->requireConnected($user, $id);
        $this->rateLimitGuard->enforceForUser($this->socialSearchPreviewLimiter, $user);

        return new JsonResponse(
            $this->searchPreviewJson->preview($this->socialSearch->newest($account, $request->toQuery())),
        );
    }

    #[Route(
        '/api/subscriptions/{id}/search-query',
        name: 'api_subscriptions_search_query',
        requirements: ['id' => '\d+'],
        methods: ['PUT'],
    )]
    public function requery(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] SocialSearchQueryRequest $request,
    ): JsonResponse {
        $subscription = $this->subscriptions->getOneForUser($user->requireId(), $id);

        $this->searchFeedEditor->requery($subscription, $request->toQuery());

        return new JsonResponse(['subscription' => SubscriptionJson::one($subscription)]);
    }
}
