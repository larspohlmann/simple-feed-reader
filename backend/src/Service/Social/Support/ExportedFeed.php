<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;

/**
 * How an export names a feed. An author feed leaves as its profile's public RSS: restored, that answers 403, and the
 * hidden-profile switch moves it back to an author feed of the restoring account.
 */
final class ExportedFeed
{
    public static function urlOf(Feed $feed): string
    {
        $did = self::authorOf($feed);

        return null === $did ? $feed->getUrl() : ProfileFeedUrl::bluesky($did);
    }

    public static function sourceFormatOf(Feed $feed): string
    {
        return null === self::authorOf($feed) ? $feed->getSourceFormat() : SourceFormat::XML;
    }

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return list<Subscription> the first subscription of each exported URL: an author feed and the refused RSS feed
     *                            it replaces export as one
     */
    public static function withoutRepeats(array $subscriptions): array
    {
        $byUrl = [];
        foreach ($subscriptions as $subscription) {
            $byUrl[self::urlOf($subscription->getFeed())] ??= $subscription;
        }

        return array_values($byUrl);
    }

    private static function authorOf(Feed $feed): ?string
    {
        return SocialFeedUrl::authorOf(SocialNetwork::Bluesky, $feed->getUrl());
    }

    private function __construct()
    {
    }
}
