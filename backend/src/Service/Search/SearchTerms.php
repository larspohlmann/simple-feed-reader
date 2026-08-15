<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Exception\ValidationException;

/**
 * The terms a search runs on, parsed from one raw query string.
 *
 * Every term must match for a row to qualify, so each extra term is another
 * pair of unindexable LIKE predicates — hence the ceiling on how many a single
 * query may carry. A paste past that ceiling loses its tail rather than being
 * rejected: the user still gets the search they asked for, only narrower than
 * the ceiling would allow.
 */
final readonly class SearchTerms
{
    public const int MIN_INPUT_LENGTH = 3;
    public const int MAX_INPUT_LENGTH = 100;
    public const int MAX_TERMS = 6;

    /** @param list<string> $terms */
    private function __construct(public array $terms, public bool $isWholeWord)
    {
    }

    public static function fromInput(string $input): self
    {
        // The mode is a property of the raw input, decided before trimming:
        // a trailing space is the signal, and trimming would erase it. It is
        // one flag for the whole query, not a per-term one — a per-term rule
        // would make every term but the last "whole word" merely by being
        // followed by a space while typing, which is not what the user meant.
        $isWholeWord = (bool) preg_match('/\s\z/', $input);

        $trimmed = trim($input);
        self::assertLengthIsUsable($trimmed);

        /** @var list<string> $terms */
        $terms = preg_split('/\s+/', $trimmed) ?: [];

        return new self(\array_slice($terms, 0, self::MAX_TERMS), $isWholeWord);
    }

    private static function assertLengthIsUsable(string $trimmed): void
    {
        if (mb_strlen($trimmed) < self::MIN_INPUT_LENGTH) {
            throw new ValidationException([
                'q' => [\sprintf('Search for at least %d characters.', self::MIN_INPUT_LENGTH)],
            ]);
        }

        if (mb_strlen($trimmed) > self::MAX_INPUT_LENGTH) {
            throw new ValidationException([
                'q' => [\sprintf('Search for at most %d characters.', self::MAX_INPUT_LENGTH)],
            ]);
        }
    }
}
