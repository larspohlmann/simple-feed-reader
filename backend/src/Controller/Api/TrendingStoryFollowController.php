<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\FollowedStoryJson;
use App\Service\Curation\Trending\TrendingStoryFollowing;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/trending')]
final readonly class TrendingStoryFollowController
{
    public function __construct(private TrendingStoryFollowing $following)
    {
    }

    #[Route('/followed', name: 'api_trending_followed', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(FollowedStoryJson::list($this->following->followedBy($user->requireId())));
    }

    #[Route('/stories/{id}/follow', name: 'api_trending_follow', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function follow(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $this->following->follow($user->requireId(), $id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/stories/{id}/follow', name: 'api_trending_unfollow', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function unfollow(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $this->following->unfollow($user->requireId(), $id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/stories/{id}/opened', name: 'api_trending_opened', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function opened(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $this->following->markOpened($user->requireId(), $id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
