<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\ReaderJson;
use App\Repository\EntryListRepository;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Reader\EntryArticleReader;
use App\Service\Reader\OriginalHeroResolver;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryReaderController
{
    public function __construct(
        private EntryListRepository $entryList,
        private ClockInterface $clock,
        private EntryArticleReader $articleReader,
        private OriginalHeroResolver $originalHero,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $readerLimiter,
    ) {
    }

    #[Route('/{id}/reader', name: 'api_entries_reader', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function reader(
        int $id,
        #[CurrentUser] User $user,
    ): JsonResponse {
        // Ownership is checked BEFORE the limiter so an unowned id 404s without spending the caller's reader budget.
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);

        if ($this->articleReader->hasPageToFetch($entry)) {
            $this->rateLimitGuard->enforceForUser($this->readerLimiter, $user);
        }

        return new JsonResponse(ReaderJson::one(
            $this->articleReader->read($entry),
            $this->originalHero->resolve($entry),
            $this->clock->now(),
        ));
    }
}
