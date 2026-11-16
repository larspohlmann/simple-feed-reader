<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Entry\EntryPageParameters;
use App\Entity\User;
use App\Enum\ListOrder;
use App\Http\FollowedStoryPage;
use App\Pagination\EntryCursor;
use App\Repository\EntryListRowEnricher;
use App\Repository\TrendingStoryListQuery;
use App\Service\Curation\Trending\FollowedStoryEntries;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** A followed Trending story's entries; reading them records no visit, the client posts that separately. */
#[Route('/api/entries/trending-stories')]
final readonly class FollowedStoryEntriesController
{
    public function __construct(
        private FollowedStoryEntries $entries,
        private EntryListRowEnricher $enricher,
        private FollowedStoryPage $followedStoryPage,
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
            cursor: EntryCursor::fromRequestValue($page->cursor),
            limit: $page->limit,
            order: ListOrder::fromRequestValue($page->order),
        );
        $result = $this->entries->list($query);
        $rows = $this->enricher->enrich($result->rows, $userId);

        return new JsonResponse($this->followedStoryPage->of($result->withRows($rows), $query->limit));
    }
}
