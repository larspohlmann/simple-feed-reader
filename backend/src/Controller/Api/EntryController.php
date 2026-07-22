<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Exception\ValidationException;
use App\Http\EntryCursor;
use App\Http\EntryJson;
use App\Repository\EntryQuery;
use App\Repository\EntryRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final class EntryController
{
    public function __construct(
        private readonly EntryRepository $entries,
    ) {
    }

    #[Route('', name: 'api_entries_list', methods: ['GET'])]
    public function list(
        #[CurrentUser] User $user,
        // The regexp guard is the whole validation for `view`. Its default
        // rejection status is 404 (HTTP_NOT_FOUND); we force 422 so a bad view
        // reads as the same "invalid input" the rest of the API reports, rather
        // than as a missing resource.
        #[MapQueryParameter(
            filter: \FILTER_VALIDATE_REGEXP,
            options: ['regexp' => '/^(all|unread|favorites|kept)$/'],
            validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
        )]
        string $view = 'all',
        #[MapQueryParameter] ?int $subscription = null,
        #[MapQueryParameter] ?int $tag = null,
        #[MapQueryParameter] ?string $cursor = null,
        #[MapQueryParameter] int $limit = EntryQuery::DEFAULT_LIMIT,
    ): JsonResponse {
        $decodedCursor = null;
        if ($cursor !== null && $cursor !== '') {
            $decodedCursor = EntryCursor::decode($cursor);
            if ($decodedCursor === null) {
                throw new ValidationException(['cursor' => ['The cursor is malformed.']]);
            }
        }

        // The regexp guard already rejects anything else, but that is opaque to
        // static analysis; this match re-states the domain type EntryQuery wants
        // and keeps the query valid should the guard ever drift.
        $view = match ($view) {
            'unread', 'favorites', 'kept' => $view,
            default => 'all',
        };

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
}
