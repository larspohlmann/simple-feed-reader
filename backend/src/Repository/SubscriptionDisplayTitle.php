<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subscription;
use App\Enum\SocialFeedKind;
use App\Service\Social\Social\Support\SocialFeedUrl;

/** The one definition of a subscription's display title, shared by every projection that names a subscription. */
final class SubscriptionDisplayTitle
{
    public static function from(?string $customTitle, ?string $feedTitle, string $feedUrl): string
    {
        if (null !== $customTitle && '' !== $customTitle) {
            return $customTitle;
        }

        $sourceTitle = self::parseSearchQuery($feedUrl) ?? $feedTitle;
        if (null !== $sourceTitle && '' !== $sourceTitle) {
            return $sourceTitle;
        }

        return $feedUrl;
    }

    public static function fromSubscription(Subscription $subscription): string
    {
        $feed = $subscription->getFeed();

        return self::from($subscription->getCustomTitle(), $feed->getTitle(), $feed->getUrl());
    }

    /** An unrenamed search follows its query, which a requery changes without touching the stored title. */
    private static function parseSearchQuery(string $feedUrl): ?string
    {
        $address = SocialFeedUrl::parseAddress($feedUrl);

        return SocialFeedKind::Search === $address?->kind ? $address->remoteId : null;
    }
}
