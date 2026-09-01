<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\ReaderJson;
use App\Repository\EntryListRepository;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Reader\ArticleExtractorInterface;
use App\Service\Reader\ExtractionCoverageGate;
use App\Service\Reader\ExtractionResult;
use App\Service\Reader\OriginalHeroResolver;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Split out of EntryController (#592): the reader action pulls in extraction,
 * hero resolution and its own rate limiter, none of which the rest of the
 * entry endpoints need — folding it back in would push that controller's
 * constructor past what ExcessiveParameterList allows.
 */
#[Route('/api/entries')]
final readonly class EntryReaderController
{
    public function __construct(
        private EntryListRepository $entryList,
        private ClockInterface $clock,
        private ArticleExtractorInterface $extractor,
        private ExtractionCoverageGate $coverageGate,
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
        // Ownership is checked BEFORE the limiter so an unowned id 404s without
        // spending the caller's reader budget.
        $entry = $this->entryList->findOneSubscribedByUser($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such entry.');

        $this->rateLimitGuard->enforceForUser($this->readerLimiter, $user);

        $url = $entry->getUrl();
        $result = $url === null || $url === ''
            ? ExtractionResult::failed(null, 'no_url')
            : $this->extractor->extract($url, $entry->getTitle(), $entry->getAuthor());

        // A confident-but-wrong extraction (page furniture instead of the article)
        // is failed here so the client falls back to the feed body (#654).
        $result = $this->coverageGate->verify($result, $entry->getContentHtml());

        $originalHero = $this->originalHero->resolve($entry);

        return new JsonResponse(ReaderJson::one($result, $originalHero, $this->clock->now()));
    }
}
