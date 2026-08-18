<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Entry\MarkReadRequest;
use App\Dto\Entry\UpdateEntryStateRequest;
use App\Entity\User;
use App\Exception\ValidationException;
use App\Http\EntryCursor;
use App\Http\EntryJson;
use App\Http\EntryPage;
use App\Http\EntryStateJson;
use App\Http\ReaderJson;
use App\Repository\EntryListRepository;
use App\Repository\EntryListSort;
use App\Repository\EntryQuery;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Reader\ArticleExtractorInterface;
use App\Service\Reader\EntryStateResolver;
use App\Service\Reader\ExtractionResult;
use App\Service\Reader\MarkReadService;
use App\Service\Recommendation\ForYouFeedResponder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryController
{
    public function __construct(
        private EntryListRepository $entryList,
        private EntryStateResolver $entryStates,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private MarkReadService $markRead,
        private ArticleExtractorInterface $extractor,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $readerLimiter,
        private ForYouFeedResponder $forYouFeed,
    ) {
    }

    #[Route('', name: 'api_entries_list', methods: ['GET'])]
    public function list(
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $view = null,
        #[MapQueryParameter] ?int $subscription = null,
        #[MapQueryParameter] ?int $tag = null,
        #[MapQueryParameter] ?string $cursor = null,
        #[MapQueryParameter] int $limit = EntryQuery::DEFAULT_LIMIT,
    ): JsonResponse {
        // Validate `view` in-controller (rather than via a MapQueryParameter
        // regexp filter) so a bad value reports the SAME `validation_error`
        // problem type as every other invalid field — the client switches on
        // that type. The match also narrows the plain string to EntryQuery's
        // literal-union view type for static analysis.
        $view = match ($view) {
            null, 'all' => 'all',
            'unread' => 'unread',
            'favorites' => 'favorites',
            'kept' => 'kept',
            'viewed' => 'viewed',
            'for-you' => 'for-you',
            default => throw new ValidationException(
                ['view' => ['Unknown view. Use one of: all, unread, favorites, kept, viewed, for-you.']],
            ),
        };

        // The for-you feed is score-ranked, not (effectiveDate, id)-ranked, so
        // it needs its own cursor and never reaches EntryQuery's applyView.
        if ($view === 'for-you') {
            return new JsonResponse($this->forYouFeed->page($user, $cursor, $limit));
        }

        $query = new EntryQuery(
            userId: (int) $user->getId(),
            view: $view,
            subscriptionId: $subscription,
            tagId: $tag,
            cursor: EntryCursor::fromRequestValue($cursor),
            limit: $limit,
        );

        return new JsonResponse(EntryPage::of(
            $this->entryList->listForUser($query),
            $query->limit,
            EntryListSort::forView($view),
        ));
    }

    #[Route('/{id}', name: 'api_entries_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(
        int $id,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $row = $this->entryList->oneRowForUser($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such entry.');

        return new JsonResponse(['entry' => EntryJson::one($row)]);
    }

    #[Route('/mark-read', name: 'api_entries_mark_read', methods: ['POST'])]
    public function markRead(
        #[CurrentUser] User $user,
        #[MapRequestPayload] MarkReadRequest $request,
    ): JsonResponse {
        $this->markRead->mark($user, $request->scope, $request->id, $request->until);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/state', name: 'api_entries_state', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateState(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateEntryStateRequest $request,
    ): JsonResponse {
        $row = $this->entryList->oneRowForUser($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such entry.');

        $state = $this->entryStates->resolve($user, $row);

        if ($request->isRead !== null) {
            // Unread also clears "opened" (EntryState::markUnread, #478), so the
            // rule reaches every client, not just the web app.
            $request->isRead
                ? $state->markRead($this->clock->now())
                : $state->markUnread();
        }
        if ($request->isFavorite !== null) {
            $state->setIsFavorite($request->isFavorite);
        }
        if ($request->isKept !== null) {
            $state->setIsKept($request->isKept);
        }
        if ($request->isViewed !== null) {
            // markViewed sets only the viewed flag; ViewedImpliesReadListener
            // adds the read flag on flush. clearViewed leaves the entry read.
            $request->isViewed
                ? $state->markViewed($this->clock->now())
                : $state->clearViewed();
        }

        $this->em->flush();

        return new JsonResponse(['state' => EntryStateJson::one($state, $id)]);
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
            : $this->extractor->extract($url, $entry->getTitle());

        return new JsonResponse(ReaderJson::one($result, $this->clock->now()));
    }
}
