<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\Subscription;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Social\Model\ExportedSubscriptionModel;

/**
 * How an export names a feed. An author feed leaves as its profile's public RSS: restored, that answers 403, and the
 * hidden-profile switch moves it back to an author feed of the restoring account.
 */
final class ExportedFeed
{
    public static function of(Subscription $subscription): ExportedSubscriptionModel
    {
        $feed = $subscription->getFeed();
        $did = SocialFeedUrl::authorOf(SocialNetwork::Bluesky, $feed->getUrl());

        return null === $did
            ? new ExportedSubscriptionModel($subscription, $feed->getUrl(), $feed->getSourceFormat())
            : new ExportedSubscriptionModel($subscription, ProfileFeedUrl::bluesky($did), SourceFormat::XML);
    }

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return list<ExportedSubscriptionModel> the first subscription of each exported URL: an author feed and the
     *                                         refused RSS feed it replaces export as one
     */
    public static function withoutRepeats(array $subscriptions): array
    {
        $byUrl = [];
        foreach ($subscriptions as $subscription) {
            $exported = self::of($subscription);
            $byUrl[$exported->feedUrl] ??= $exported;
        }

        return array_values($byUrl);
    }

    private function __construct()
    {
    }
}
