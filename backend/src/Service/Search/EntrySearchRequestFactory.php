<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\User;
use App\Enum\ListOrder;
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
    public const array ALLOWED_PARAMETERS = ['q', 'cursor', 'limit', 'unread', 'order'];

    public function fromRequest(Request $request, User $user): EntrySearchQuery
    {
        $this->assertNoUnknownParameters($request);

        return new EntrySearchQuery(
            userId: $user->requireId(),
            terms: SearchTerms::fromInput($this->singleValue($request, 'q')),
            cursor: EntryCursor::fromRequestValue($this->singleValue($request, 'cursor')),
            limit: $this->limit($request),
            unread: $this->unread($request),
            order: ListOrder::fromRequestValue($this->singleValue($request, 'order')),
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

    /**
     * A plain string read, not getString(): `q[]=x` then gets the validation_error naming the field that every
     * other invalid input here gets, instead of a bare request_error without field detail (#410).
     */
    private function singleValue(Request $request, string $name): string
    {
        $value = $request->query->all()[$name] ?? '';
        if (!\is_string($value)) {
            throw new ValidationException([
                $name => [\sprintf('Send one value for "%s", not a list.', $name)],
            ]);
        }

        return $value;
    }

    private function limit(Request $request): int
    {
        $raw = $this->singleValue($request, 'limit');
        if ($raw === '') {
            return EntryQuery::DEFAULT_LIMIT;
        }

        if (!ctype_digit($raw)) {
            throw new ValidationException(['limit' => ['The limit must be a whole number.']]);
        }

        return (int) $raw;
    }

    private function unread(Request $request): bool
    {
        return $this->singleValue($request, 'unread') === '1';
    }
}
