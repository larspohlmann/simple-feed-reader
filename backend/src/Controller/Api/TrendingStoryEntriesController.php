<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Entry\EntryPageParameters;
use App\Entity\User;
use App\Http\TrendingStoryPage;
use App\Repository\EntryListRowEnricher;
use App\Repository\TrendingStoryListQuery;
use App\Service\Curation\Trending\TrendingStoryEntries;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** A Trending story's entries; reading them records no visit, the client posts that separately. */
#[Route('/api/entries/trending-stories')]
final readonly class TrendingStoryEntriesController
{
    public function __construct(
        private TrendingStoryEntries $entries,
        private EntryListRowEnricher $enricher,
        private TrendingStoryPage $storyPage,
    ) {
    }

    #[Route('/{id}', name: 'api_entries_trending_story', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function one(
        int $id,
        #[CurrentUser] User $user,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        EntryPageParameters $page = new EntryPageParameters(),
    ): JsonResponse {
        $userId = $user->requireId();
        $query = new TrendingStoryListQuery(
            userId: $userId,
            storyId: $id,
            page: $page->toListPage(),
        );
        $result = $this->entries->list($query);
        $rows = $this->enricher->enrich($result->rows, $userId);

        return new JsonResponse($this->storyPage->of($result->withRows($rows), $query->page->limit));
    }
}
