<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Feed\PreviewFeedRequest;
use App\Entity\User;
use App\Exception\FeedPreviewException;
use App\Exception\RateLimitedException;
use App\Http\FeedPreviewJson;
use App\Service\Preview\FeedPreviewService;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/feeds')]
final class FeedPreviewController
{
    public function __construct(
        private readonly FeedPreviewService $previews,
        private readonly RateLimiterFactoryInterface $feedPreviewLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/preview', name: 'api_feeds_preview', methods: ['POST'])]
    public function preview(
        #[CurrentUser] User $user,
        #[MapRequestPayload] PreviewFeedRequest $request,
    ): JsonResponse {
        $this->enforceLimit($user);

        try {
            $preview = $this->previews->preview($request->url);
        } catch (FeedPreviewException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return new JsonResponse(FeedPreviewJson::one($preview));
    }

    private function enforceLimit(User $user): void
    {
        $limit = $this->feedPreviewLimiter->create('user-' . $user->getId())->consume();
        if ($limit->isAccepted()) {
            return;
        }

        throw new RateLimitedException(
            max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()),
        );
    }
}
