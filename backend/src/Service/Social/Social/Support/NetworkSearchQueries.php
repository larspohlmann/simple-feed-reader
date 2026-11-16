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
        $alternatives = new SearchQueryAlternatives(SearchQueryTokens::of($query));

        return match ($network) {
            SocialNetwork::Bluesky => [$query],
            SocialNetwork::Mastodon => $alternatives->queries(),
            SocialNetwork::Lemmy => LemmySearchTerms::of($alternatives->factors()),
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

    private function __construct()
    {
    }
}
