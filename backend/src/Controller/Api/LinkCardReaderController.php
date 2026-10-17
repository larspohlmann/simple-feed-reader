<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\ReaderJson;
use App\Repository\EntryListRepository;
use App\Repository\LinkCardRepository;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Reader\LinkCardArticleReader;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class LinkCardReaderController
{
    public function __construct(
        private EntryListRepository $entryList,
        private LinkCardRepository $linkCards,
        private ClockInterface $clock,
        private LinkCardArticleReader $articleReader,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $readerLimiter,
    ) {
    }

    #[Route(
        '/{id}/link-cards/{position}/reader',
        name: 'api_entries_link_card_reader',
        methods: ['GET'],
        requirements: ['id' => '\d+', 'position' => '\d+'],
    )]
    public function reader(int $id, int $position, #[CurrentUser] User $user): JsonResponse
    {
        // Ownership is checked BEFORE the limiter so an unowned card 404s without spending the caller's reader budget.
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $card = $this->linkCards->contentAt($entry->requireId(), $position);
        $this->rateLimitGuard->enforceForUser($this->readerLimiter, $user);

        return new JsonResponse(ReaderJson::one(
            $this->articleReader->read($card),
            $this->articleReader->lead($card),
            $this->clock->now(),
        ));
    }
}
