<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\SocialLikeJson;
use App\Repository\EntryListRepository;
use App\Service\Foundation\RateLimit\RateLimitGuard;
use App\Service\Social\Social\EntryLiking;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryLikeController
{
    public function __construct(
        private EntryListRepository $entryList,
        private EntryLiking $liking,
        private RateLimitGuard $rateLimitGuard,
        #[Target]
        private RateLimiterFactoryInterface $socialLikeLimiter,
    ) {
    }

    #[Route('/{id}/like', name: 'api_entries_like', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function like(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $row = $this->entryList->getRowForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialLikeLimiter, $user);

        return new JsonResponse(SocialLikeJson::fromPostLike(true, $this->liking->like($user, $row)));
    }

    #[Route('/{id}/like', name: 'api_entries_unlike', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function unlike(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $row = $this->entryList->getRowForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialLikeLimiter, $user);

        return new JsonResponse(SocialLikeJson::fromPostLike(false, $this->liking->unlike($user, $row)));
    }
}
