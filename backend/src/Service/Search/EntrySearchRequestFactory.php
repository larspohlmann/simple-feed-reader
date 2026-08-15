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
            terms: SearchTerms::fromInput($this->singleValue($request, 'q')),
            cursor: EntryCursor::fromRequestValue($this->singleValue($request, 'cursor')),
            limit: $this->limit($request),
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
     * Reads one query parameter as a plain string rather than through
     * `getString()`, so that `q[]=x` reports the same `validation_error`
     * problem — with a message naming the field — as every other invalid
     * input to this endpoint.
     *
     * `getString()` would not break: it throws `BadRequestException`, which
     * `HttpKernel::handle()` converts to `BadRequestHttpException` BEFORE
     * `kernel.exception` fires, so `ApiExceptionListener` already answers a
     * clean 400 `request_error`. An earlier version of this comment claimed
     * it produced a 500 and that this method existed to prevent one; that was
     * measured and is false — see the correction on #410. What is left is a
     * smaller, real choice: a 400 with no field detail, or a 422 that tells
     * the client WHICH parameter was malformed, matching the 422 this
     * endpoint already answers for a too-short or over-long `q`.
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
}
