<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\SocialLikeJson;
use App\Repository\EntryListRepository;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\SocialLikes;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryLikeController
{
    public function __construct(
        private EntryListRepository $entryList,
        private SocialLikes $socialLikes,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $socialLikeLimiter,
    ) {
    }

    #[Route('/{id}/like', name: 'api_entries_like', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function like(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialLikeLimiter, $user);

        $this->socialLikes->like($user, $entry);

        return new JsonResponse(SocialLikeJson::of(true));
    }

    #[Route('/{id}/like', name: 'api_entries_unlike', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function unlike(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialLikeLimiter, $user);

        $this->socialLikes->unlike($user, $entry);

        return new JsonResponse(SocialLikeJson::of(false));
    }
}
