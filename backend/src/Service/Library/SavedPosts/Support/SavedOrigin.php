<?php

declare(strict_types=1);

namespace App\Service\Library\SavedPosts\Support;

use App\Entity\EntryAuthor;
use App\Entity\EntryOrigin;
use App\Entity\Subscription;
use App\Enum\SourceFormat;
use App\Repository\SubscriptionDisplayTitle;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Foundation\Url\Support\FeedWebsite;

/** What a saved copy keeps of the feed it is leaving: the profile it was posted on, or the RSS feed's name and icon. */
final class SavedOrigin
{
    private const int NAME_MAX_LENGTH = 255;

    public static function buildPoster(Subscription $leaving): ?EntryAuthor
    {
        $feed = $leaving->getFeed();
        if (null === $feed->getNetwork() || SourceFormat::isSocial($feed->getSourceFormat())) {
            return null;
        }

        return new EntryAuthor(
            self::cutName($leaving),
            AbsoluteHttpUrl::keepIfAbsolute(FeedWebsite::resolveWebsite($feed->getUrl(), $feed->getSiteUrl())),
            $feed->getImageUrl(),
        );
    }

    public static function buildOrigin(Subscription $leaving): ?EntryOrigin
    {
        $feed = $leaving->getFeed();

        return null === $feed->getNetwork() ? new EntryOrigin(self::cutName($leaving), $feed->getFaviconUrl()) : null;
    }

    private static function cutName(Subscription $leaving): string
    {
        return mb_substr(SubscriptionDisplayTitle::fromSubscription($leaving), 0, self::NAME_MAX_LENGTH);
    }

    private function __construct()
    {
    }
}
