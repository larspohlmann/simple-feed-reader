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
}
