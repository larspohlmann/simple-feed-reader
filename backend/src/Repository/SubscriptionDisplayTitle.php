<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subscription;

/** The one definition of a subscription's display title, shared by every projection that names a subscription. */
final class SubscriptionDisplayTitle
{
    public static function from(?string $customTitle, ?string $feedTitle, string $feedUrl): string
    {
        if (null !== $customTitle && '' !== $customTitle) {
            return $customTitle;
        }

        if (null !== $feedTitle && '' !== $feedTitle) {
            return $feedTitle;
        }

        return $feedUrl;
    }

    public static function ofSubscription(Subscription $subscription): string
    {
        $feed = $subscription->getFeed();

        return self::from($subscription->getCustomTitle(), $feed->getTitle(), $feed->getUrl());
    }
}
