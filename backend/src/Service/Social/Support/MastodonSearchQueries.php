<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Pass\SearchQueryAlternatives;

/** A query as Mastodon can run it: one search per `||` alternative, an operator Mastodon's search does not know. */
final class MastodonSearchQueries
{
    /**
     * @return non-empty-list<string>
     *
     * @throws SearchQueryTooBroadException
     */
    public static function of(string $query): array
    {
        return (new SearchQueryAlternatives(SearchQueryTokens::of($query)))->queries();
    }

    /** @throws SearchQueryTooBroadException */
    public static function requireFits(string $query): void
    {
        self::of($query);
    }

    public static function fits(string $query): bool
    {
        try {
            self::of($query);
        } catch (SearchQueryTooBroadException) {
            return false;
        }

        return true;
    }

    private function __construct()
    {
    }
}
