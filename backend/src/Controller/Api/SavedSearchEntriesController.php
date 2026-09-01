<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Entry\MarkSavedSearchesReadRequest;
use App\Entity\User;
use App\Http\EntryCursor;
use App\Http\SavedSearchPage;
use App\Repository\EntryListRow;
use App\Repository\EntryQuery;
use App\Repository\SavedSearchEntryQuery;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Reader\SavedSearchMarkReadService;
use App\Service\Search\SavedSearchTerms;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The combined saved-search list: one stream of everything the caller's saved
 * searches match. Its own endpoint rather than a mode on `/entries/search`,
 * which answers one term, and rather than a `view` on `/entries`, which filters
 * feeds rather than content (#769).
 */
#[Route('/api/entries/saved-searches')]
final readonly class SavedSearchEntriesController
{
    public function __construct(
        private SavedSearchTerms $terms,
        private SavedSearchEntryRepository $entries,
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
        $searches = $this->terms->forUserWithIds($userId);
        $query = new SavedSearchEntryQuery(
            userId: $userId,
            termsPerSearch: $searches->terms,
            onlyUnread: $unread,
            cursor: EntryCursor::fromRequestValue($cursor),
            limit: $limit,
        );

        $rows = $this->entries->listForSavedSearches($query);
        $entryIds = array_map(static fn (EntryListRow $row): int => (int) $row->entry->getId(), $rows);

        return new JsonResponse(SavedSearchPage::of(
            $rows,
            $query->limit,
            $this->entries->matchedSavedSearchIds($query, $entryIds, $searches->ids),
        ));
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
