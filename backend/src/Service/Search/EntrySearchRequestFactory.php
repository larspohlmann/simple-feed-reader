<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\User;
use App\Exception\ValidationException;
use App\Http\EntryCursor;
use App\Repository\EntryQuery;
use App\Repository\EntrySearchQuery;
use Symfony\Component\HttpFoundation\Request;

/**
 * Turns one HTTP request into a search query, and refuses anything it does not
 * understand. An unknown parameter is rejected rather than ignored: silently
 * dropping `tag=3` would answer a search the caller did not ask for, and a
 * caller who believes the filter applied has no way to tell.
 */
final readonly class EntrySearchRequestFactory
{
    public const array ALLOWED_PARAMETERS = ['q', 'cursor', 'limit'];

    public function fromRequest(Request $request, User $user): EntrySearchQuery
    {
        $this->assertNoUnknownParameters($request);

        return new EntrySearchQuery(
            userId: (int) $user->getId(),
            terms: SearchTerms::fromInput($request->query->getString('q')),
            cursor: $this->cursor($request->query->getString('cursor')),
            limit: $request->query->getInt('limit', EntryQuery::DEFAULT_LIMIT),
        );
    }

    private function assertNoUnknownParameters(Request $request): void
    {
        $unknown = array_diff(array_keys($request->query->all()), self::ALLOWED_PARAMETERS);
        if ($unknown === []) {
            return;
        }

        throw new ValidationException([
            'query' => array_map(
                static fn (string $name): string => \sprintf('Unknown parameter "%s".', $name),
                array_values($unknown),
            ),
        ]);
    }

    private function cursor(string $raw): ?EntryCursor
    {
        if ($raw === '') {
            return null;
        }

        return EntryCursor::decode($raw)
            ?? throw new ValidationException(['cursor' => ['The cursor is malformed.']]);
    }
}
