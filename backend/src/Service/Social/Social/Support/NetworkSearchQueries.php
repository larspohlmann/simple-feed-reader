<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Social\Pass\SearchQueryAlternatives;

/** A query as a network can run it: the searches to send, one per `||` alternative where the network has no `||`. */
final class NetworkSearchQueries
{
    /**
     * @return non-empty-list<string>
     *
     * @throws SearchQueryTooBroadException
     */
    public static function of(SocialNetwork $network, string $query): array
    {
        return match ($network) {
            SocialNetwork::Bluesky => [$query],
            SocialNetwork::Mastodon => self::alternativesOf($query)->queries(),
            SocialNetwork::Lemmy => LemmySearchTerms::of(self::alternativesOf($query)->factors()),
        };
    }

    /** @throws SearchQueryTooBroadException */
    public static function requireFits(SocialNetwork $network, string $query): void
    {
        self::of($network, $query);
    }

    public static function fits(SocialNetwork $network, string $query): bool
    {
        try {
            self::of($network, $query);
        } catch (SearchQueryTooBroadException) {
            return false;
        }

        return true;
    }

    private static function alternativesOf(string $query): SearchQueryAlternatives
    {
        return new SearchQueryAlternatives(SearchQueryTokens::of($query));
    }

    private function __construct()
    {
    }
}
