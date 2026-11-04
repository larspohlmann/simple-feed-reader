<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Trending\HideTrendingStoryRequest;
use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Http\TrendingJson;
use App\Service\Trending\TrendingStories;
use App\Service\Trending\TrendingStoryHiding;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/trending')]
final readonly class TrendingController
{
    public function __construct(
        private TrendingStories $trendingStories,
        private TrendingJson $trendingJson,
        private TrendingStoryHiding $storyHiding,
    ) {
    }

    #[Route('', name: 'api_trending', methods: ['GET'])]
    public function list(#[CurrentUser] User $user, #[MapQueryParameter] ?string $window = null): JsonResponse
    {
        $page = $this->trendingStories->forReader($user->requireId(), TrendingWindow::fromRequestValue($window));

        return new JsonResponse($this->trendingJson->page($page));
    }

    #[Route('/hidden', name: 'api_trending_hide', methods: ['POST'])]
    public function hide(
        #[CurrentUser] User $user,
        #[MapRequestPayload] HideTrendingStoryRequest $request,
    ): JsonResponse {
        $this->storyHiding->hide(
            $user->requireId(),
            TrendingWindow::fromRequestValue($request->window),
            $request->entryId,
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
