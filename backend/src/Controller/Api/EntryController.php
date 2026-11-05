<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Entry\EntryPageParameters;
use App\Dto\Entry\UpdateEntryStateRequest;
use App\Entity\User;
use App\Enum\EntryView;
use App\Enum\FeedGroup;
use App\Enum\ListOrder;
use App\Http\EntryPage;
use App\Http\EntryStateJson;
use App\Http\RecommendationFeedJson;
use App\Pagination\EntryCursor;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRowEnricher;
use App\Repository\EntryListSort;
use App\Repository\EntryQuery;
use App\Repository\ForYouFeedQuery;
use App\Repository\NetworkScope;
use App\Service\Reading\EntryStateUpdater;
use App\Service\Recommendation\Feed\ForYouFeed;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryController
{
    public function __construct(
        private EntryListRepository $entryList,
        private EntryListRowEnricher $enricher,
        private EntryStateUpdater $entryStateUpdater,
        private ForYouFeed $forYouFeed,
        private EntryPage $entryPage,
        private RecommendationFeedJson $recommendationFeedJson,
    ) {
    }

    #[Route('', name: 'api_entries_list', methods: ['GET'])]
    public function list(
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $view = null,
        #[MapQueryParameter] ?int $subscription = null,
        #[MapQueryParameter] ?int $tag = null,
        #[MapQueryParameter] ?string $network = null,
        #[MapQueryParameter] ?string $feedKind = null,
        #[MapQueryParameter] ?string $group = null,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        EntryPageParameters $page = new EntryPageParameters(),
    ): JsonResponse {
        // Parsed here, not by a MapQueryParameter filter, so a bad view is the same
        // `validation_error` as every other invalid field, which the client switches on.
        $entryView = EntryView::fromRequestValue($view);
        $listOrder = ListOrder::fromRequestValue($page->order);
        $networkScope = NetworkScope::fromRequestValues($network, $feedKind);

        // Score-ranked, with its own cursor.
        if ($entryView === EntryView::ForYou) {
            return new JsonResponse($this->recommendationFeedJson->page($this->forYouFeed->page(
                new ForYouFeedQuery($user, $page->cursor, $page->limit, $page->unread),
            )));
        }

        $query = new EntryQuery(
            userId: $user->requireId(),
            view: $entryView,
            subscriptionId: $subscription,
            tagId: $tag,
            cursor: EntryCursor::fromRequestValue($page->cursor),
            limit: $page->limit,
            order: $listOrder,
            networkScope: $networkScope,
            feedGroup: FeedGroup::fromRequestValue($group),
        );

        $rows = $this->enricher->enrich($this->entryList->listForUser($query), $user->requireId());

        return new JsonResponse($this->entryPage->of($rows, $query->limit, EntryListSort::forView($entryView)));
    }

    #[Route('/{id}/state', name: 'api_entries_state', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateState(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateEntryStateRequest $request,
    ): JsonResponse {
        $row = $this->entryList->getRowForUser($user->requireId(), $id);

        $updated = $this->entryStateUpdater->apply($user, $row, $request->toChange());

        return new JsonResponse(['state' => EntryStateJson::one($updated, $id)]);
    }
}
