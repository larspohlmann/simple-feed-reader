<?php

declare(strict_types=1);

namespace App\Service\SavedPosts\Support;

use App\Entity\EntryAuthor;
use App\Entity\EntryOrigin;
use App\Entity\Subscription;
use App\Enum\SourceFormat;
use App\Repository\SubscriptionDisplayTitle;
use App\Service\Url\Support\AbsoluteHttpUrl;
use App\Service\Url\Support\FeedWebsite;

/** What a saved copy keeps of the feed it is leaving: the profile it was posted on, or the RSS feed's name and icon. */
final class SavedOrigin
{
    private const int NAME_MAX_LENGTH = 255;

    public static function posterOf(Subscription $leaving): ?EntryAuthor
    {
        $feed = $leaving->getFeed();
        if (null === $feed->getNetwork() || SourceFormat::isSocial($feed->getSourceFormat())) {
            return null;
        }

        return new EntryAuthor(
            self::nameOf($leaving),
            AbsoluteHttpUrl::orNull(FeedWebsite::of($feed->getUrl(), $feed->getSiteUrl())),
            $feed->getImageUrl(),
        );
    }

    public static function originOf(Subscription $leaving): ?EntryOrigin
    {
        $feed = $leaving->getFeed();

        return null === $feed->getNetwork() ? new EntryOrigin(self::nameOf($leaving), $feed->getFaviconUrl()) : null;
    }

    private static function nameOf(Subscription $leaving): string
    {
        $feed = $leaving->getFeed();
        $title = SubscriptionDisplayTitle::from($leaving->getCustomTitle(), $feed->getTitle(), $feed->getUrl());

        return mb_substr($title, 0, self::NAME_MAX_LENGTH);
    }

    private function __construct()
    {
    }
}
