<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Social\Social\Exception\SearchQueryTooBroadException;

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
        $longest = '';
        foreach ($factors as $factor) {
            $term = self::termOf($factor);
            if (mb_strlen($term) > mb_strlen($longest)) {
                $longest = $term;
            }
        }

        return '' === $longest ? throw self::termless() : $longest;
    }

    private static function termOf(string $factor): string
    {
        if (str_starts_with($factor, '-') || SearchQueryTokens::isAuthorFilter($factor)) {
            return '';
        }

        return implode(' ', SearchQueryTokens::wordsOf($factor));
    }

    private static function termless(): SearchQueryTooBroadException
    {
        return new SearchQueryTooBroadException('Lemmy needs a word to search for in every alternative.');
    }

    private function __construct()
    {
    }
}
