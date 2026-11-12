<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Feed\DiscoverFeedsRequest;
use App\Dto\Feed\PreviewFeedRequest;
use App\Entity\User;
use App\Http\FeedPreviewJson;
use App\Http\PreviewedDiscoveryJson;
use App\Service\Preview\FeedPreviewService;
use App\Service\Preview\PreviewedDiscovery;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/feeds')]
final readonly class FeedPreviewController
{
    public function __construct(
        private FeedPreviewService $previews,
        private PreviewedDiscovery $discoveries,
        private RateLimitGuard $rateLimitGuard,
        #[Target]
        private RateLimiterFactoryInterface $feedPreviewLimiter,
        #[Target]
        private RateLimiterFactoryInterface $feedDiscoverLimiter,
    ) {
    }

    #[Route('/preview', name: 'api_feeds_preview', methods: ['POST'])]
    public function preview(
        #[CurrentUser] User $user,
        #[MapRequestPayload] PreviewFeedRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->feedPreviewLimiter, $user);
        $preview = $this->previews->preview($user, $request->url, $request->format);

        return new JsonResponse(FeedPreviewJson::one($preview));
    }

    #[Route('/discover', name: 'api_feeds_discover', methods: ['POST'])]
    public function discover(
        #[CurrentUser] User $user,
        #[MapRequestPayload] DiscoverFeedsRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->feedDiscoverLimiter, $user);

        return new JsonResponse(PreviewedDiscoveryJson::of($this->discoveries->discover($user, $request->url)));
    }
}
