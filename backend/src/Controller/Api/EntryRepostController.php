<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\SocialRepostJson;
use App\Repository\EntryListRepository;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\SocialReposts;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryRepostController
{
    public function __construct(
        private EntryListRepository $entryList,
        private SocialReposts $reposts,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $socialWritesLimiter,
    ) {
    }

    #[Route('/{id}/repost', name: 'api_entries_repost', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function repost(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialWritesLimiter, $user);

        $this->reposts->repost($user, $entry);

        return new JsonResponse(SocialRepostJson::of(true));
    }

    #[Route('/{id}/repost', name: 'api_entries_unrepost', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function unrepost(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialWritesLimiter, $user);

        $this->reposts->unrepost($user, $entry);

        return new JsonResponse(SocialRepostJson::of(false));
    }

    #[Route(
        '/{id}/comments/repost',
        name: 'api_entries_comment_repost',
        methods: ['PUT'],
        requirements: ['id' => '\d+'],
    )]
    public function repostReply(
        int $id,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] string $address,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialWritesLimiter, $user);

        $this->reposts->repostReply($user, $entry, $address);

        return new JsonResponse(SocialRepostJson::of(true));
    }

    #[Route(
        '/{id}/comments/repost',
        name: 'api_entries_comment_unrepost',
        methods: ['DELETE'],
        requirements: ['id' => '\d+'],
    )]
    public function unrepostReply(
        int $id,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] string $address,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialWritesLimiter, $user);

        $this->reposts->unrepostReply($user, $entry, $address);

        return new JsonResponse(SocialRepostJson::of(false));
    }
}
