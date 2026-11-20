<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy\Support;

use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Social\Support\SearchQueryTokens;

/** What Lemmy is asked for each alternative: its longest word or phrase, the rest is checked on the posts. */
final class LemmySearchTerms
{
    /**
     * @param list<list<string>> $alternatives
     *
     * @return non-empty-list<string>
     *
     * @throws SearchQueryTooBroadException
     */
    public static function of(array $alternatives): array
    {
        $terms = array_values(array_unique(array_map(self::longestTermOf(...), $alternatives)));
        if ([] === $terms) {
            throw self::termless();
        }

        return $terms;
    }

    /** @param list<string> $factors */
    private static function longestTermOf(array $factors): string
    {
        $positives = array_values(array_filter($factors, self::isPositive(...)));
        $checked = array_values(array_filter(
            $positives,
            static fn (string $factor): bool => !SearchQueryTokens::isFilter($factor),
        ));
        $longest = self::longestOf([] === $checked ? $positives : $checked);

        return '' === $longest ? throw self::termless() : $longest;
    }

    private static function isPositive(string $factor): bool
    {
        return !str_starts_with($factor, '-') && !SearchQueryTokens::isAuthorFilter($factor);
    }

    /** @param list<string> $factors */
    private static function longestOf(array $factors): string
    {
        $longest = '';
        foreach ($factors as $factor) {
            $term = implode(' ', SearchQueryTokens::splitWords($factor));
            if (mb_strlen($term) > mb_strlen($longest)) {
                $longest = $term;
            }
        }

        return $longest;
    }

    private static function termless(): SearchQueryTooBroadException
    {
        return new SearchQueryTooBroadException('Lemmy needs a word to search for in every alternative.');
    }

    private function __construct()
    {
    }
}
