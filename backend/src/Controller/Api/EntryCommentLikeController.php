<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\SocialLikeJson;
use App\Repository\EntryListRepository;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\SocialCommentLikes;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryCommentLikeController
{
    public function __construct(
        private EntryListRepository $entryList,
        private SocialCommentLikes $commentLikes,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $socialLikeLimiter,
    ) {
    }

    #[Route('/{id}/comments/like', name: 'api_entries_comment_like', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function like(
        int $id,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] string $address,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialLikeLimiter, $user);

        $this->commentLikes->like($user, $entry, $address);

        return new JsonResponse(SocialLikeJson::of(true));
    }

    #[Route(
        '/{id}/comments/like',
        name: 'api_entries_comment_unlike',
        methods: ['DELETE'],
        requirements: ['id' => '\d+'],
    )]
    public function unlike(
        int $id,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] string $address,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialLikeLimiter, $user);

        $this->commentLikes->unlike($user, $entry, $address);

        return new JsonResponse(SocialLikeJson::of(false));
    }
}
