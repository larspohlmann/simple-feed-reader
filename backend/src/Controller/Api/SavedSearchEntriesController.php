<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Entry\MarkSavedSearchesReadRequest;
use App\Entity\User;
use App\Http\EntryCursor;
use App\Http\SavedSearchPage;
use App\Repository\EntryQuery;
use App\Repository\SavedSearchEntryQuery;
use App\Service\Reader\SavedSearchMarkReadService;
use App\Service\Search\SavedSearchEntriesInterface;
use App\Service\Search\SavedSearchTerms;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The combined saved-search list: one stream of everything the caller's saved
 * searches match. Its own endpoint because `/entries/search` answers one term
 * and `/entries`' views filter feeds, not content.
 */
#[Route('/api/entries/saved-searches')]
final readonly class SavedSearchEntriesController
{
    public function __construct(
        private SavedSearchTerms $terms,
        private SavedSearchEntriesInterface $entries,
        private SavedSearchMarkReadService $markRead,
    ) {
    }

    #[Route('', name: 'api_entries_saved_searches', methods: ['GET'])]
    public function list(
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $cursor = null,
        #[MapQueryParameter] int $limit = EntryQuery::DEFAULT_LIMIT,
        #[MapQueryParameter] bool $unread = false,
    ): JsonResponse {
        $userId = (int) $user->getId();
        $query = new SavedSearchEntryQuery(
            userId: $userId,
            savedSearches: $this->terms->forUser($userId),
            onlyUnread: $unread,
            cursor: EntryCursor::fromRequestValue($cursor),
            limit: $limit,
        );

        return new JsonResponse(SavedSearchPage::of($this->entries->list($query), $query->limit));
    }

    #[Route('/mark-read', name: 'api_entries_saved_searches_mark_read', methods: ['POST'])]
    public function markRead(
        #[CurrentUser] User $user,
        #[MapRequestPayload] MarkSavedSearchesReadRequest $request,
    ): JsonResponse {
        $this->markRead->mark($user, $request->until);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
