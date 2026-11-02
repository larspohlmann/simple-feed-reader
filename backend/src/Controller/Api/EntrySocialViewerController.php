<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\SocialViewerJson;
use App\Repository\EntryListRepository;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\SocialLikes;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntrySocialViewerController
{
    public function __construct(
        private EntryListRepository $entryList,
        private SocialLikes $socialLikes,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $socialViewerLimiter,
    ) {
    }

    #[Route(
        '/{id}/social/viewer',
        name: 'api_entries_social_viewer',
        methods: ['GET'],
        requirements: ['id' => '\d+'],
    )]
    public function viewer(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialViewerLimiter, $user);

        return new JsonResponse(SocialViewerJson::of($this->socialLikes->syncFromNetwork($user, $entry)));
    }
}
