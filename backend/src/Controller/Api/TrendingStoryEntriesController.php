<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Entry\EntryPageParameters;
use App\Dto\Entry\MarkTrendingStoryReadRequest;
use App\Entity\User;
use App\Http\TrendingStoryPage;
use App\Service\Library\EntryList\EntryListRowEnricher;
use App\Repository\TrendingStoryListQuery;
use App\Service\Curation\Trending\TrendingStoryEntries;
use App\Service\Library\Reading\TrendingStoryMarkReadService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
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
        private TrendingStoryMarkReadService $markRead,
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

    #[Route(
        '/{id}/mark-read',
        name: 'api_entries_trending_story_mark_read',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function markRead(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] MarkTrendingStoryReadRequest $request,
    ): JsonResponse {
        $this->markRead->mark(new TrendingStoryListQuery(userId: $user->requireId(), storyId: $id), $request->until);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
