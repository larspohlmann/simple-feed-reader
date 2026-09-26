<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Entry\MarkSavedSearchesReadRequest;
use App\Entity\User;
use App\Enum\ListOrder;
use App\Http\EntryCursor;
use App\Http\SavedSearchPage;
use App\Repository\EntryCategoryLoader;
use App\Repository\EntryQuery;
use App\Repository\SavedSearchListQuery;
use App\Repository\SavedSearchMembershipLoader;
use App\Repository\SavedSearchRepository;
use App\Service\Reader\SavedSearchMarkReadService;
use App\Service\Search\SavedSearchEntries;
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
        private SavedSearchRepository $savedSearches,
        private SavedSearchEntries $entries,
        private EntryCategoryLoader $categoryLoader,
        private SavedSearchMembershipLoader $savedSearchLoader,
        private SavedSearchMarkReadService $markRead,
    ) {
    }

    #[Route('', name: 'api_entries_saved_searches', methods: ['GET'])]
    public function list(
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $cursor = null,
        #[MapQueryParameter] int $limit = EntryQuery::DEFAULT_LIMIT,
        #[MapQueryParameter] bool $unread = false,
        #[MapQueryParameter] ?string $order = null,
    ): JsonResponse {
        $userId = $user->requireId();
        $query = new SavedSearchListQuery(
            userId: $userId,
            savedSearchIds: $this->savedSearches->idsForUser($userId),
            onlyUnread: $unread,
            cursor: EntryCursor::fromRequestValue($cursor),
            limit: $limit,
            order: ListOrder::fromRequestValue($order),
        );
        $result = $this->entries->list($query);
        $rows = $this->savedSearchLoader->loadInto(
            $this->categoryLoader->loadInto($result->rows),
            $userId,
        );

        return new JsonResponse(SavedSearchPage::of($result->withRows($rows), $query->limit));
    }

    #[Route('/{id}', name: 'api_entries_saved_search_one', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function one(
        int $id,
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $cursor = null,
        #[MapQueryParameter] int $limit = EntryQuery::DEFAULT_LIMIT,
        #[MapQueryParameter] bool $unread = false,
        #[MapQueryParameter] ?string $order = null,
    ): JsonResponse {
        $userId = $user->requireId();
        $savedSearch = $this->savedSearches->getOneOwnedBy($id, $userId);

        $query = new SavedSearchListQuery(
            userId: $userId,
            savedSearchIds: [$savedSearch->requireId()],
            onlyUnread: $unread,
            cursor: EntryCursor::fromRequestValue($cursor),
            limit: $limit,
            order: ListOrder::fromRequestValue($order),
        );
        $result = $this->entries->list($query);
        $rows = $this->savedSearchLoader->loadInto(
            $this->categoryLoader->loadInto($result->rows),
            $userId,
        );

        return new JsonResponse(SavedSearchPage::of($result->withRows($rows), $query->limit));
    }

    #[Route('/mark-read', name: 'api_entries_saved_searches_mark_read', methods: ['POST'])]
    public function markRead(
        #[CurrentUser] User $user,
        #[MapRequestPayload] MarkSavedSearchesReadRequest $request,
    ): JsonResponse {
        $this->markRead->mark($user, $request->until);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(
        '/{id}/mark-read',
        name: 'api_entries_saved_search_one_mark_read',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function markOneRead(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] MarkSavedSearchesReadRequest $request,
    ): JsonResponse {
        $savedSearch = $this->savedSearches->getOneOwnedBy($id, $user->requireId());
        $this->markRead->markOne($user, $savedSearch->requireId(), $request->until);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
