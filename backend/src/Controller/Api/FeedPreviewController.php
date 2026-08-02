<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Feed\PreviewFeedRequest;
use App\Entity\User;
use App\Exception\FeedPreviewApiException;
use App\Exception\FeedPreviewException;
use App\Http\FeedPreviewJson;
use App\Service\Preview\FeedPreviewService;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Subscription\Exception\ScrapingDisabledException;
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
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $feedPreviewLimiter,
    ) {
    }

    #[Route('/preview', name: 'api_feeds_preview', methods: ['POST'])]
    public function preview(
        #[CurrentUser] User $user,
        #[MapRequestPayload] PreviewFeedRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->feedPreviewLimiter, $user);

        try {
            $preview = $this->previews->preview($user, $request->url, $request->format);
        } catch (ScrapingDisabledException $e) {
            throw new FeedPreviewApiException($e->getMessage(), $e);
        } catch (FeedPreviewException $e) {
            // Rethrow as an ApiException so the listener keeps the message: a
            // scraped failure carries the extractor's own diagnosis, an xml
            // failure "That address is not a readable feed." — both reach the
            // client as the problem document's `detail`.
            throw new FeedPreviewApiException($e->getMessage(), $e);
        }

        return new JsonResponse(FeedPreviewJson::one($preview));
    }
}
