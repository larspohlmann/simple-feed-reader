<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Entry\MarkReadRequest;
use App\Dto\Entry\UpdateEntryStateRequest;
use App\Entity\EntryState;
use App\Entity\User;
use App\Exception\RateLimitedException;
use App\Exception\ValidationException;
use App\Http\EntryCursor;
use App\Http\EntryJson;
use App\Http\ReaderJson;
use App\Repository\EntryQuery;
use App\Repository\EntryRepository;
use App\Repository\EntryStateRepository;
use App\Service\Reader\ArticleExtractorInterface;
use App\Service\Reader\ExtractionResult;
use App\Service\Reader\MarkReadService;
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
final class EntryController
{
    public function __construct(
        private readonly EntryRepository $entries,
        private readonly EntryStateRepository $states,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly MarkReadService $markRead,
        private readonly ArticleExtractorInterface $extractor,
        private readonly RateLimiterFactoryInterface $readerLimiter,
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
            default => throw new ValidationException(
                ['view' => ['Unknown view. Use one of: all, unread, favorites, kept.']],
            ),
        };

        $decodedCursor = null;
        if ($cursor !== null && $cursor !== '') {
            $decodedCursor = EntryCursor::decode($cursor);
            if ($decodedCursor === null) {
                throw new ValidationException(['cursor' => ['The cursor is malformed.']]);
            }
        }

        $rows = $this->entries->listForUser(new EntryQuery(
            userId: (int) $user->getId(),
            view: $view,
            subscriptionId: $subscription,
            tagId: $tag,
            cursor: $decodedCursor,
            limit: $limit,
        ));

        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        $nextCursor = null;
        // A full page implies there may be more; hand back a cursor from the
        // last row. (A short page cannot have a next page.)
        if ($last !== null && \count($rows) >= min(max(1, $limit), EntryQuery::MAX_LIMIT)) {
            $entry = $last->entry;
            $nextCursor = EntryCursor::encode(
                $entry->getPublishedAt() ?? $entry->getCreatedAt(),
                $entry->getId() ?? 0,
            );
        }

        return new JsonResponse([
            'entries' => array_map(static fn ($r) => EntryJson::one($r), $rows),
            'nextCursor' => $nextCursor,
        ]);
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
        $entry = $this->entries->findOneSubscribedByUser($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such entry.');

        $state = $this->states->findOneForUserEntry((int) $user->getId(), $id);
        if ($state === null) {
            $state = new EntryState($user, $entry);
            $this->em->persist($state);
        }

        if ($request->isRead !== null) {
            $state->setIsRead($request->isRead);
            $state->setReadAt($request->isRead ? $this->clock->now() : null);
        }
        if ($request->isFavorite !== null) {
            $state->setIsFavorite($request->isFavorite);
        }
        if ($request->isKept !== null) {
            $state->setIsKept($request->isKept);
        }

        $this->em->flush();

        return new JsonResponse(['state' => [
            'entryId' => $id,
            'isRead' => $state->isRead(),
            'isFavorite' => $state->isFavorite(),
            'isKept' => $state->isKept(),
            'readAt' => $state->getReadAt()?->format(\DateTimeInterface::ATOM),
        ]]);
    }

    #[Route('/{id}/reader', name: 'api_entries_reader', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function reader(
        int $id,
        #[CurrentUser] User $user,
    ): JsonResponse {
        // Ownership is checked BEFORE the limiter so an unowned id 404s without
        // spending the caller's reader budget.
        $entry = $this->entries->findOneSubscribedByUser($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such entry.');

        $this->enforceReaderLimit($user);

        $url = $entry->getUrl();
        $result = $url === null || $url === ''
            ? ExtractionResult::failed(null, 'no_url')
            : $this->extractor->extract($url);

        return new JsonResponse(ReaderJson::one($result, $this->clock->now()));
    }

    private function enforceReaderLimit(User $user): void
    {
        $limit = $this->readerLimiter->create('user-' . $user->getId())->consume();
        if ($limit->isAccepted()) {
            return;
        }

        throw new RateLimitedException(
            max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()),
        );
    }
}
