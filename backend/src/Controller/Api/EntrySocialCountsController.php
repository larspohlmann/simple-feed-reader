<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\SocialCountsJson;
use App\Repository\EntryListRepository;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\SocialCounts;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntrySocialCountsController
{
    public function __construct(
        private EntryListRepository $entryList,
        private SocialCounts $socialCounts,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $socialCountsLimiter,
    ) {
    }

    #[Route('/{id}/social', name: 'api_entries_social_counts', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function counts(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialCountsLimiter, $user);

        $counts = $request->query->getBoolean('refresh')
            ? $this->socialCounts->refreshed($user, $entry)
            : $this->socialCounts->of($user, $entry);

        return new JsonResponse(SocialCountsJson::of($counts));
    }
}
